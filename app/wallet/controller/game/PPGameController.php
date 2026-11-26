<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameRecordCacheService;
use app\service\RedisLuaScripts;
use app\service\WalletService;
use Exception;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;

/**
 * Pragmatic Play（PP）单一钱包回调（Seamless Wallet HTTP 服务，文档 3.4–3.15）
 *
 * 请求：application/x-www-form-urlencoded POST（服务器对服务器）；响应：JSON。
 * 签名：文档 3.2（全部参数按字母序拼 key=value&… 末尾拼 SECRET 后 MD5）。
 * 错误码：文档 3.17（0 成功 / 1 余额不足 / 2 玩家不存在或已登出 / 3 禁止下注 / 4 认证失败 /
 * 5 签名错误 / 6 玩家冻结 / 7 参数错误 / 100 内部错误需重试 / 130 EndRound 内部错误）。
 * 资金链路：RedisLuaScripts::atomicBet/atomicSettle/atomicCancel（原子扣款/派彩/退款）→
 * GameRecordCacheService 记录 → GameRecordSyncWorker 异步落 MySQL。
 * 注单模型（同 AS 范式，一局一单）：注单单号 = 局号 roundId；Result 派彩以 reference 为资金幂等键，
 * 记录经 saveSettleIntoBet 按单号合并进下注单（win 累加、is_final 收口），下注 + 结算只产一条订单。
 * 幂等：Bet/Result/BonusWin/JackpotWin/PromoWin/Adjustment/Refund 均以 reference 为交易唯一键，
 * 重试只返回实际玩家余额（文档 3.6/3.7 等）；transactionId 首次生成后缓存 pp:tx:{op}:{reference}。
 */
class PPGameController
{
    use TelegramAlertTrait;

    /** 平台 code（game_platform 表 / config/game_platform.php / Redis 记录前缀均用它） */
    private const PLATFORM = 'PP';

    /** 幂等 transactionId 缓存 TTL（秒） */
    private const TX_TTL = 86400;

    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_PP);
        $this->logger = Log::channel('pp_server');
    }

    // ==================== 3.4 Authenticate ====================
    // 请求必需 hash、token、providerId；响应必需 userId、currency、cash、bonus、error、description

    public function authenticate(Request $request): Response
    {
        $params = $request->post();
        try {
            if (!$this->service->verifyHash($params)) {
                return $this->ppResponse([], 5, 'Invalid hash code');
            }
            if ($missing = $this->missing($params, ['token', 'providerId'])) {
                return $this->ppResponse([], 7, 'Bad parameters: ' . implode(',', $missing));
            }
            $tokenData = $this->service->verifyToken((string)$params['token']);
            if ($tokenData === false) {
                return $this->ppResponse([], 4, 'Authentication failed');
            }
            $player = Player::query()->where('id', (int)$tokenData['player_id'])->first();
            if ($err = $this->guardPlayer($player)) {
                return $err;
            }
            logGameInteraction(self::PLATFORM, 'authenticate', $params, ['player_id' => $player->id]);
            return $this->ppResponse([
                'userId' => (string)$player->uuid,
                'currency' => (string)$player->currency,
                'cash' => $this->cash($player),
                'bonus' => 0,
                'token' => (string)$params['token'],
                'extraInfo' => ['promoAvailable' => (string)(config('game_platform.PP.promo_available') ?? 'y')],
            ]);
        } catch (Exception $e) {
            return $this->handleException('authenticate', $e, $params);
        }
    }

    // ==================== 3.5 Balance ====================
    // 请求必需 hash、providerId、userId；响应必需 currency、cash、bonus、error、description

    public function balance(Request $request): Response
    {
        $params = $request->post();
        try {
            if (!$this->service->verifyHash($params)) {
                return $this->ppResponse([], 5, 'Invalid hash code');
            }
            if ($missing = $this->missing($params, ['userId', 'providerId'])) {
                return $this->ppResponse([], 7, 'Bad parameters: ' . implode(',', $missing));
            }
            $player = Player::query()->where('uuid', $params['userId'])->first();
            if ($err = $this->guardPlayer($player)) {
                return $err;
            }
            return $this->ppResponse([
                'currency' => (string)$player->currency,
                'cash' => $this->cash($player),
                'bonus' => 0,
            ]);
        } catch (Exception $e) {
            return $this->handleException('balance', $e, $params);
        }
    }

    // ==================== 3.6 Bet ====================
    // 请求必需 hash、userId、gameId、roundId、amount(≥0.00)、reference、providerId、timestamp、roundDetails
    // 响应必需 transactionId、currency、cash、bonus、usedPromo、error、description

    public function bet(Request $request): Response
    {
        $params = $request->post();
        try {
            if (!$this->service->verifyHash($params)) {
                return $this->ppResponse([], 5, 'Invalid hash code');
            }
            if ($missing = $this->missing($params, ['userId', 'gameId', 'roundId', 'amount', 'reference', 'providerId', 'timestamp', 'roundDetails'])) {
                return $this->ppResponse([], 7, 'Bad parameters: ' . implode(',', $missing));
            }
            if (!is_numeric($params['amount']) || (float)$params['amount'] < 0) {
                return $this->ppResponse([], 7, 'Bad parameters: amount');
            }
            $player = Player::query()->where('uuid', $params['userId'])->first();
            if ($err = $this->guardPlayer($player)) {
                return $err;
            }
            if ((int)$player->status_game_platform !== 1) {
                return $this->ppResponse([], 3, 'Bet is not allowed');
            }
            $this->service->player = $player;
            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->ppResponse([], 1, 'Insufficient balance');
            }

            $reference = (string)$params['reference'];
            $amount = round((float)$params['amount'], 2);
            // 注单单号 = 局号（同 AS 范式：一局一单，派彩按单号合并进下注单）
            $orderNo = (string)$params['roundId'];

            // 幂等：同 reference 重复下注只创建一个交易，重试返回实际余额（3.6）
            if ($existingTx = $this->existingTransactionId('bet', $reference)) {
                return $this->betResponse($existingTx, $player);
            }

            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->platformId(),
                'amount' => $amount,
                'game_code' => (string)$params['gameId'],
                'transaction_type' => TransactionType::BET,
                'original_data' => $params,
            ];
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');
            $result = RedisLuaScripts::atomicBet($player->id, self::PLATFORM, $luaParams, $this->logger);
            logLuaScriptCall('bet', self::PLATFORM, $player->id, $luaParams);

            if (($result['ok'] ?? 0) !== 1) {
                $error = $result['error'] ?? 'unknown';
                if ($error === 'duplicate_order') {
                    return $this->betResponse($this->transactionId('bet', $reference), $player);
                }
                if ($error === 'insufficient_balance') {
                    return $this->ppResponse([], 1, 'Insufficient balance');
                }
                $this->logger->error('PP bet Lua 失败', ['params' => $params, 'result' => $result]);
                return $this->ppResponse([], 100, 'Internal error');
            }

            // reference→单号映射：Refund 只带原下注 reference（3.11），据此反查单号
            // （AS 的 refund 自带 roundId 直接用；PP 没有，用映射替代）
            Redis::connection()->setex('pp:bet:ref:' . $reference, self::TX_TTL, $orderNo);

            GameRecordCacheService::saveBet(self::PLATFORM, [
                'order_no' => $orderNo,
                'player_id' => $player->id,
                'platform_id' => $this->platformId(),
                'amount' => $amount,
                'game_code' => (string)$params['gameId'],
                'original_data' => $params,
                'balance_before' => $result['old_balance'] ?? 0,
                'balance_after' => $result['balance'],
            ]);
            WalletService::checkMachineCrashAfterTransaction($player->id, (float)$result['balance'], $result['old_balance'] ?? null);
            logGameInteraction(self::PLATFORM, 'bet', $params, ['result' => $result]);

            return $this->betResponse($this->transactionId('bet', $reference), $player, (float)$result['balance']);
        } catch (Exception $e) {
            return $this->handleException('bet', $e, $params);
        }
    }

    // ==================== 3.7 Result ====================
    // 请求必需 hash、userId、gameId、roundId、amount(赢奖)、reference、providerId、timestamp、roundDetails
    // 可选 promoWinAmount+promoWinReference+promoCampaignID+promoCampaignType（四者同现，金额加到 cash）
    // 响应必需 transactionId、currency、cash、bonus、error、description

    public function result(Request $request): Response
    {
        $params = $request->post();
        try {
            if (!$this->service->verifyHash($params)) {
                return $this->ppResponse([], 5, 'Invalid hash code');
            }
            if ($missing = $this->missing($params, ['userId', 'gameId', 'roundId', 'amount', 'reference', 'providerId', 'timestamp', 'roundDetails'])) {
                return $this->ppResponse([], 7, 'Bad parameters: ' . implode(',', $missing));
            }
            if (!is_numeric($params['amount']) || (float)$params['amount'] < 0) {
                return $this->ppResponse([], 7, 'Bad parameters: amount');
            }
            // 促销天降奖励（R）：promoWin* 四字段须同现，金额必须加到 cash（3.7）
            $promoWin = 0.0;
            if (isset($params['promoWinAmount']) && $params['promoWinAmount'] !== '') {
                if (!$this->hasAll($params, ['promoWinReference', 'promoCampaignID', 'promoCampaignType']) || !is_numeric($params['promoWinAmount'])) {
                    return $this->ppResponse([], 7, 'Bad parameters: promoWin*');
                }
                $promoWin = round((float)$params['promoWinAmount'], 2);
            }
            $player = Player::query()->where('uuid', $params['userId'])->first();
            if ($err = $this->guardPlayer($player)) {
                return $err;
            }
            $reference = (string)$params['reference'];
            $roundId = (string)$params['roundId'];
            $totalWin = round((float)$params['amount'] + $promoWin, 2);

            // 幂等：同 reference 重复派彩只创建一个交易（3.7）
            if ($existingTx = $this->existingTransactionId('result', $reference)) {
                return $this->winResponse($existingTx, $player);
            }

            // 资金幂等键 = reference（同 AS deposit 的 txnId）；记录按单号 roundId 合并进下注单
            $luaParams = [
                'order_no' => $reference,
                'platform_id' => $this->platformId(),
                'amount' => $totalWin,
                'diff' => $totalWin,
                'transaction_type' => TransactionType::SETTLE,
                'game_code' => (string)$params['gameId'],
                'original_data' => $params,
            ];
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');
            $result = RedisLuaScripts::atomicSettle($player->id, self::PLATFORM, $luaParams, $this->logger);
            logLuaScriptCall('settle', self::PLATFORM, $player->id, $luaParams);

            if (($result['ok'] ?? 0) !== 1) {
                if (($result['error'] ?? '') === 'duplicate_settle') {
                    return $this->winResponse($this->transactionId('result', $reference), $player);
                }
                $this->logger->error('PP result Lua 失败', ['params' => $params, 'result' => $result]);
                return $this->ppResponse([], 100, 'Internal error');
            }

            // 派彩按单号合并进下注单（同 AS deposit）：一笔下注只产一条订单，
            // win 累加，Result 即回合收口（is_final）
            $merged = GameRecordCacheService::saveSettleIntoBet(self::PLATFORM, $roundId, [
                'amount' => $totalWin,
                'settle_type' => 'settle',
                'is_final' => true,
                'original_data' => $params,
            ]);
            if (!$merged) {
                // 找不到该局下注记录：回退独立结算单（同 AS 范式，数据不丢失）
                $this->logger->warning('PP result 合并失败：下注记录不存在，回退独立结算单', [
                    'reference' => $reference,
                    'order_no' => $roundId,
                ]);
                GameRecordCacheService::saveSettle(self::PLATFORM, [
                    'order_no' => $reference,
                    'player_id' => $player->id,
                    'platform_id' => $this->platformId(),
                    'amount' => $totalWin,
                    'diff' => $totalWin,
                    'settle_type' => 'settle',
                    'game_code' => (string)$params['gameId'],
                    'original_data' => $params,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }
            WalletService::checkMachineCrashAfterTransaction($player->id, (float)$result['balance'], $result['old_balance'] ?? null);
            logGameInteraction(self::PLATFORM, 'result', $params, ['result' => $result]);

            return $this->winResponse($this->transactionId('result', $reference), $player, (float)$result['balance']);
        } catch (Exception $e) {
            return $this->handleException('result', $e, $params);
        }
    }

    // ==================== 3.8 BonusWin（FRB 免费回合赢奖，异步） ====================

    public function bonusWin(Request $request): Response
    {
        return $this->simpleWin(
            $request,
            'bonusWin',
            ['userId', 'amount', 'reference', 'providerId', 'timestamp'],
            TransactionType::SETTLE_FREEGAME,
            'reward'
        );
    }

    // ==================== 3.9 JackpotWin（累积奖金派彩） ====================

    public function jackpotWin(Request $request): Response
    {
        return $this->simpleWin(
            $request,
            'jackpotWin',
            ['userId', 'gameId', 'roundId', 'jackpotId', 'amount', 'reference', 'providerId', 'timestamp'],
            TransactionType::SETTLE_JACKPOT,
            'jackpot'
        );
    }

    // ==================== 3.13 PromoWin（锦标赛/现金返还/社区大奖，异步） ====================

    public function promoWin(Request $request): Response
    {
        return $this->simpleWin(
            $request,
            'promoWin',
            ['userId', 'campaignId', 'campaignType', 'amount', 'currency', 'reference', 'providerId', 'timestamp'],
            TransactionType::SETTLE_REWARD,
            'reward'
        );
    }

    /**
     * 纯派彩类回调通用处理（BonusWin/JackpotWin/PromoWin）：
     * 无对应下注记录，atomicSettle 自动建独立结算记录，diff = 派彩额（纯赢）。
     */
    private function simpleWin(Request $request, string $action, array $required, string $transactionType, string $settleType): Response
    {
        $params = $request->post();
        try {
            if (!$this->service->verifyHash($params)) {
                return $this->ppResponse([], 5, 'Invalid hash code');
            }
            if ($missing = $this->missing($params, $required)) {
                return $this->ppResponse([], 7, 'Bad parameters: ' . implode(',', $missing));
            }
            if (!is_numeric($params['amount']) || (float)$params['amount'] < 0) {
                return $this->ppResponse([], 7, 'Bad parameters: amount');
            }
            $player = Player::query()->where('uuid', $params['userId'])->first();
            if ($err = $this->guardPlayer($player)) {
                return $err;
            }
            $reference = (string)$params['reference'];
            $win = round((float)$params['amount'], 2);

            if ($existingTx = $this->existingTransactionId($action, $reference)) {
                return $this->winResponse($existingTx, $player);
            }

            $gameCode = (string)($params['gameId'] ?? '');
            $luaParams = [
                'order_no' => $reference,
                'platform_id' => $this->platformId(),
                'amount' => $win,
                'diff' => $win,
                'transaction_type' => $transactionType,
                'game_code' => $gameCode,
                'original_data' => $params,
            ];
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');
            $result = RedisLuaScripts::atomicSettle($player->id, self::PLATFORM, $luaParams, $this->logger);
            logLuaScriptCall('settle', self::PLATFORM, $player->id, $luaParams);

            if (($result['ok'] ?? 0) !== 1) {
                if (($result['error'] ?? '') === 'duplicate_settle') {
                    return $this->winResponse($this->transactionId($action, $reference), $player);
                }
                $this->logger->error("PP {$action} Lua 失败", ['params' => $params, 'result' => $result]);
                return $this->ppResponse([], 100, 'Internal error');
            }

            GameRecordCacheService::saveSettle(self::PLATFORM, [
                'order_no' => $reference,
                'player_id' => $player->id,
                'platform_id' => $this->platformId(),
                'amount' => $win,
                'diff' => $win,
                'settle_type' => $settleType,
                'game_code' => $gameCode,
                'original_data' => $params,
                'balance_before' => $result['old_balance'] ?? 0,
                'balance_after' => $result['balance'],
            ]);
            WalletService::checkMachineCrashAfterTransaction($player->id, (float)$result['balance'], $result['old_balance'] ?? null);
            logGameInteraction(self::PLATFORM, $action, $params, ['result' => $result]);

            return $this->winResponse($this->transactionId($action, $reference), $player, (float)$result['balance']);
        } catch (Exception $e) {
            return $this->handleException($action, $e, $params);
        }
    }

    // ==================== 3.11 Refund ====================
    // 请求必需 hash、userId、reference（=原赌注交易的参考）、providerId；可选 amount（不传退原注）
    // 原单找不到：不做任何操作直接返回成功（3.11 重要信息）；响应仅 transactionId、error、description

    public function refund(Request $request): Response
    {
        $params = $request->post();
        try {
            if (!$this->service->verifyHash($params)) {
                return $this->ppResponse([], 5, 'Invalid hash code');
            }
            if ($missing = $this->missing($params, ['userId', 'reference', 'providerId'])) {
                return $this->ppResponse([], 7, 'Bad parameters: ' . implode(',', $missing));
            }
            $player = Player::query()->where('uuid', $params['userId'])->first();
            if ($err = $this->guardPlayer($player)) {
                return $err;
            }
            $reference = (string)$params['reference'];

            // 注单单号 = 局号（同 AS 范式，退款按单号就地撤销）；
            // PP 的 Refund 只带原下注 reference（3.11），经下注时写入的映射反查单号
            $orderNo = $this->resolveBetOrder($reference);

            // 原赌注不存在：无操作直接成功（3.11）
            if ($orderNo === '') {
                return $this->refundResponse($this->transactionId('refund', $reference));
            }

            // 幂等：同 reference 重复退款只创建一个交易
            if ($existingTx = $this->existingTransactionId('refund', $reference)) {
                return $this->refundResponse($existingTx);
            }

            // 退款额：amount 参数优先，缺省退原注全额（下注记录 amount 即原注）
            if (isset($params['amount']) && $params['amount'] !== '' && is_numeric($params['amount']) && (float)$params['amount'] > 0) {
                $refundAmount = round((float)$params['amount'], 2);
            } else {
                $refundAmount = round((float)getBetAmountWithFallback(self::PLATFORM, $orderNo, $player->id, $this->platformId(), $this->logger), 2);
            }
            if ($refundAmount <= 0) {
                return $this->refundResponse($this->transactionId('refund', $reference));
            }

            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->platformId(),
                'refund_amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL_REFUND,
                'original_data' => $params,
            ];
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');
            $result = RedisLuaScripts::atomicCancel($player->id, self::PLATFORM, $luaParams);
            logLuaScriptCall('cancel', self::PLATFORM, $player->id, $luaParams);

            if (($result['ok'] ?? 0) !== 1) {
                $error = $result['error'] ?? 'unknown';
                if (in_array($error, ['duplicate_order', 'duplicate_cancel', 'order_not_found'], true)) {
                    // 原单找不到 / 重复退款：不做任何操作直接成功（3.11）
                    return $this->refundResponse($this->transactionId('refund', $reference));
                }
                $this->logger->error('PP refund Lua 失败', ['params' => $params, 'result' => $result]);
                return $this->ppResponse([], 100, 'Internal error');
            }

            GameRecordCacheService::saveCancel(self::PLATFORM, [
                'order_no' => $orderNo,
                'player_id' => $player->id,
                'platform_id' => $this->platformId(),
                'refund_amount' => $refundAmount,
                'cancel_type' => 'refund',
                'original_data' => $params,
                'balance_before' => $result['old_balance'] ?? 0,
                'balance_after' => $result['balance'],
            ]);
            WalletService::checkMachineCrashAfterTransaction($player->id, (float)$result['balance'], $result['old_balance'] ?? null);
            logGameInteraction(self::PLATFORM, 'refund', $params, ['result' => $result]);

            return $this->refundResponse($this->transactionId('refund', $reference));
        } catch (Exception $e) {
            return $this->handleException('refund', $e, $params);
        }
    }

    // ==================== 3.10 EndRound（回合结束通知，可选，纯通知） ====================
    // 请求必需 hash、userId、gameId、roundId、providerId；win 字段仅通知不作交易
    // 响应必需 cash、bonus、error、description（无 transactionId）；重复请求忽略并返回成功

    public function endRound(Request $request): Response
    {
        $params = $request->post();
        try {
            if (!$this->service->verifyHash($params)) {
                return $this->ppResponse([], 5, 'Invalid hash code');
            }
            if ($missing = $this->missing($params, ['userId', 'gameId', 'roundId', 'providerId'])) {
                return $this->ppResponse([], 7, 'Bad parameters: ' . implode(',', $missing));
            }
            $player = Player::query()->where('uuid', $params['userId'])->first();
            if ($err = $this->guardPlayer($player)) {
                return $err;
            }
            // 纯通知：只记日志不作账务，回合已结束/重复请求忽略并返回成功（3.10）
            logGameInteraction(self::PLATFORM, 'endRound', $params, null);
            return $this->ppResponse([
                'cash' => $this->cash($player),
                'bonus' => 0,
            ]);
        } catch (Exception $e) {
            return $this->handleException('endRound', $e, $params, 130);
        }
    }

    // ==================== 3.15 Adjustment（真人游戏余额调整，可正可负） ====================
    // 请求必需 hash、userId、gameId、roundId、amount、reference、providerId、validBetAmount、timestamp
    // 负数且余额不足：error 1，描述 'Insufficient balance'（3.15）

    public function adjustment(Request $request): Response
    {
        $params = $request->post();
        try {
            if (!$this->service->verifyHash($params)) {
                return $this->ppResponse([], 5, 'Invalid hash code');
            }
            if ($missing = $this->missing($params, ['userId', 'gameId', 'roundId', 'amount', 'reference', 'providerId', 'validBetAmount', 'timestamp'])) {
                return $this->ppResponse([], 7, 'Bad parameters: ' . implode(',', $missing));
            }
            if (!is_numeric($params['amount'])) {
                return $this->ppResponse([], 7, 'Bad parameters: amount');
            }
            $player = Player::query()->where('uuid', $params['userId'])->first();
            if ($err = $this->guardPlayer($player)) {
                return $err;
            }
            $reference = (string)$params['reference'];
            $amount = round((float)$params['amount'], 2);

            if ($existingTx = $this->existingTransactionId('adjustment', $reference)) {
                return $this->winResponse($existingTx, $player);
            }

            $result = null;
            if ($amount > 0) {
                // 入账调整：独立派彩记录
                $luaParams = [
                    'order_no' => $reference,
                    'platform_id' => $this->platformId(),
                    'amount' => $amount,
                    'diff' => $amount,
                    'transaction_type' => TransactionType::SETTLE_ADJUST,
                    'game_code' => (string)$params['gameId'],
                    'original_data' => $params,
                ];
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric', 'min:0'],
                    'diff' => ['required', 'numeric'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');
                $result = RedisLuaScripts::atomicSettle($player->id, self::PLATFORM, $luaParams, $this->logger);
                logLuaScriptCall('settle', self::PLATFORM, $player->id, $luaParams);
                if (($result['ok'] ?? 0) !== 1) {
                    if (($result['error'] ?? '') === 'duplicate_settle') {
                        return $this->winResponse($this->transactionId('adjustment', $reference), $player);
                    }
                    $this->logger->error('PP adjustment Lua 失败', ['params' => $params, 'result' => $result]);
                    return $this->ppResponse([], 100, 'Internal error');
                }
                GameRecordCacheService::saveSettle(self::PLATFORM, [
                    'order_no' => $reference,
                    'player_id' => $player->id,
                    'platform_id' => $this->platformId(),
                    'amount' => $amount,
                    'diff' => $amount,
                    'settle_type' => 'adjust',
                    'game_code' => (string)$params['gameId'],
                    'original_data' => $params,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            } elseif ($amount < 0) {
                // 扣款调整（同 BTG 范式：BET_ADJUST 原子扣款）
                $deduct = round(abs($amount), 2);
                $luaParams = [
                    'order_no' => $reference,
                    'platform_id' => $this->platformId(),
                    'amount' => $deduct,
                    'game_code' => (string)$params['gameId'],
                    'transaction_type' => TransactionType::BET_ADJUST,
                    'original_data' => $params,
                ];
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric', 'min:0'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicBet');
                $result = RedisLuaScripts::atomicBet($player->id, self::PLATFORM, $luaParams, $this->logger);
                logLuaScriptCall('bet', self::PLATFORM, $player->id, $luaParams);
                if (($result['ok'] ?? 0) !== 1) {
                    $error = $result['error'] ?? 'unknown';
                    if ($error === 'duplicate_order') {
                        return $this->winResponse($this->transactionId('adjustment', $reference), $player);
                    }
                    if ($error === 'insufficient_balance') {
                        return $this->ppResponse([], 1, 'Insufficient balance');
                    }
                    $this->logger->error('PP adjustment Lua 失败', ['params' => $params, 'result' => $result]);
                    return $this->ppResponse([], 100, 'Internal error');
                }
                GameRecordCacheService::saveBet(self::PLATFORM, [
                    'order_no' => $reference,
                    'player_id' => $player->id,
                    'platform_id' => $this->platformId(),
                    'amount' => $deduct,
                    'game_code' => (string)$params['gameId'],
                    'original_data' => $params,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            $cash = $this->cash($player);
            if ($result !== null) {
                $cash = (float)$result['balance'];
                WalletService::checkMachineCrashAfterTransaction($player->id, $cash, $result['old_balance'] ?? null);
            }
            logGameInteraction(self::PLATFORM, 'adjustment', $params, ['result' => $result]);

            return $this->winResponse($this->transactionId('adjustment', $reference), $player, $cash);
        } catch (Exception $e) {
            return $this->handleException('adjustment', $e, $params);
        }
    }

    // ==================== 公共工具 ====================

    /** PP 响应统一出口：200 + JSON 扁平结构，error/description 见文档 3.17 */
    private function ppResponse(array $data, int $error = 0, string $description = 'Success'): Response
    {
        $payload = array_merge($data, ['error' => $error, 'description' => $description]);
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /** Bet 响应（含 usedPromo，恒 0） */
    private function betResponse(string $transactionId, Player $player, ?float $cash = null): Response
    {
        return $this->ppResponse([
            'transactionId' => $transactionId,
            'currency' => (string)$player->currency,
            'cash' => $cash ?? $this->cash($player),
            'bonus' => 0,
            'usedPromo' => 0,
        ]);
    }

    /** 派彩类响应（Result/BonusWin/JackpotWin/PromoWin/Adjustment） */
    private function winResponse(string $transactionId, Player $player, ?float $cash = null): Response
    {
        return $this->ppResponse([
            'transactionId' => $transactionId,
            'currency' => (string)$player->currency,
            'cash' => $cash ?? $this->cash($player),
            'bonus' => 0,
        ]);
    }

    /** Refund 响应：仅 transactionId、error、description（3.11） */
    private function refundResponse(string $transactionId): Response
    {
        return $this->ppResponse(['transactionId' => $transactionId]);
    }

    /** 玩家校验：不存在/已登出 → 2；冻结/停用 → 6 */
    private function guardPlayer(?Player $player): ?Response
    {
        if (!$player) {
            return $this->ppResponse([], 2, 'Player not found or is logged out');
        }
        if ((int)$player->status !== Player::STATUS_ENABLE) {
            return $this->ppResponse([], 6, 'Player is frozen');
        }
        return null;
    }

    /** 当前余额（元，2 位小数） */
    private function cash(Player $player): float
    {
        return round((float)WalletService::getBalance($player->id), 2);
    }

    private function platformId(): int
    {
        $id = (int)($this->service->platform->id ?? 0);
        if ($id <= 0) {
            throw new Exception('GamePlatform 表缺少 code=PP 记录，无法入账');
        }
        return $id;
    }

    /** 缺失必填参数名列表（空串视为缺失；amount=0 合法） */
    private function missing(array $params, array $keys): array
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $params) || $params[$key] === '' || $params[$key] === null) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    private function hasAll(array $params, array $keys): bool
    {
        return $this->missing($params, $keys) === [];
    }

    /** 首次生成并缓存 transactionId（幂等重试返回同一值） */
    private function transactionId(string $op, string $reference): string
    {
        $key = 'pp:tx:' . $op . ':' . $reference;
        $redis = Redis::connection();
        $existing = $redis->get($key);
        if ($existing) {
            return (string)$existing;
        }
        $tx = (string)createOrderNo();
        $redis->setex($key, self::TX_TTL, $tx);
        return $tx;
    }

    /** 幂等预检：已生成过 transactionId 即视为重复请求 */
    private function existingTransactionId(string $op, string $reference): ?string
    {
        $existing = Redis::connection()->get('pp:tx:' . $op . ':' . $reference);
        return $existing ? (string)$existing : null;
    }

    /**
     * 解析原下注的注单单号（一局一单后单号 = 局号）：
     * PP 的 Refund 只带原下注 reference（3.11），经下注时写入的映射 pp:bet:ref:{reference} 反查；
     * 映射缺失时兼容合并前旧单（记录按 reference 直接存放）。找不到返回 ''。
     */
    private function resolveBetOrder(string $reference): string
    {
        $redis = Redis::connection();
        $orderNo = (string)$redis->get('pp:bet:ref:' . $reference);
        if ($orderNo !== '') {
            return $orderNo;
        }
        if ($redis->exists('game:record:bet:' . self::PLATFORM . ':' . $reference)) {
            return $reference;
        }
        return '';
    }

    /** 异常统一出口：日志 + Telegram 告警 + 内部错误码（默认 100，EndRound 用 130） */
    private function handleException(string $action, Exception $e, array $params, int $error = 100): Response
    {
        $this->logger->error('PP ' . $action . ' 异常: ' . $e->getMessage(), ['params' => $params, 'trace' => $e->getTraceAsString()]);
        $this->sendTelegramAlert(self::PLATFORM, $action . ' 异常', $e, ['params' => $params]);
        return $this->ppResponse([], $error, 'Internal error');
    }
}
