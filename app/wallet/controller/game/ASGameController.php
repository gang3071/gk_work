<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameRecordCacheService;
use app\service\RedisLuaScripts;
use app\service\WalletService;
use Exception;
use support\Log;
use support\Request;
use support\Response;

/**
 * AS电子（AS Gaming）Seamless Wallet 回调（AS 调我们）
 *
 * 端点（POST，全部在 /single-wallet/as-channel 组下，成功响应一律带 code:"OK"）:
 *   /verify-session  {session,gameId,hash,ts}
 *                    -> {code,playerId,balance,currency}
 *   /balance         {playerId,hash,ts}
 *                    -> {code,balance,currency}
 *   /withdraw        {playerId,txnId,roundId,amount,currency,gameId,created,session,completed,hash,ts}
 *                    -> {code,balance}   （下注扣款）
 *   /deposit         同 withdraw 参数
 *                    -> {code,balance}   （派彩加款，同一 roundId 可多次 deposit）
 *   /refund          {playerId,txnId,betTxnId,roundId,created,hash,ts}
 *                    -> {code,balance}   （撤销原 withdraw 注单，找不到原注单也返回成功+余额）
 *
 * 错误响应 {code,message} + HTTP 状态：
 *   INVALID_TOKEN(400) LOGIN_FAILED(401) REQUEST_DECLINED(400)
 *   INSUFFICIENT_FUNDS(400) ACCOUNT_BLOCKED(403) UNKNOWN_ERROR(500) MAINTENANCE(503)
 *
 * 事务语义：同 txnId 重试幂等返回原结果不产生新交易；UNKNOWN_ERROR 触发 AS 侧 rollback/retry。
 */
class ASGameController
{
    use TelegramAlertTrait;

    // AS Seamless Wallet 错误码定义（官方文档）
    public const CODE_INVALID_TOKEN = 'INVALID_TOKEN';          // 签名/ts 非法
    public const CODE_LOGIN_FAILED = 'LOGIN_FAILED';            // session/会员不存在或过期
    public const CODE_REQUEST_DECLINED = 'REQUEST_DECLINED';    // 参数非法（金额/币种等）
    public const CODE_INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS'; // 余额不足
    public const CODE_ACCOUNT_BLOCKED = 'ACCOUNT_BLOCKED';      // 帐号被锁/停用
    public const CODE_UNKNOWN_ERROR = 'UNKNOWN_ERROR';          // 系统错误（AS 会 rollback/retry）
    public const CODE_MAINTENANCE = 'MAINTENANCE';              // 维护中

    /** 错误码 => HTTP 状态 */
    public const HTTP_CODE_MAP = [
        self::CODE_INVALID_TOKEN => 400,
        self::CODE_LOGIN_FAILED => 401,
        self::CODE_REQUEST_DECLINED => 400,
        self::CODE_INSUFFICIENT_FUNDS => 400,
        self::CODE_ACCOUNT_BLOCKED => 403,
        self::CODE_UNKNOWN_ERROR => 500,
        self::CODE_MAINTENANCE => 503,
    ];

    /** 错误码 => 默认消息 */
    public const CODE_MESSAGE_MAP = [
        self::CODE_INVALID_TOKEN => 'invalid token',
        self::CODE_LOGIN_FAILED => 'login failed',
        self::CODE_REQUEST_DECLINED => 'request declined',
        self::CODE_INSUFFICIENT_FUNDS => 'insufficient funds',
        self::CODE_ACCOUNT_BLOCKED => 'account blocked',
        self::CODE_UNKNOWN_ERROR => 'unknown error',
        self::CODE_MAINTENANCE => 'maintenance',
    ];

    /** 各端点文档「可能的错误」白名单（白名单外的错误码按兜底码收敛） */
    public const ENDPOINT_ERRORS = [
        'verifySession' => [self::CODE_INVALID_TOKEN, self::CODE_LOGIN_FAILED, self::CODE_UNKNOWN_ERROR, self::CODE_MAINTENANCE],
        'balance' => [self::CODE_UNKNOWN_ERROR, self::CODE_MAINTENANCE],
        'withdraw' => [self::CODE_REQUEST_DECLINED, self::CODE_INSUFFICIENT_FUNDS, self::CODE_ACCOUNT_BLOCKED, self::CODE_UNKNOWN_ERROR, self::CODE_MAINTENANCE],
        'deposit' => [self::CODE_REQUEST_DECLINED, self::CODE_ACCOUNT_BLOCKED, self::CODE_UNKNOWN_ERROR, self::CODE_MAINTENANCE],
        'refund' => [self::CODE_REQUEST_DECLINED, self::CODE_ACCOUNT_BLOCKED, self::CODE_UNKNOWN_ERROR, self::CODE_MAINTENANCE],
    ];

    /** 原始响应/回合关闭标记 TTL（秒） */
    public const MARK_TTL = 86400;

    private GameServiceInterface|SingleWalletServiceInterface $service;

    /** 当前端点名（响应日志用） */
    private string $op = '';

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_AS);
    }

    /**
     * 验证游戏连结 session
     * POST /single-wallet/as-channel/verify-session
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function verifySession(Request $request): Response
    {
        $this->op = __FUNCTION__;
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS验证session记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->endpointError('verifySession', (string)$this->service->error);
            }

            $player = $this->service->player;
            return $this->success([
                'playerId' => (string)$player->uuid,
                'balance' => $this->formatBalance($this->service->balance()),
                'currency' => $this->service->walletCurrency(),
            ]);
        } catch (Exception $e) {
            Log::channel('as_server')->error('AS verify-session failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('AS', '验证session异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::CODE_UNKNOWN_ERROR);
        }
    }

    /**
     * 查询余额
     * POST /single-wallet/as-channel/balance
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request): Response
    {
        $this->op = __FUNCTION__;
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->endpointError('balance', (string)$this->service->error);
            }

            $player = $this->service->player;
            return $this->success([
                'balance' => $this->formatBalance($this->service->balance()),
                'currency' => $this->service->walletCurrency(),
            ]);
        } catch (Exception $e) {
            Log::channel('as_server')->error('AS balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('AS', '余额查询异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::CODE_UNKNOWN_ERROR);
        }
    }

    /**
     * 下注扣款 Withdraw（Lua 原子操作，幂等键 txnId）
     * 场景：Loss = Withdraw(10, round 2) + Deposit(0, round 2)
     *       Win  = Withdraw(10, round 2) + Deposit(100, round 2)
     * POST /single-wallet/as-channel/withdraw
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function withdraw(Request $request): Response
    {
        $this->op = __FUNCTION__;
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS下注请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->endpointError('withdraw', (string)$this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txnId'] ?? '');
            $roundId = (string)($data['roundId'] ?? '');
            $amount = $this->parseAmount($data['amount'] ?? 0);

            // 参数校验：txnId/roundId 必填、amount > 0 且最多 2 位小数（文档）
            if ($orderNo === '' || $roundId === '' || $amount === null || $amount <= 0) {
                return $this->error(self::CODE_REQUEST_DECLINED);
            }

            // 文档：completed=true 后不再允许新的扣款/派彩/回滚（幂等重试除外）
            if ($this->isRoundClosed($roundId) && !$this->isWalletRetry($orderNo, "game:record:bet:AS:{$orderNo}")) {
                Log::channel('as_server')->warning('AS扣款拒绝：回合已结束', ['txnId' => $orderNo, 'roundId' => $roundId]);
                return $this->error(self::CODE_REQUEST_DECLINED);
            }

            //判断当前设备是否爆机
            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->endpointError('withdraw', (string)$this->service->error);
            }

            // Lua 原子下注
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $amount,
                'game_code' => (string)($data['gameId'] ?? ''),
                'transaction_type' => TransactionType::BET,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $result = RedisLuaScripts::atomicBet($player->id, 'AS', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'AS', $player->id, $luaParams);

            // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步和推送）
            if ($result['ok'] === 1) {
                GameRecordCacheService::saveBet('AS', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $amount,
                    'game_code' => (string)($data['gameId'] ?? ''),
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            // 游戏交互日志
            logGameInteraction('AS', 'bet', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);

            // 处理结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    // 幂等：重试一律返回原始的响应（文档）
                    Log::channel('as_server')->info('AS下注重复请求（Lua检测）', ['txnId' => $orderNo]);
                    $currentBalance = (float)($result['balance'] ?? WalletService::getBalance($player->id));
                    return $this->respondRetry($orderNo, $roundId, $data, $currentBalance);
                }
                if ($result['error'] === 'insufficient_balance') {
                    return $this->error(self::CODE_INSUFFICIENT_FUNDS);
                }
                return $this->error(self::CODE_UNKNOWN_ERROR);
            }

            Log::channel('as_server')->info('AS下注成功（Lua原子）', ['txnId' => $orderNo]);

            return $this->respondSuccess($orderNo, $roundId, $data, (float)$result['balance']);
        } catch (Exception $e) {
            Log::channel('as_server')->error('AS withdraw failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('AS', '下注异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::CODE_UNKNOWN_ERROR);
        }
    }

    /**
     * 派彩加款 Deposit（Lua 原子操作，幂等键 txnId）
     * 同一 roundId 可多次 deposit（Free Game 多段派彩：Withdraw(10)+Deposit(20)+Deposit(5)）
     * POST /single-wallet/as-channel/deposit
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function deposit(Request $request): Response
    {
        $this->op = __FUNCTION__;
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS派彩请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->endpointError('deposit', (string)$this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txnId'] ?? '');
            $roundId = (string)($data['roundId'] ?? '');
            $amount = $this->parseAmount($data['amount'] ?? 0);

            // 参数校验：txnId/roundId 必填、amount >= 0 且最多 2 位小数（文档；Loss 场景存在 Deposit(0) 收单）
            if ($orderNo === '' || $roundId === '' || $amount === null || $amount < 0) {
                return $this->error(self::CODE_REQUEST_DECLINED);
            }

            // 文档：completed=true 后不再允许新的扣款/派彩/回滚（幂等重试除外）
            if ($this->isRoundClosed($roundId) && !$this->isWalletRetry($orderNo, "game:record:settle:AS:{$orderNo}")) {
                Log::channel('as_server')->warning('AS派彩拒绝：回合已结束', ['txnId' => $orderNo, 'roundId' => $roundId]);
                return $this->error(self::CODE_REQUEST_DECLINED);
            }

            // Lua 原子结算（加款）
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $amount,
                'diff' => $amount, // 加款
                'transaction_type' => TransactionType::SETTLE,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $result = RedisLuaScripts::atomicSettle($player->id, 'AS', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'AS', $player->id, $luaParams);

            if ($result['ok'] === 1) {
                // 保存结算记录到 Redis（供 GameRecordSyncWorker 同步和推送）
                GameRecordCacheService::saveSettle('AS', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $amount,
                    'diff' => $amount,
                    'game_code' => (string)($data['gameId'] ?? ''),
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);

                // 加款成功后检查是否爆机，如果爆机则更新状态
                WalletService::checkMachineCrashAfterTransaction(
                    $player->id,
                    $result['balance'],
                    $result['old_balance'] ?? null
                );
            }

            // 游戏交互日志
            logGameInteraction('AS', 'deposit', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);

            // 处理结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_settle' || $result['error'] === 'duplicate_order') {
                    // 幂等：重试一律返回原始的响应（文档）
                    Log::channel('as_server')->info('AS派彩重复请求（Lua检测）', ['txnId' => $orderNo]);
                    $currentBalance = (float)($result['balance'] ?? WalletService::getBalance($player->id));
                    return $this->respondRetry($orderNo, $roundId, $data, $currentBalance);
                }
                return $this->error(self::CODE_UNKNOWN_ERROR);
            }

            Log::channel('as_server')->info('AS派彩成功（Lua原子）', ['txnId' => $orderNo]);

            return $this->respondSuccess($orderNo, $roundId, $data, (float)$result['balance']);
        } catch (Exception $e) {
            Log::channel('as_server')->error('AS deposit failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('AS', '派彩异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::CODE_UNKNOWN_ERROR);
        }
    }

    /**
     * 退款 Refund（Lua 原子操作，撤销原 withdraw 注单，幂等键 betTxnId）
     * 文档规定：找不到原注单也返回成功 + 当前余额
     * POST /single-wallet/as-channel/refund
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function refund(Request $request): Response
    {
        $this->op = __FUNCTION__;
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS退款请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->endpointError('refund', (string)$this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['betTxnId'] ?? ''); // 原 withdraw 的 txnId
            $txnId = (string)($data['txnId'] ?? '');      // 本笔回滚的 txnId
            $roundId = (string)($data['roundId'] ?? '');
            // 参数校验（文档）：txnId/betTxnId/roundId 必填
            if ($orderNo === '' || $txnId === '' || $roundId === '') {
                return $this->error(self::CODE_REQUEST_DECLINED);
            }

            // 文档：completed=true 后不再允许新的扣款/派彩/回滚（幂等重试除外）
            if ($this->isRoundClosed($roundId) && !$this->isWalletRetry($txnId)) {
                Log::channel('as_server')->warning('AS退款拒绝：回合已结束', ['txnId' => $txnId, 'roundId' => $roundId]);
                return $this->error(self::CODE_REQUEST_DECLINED);
            }

            // ✅ 从 Redis 读取原始下注金额（Redis 存储的是"分"，转换为"元"）
            $betRecordKey = "game:record:bet:AS:{$orderNo}";
            $refundAmount = 0.0;
            if (\support\Redis::exists($betRecordKey)) {
                $amountInCents = (int)\support\Redis::hGet($betRecordKey, 'amount');
                $refundAmount = round($amountInCents / 100, 2);
            }
            if ($refundAmount <= 0) {
                // ✅ 找不到原注单也返回成功 + 当前余额（AS 文档规定）
                Log::channel('as_server')->warning('AS退款：原注单不存在，按文档返回成功', [
                    'betTxnId' => $orderNo,
                    'txnId' => $txnId,
                ]);
                $currentBalance = WalletService::getBalance($player->id);
                return $this->respondRetry($txnId, $roundId, $data, (float)$currentBalance);
            }

            // Lua 原子取消（退款）
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL_REFUND,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'AS', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'AS', $player->id, $luaParams);

            if ($result['ok'] === 1) {
                // 保存取消记录到 Redis
                GameRecordCacheService::saveCancel('AS', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);

                // 退款成功后检查是否爆机，如果爆机则更新状态
                WalletService::checkMachineCrashAfterTransaction(
                    $player->id,
                    $result['balance'],
                    $result['old_balance'] ?? null
                );
            }

            // 游戏交互日志
            logGameInteraction('AS', 'refund', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);

            // 处理结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'order_not_found') {
                    // ✅ 找不到原注单也返回成功 + 当前余额（AS 文档规定）
                    Log::channel('as_server')->warning('AS退款：原注单不存在（Lua检测）', ['betTxnId' => $orderNo]);
                    $currentBalance = (float)($result['balance'] ?? WalletService::getBalance($player->id));
                    return $this->respondRetry($txnId, $roundId, $data, $currentBalance);
                }
                if ($result['error'] === 'duplicate_cancel') {
                    // 幂等：重试一律返回原始的响应（文档）
                    Log::channel('as_server')->info('AS退款重复请求（Lua检测）', ['betTxnId' => $orderNo]);
                    $currentBalance = (float)($result['balance'] ?? WalletService::getBalance($player->id));
                    return $this->respondRetry($txnId, $roundId, $data, $currentBalance);
                }
                return $this->error(self::CODE_UNKNOWN_ERROR);
            }

            Log::channel('as_server')->info('AS退款成功（Lua原子）', ['betTxnId' => $orderNo]);

            return $this->respondSuccess($txnId, $roundId, $data, (float)$result['balance']);
        } catch (Exception $e) {
            Log::channel('as_server')->error('AS refund failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('AS', '退款异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::CODE_UNKNOWN_ERROR);
        }
    }

    /**
     * 按端点错误码表收敛响应码（文档「可能的错误」之外的码收敛为兜底码）
     * @param string $op 端点：verifySession/balance/withdraw/deposit/refund
     * @param string $code 原错误码
     * @return Response
     */
    private function endpointError(string $op, string $code): Response
    {
        $allowed = self::ENDPOINT_ERRORS[$op] ?? [];
        if (!in_array($code, $allowed, true)) {
            $code = in_array(self::CODE_REQUEST_DECLINED, $allowed, true)
                ? self::CODE_REQUEST_DECLINED
                : self::CODE_UNKNOWN_ERROR;
        }
        return $this->error($code);
    }

    /**
     * 解析金额：文档规定 decimal 最多 2 位小数，超出精度返回 null
     * @param mixed $raw
     * @return float|null
     */
    private function parseAmount(mixed $raw): ?float
    {
        if (!is_numeric($raw)) {
            return null;
        }
        $amount = (float)$raw;
        if (abs($amount * 100 - round($amount * 100)) > 0.00001) {
            return null;
        }
        return round($amount, 2);
    }

    /**
     * 记住原始响应（文档：重试一律返回原始的响应）
     * @param string $txnId
     * @param array $payload
     * @return void
     */
    private function rememberResponse(string $txnId, array $payload): void
    {
        \support\Redis::setex('as:resp:' . $txnId, self::MARK_TTL, json_encode($payload));
    }

    /**
     * 读取原始响应
     * @param string $txnId
     * @return array|null
     */
    private function originalResponse(string $txnId): ?array
    {
        $raw = \support\Redis::get('as:resp:' . $txnId);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $payload = json_decode($raw, true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * 幂等重试判定（已存在原始响应或本交易的处理记录）
     * @param string $txnId
     * @param string $recordKey 本交易的记录键（可空）
     * @return bool
     */
    private function isWalletRetry(string $txnId, string $recordKey = ''): bool
    {
        if (\support\Redis::exists('as:resp:' . $txnId)) {
            return true;
        }
        return $recordKey !== '' && (bool)\support\Redis::exists($recordKey);
    }

    /**
     * 标记回合已结束（completed=true 且本次交易已生效）
     * @param string $roundId
     * @return void
     */
    private function setRoundClosed(string $roundId): void
    {
        if ($roundId === '') {
            return;
        }
        \support\Redis::setex('as:round:closed:' . $roundId, self::MARK_TTL, 1);
    }

    /**
     * 回合是否已结束（文档：completed=true 后不再允许扣款、派彩或回滚）
     * @param string $roundId
     * @return bool
     */
    private function isRoundClosed(string $roundId): bool
    {
        return (bool)\support\Redis::exists('as:round:closed:' . $roundId);
    }

    /**
     * 首次成功响应：记忆原始响应、按需关闭回合
     * @param string $txnId
     * @param string $roundId
     * @param array $data 原请求（取 completed）
     * @param float $balance 生效后的余额（元）
     * @return Response
     */
    private function respondSuccess(string $txnId, string $roundId, array $data, float $balance): Response
    {
        $payload = ['balance' => $this->formatBalance($balance)];
        $this->rememberResponse($txnId, $payload);
        if (!empty($data['completed'])) {
            $this->setRoundClosed($roundId);
        }
        return $this->success($payload);
    }

    /**
     * 幂等重试响应：一律返回原始响应（文档），无记忆时回退当前余额
     * @param string $txnId
     * @param string $roundId
     * @param array $data 原请求（取 completed）
     * @param float $fallbackBalance 当前余额（元）
     * @return Response
     */
    private function respondRetry(string $txnId, string $roundId, array $data, float $fallbackBalance): Response
    {
        $payload = $this->originalResponse($txnId);
        if ($payload === null) {
            $payload = ['balance' => $this->formatBalance($fallbackBalance)];
            $this->rememberResponse($txnId, $payload);
        }
        if (!empty($data['completed'])) {
            $this->setRoundClosed($roundId);
        }
        return $this->success($payload);
    }

    /**
     * 金额统一 2 位小数
     * @param mixed $balance
     * @return float
     */
    private function formatBalance($balance): float
    {
        return round((float)$balance, 2);
    }

    /**
     * 成功响应（文档：成功响应一律带 code:"OK"，如 {code,balance}、{code,playerId,balance,currency}）
     * @param array $data
     * @param int $httpCode
     * @return Response
     */
    public function success(array $data = [], int $httpCode = 200): Response
    {
        $body = json_encode(['code' => 'OK'] + $data, JSON_UNESCAPED_UNICODE);
        Log::channel('as_server')->info('AS钱包响应', [
            'op' => $this->op,
            'http_status' => $httpCode,
            'body' => $body,
        ]);

        return new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            $body
        );
    }

    /**
     * 失败响应（{code,message} + HTTP 状态）
     * @param string $code 错误码
     * @param string $message 错误消息（空则取默认）
     * @param int $httpCode HTTP 状态码（0 则按错误码映射）
     * @return Response
     */
    public function error(string $code, string $message = '', int $httpCode = 0): Response
    {
        $httpCode = $httpCode ?: (self::HTTP_CODE_MAP[$code] ?? 500);
        $payload = [
            'code' => $code,
            'message' => $message !== '' ? $message : (self::CODE_MESSAGE_MAP[$code] ?? $code),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        Log::channel('as_server')->warning('AS钱包响应', [
            'op' => $this->op,
            'http_status' => $httpCode,
            'body' => $body,
        ]);

        return new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            $body
        );
    }
}
