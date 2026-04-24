<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\RSGLiveServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
use app\service\WalletService;
use Exception;
use support\Cache;
use support\Log;
use support\Request;
use support\Response;

/**
 * RSG皇家真人
 */
class RsgLiveGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_TRANSACTIONID_DUPLICATE = 1;
    public const API_CODE_AMOUNT_OVER_BALANCE = 2;
    public const API_CODE_REFERENCEID_NOT_FOUND = 3;
    public const API_CODE_TOKEN_DOES_NOT_EXIST = 4;
    public const API_CODE_AUTHTOKEN_EXPIRED = 5;
    public const API_CODE_SESSION_TOKEN_EXPIRED = 6;
    public const API_CODE_TARGET_ID_NOT_FOUND = 7;
    public const API_CODE_ACCOUNT_LOCKED = 8;
    public const API_CODE_CERTIFICATE_ERROR = 9;
    public const API_CODE_MAXIMUM_DISABLE_LIMIT_REACHED = 10;
    public const API_CODE_REQUEST_A_TIMEOUT = 11;
    public const API_CODE_DATABASE_ERROR = 12;


    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'Success',
        self::API_CODE_TRANSACTIONID_DUPLICATE => '交易識別碼重複',
        self::API_CODE_AMOUNT_OVER_BALANCE => '餘額不足',
        self::API_CODE_REFERENCEID_NOT_FOUND => '參考編號不存在',
        self::API_CODE_TOKEN_DOES_NOT_EXIST => '令牌無效',
        self::API_CODE_AUTHTOKEN_EXPIRED => '身份令牌不存在',
        self::API_CODE_SESSION_TOKEN_EXPIRED => '對話令牌不存在',
        self::API_CODE_TARGET_ID_NOT_FOUND => '目標交易ID不存在',
        self::API_CODE_ACCOUNT_LOCKED => '帳戶鎖定',
        self::API_CODE_CERTIFICATE_ERROR => '憑證錯誤',
        self::API_CODE_MAXIMUM_DISABLE_LIMIT_REACHED => '已達到停用額度上限',
        self::API_CODE_REQUEST_A_TIMEOUT => '請求逾時',
        self::API_CODE_DATABASE_ERROR => '數據庫錯誤',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    /**
     * @var RSGLiveServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_RSG_LIVE);
        $this->logger = Log::channel('rsg_live_server');
    }


    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function checkUser(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('rsg_live檢查使用者', ['params' => $params]);
            $account = $params['account'];
            $token = strtolower($params['token']);
            if($token != Cache::get('rsg_live_user_token_'.$account)){
                return $this->error(self::API_CODE_TOKEN_DOES_NOT_EXIST);
            }

            $player = Player::query()->where('uuid', $account)->first();

            if (!$player) {
                return $this->error(self::API_CODE_TOKEN_DOES_NOT_EXIST);
            }

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'authToken' => $this->service->getToken('authToken', $account,86400),
                'sessionToken' => $this->service->getToken('sessionToken', $account),
                'requestId' => $params['requestId'],
                'account' => $params['account'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive checkUser failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '检查用户异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function RequestExtendToken(Request $request): Response
    {
        try {
            $params = $request->post();

            $token = $this->service->refresh(request()->header('authorization'));

            $this->logger->info('rsg_live檢查使用者', ['params' => $params]);

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'sessionToken' => $token,
                'requestId' => $params['requestId'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive RequestExtendToken failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '延长令牌异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_AUTHTOKEN_EXPIRED);
        }
    }


    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');
            $this->logger->info('rsg_live余额查询记录', ['params' => $params, 'token' => $token]);
            $data = $this->service->decrypt($token);
            $this->logger->info('rsg_live余额查询记录', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $balance = $this->service->balance();
            $formattedBalance = round((float)$balance, 4);  // ✅ 统一格式化为4位小数

            $this->logger->info('balance',[
                'balance' => $formattedBalance,
                'status' => 0,
                'requestId' => $params['requestId'],
                'account' => $data['memberaccount'],
            ]);
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => $formattedBalance,  // ✅ API文档要求小数4位
                'status' => 0,
                'requestId' => $params['requestId'],
                'account' => $data['memberaccount'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * 下注（Lua原子操作）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');
            $this->logger->info('rsg_live下注请求（Lua原子）', ['params' => $params, 'token' => $token]);
            $data = $this->service->decrypt($token);
            $this->logger->info('rsg_live下注数据', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            // ✅ 修复：使用 referenceId 作为订单号（下注和结算关联同一局）
            $transactionId = (string)($params['transaction']['id'] ?? '');
            $orderNo = (string)($params['transaction']['referenceId'] ?? $transactionId);
            $betAmount = $params['transaction']['amount'] ?? 0;

            //判断当前设备是否爆机
            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->error($this->service->error);
            }

            // Lua 原子下注
            $luaParams = [
                'order_no' => $orderNo,  // 使用 referenceId
                'platform_id' => $this->service->platform->id,
                'amount' => $betAmount,
                'game_code' => $params['transaction']['gameCode'] ?? '',
                'transaction_type' => TransactionType::BET,
                'original_data' => $params,
                'transaction_id' => $transactionId,  // 记录原始 transaction.id
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $result = RedisLuaScripts::atomicBet($player->id, 'RSGLIVE', $luaParams);

            // ✅ 记录Lua脚本返回结果
            $this->logger->info('rsg_live下注Lua结果', [
                'order_no' => $orderNo,
                'lua_result' => $result,
                'balance_before' => $result['old_balance'] ?? null,
                'balance_after' => $result['balance'] ?? null,
            ]);

            // 审计日志
            logLuaScriptCall('bet', 'RSGLIVE', $player->id, $luaParams);

            // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步）
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveBet('RSGLIVE', [
                    'order_no' => $orderNo,  // referenceId（关联订单号）
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $betAmount,
                    'game_code' => $params['transaction']['gameCode'] ?? '',
                    'original_data' => $params,  // 包含 transaction.id 和 referenceId
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                    'transaction_id' => $transactionId,  // 记录原始 transaction.id
                ]);

                // ✅ 建立 transaction_id -> order_no 映射（供Cancel接口使用）
                $txMapKey = "game:transaction_id:RSGLIVE:{$transactionId}";
                \support\Redis::connection()->setex($txMapKey, 604800, $orderNo);  // 7天过期
            }

            // 游戏交互日志
            logGameInteraction('RSGLIVE', 'bet', $params, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);


            // 处理下注结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    $this->logger->info('RSGLive下注重复请求（Lua检测）', ['order_no' => $orderNo, 'transaction_id' => $transactionId]);
                    // ✅ 修复：重复交易ID应返回HTTP 400 + msgId=1
                    return $this->error(self::API_CODE_TRANSACTIONID_DUPLICATE);
                } elseif ($result['error'] === 'insufficient_balance') {
                    return $this->error(self::API_CODE_AMOUNT_OVER_BALANCE);
                }
            }

            $this->logger->info('rsg_live下注成功（Lua原子）', ['order_no' => $orderNo, 'transaction_id' => $transactionId]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'transaction' => [
                    'id' => $transactionId,  // ✅ 返回原始 transaction.id
                    'balance' => round((float)$result['balance'], 4),  // ✅ API文档要求小数4位
                ],
                'requestId' => $params['requestId'],
                'account' => $data['memberaccount'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * 結算（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');
            $this->logger->info('rsg_live结算请求（Lua原子）', ['params' => $params, 'token' => $token]);
            $data = $this->service->decrypt($token);
            $this->logger->info('rsg_live结算数据', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            // ✅ 修复：使用 referenceId 作为订单号（与下注关联）
            $transactionId = (string)($params['transaction']['id'] ?? '');
            $orderNo = (string)($params['transaction']['referenceId'] ?? $transactionId);
            $winAmount = (float)($params['transaction']['amount'] ?? 0);

            // ✅ 验证金额有效性（Credit接口amount应该>=0）
            if ($winAmount < 0) {
                $this->logger->error('RSGLive结算金额无效', ['order_no' => $orderNo, 'win_amount' => $winAmount]);
                return $this->error(self::API_CODE_DATABASE_ERROR, '结算金额无效');
            }

            // ✅ 优化：从 Redis 读取实际下注金额（referenceId 关联）
            $redisKey = "game:record:bet:RSGLIVE:{$orderNo}";
            $cachedBet = \support\Redis::connection()->hGet($redisKey, 'amount');
            $betAmount = $cachedBet !== false ? (float)$cachedBet : 0;

            // ✅ Redis 未命中，从数据库降级获取
            if ($betAmount == 0 && $cachedBet === false) {
                $betAmount = getBetAmountWithFallback('RSGLIVE', $orderNo, $player->id, $this->service->platform->id);
            }

            // 计算 diff = win - bet
            $diff = bcsub((string)$winAmount, (string)$betAmount, 2);

            // Lua 原子结算
            $luaParams = [
                'order_no' => $orderNo,  // 使用 referenceId
                'platform_id' => $this->service->platform->id,
                'amount' => $winAmount,  // ✅ 已验证>=0，直接使用
                'diff' => (float)$diff,  // ✅ 修正：diff = win - bet
                'transaction_type' => TransactionType::SETTLE,
                'original_data' => $params,
                'transaction_id' => $transactionId,  // 记录原始 transaction.id
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $result = RedisLuaScripts::atomicSettle($player->id, 'RSGLIVE', $luaParams);

            // ✅ 记录Lua脚本返回结果
            $this->logger->info('rsg_live结算Lua结果', [
                'order_no' => $orderNo,
                'lua_result' => $result,
                'balance_before' => $result['old_balance'] ?? null,
                'balance_after' => $result['balance'] ?? null,
            ]);

            // 审计日志
            logLuaScriptCall('settle', 'RSGLIVE', $player->id, $luaParams);

            // 保存结算记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveSettle('RSGLIVE', [
                    'order_no' => $orderNo,  // referenceId（关联订单号）
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => max($winAmount, 0),
                    'diff' => $diff,
                    'game_code' => $params['transaction']['gameCode'] ?? '',
                    'original_data' => $params,  // 包含 transaction.id 和 referenceId
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                    'transaction_id' => $transactionId,  // 记录原始 transaction.id
                ]);

                // ✅ 结算成功后检查是否爆机，如果爆机则更新状态
                WalletService::checkMachineCrashAfterTransaction(
                    $player->id,
                    $result['balance'],
                    $result['old_balance'] ?? null
                );
            }

            // 处理结算结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                $this->logger->info('RSGLive结算重复请求（Lua检测）', ['order_no' => $orderNo, 'transaction_id' => $transactionId]);
                // ✅ 修复：重复交易ID应返回HTTP 400 + msgId=1
                return $this->error(self::API_CODE_TRANSACTIONID_DUPLICATE);
            }

            $this->logger->info('rsg_live结算成功（Lua原子）', ['order_no' => $orderNo, 'transaction_id' => $transactionId, 'diff' => $diff]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'transaction' => [
                    'id' => $transactionId,  // ✅ 返回原始 transaction.id
                    'balance' => round((float)$result['balance'], 4),  // ✅ API文档要求小数4位
                ],
                'requestId' => $params['requestId'],
                'account' => $data['memberaccount'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }


    /**
     * 取消注单（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function cancel(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');
            $this->logger->info('rsg_live取消注单请求（Lua原子）', ['params' => $params, 'token' => $token]);
            $data = $this->service->decrypt($token);
            $this->logger->info('rsg_live取消注单数据', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            // ✅ 根据API文档：使用 targetId 查找要取消的交易
            $transactionId = (string)($params['transaction']['id'] ?? '');  // 本次Cancel的交易ID
            $targetId = (string)($params['transaction']['targetId'] ?? '');  // 要取消的Debit交易ID

            if (empty($targetId)) {
                $this->logger->error('RSGLive取消失败：targetId为空', ['params' => $params]);
                return $this->error(self::API_CODE_REFERENCEID_NOT_FOUND);
            }

            // ✅ 通过 targetId 查找对应的 order_no
            // 方案1：先尝试从 transaction_id 映射中查找
            $txMapKey = "game:transaction_id:RSGLIVE:{$targetId}";
            $orderNo = \support\Redis::connection()->get($txMapKey);

            // 方案2：如果映射不存在，尝试直接使用 targetId 作为 order_no（兼容性处理）
            if (!$orderNo) {
                $this->logger->warning('RSGLive未找到transaction_id映射，尝试直接使用targetId', ['targetId' => $targetId]);
                $orderNo = $targetId;
            }

            // 从 Redis 读取实际下注金额
            $redisKey = "game:record:bet:RSGLIVE:{$orderNo}";
            $cachedBet = \support\Redis::connection()->hGet($redisKey, 'amount');
            $actualRefundAmount = $cachedBet !== false ? (float)$cachedBet : 0;

            // Redis 未命中，从数据库降级获取
            if ($actualRefundAmount == 0 && $cachedBet === false) {
                $actualRefundAmount = getBetAmountWithFallback('RSGLIVE', $orderNo, $player->id, $this->service->platform->id);
            }

            // Lua 原子取消
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $actualRefundAmount,
                'transaction_type' => TransactionType::CANCEL,
                'original_data' => $params,
                'transaction_id' => $transactionId,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'RSGLIVE', $luaParams);

            // ✅ 记录Lua脚本返回结果
            $this->logger->info('rsg_live取消Lua结果', [
                'order_no' => $orderNo,
                'targetId' => $targetId,
                'lua_result' => $result,
                'balance_before' => $result['old_balance'] ?? null,
                'balance_after' => $result['balance'] ?? null,
            ]);

            // 审计日志
            logLuaScriptCall('cancel', 'RSGLIVE', $player->id, $luaParams);

            // 处理取消结果
            if ($result['ok'] === 0) {
                $error = $result['error'] ?? 'unknown';

                if ($error === 'duplicate_cancel') {
                    // ✅ 订单已被取消，返回目标交易ID不存在（符合"相同targetId只可以取消一次"）
                    $this->logger->info('RSGLive重复取消（Lua检测）', ['order_no' => $orderNo, 'targetId' => $targetId]);
                    return $this->error(self::API_CODE_TARGET_ID_NOT_FOUND);
                } elseif ($error === 'order_not_found') {
                    // ✅ 订单不存在，返回目标交易ID不存在
                    $this->logger->warning('RSGLive取消失败：订单不存在', ['order_no' => $orderNo, 'targetId' => $targetId]);
                    return $this->error(self::API_CODE_TARGET_ID_NOT_FOUND);
                } else {
                    // 其他错误
                    $this->logger->error('RSGLive取消失败', ['order_no' => $orderNo, 'error' => $error]);
                    return $this->error(self::API_CODE_DATABASE_ERROR);
                }
            }

            $this->logger->info('rsg_live取消注单成功（Lua原子）', ['order_no' => $orderNo, 'refund_amount' => $actualRefundAmount]);

            // 保存取消记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveCancel('RSGLIVE', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $actualRefundAmount,
                    'original_data' => $params,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                    'transaction_id' => $transactionId,
                ]);
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'transaction' => [
                    'id' => $transactionId,
                    'balance' => round((float)$result['balance'], 4),  // ✅ API文档要求小数4位
                ],
                'requestId' => $params['requestId'],
                'account' => $data['memberaccount'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive cancel failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '取消注单异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * 成功响应方法
     *
     * @param string $message 响应消息
     * @param array $data 响应数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function success(string $message = '', array $data = [], int $httpCode = 200): Response
    {

        $timestamp = time();
        $responseData = [
            'msgId' => self::API_CODE_SUCCESS, // 使用业务状态码常量
            'message' => $message ?: self::API_CODE_MAP[self::API_CODE_SUCCESS],
            'timestamp' => $timestamp,
            'data' => $data,
        ];

        // ✅ 记录成功响应日志
        $this->logger->info('rsg_live成功响应', [
            'http_code' => $httpCode,
            'response' => $responseData
        ]);

//        $reqBase64 = $this->service->encrypt(json_encode($responseData));

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
        ));
    }

    /**
     * 失败响应方法
     *
     * @param string $code 错误码
     * @param string|null $message 自定义错误信息
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function error(string $code, ?string $message = null, int $httpCode = 400): Response
    {
        $responseData = [
            'msgId' => $code,
            'message' => $message ?: (self::API_CODE_MAP[$code] ?? '未知错误'),
            'timestamp' => time(),
            'data' => null,  // ✅ 修复：按照API文档4.1使用小写 data
        ];

        // ✅ 记录错误响应日志
        $this->logger->warning('rsg_live错误响应', [
            'http_code' => $httpCode,
            'response' => $responseData
        ]);

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
        ));
    }
}