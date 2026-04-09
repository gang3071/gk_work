<?php

namespace app\wallet\controller\game;


use app\Constants\TransactionType;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
use app\service\WalletService;
use Exception;
use SimpleXMLElement;
use support\Log;
use support\Request;
use support\Response;

class SAGameController
{
    use TelegramAlertTrait;

    // SA平台错误码定义（官方文档）
    public const API_CODE_SUCCESS = 0;                      // 成功
    public const API_CODE_PLAYER_NOT_EXIST = 1000;          // 会员帐号不存在
    public const API_CODE_CURRENCY_ERROR = 1001;            // 货币代码不正确
    public const API_CODE_AMOUNT_ERROR = 1002;              // 金额不正确
    public const API_CODE_PLAYER_LOCKED = 1003;             // 会员帐号已被锁
    public const API_CODE_INSUFFICIENT_BALANCE = 1004;      // 不足够点数
    public const API_CODE_GENERAL_ERROR = 1005;             // 一般错误
    public const API_CODE_DECRYPT_ERROR = 1006;             // 解密错误
    public const API_CODE_SESSION_EXPIRED = 1007;           // 登入时段过期，需要重新登入
    public const API_CODE_MAINTENANCE = 9999;               // 系统错误

    // 错误码映射
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => '成功',
        self::API_CODE_PLAYER_NOT_EXIST => '会员帐号不存在',
        self::API_CODE_CURRENCY_ERROR => '货币代码不正确',
        self::API_CODE_AMOUNT_ERROR => '金额不正确',
        self::API_CODE_PLAYER_LOCKED => '会员帐号已被锁',
        self::API_CODE_INSUFFICIENT_BALANCE => '不足够点数',
        self::API_CODE_GENERAL_ERROR => '一般错误',
        self::API_CODE_DECRYPT_ERROR => '解密错误',
        self::API_CODE_SESSION_EXPIRED => '登入时段过期，需要重新登入',
        self::API_CODE_MAINTENANCE => '系统错误',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_SA);
    }

    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request)
    {
        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sa_server')->info('sa余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->balance();
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], array_merge($data, ['amount' => $balance]));
        } catch (Exception $e) {
            Log::error('SA balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SA', '余额查询异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
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
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sa_server')->info('SA下注请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txnid'] ?? '');
            $bet = $data['amount'];

            //判断当前设备是否爆机
            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->error($this->service->error);
            }

            // Lua 原子下注
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $bet,
                'game_code' => $data['hostid'],
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

            $result = RedisLuaScripts::atomicBet($player->id, 'SA', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'SA', $player->id, $luaParams);

            // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步和推送）
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveBet('SA', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $bet,
                    'game_code' => $data['hostid'],
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            // 游戏交互日志
            logGameInteraction('SA', 'bet', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);


            // 处理结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    Log::channel('sa_server')->info('SA下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                    // 重复订单返回成功（幂等性）
                    $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => (float)$currentBalance,
                    ]);
                } elseif ($result['error'] === 'insufficient_balance') {
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => round((float)$result['balance'], 2),
                    ]);
                }
            }

            Log::channel('sa_server')->info('SA下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => round((float)$result['balance'], 2),
            ]);
        } catch (Exception $e) {
            Log::error('SA bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SA', '下注异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
        }
    }

    /**
     * 取消下注（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sa_server')->info('SA取消下注请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txn_reverse_id'] ?? '');
            $platformAmt = (float)($data['amount'] ?? 0);

            // ✅ 检查订单是否存在
            $betRecordKey = "game:record:bet:SA:{$orderNo}";
            if (!\support\Redis::exists($betRecordKey)) {
                Log::channel('sa_server')->warning('SA取消下注失败：订单不存在', ['order_no' => $orderNo]);
                $currentBalance = \app\service\WalletService::getBalance($player->id);
                return $this->error(self::API_CODE_GENERAL_ERROR, [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => round((float)$currentBalance, 2),
                ]);
            }

            // ✅ 从 Redis 读取原始下注金额
            $originalBetAmount = (float)\support\Redis::hGet($betRecordKey, 'amount');
            if ($originalBetAmount <= 0) {
                Log::channel('sa_server')->error('SA取消下注失败：无法读取原始下注金额', [
                    'order_no' => $orderNo,
                    'redis_key' => $betRecordKey
                ]);
                $currentBalance = \app\service\WalletService::getBalance($player->id);
                return $this->error(self::API_CODE_GENERAL_ERROR, [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => round((float)$currentBalance, 2),
                ]);
            }

            // ✅ 验证平台传入的金额是否一致
            if (abs($platformAmt - $originalBetAmount) > 0.01) {
                Log::channel('sa_server')->warning('SA取消下注：平台传入金额与原始下注金额不一致', [
                    'order_no' => $orderNo,
                    'platform_amt' => $platformAmt,
                    'original_bet_amount' => $originalBetAmount,
                    'diff' => $platformAmt - $originalBetAmount
                ]);
            }

            // ✅ 使用原始下注金额作为退款金额
            $refundAmount = $originalBetAmount;

            // Lua 原子取消
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL_REFUND,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'SA', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'SA', $player->id, $luaParams);

            // 处理 Lua 返回的错误
            if ($result['ok'] === 0) {
                $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);

                // ✅ 订单不存在
                if ($result['error'] === 'order_not_found') {
                    Log::channel('sa_server')->warning('SA取消下注失败：订单不存在', ['order_no' => $orderNo]);
                    return $this->error(self::API_CODE_GENERAL_ERROR, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => round((float)$currentBalance, 2),
                    ]);
                }

                // 重复取消
                if ($result['error'] === 'duplicate_cancel') {
                    Log::channel('sa_server')->info('SA取消下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                    return $this->error(self::API_CODE_GENERAL_ERROR, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => round((float)$currentBalance, 2),
                    ]);
                }

                // 其他错误
                Log::channel('sa_server')->error('SA取消下注失败', ['order_no' => $orderNo, 'error' => $result['error']]);
                return $this->error(self::API_CODE_GENERAL_ERROR, [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => round((float)$currentBalance, 2),
                ]);
            }

            // 保存取消记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveCancel('SA', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            Log::channel('sa_server')->info('SA取消下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => round((float)$result['balance'], 2),
            ]);
        } catch (Exception $e) {
            Log::error('SA cancelBet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SA', '取消下注异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
        }
    }

    /**
     * 結算（Lua原子操作 - 批量处理）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sa_server')->info('SA结算请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;

            // 解析批量结算列表
            $detail = json_decode($data['payoutdetails'], true);
            $betList = $detail['betlist'] ?? [];

            $processedCount = 0;
            $duplicateCount = 0;
            $notFoundCount = 0;
            $lastBalance = null;

            // 批量处理结算（每个订单一次 Lua 原子操作）
            foreach ($betList as $betInfo) {
                $orderNo = (string)($betInfo['txnid'] ?? '');
                $resultAmount = max($betInfo['resultamount'], 0);

                // ✅ 检查对应的 bet 订单是否存在
                $betRecordKey = "game:record:bet:SA:{$orderNo}";
                if (!\support\Redis::exists($betRecordKey)) {
                    Log::channel('sa_server')->warning('SA结算失败：下注订单不存在', ['order_no' => $orderNo]);
                    $notFoundCount++;
                    continue;
                }

                // Lua 原子结算
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $resultAmount,
                    'diff' => $betInfo['resultamount'], // 保留原始值
                    'transaction_type' => TransactionType::SETTLE,
                    'original_data' => $betInfo,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric'],
                    'diff' => ['required', 'numeric'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $result = RedisLuaScripts::atomicSettle($player->id, 'SA', $luaParams);

                // 审计日志
                logLuaScriptCall('settle', 'SA', $player->id, $luaParams);

                if ($result['ok'] === 1) {
                    // 保存结算记录到 Redis（供 GameRecordSyncWorker 同步和推送）
                    \app\service\GameRecordCacheService::saveSettle('SA', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $resultAmount,
                        'diff' => $betInfo['resultamount'],
                        'game_code' => $betInfo['hostid'] ?? '',
                        'original_data' => $betInfo,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);

                    // ✅ 结算成功后检查是否爆机，如果爆机则更新状态
                    WalletService::checkMachineCrashAfterTransaction(
                        $player->id,
                        $result['balance'],
                        $result['old_balance'] ?? null
                    );

                    $processedCount++;
                    $lastBalance = $result['balance'];
                } elseif ($result['error'] === 'duplicate_order' || $result['error'] === 'duplicate_settle') {
                    // 重复订单计数
                    $duplicateCount++;
                    Log::channel('sa_server')->warning('SA结算订单重复（Lua检测）', ['order_no' => $orderNo]);
                    if (isset($result['balance'])) {
                        $lastBalance = $result['balance'];
                    }
                }
            }

            // ✅ 检查是否所有订单都失败了（重复或不存在）
            if ($processedCount === 0 && ($duplicateCount > 0 || $notFoundCount > 0)) {
                // 所有订单都失败了，返回错误
                $currentBalance = $lastBalance ?? WalletService::getBalance($player->id);
                Log::channel('sa_server')->warning('SA批量结算全部失败', [
                    'total' => count($betList),
                    'duplicates' => $duplicateCount,
                    'not_found' => $notFoundCount,
                ]);
                return $this->error(self::API_CODE_GENERAL_ERROR, [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => round((float)$currentBalance, 2),
                ]);
            }

            // 获取最终余额：优先使用Lua返回的余额，如果没有则从钱包服务获取
            $finalBalance = $lastBalance ?? WalletService::getBalance($player->id);

            Log::channel('sa_server')->info('SA结算成功（Lua原子）', [
                'total' => count($betList),
                'processed' => $processedCount,
                'duplicates' => $duplicateCount,
                'not_found' => $notFoundCount,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => (float)$finalBalance,
            ]);
        } catch (Exception $e) {
            Log::error('SA betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SA', '结算异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
        }
    }

    /**
     * 余额调整（Lua原子操作）
     * adjustmenttype: 1=奖励, 2=赠送奖赏, 3=取消奖赏
     * @param Request $request
     * @return Response
     */
    public function adjustment(Request $request): Response
    {
        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sa_server')->info('SA余额调整请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txnid'] ?? '');
            $amount = $data['amount'] ?? 0;
            $adjustmentType = $data['adjustmenttype'] ?? 0;

            // adjustmenttype: 1=奖励(加钱), 2=赠送奖赏(扣钱), 3=取消奖赏(退钱)
            if ($adjustmentType == 2) {
                // 类型2: 赠送奖赏（扣款，玩家送礼物出去）
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $amount,
                    'game_code' => $data['hostid'] ?? '',
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

                $result = RedisLuaScripts::atomicBet($player->id, 'SA', $luaParams);

                // 审计日志
                logLuaScriptCall('adjustment_gift', 'SA', $player->id, $luaParams);

                // 保存下注记录到 Redis
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveBet('SA', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $amount,
                        'game_code' => $data['hostid'] ?? '',
                        'original_data' => $data,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);
                }

                // 处理结果
                if ($result['ok'] === 0) {
                    if ($result['error'] === 'duplicate_order') {
                        Log::channel('sa_server')->info('SA调整赠送重复请求（Lua检测）', ['order_no' => $orderNo]);
                        $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);
                        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                            'username' => $data['username'],
                            'currency' => $data['currency'],
                            'amount' => round((float)$currentBalance, 2),
                        ]);
                    } elseif ($result['error'] === 'insufficient_balance') {
                        return $this->error(self::API_CODE_INSUFFICIENT_BALANCE, [
                            'username' => $data['username'],
                            'currency' => $data['currency'],
                            'amount' => round((float)$result['balance'], 2),
                        ]);
                    }
                }

                Log::channel('sa_server')->info('SA调整赠送成功（Lua原子）', ['order_no' => $orderNo]);

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => round((float)$result['balance'], 2),
                ]);
            } elseif ($adjustmentType == 3) {
                // 类型3: 取消奖赏（退回余额）
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $amount,
                    'transaction_type' => TransactionType::CANCEL_REFUND,
                    'original_data' => $data,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'refund_amount' => ['required', 'numeric', 'min:0'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicCancel');

                $result = RedisLuaScripts::atomicCancel($player->id, 'SA', $luaParams);

                // 审计日志
                logLuaScriptCall('adjustment_cancel', 'SA', $player->id, $luaParams);

                // 保存取消记录到 Redis
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveCancel('SA', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'refund_amount' => $amount,
                        'original_data' => $data,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                        'adjustment_type' => $adjustmentType,
                    ]);
                }

                // 处理重复订单
                if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                    Log::channel('sa_server')->info('SA调整取消重复请求（Lua检测）', ['order_no' => $orderNo]);
                    $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => round((float)$currentBalance, 2),
                    ]);
                }

                Log::channel('sa_server')->info('SA调整取消成功（Lua原子）', ['order_no' => $orderNo]);

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => round((float)$result['balance'], 2),
                ]);
            } else {
                // 类型1: 奖励（增加余额）
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $amount,
                    'diff' => $amount, // 增加金额
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

                $result = RedisLuaScripts::atomicSettle($player->id, 'SA', $luaParams);

                // 审计日志
                logLuaScriptCall('adjustment_reward', 'SA', $player->id, $luaParams);

                // 保存结算记录到 Redis
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveSettle('SA', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $amount,
                        'diff' => $amount,
                        'game_code' => $data['hostid'] ?? '',
                        'original_data' => $data,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                        'adjustment_type' => $adjustmentType,
                    ]);

                    // ✅ 结算成功后检查是否爆机，如果爆机则更新状态
                    WalletService::checkMachineCrashAfterTransaction(
                        $player->id,
                        $result['balance'],
                        $result['old_balance'] ?? null
                    );
                } elseif ($result['error'] === 'duplicate_order') {
                    Log::channel('sa_server')->info('SA调整奖励重复订单（Lua检测）', ['order_no' => $orderNo]);
                    // 重复订单也要更新余额
                    if (isset($result['balance'])) {
                        $currentBalance = $result['balance'];
                        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                            'username' => $data['username'],
                            'currency' => $data['currency'],
                            'amount' => round((float)$currentBalance, 2),
                        ]);
                    }
                }

                Log::channel('sa_server')->info('SA调整奖励成功（Lua原子）', [
                    'order_no' => $orderNo,
                    'adjustment_type' => $adjustmentType,
                ]);

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => round((float)$result['balance'], 2),
                ]);
            }
        } catch (Exception $e) {
            Log::error('SA adjustment failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SA', '余额调整异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
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
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><RequestResponse/>');
        $xml->error = self::API_CODE_SUCCESS;
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild($key);
                foreach ($value as $k => $v) {
                    // ✅ 格式化金额字段为2位小数
                    if ($k === 'amount' && is_numeric($v)) {
                        $v = number_format((float)$v, 2, '.', '');
                    }
                    $child->addChild($k, htmlspecialchars($v));
                }
            } else {
                // ✅ 格式化金额字段为2位小数
                if ($key === 'amount' && is_numeric($value)) {
                    $value = number_format((float)$value, 2, '.', '');
                }
                $xml->addChild($key, htmlspecialchars($value));
            }
        }

        // 获取XML字符串
        $xmlString = $xml->asXML();

        return new Response(
            $httpCode,
            ['Content-Type' => 'text/xml'],
            $xmlString
        );
    }

    /**
     * 失败响应方法
     *
     * @param string $code 错误码
     * @param array $data 额外数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function error(string $code, array $data = [], int $httpCode = 200): Response
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><RequestResponse/>');
        $xml->error = $code;
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild($key);
                foreach ($value as $k => $v) {
                    // ✅ 格式化金额字段为2位小数
                    if ($k === 'amount' && is_numeric($v)) {
                        $v = number_format((float)$v, 2, '.', '');
                    }
                    $child->addChild($k, htmlspecialchars($v));
                }
            } else {
                // ✅ 格式化金额字段为2位小数
                if ($key === 'amount' && is_numeric($value)) {
                    $value = number_format((float)$value, 2, '.', '');
                }
                $xml->addChild($key, htmlspecialchars($value));
            }
        }

        // 获取XML字符串
        $xmlString = $xml->asXML();

        return new Response(
            $httpCode,
            ['Content-Type' => 'text/xml'],
            $xmlString
        );
    }
}