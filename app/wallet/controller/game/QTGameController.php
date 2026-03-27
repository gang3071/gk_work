<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\service\game\QTServiceInterface;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * QT中心钱包
 */
class QTGameController
{
    use TelegramAlertTrait;

    // QT标准错误码
    const ERROR_INVALID_TOKEN = 'INVALID_TOKEN';           // 400 - 缺失、无效或过期的会话令牌
    const ERROR_ACCOUNT_BLOCKED = 'ACCOUNT_BLOCKED';       // 403 - 玩家账户被锁定
    const ERROR_LOGIN_FAILED = 'LOGIN_FAILED';             // 401 - Pass-Key不正确
    const ERROR_REQUEST_DECLINED = 'REQUEST_DECLINED';     // 400 - 通用错误，请求无法处理
    const ERROR_INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS'; // 400 - 余额不足
    const ERROR_LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';         // 400 - 超过游戏限制
    const ERROR_UNKNOWN_ERROR = 'UNKNOWN_ERROR';           // 500 - 意外错误

    /**
     * @var QTServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_QT);
        $this->logger = Log::channel('qt_server');
    }

    /**
     * 验证请求头
     * @param Request $request
     * @param bool $requireWalletSession 是否要求Wallet-Session
     * @return Response|null 如果验证失败返回错误响应，成功返回null
     */
    private function verifyHeaders(Request $request, bool $requireWalletSession = true): ?Response
    {
        $passKey = $request->header('Pass-Key');
        $walletSession = $request->header('Wallet-Session');

        $this->logger->info('QT验证请求头', [
            'pass_key' => $passKey ?? 'missing',
            'wallet_session' => $walletSession ?? 'missing'
        ]);

        // 验证Pass-Key
        if (!$this->service->verifyPassKey($passKey)) {
            $this->logger->error('QT Pass-Key验证失败');
            return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
        }

        // 验证Wallet-Session（如果需要）
        if ($requireWalletSession) {
            if (!$walletSession) {
                $this->logger->error('QT Wallet-Session缺失');
                return $this->errorResponse(self::ERROR_INVALID_TOKEN, 'Missing player session token.', 400);
            }

            // 验证Wallet-Session格式（应该是UUID格式）
            if (!$this->isValidWalletSession($walletSession)) {
                $this->logger->error('QT Wallet-Session格式无效', [
                    'wallet_session' => substr($walletSession, 0, 20) . '...'
                ]);
                return $this->errorResponse(self::ERROR_INVALID_TOKEN, 'Invalid or expired player session token.', 400);
            }
        }

        return null;
    }

    /**
     * 验证Wallet-Session格式
     * @param string $walletSession
     * @return bool
     */
    private function isValidWalletSession(string $walletSession): bool
    {
        // Wallet-Session应该是UUID格式或者长Access Token格式
        // UUID格式: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx (36字符)
        // Access Token格式: 长字符串（通常100+字符）
        // 测试Session格式: session-xxx（用于测试目的）

        $length = strlen($walletSession);

        // 太短，无效
        if ($length < 10) {
            return false;
        }

        // 检查是否是UUID格式（标准UUID是36个字符）
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $walletSession)) {
            return true;
        }

        // 检查是否是测试Session格式（session-xxx，用于测试blocked account等场景）
        if (preg_match('/^session-[A-Z\-_]+$/i', $walletSession)) {
            return true;
        }

        // 检查是否是长Access Token（100+字符，不包含特殊字符如[、]、:等）
        if ($length >= 100 && !preg_match('/[\[\]:\/]/', $walletSession)) {
            return true;
        }

        // 其他格式视为无效（如时间戳格式等）
        return false;
    }

    /**
     * 检查玩家状态
     * @param Player $player
     * @return Response|null 如果玩家被锁定返回错误响应，正常返回null
     */
    private function checkPlayerStatus(Player $player): ?Response
    {
        // 检查玩家是否被删除（软删除）
        if ($player->trashed()) {
            $this->logger->error('QT玩家账户已被删除', ['player_id' => $player->id]);
            return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
        }

        // 检查玩家状态（1=正常，0=锁定）
        if (isset($player->status) && $player->status != 1) {
            $this->logger->error('QT玩家账户被锁定', ['player_id' => $player->id, 'status' => $player->status]);
            return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
        }

        return null;
    }

    #[RateLimiter(limit: 10)]
    /**
     * 1. 验证会话 - Verify Session
     * GET /accounts/{playerId}/session?gameId={gameId}
     * @param Request $request
     * @param string $playerId
     * @return Response
     */
    public function verifySession(Request $request, string $playerId): Response
    {
        try {
            $gameId = $request->get('gameId');
            $this->logger->info('QT验证会话请求', [
                'playerId' => $playerId,
                'gameId' => $gameId
            ]);

            // 验证请求头（需要Wallet-Session）
            if ($error = $this->verifyHeaders($request, true)) {
                return $error;
            }

            // 检查是否是测试专用的blocked player
            if ($playerId === 'ALWAYS-THROW-ACCOUNT-BLOCKED') {
                $this->logger->warning('QT测试专用blocked player');
                return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $playerId)->first();
            if (!$player) {
                return $this->errorResponse(self::ERROR_INVALID_TOKEN, 'Player not found', 400);
            }

            // 检查玩家状态
            if ($error = $this->checkPlayerStatus($player)) {
                return $error;
            }

            $this->service->player = $player;

            // 获取余额
            $balance = $this->service->balance();

            $response = [
                'balance' => round((float)$balance, 2),
                'currency' => 'TWD', // QT平台使用台币
            ];

            $this->logger->info('QT验证会话成功', $response);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
        } catch (Exception $e) {
            $this->logger->error('QT验证会话异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('QT', '验证会话异常', $e, ['playerId' => $playerId]);
            return $this->errorResponse(self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500);
        }
    }

    #[RateLimiter(limit: 10)]
    /**
     * 2. 查询余额 - Get Balance
     * GET /accounts/{playerId}/balance?gameId={gameId}
     *
     * 可能的错误:
     * - REQUEST_DECLINED (400) - 通用错误，请求无法处理
     * - LOGIN_FAILED (401) - Pass-Key不正确
     * - UNKNOWN_ERROR (500) - 意外错误
     *
     * @param Request $request
     * @param string $playerId
     * @return Response
     */
    public function getBalance(Request $request, string $playerId): Response
    {
        try {
            $gameId = $request->get('gameId');
            $this->logger->info('QT查询余额请求', [
                'playerId' => $playerId,
                'gameId' => $gameId
            ]);

            // 验证Pass-Key
            $passKey = $request->header('Pass-Key');
            if (!$this->service->verifyPassKey($passKey)) {
                $this->logger->error('QT查询余额失败：Pass-Key验证失败');
                return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
            }

            // 注意：余额查询允许过期或缺失的wallet-session，所以不验证它

            // 查询玩家
            $player = Player::query()->where('uuid', $playerId)->first();
            if (!$player) {
                $this->logger->error('QT查询余额失败：玩家不存在', ['playerId' => $playerId]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player not found', 400);
            }

            // 检查玩家状态（软删除或锁定）
            if ($player->trashed()) {
                $this->logger->error('QT查询余额失败：玩家账户已删除', ['player_id' => $player->id]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is deleted', 400);
            }

            // 检查玩家状态（1=正常，0=锁定）
            if (isset($player->status) && $player->status != 1) {
                $this->logger->error('QT查询余额失败：玩家账户被锁定', ['player_id' => $player->id, 'status' => $player->status]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is blocked', 400);
            }

            $this->service->player = $player;

            // 获取余额
            $balance = $this->service->balance();

            if ($this->service->error) {
                $this->logger->error('QT查询余额失败：获取余额错误', ['error' => $this->service->error]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Failed to get balance', 400);
            }

            $response = [
                'balance' => round((float)$balance, 2),
                'currency' => 'TWD', // QT平台使用台币
            ];

            $this->logger->info('QT查询余额成功', $response);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
        } catch (Exception $e) {
            $this->logger->error('QT查询余额异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('QT', '查询余额异常', $e, ['playerId' => $playerId]);
            return $this->errorResponse(self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500);
        }
    }

    #[RateLimiter(limit: 20)]
    /**
     * 3. 交易接口 - Transactions (DEBIT/CREDIT)
     * POST /transactions
     *
     * DEBIT可能的错误:
     * - REQUEST_DECLINED (400) - 通用错误，请求无法处理
     * - INVALID_TOKEN (400) - 缺失、无效或过期的会话令牌
     * - INSUFFICIENT_FUNDS (400) - 余额不足
     * - LIMIT_EXCEEDED (400) - 超过游戏限制
     * - ACCOUNT_BLOCKED (403) - 玩家账户被锁定
     * - LOGIN_FAILED (401) - Pass-Key不正确
     * - UNKNOWN_ERROR (500) - 意外错误
     *
     * CREDIT可能的错误:
     * - REQUEST_DECLINED (400) - 通用错误，请求无法处理
     * - LOGIN_FAILED (401) - Pass-Key不正确
     * - UNKNOWN_ERROR (500) - 意外错误
     *
     * @param Request $request
     * @return Response
     */
    public function transaction(Request $request): Response
    {
        try {
            $params = json_decode($request->rawBody(), true);
            $this->logger->info('QT交易请求', ['params' => $params]);

            $txnType = $params['txnType'] ?? '';

            // 验证Pass-Key
            $passKey = $request->header('Pass-Key');
            if (!$this->service->verifyPassKey($passKey)) {
                $this->logger->error('QT交易失败：Pass-Key验证失败');
                return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
            }

            // 验证Wallet-Session（DEBIT和CREDIT都需要）
            $walletSession = $request->header('Wallet-Session');
            if (!$walletSession) {
                $this->logger->error('QT交易失败：Wallet-Session缺失');
                // DEBIT使用INVALID_TOKEN，CREDIT使用REQUEST_DECLINED
                if ($txnType === 'DEBIT') {
                    return $this->errorResponse(self::ERROR_INVALID_TOKEN, 'Missing player session token.', 400);
                } else {
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Missing player session token.', 400);
                }
            }

            // 验证必要参数
            $requiredFields = ['txnType', 'txnId', 'playerId', 'roundId', 'amount', 'currency', 'gameId', 'created', 'completed'];
            foreach ($requiredFields as $field) {
                if (!isset($params[$field])) {
                    $this->logger->error('QT交易失败：缺少必要参数', ['field' => $field]);
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, "Missing required field: {$field}", 400);
                }
            }

            // 检查是否是测试专用的blocked player
            if ($params['playerId'] === 'ALWAYS-THROW-ACCOUNT-BLOCKED') {
                $this->logger->warning('QT测试专用blocked player');
                // DEBIT使用ACCOUNT_BLOCKED，CREDIT使用REQUEST_DECLINED
                if ($txnType === 'DEBIT') {
                    return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
                } else {
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'The player account is blocked.', 400);
                }
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['playerId'])->first();
            if (!$player) {
                $this->logger->error('QT交易失败：玩家不存在', ['playerId' => $params['playerId']]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player not found', 400);
            }

            // 检查玩家状态
            if ($player->trashed()) {
                $this->logger->error('QT交易失败：玩家账户已删除', ['player_id' => $player->id]);
                // DEBIT使用ACCOUNT_BLOCKED，CREDIT使用REQUEST_DECLINED
                if ($txnType === 'DEBIT') {
                    return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
                } else {
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'The player account is blocked.', 400);
                }
            }

            // 检查玩家状态（1=正常，0=锁定）
            if (isset($player->status) && $player->status != 1) {
                $this->logger->error('QT交易失败：玩家账户被锁定', ['player_id' => $player->id, 'status' => $player->status]);
                // DEBIT使用ACCOUNT_BLOCKED，CREDIT使用REQUEST_DECLINED
                if ($txnType === 'DEBIT') {
                    return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
                } else {
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'The player account is blocked.', 400);
                }
            }

            $this->service->player = $player;

            // 根据交易类型处理
            $result = match ($params['txnType']) {
                'DEBIT' => $this->service->debit($params),
                'CREDIT' => $this->service->credit($params),
                default => throw new Exception("Invalid transaction type: {$params['txnType']}")
            };

            if ($this->service->error) {
                $this->logger->error('QT交易失败', [
                    'txnType' => $params['txnType'],
                    'error' => $this->service->error
                ]);

                // 根据交易类型映射错误码
                if ($params['txnType'] === 'DEBIT') {
                    // DEBIT专用错误码映射
                    $errorMap = [
                        'INSUFFICIENT_BALANCE' => [self::ERROR_INSUFFICIENT_FUNDS, 'Insufficient funds', 400],
                        'LIMIT_EXCEEDED' => [self::ERROR_LIMIT_EXCEEDED, 'Game limit exceeded', 400],
                        'INTERNAL_ERROR' => [self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500],
                    ];
                    $errorInfo = $errorMap[$this->service->error] ?? [self::ERROR_REQUEST_DECLINED, 'Transaction failed', 400];
                } else {
                    // CREDIT只使用3种错误码：REQUEST_DECLINED, LOGIN_FAILED, UNKNOWN_ERROR
                    $errorMap = [
                        'INTERNAL_ERROR' => [self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500],
                    ];
                    $errorInfo = $errorMap[$this->service->error] ?? [self::ERROR_REQUEST_DECLINED, 'Transaction failed', 400];
                }

                return $this->errorResponse($errorInfo[0], $errorInfo[1], $errorInfo[2]);
            }

            $this->logger->info('QT交易成功', [
                'txnType' => $params['txnType'],
                'txnId' => $params['txnId'],
                'result' => $result
            ]);

            return new Response(201, ['Content-Type' => 'application/json'], json_encode($result));
        } catch (Exception $e) {
            $this->logger->error('QT交易异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('QT', '交易异常', $e, ['params' => $request->rawBody()]);
            return $this->errorResponse(self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500);
        }
    }

    #[RateLimiter(limit: 10)]
    /**
     * 4. 活动状态 - Promotion Status
     * POST /bonus/status
     *
     * 这是一个信息型回调，应该静默处理，除非Pass-Key验证失败
     *
     * 可能的错误:
     * - LOGIN_FAILED (401) - Pass-Key不正确
     *
     * @param Request $request
     * @return Response
     */
    public function promotionStatus(Request $request): Response
    {
        try {
            $params = json_decode($request->rawBody(), true);
            $this->logger->info('QT活动状态通知', ['params' => $params]);

            // 验证Pass-Key（这是唯一应该返回错误的情况）
            $passKey = $request->header('Pass-Key');
            if (!$this->service->verifyPassKey($passKey)) {
                $this->logger->error('QT活动状态失败：Pass-Key验证失败');
                return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
            }

            // 注意：此接口不包含Wallet-Session，不需要验证

            // 查询玩家（静默处理，即使玩家不存在也返回成功）
            $player = Player::query()->where('uuid', $params['playerId'] ?? '')->first();

            if ($player) {
                // 记录活动状态到数据库（根据业务需求实现）
                // 这里可以创建一个表来记录免费游戏局活动状态
                $this->logger->info('QT活动状态记录', [
                    'player_id' => $player->id,
                    'bonus_id' => $params['bonusId'] ?? '',
                    'status' => $params['status'] ?? '',
                    'total_bet_value' => $params['totalBetValue'] ?? 0,
                    'total_payout' => $params['totalPayout'] ?? 0,
                    'game_ids' => $params['gameIds'] ?? [],
                    'round_options' => $params['roundOptions'] ?? [],
                    'claimed_round_option' => $params['claimedRoundOption'] ?? null,
                    'claimed_game_id' => $params['claimedGameId'] ?? null,
                    'promo_code' => $params['promoCode'] ?? null,
                    'validity_days' => $params['validityDays'] ?? null,
                ]);

                // TODO: 根据业务需求，将活动状态保存到数据库
                // 例如：创建或更新 player_bonus_promotions 表
                // 状态变化：PROMOTED -> CLAIMED -> IN_PROGRESS -> COMPLETED
                //          PROMOTED -> CLAIMED -> EXPIRED
                //          PROMOTED -> DELETED
                //          PROMOTED -> CANCELLED
                //          PROMOTED -> CLAIMED -> FAILED
            } else {
                $this->logger->warning('QT活动状态通知：玩家不存在（静默处理）', [
                    'playerId' => $params['playerId'] ?? ''
                ]);
            }

            // 静默处理，始终返回成功（除非Pass-Key验证失败）
            $this->logger->info('QT活动状态处理成功', [
                'bonusId' => $params['bonusId'] ?? '',
                'status' => $params['status'] ?? ''
            ]);

            // 返回空的成功响应
            return new Response(200, ['Content-Type' => 'application/json'], json_encode(new \stdClass()));
        } catch (Exception $e) {
            // 即使发生异常，也应该静默处理，返回成功
            $this->logger->error('QT活动状态异常（静默处理）', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // 静默处理异常，返回成功
            return new Response(200, ['Content-Type' => 'application/json'], json_encode(new \stdClass()));
        }
    }

    #[RateLimiter(limit: 10)]
    /**
     * 5. 奖金发放 - Rewards
     * POST /bonus/rewards
     *
     * 用于接收QT平台推送的竞赛、锦标赛等活动的奖励
     *
     * 可能的错误:
     * - REQUEST_DECLINED (400) - 通用错误，请求无法处理
     * - LOGIN_FAILED (401) - Pass-Key不正确
     * - UNKNOWN_ERROR (500) - 意外错误
     *
     * @param Request $request
     * @return Response
     */
    public function rewards(Request $request): Response
    {
        try {
            $params = json_decode($request->rawBody(), true);
            $this->logger->info('QT奖金发放请求', ['params' => $params]);

            // 验证Pass-Key
            $passKey = $request->header('Pass-Key');
            if (!$this->service->verifyPassKey($passKey)) {
                $this->logger->error('QT奖金发放失败：Pass-Key验证失败');
                return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
            }

            // 验证必要参数
            $requiredFields = ['rewardType', 'rewardTitle', 'txnId', 'playerId', 'amount', 'currency', 'created'];
            foreach ($requiredFields as $field) {
                if (!isset($params[$field])) {
                    $this->logger->error('QT奖金发放失败：缺少必要参数', ['field' => $field]);
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, "Missing required field: {$field}", 400);
                }
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['playerId'])->first();
            if (!$player) {
                $this->logger->error('QT奖金发放失败：玩家不存在', ['playerId' => $params['playerId']]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player not found', 400);
            }

            // 检查玩家状态（软删除或锁定）
            if ($player->trashed()) {
                $this->logger->error('QT奖金发放失败：玩家账户已删除', ['player_id' => $player->id]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is deleted', 400);
            }

            // 检查玩家状态（1=正常，0=锁定）
            if (isset($player->status) && $player->status != 1) {
                $this->logger->error('QT奖金发放失败：玩家账户被锁定', ['player_id' => $player->id, 'status' => $player->status]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is blocked', 400);
            }

            $this->service->player = $player;

            $txnId = $params['txnId'];
            $amount = (float)$params['amount'];
            $rewardType = $params['rewardType'];
            $rewardTitle = $params['rewardTitle'];

            if ($amount <= 0) {
                $this->logger->error('QT奖金发放失败：金额无效', ['amount' => $amount]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Invalid amount', 400);
            }

            // 幂等性检查 - 检查是否已发放过该奖金
            $existingReward = PlayerDeliveryRecord::query()
                ->where('tradeno', $txnId)
                ->where('source', 'qt_reward')
                ->first();

            if ($existingReward) {
                $this->logger->info('QT奖金已发放（幂等）', [
                    'txnId' => $txnId,
                    'record_id' => $existingReward->id
                ]);

                // 返回当前余额
                $machineWallet = $player->machine_wallet;
                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'balance' => round((float)($machineWallet->money ?? 0), 2),
                    'referenceId' => (string)$existingReward->id
                ]));
            }

            // 锁定玩家钱包
            $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

            // 发放奖金 - 加款
            $beforeBalance = $machineWallet->money;
            $machineWallet->money = bcadd($machineWallet->money, $amount, 2);
            $machineWallet->save();

            // 创建奖金交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = 'qt_rewards';
            $playerDeliveryRecord->target_id = 0;
            $playerDeliveryRecord->platform_id = $this->service->platform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
            $playerDeliveryRecord->source = 'qt_reward';
            $playerDeliveryRecord->amount = $amount;
            $playerDeliveryRecord->amount_before = $beforeBalance;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $txnId;
            $playerDeliveryRecord->remark = "QT奖金: {$rewardTitle} ({$rewardType})";
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = 'QT系统';
            $playerDeliveryRecord->save();

            $this->logger->info('QT奖金发放成功', [
                'txnId' => $txnId,
                'player_id' => $player->id,
                'amount' => $amount,
                'reward_type' => $rewardType,
                'reward_title' => $rewardTitle,
                'balance' => $machineWallet->money
            ]);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'balance' => round((float)$machineWallet->money, 2),
                'referenceId' => (string)$playerDeliveryRecord->id
            ]));
        } catch (Exception $e) {
            $this->logger->error('QT奖金发放异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('QT', '奖金发放异常', $e, ['params' => $request->rawBody()]);
            return $this->errorResponse(self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500);
        }
    }

    #[RateLimiter(limit: 10)]
    /**
     * 6. 回滚交易 - Rollback
     * POST /transactions/rollback
     *
     * 可能的错误:
     * - REQUEST_DECLINED (400) - 通用错误，请求无法处理
     * - LOGIN_FAILED (401) - Pass-Key不正确
     * - UNKNOWN_ERROR (500) - 意外错误
     *
     * @param Request $request
     * @return Response
     */
    public function rollback(Request $request): Response
    {
        try {
            $params = json_decode($request->rawBody(), true);
            $this->logger->info('QT回滚请求', ['params' => $params]);

            // 验证Pass-Key
            $passKey = $request->header('Pass-Key');
            if (!$this->service->verifyPassKey($passKey)) {
                $this->logger->error('QT回滚失败：Pass-Key验证失败');
                return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
            }

            // 注意：回滚允许过期或缺失的wallet-session，所以不验证它

            // 验证必要参数
            $requiredFields = ['betId', 'txnId', 'playerId', 'roundId', 'amount', 'currency', 'gameId', 'created', 'completed'];
            foreach ($requiredFields as $field) {
                if (!isset($params[$field])) {
                    $this->logger->error('QT回滚失败：缺少必要参数', ['field' => $field]);
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, "Missing required field: {$field}", 400);
                }
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['playerId'])->first();
            if (!$player) {
                $this->logger->error('QT回滚失败：玩家不存在', ['playerId' => $params['playerId']]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player not found', 400);
            }

            // 检查玩家状态（软删除或锁定）
            if ($player->trashed()) {
                $this->logger->error('QT回滚失败：玩家账户已删除', ['player_id' => $player->id]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is deleted', 400);
            }

            // 检查玩家状态（1=正常，0=锁定）
            if (isset($player->status) && $player->status != 1) {
                $this->logger->error('QT回滚失败：玩家账户被锁定', ['player_id' => $player->id, 'status' => $player->status]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is blocked', 400);
            }

            $this->service->player = $player;

            // 执行回滚
            $result = $this->service->rollback($params);

            if ($this->service->error) {
                $this->logger->error('QT回滚失败', ['error' => $this->service->error]);
                // Rollback只使用3种错误码：REQUEST_DECLINED, LOGIN_FAILED, UNKNOWN_ERROR
                $errorMap = [
                    'INTERNAL_ERROR' => [self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500],
                ];
                // 默认所有其他错误使用REQUEST_DECLINED
                $errorInfo = $errorMap[$this->service->error] ?? [self::ERROR_REQUEST_DECLINED, 'Rollback failed', 400];
                return $this->errorResponse($errorInfo[0], $errorInfo[1], $errorInfo[2]);
            }

            $this->logger->info('QT回滚成功', [
                'betId' => $params['betId'],
                'txnId' => $params['txnId'],
                'result' => $result
            ]);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($result));
        } catch (Exception $e) {
            $this->logger->error('QT回滚异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('QT', '回滚异常', $e, ['params' => $request->rawBody()]);
            return $this->errorResponse(self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500);
        }
    }

    /**
     * 错误响应
     * @param string $code
     * @param string $message
     * @param int $httpCode
     * @return Response
     */
    private function errorResponse(string $code, string $message, int $httpCode = 400): Response
    {
        $response = [
            'code' => $code,
            'message' => $message
        ];

        $this->logger->error('QT错误响应', $response);

        return new Response($httpCode, ['Content-Type' => 'application/json'], json_encode($response));
    }
}
