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
 * 端点（POST，全部在 /single-wallet/as-channel 组下）:
 *   /verify-session  {session,gameId,hash,ts}
 *                    -> {playerId,balance,currency}
 *   /balance         {playerId,hash,ts}
 *                    -> {balance,currency}
 *   /withdraw        {playerId,txnId,roundId,amount,currency,gameId,created,session,completed,hash,ts}
 *                    -> {balance}   （下注扣款）
 *   /deposit         同 withdraw 参数
 *                    -> {balance}   （派彩加款，同一 roundId 可多次 deposit）
 *   /refund          {playerId,txnId,betTxnId,roundId,created,hash,ts}
 *                    -> {balance}   （撤销原 withdraw 注单，找不到原注单也返回成功+余额）
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

    private GameServiceInterface|SingleWalletServiceInterface $service;

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
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS验证session记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error((string)$this->service->error);
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
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error((string)$this->service->error);
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
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS下注请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error((string)$this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txnId'] ?? '');
            $amount = round((float)($data['amount'] ?? 0), 2);

            // 参数校验：txnId 必填、amount > 0 且不超过 2 位小数
            if ($orderNo === '' || $amount <= 0) {
                return $this->error(self::CODE_REQUEST_DECLINED);
            }

            //判断当前设备是否爆机
            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->error((string)$this->service->error);
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
                    // 幂等：同 txnId 重试返回原结果不产生新交易
                    Log::channel('as_server')->info('AS下注重复请求（Lua检测）', ['txnId' => $orderNo]);
                    $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);
                    return $this->success(['balance' => $this->formatBalance($currentBalance)]);
                }
                if ($result['error'] === 'insufficient_balance') {
                    return $this->error(self::CODE_INSUFFICIENT_FUNDS);
                }
                return $this->error(self::CODE_UNKNOWN_ERROR);
            }

            Log::channel('as_server')->info('AS下注成功（Lua原子）', ['txnId' => $orderNo]);

            return $this->success(['balance' => $this->formatBalance($result['balance'])]);
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
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS派彩请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error((string)$this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txnId'] ?? '');
            $amount = round((float)($data['amount'] ?? 0), 2);

            // 参数校验：txnId 必填、amount >= 0（Loss 场景存在 Deposit(0) 收单）
            if ($orderNo === '' || $amount < 0) {
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
                    // 幂等：同 txnId 重试返回原结果不产生新交易
                    Log::channel('as_server')->info('AS派彩重复请求（Lua检测）', ['txnId' => $orderNo]);
                    $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);
                    return $this->success(['balance' => $this->formatBalance($currentBalance)]);
                }
                return $this->error(self::CODE_UNKNOWN_ERROR);
            }

            Log::channel('as_server')->info('AS派彩成功（Lua原子）', ['txnId' => $orderNo]);

            return $this->success(['balance' => $this->formatBalance($result['balance'])]);
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
        try {
            $data = $this->service->decrypt($request->rawBody());
            Log::channel('as_server')->info('AS退款请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error((string)$this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['betTxnId'] ?? ''); // 原 withdraw 的 txnId
            if ($orderNo === '') {
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
                    'txnId' => (string)($data['txnId'] ?? ''),
                ]);
                $currentBalance = WalletService::getBalance($player->id);
                return $this->success(['balance' => $this->formatBalance($currentBalance)]);
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
                    $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);
                    return $this->success(['balance' => $this->formatBalance($currentBalance)]);
                }
                if ($result['error'] === 'duplicate_cancel') {
                    // 幂等：同 txnId 重试返回原结果不产生新交易
                    Log::channel('as_server')->info('AS退款重复请求（Lua检测）', ['betTxnId' => $orderNo]);
                    $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);
                    return $this->success(['balance' => $this->formatBalance($currentBalance)]);
                }
                return $this->error(self::CODE_UNKNOWN_ERROR);
            }

            Log::channel('as_server')->info('AS退款成功（Lua原子）', ['betTxnId' => $orderNo]);

            return $this->success(['balance' => $this->formatBalance($result['balance'])]);
        } catch (Exception $e) {
            Log::channel('as_server')->error('AS refund failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('AS', '退款异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::CODE_UNKNOWN_ERROR);
        }
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
     * 成功响应（{balance} 或 {playerId,balance,currency} 等）
     * @param array $data
     * @param int $httpCode
     * @return Response
     */
    public function success(array $data = [], int $httpCode = 200): Response
    {
        return new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_UNICODE)
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

        return new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }
}
