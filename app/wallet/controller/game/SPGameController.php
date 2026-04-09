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

class SPGameController
{
    use TelegramAlertTrait;
    // SP平台错误码定义（官方文档）
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
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_SP);
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
            Log::channel('sp_server')->info('sp余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->balance();
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], array_merge($data, ['amount' => $balance]));
        } catch (Exception $e) {
            Log::error('SP balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '余额查询异常', $e, ['params' => $request->rawBody()]);
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
            Log::channel('sp_server')->info('SP下注请求（Lua原子）', ['params' => $data]);
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
                'game_code' => $data['gamecode'] ?? '',
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

            $result = RedisLuaScripts::atomicBet($player->id, 'SP', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'SP', $player->id, $luaParams);

            // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步和推送）
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveBet('SP', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $bet,
                    'game_code' => $data['gamecode'] ?? '',
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            // 游戏交互日志
            logGameInteraction('SP', 'bet', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);


            // 处理结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    Log::channel('sp_server')->info('SP下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                    // 重复订单返回成功（幂等性）
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

            Log::channel('sp_server')->info('SP下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => round((float)$result['balance'], 2),
            ]);
        } catch (Exception $e) {
            Log::error('SP bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '下注异常', $e, ['params' => $request->rawBody()]);
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
            Log::channel('sp_server')->info('SP取消下注请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txn_reverse_id'] ?? '');
            $platformAmt = (float)($data['amount'] ?? 0);

            // ✅ 检查订单是否存在
            $betRecordKey = "game:record:bet:SP:{$orderNo}";
            if (!\support\Redis::exists($betRecordKey)) {
                Log::channel('sp_server')->warning('SP取消下注失败：订单不存在', ['order_no' => $orderNo]);
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
                Log::channel('sp_server')->error('SP取消下注失败：无法读取原始下注金额', [
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
                Log::channel('sp_server')->warning('SP取消下注：平台传入金额与原始下注金额不一致', [
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

            $result = RedisLuaScripts::atomicCancel($player->id, 'SP', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'SP', $player->id, $luaParams);

            // 处理 Lua 返回的错误
            if ($result['ok'] === 0) {
                $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);

                // ✅ 订单不存在
                if ($result['error'] === 'order_not_found') {
                    Log::channel('sp_server')->warning('SP取消下注失败：订单不存在', ['order_no' => $orderNo]);
                    return $this->error(self::API_CODE_GENERAL_ERROR, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => round((float)$currentBalance, 2),
                    ]);
                }

                // 重复取消
                if ($result['error'] === 'duplicate_cancel') {
                    Log::channel('sp_server')->info('SP取消下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                    return $this->error(self::API_CODE_GENERAL_ERROR, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => round((float)$currentBalance, 2),
                    ]);
                }

                // 其他错误
                Log::channel('sp_server')->error('SP取消下注失败', ['order_no' => $orderNo, 'error' => $result['error']]);
                return $this->error(self::API_CODE_GENERAL_ERROR, [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => round((float)$currentBalance, 2),
                ]);
            }

            // 保存取消记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveCancel('SP', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            Log::channel('sp_server')->info('SP取消下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => round((float)$result['balance'], 2),
            ]);
        } catch (Exception $e) {
            Log::error('SP cancelBet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '取消下注异常', $e, ['params' => $request->rawBody()]);
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
            Log::channel('sp_server')->info('SP结算请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;

            // ✅ 使用外层 amount 作为实际派彩金额（SP 平台规范）
            $payoutAmount = (float)($data['amount'] ?? 0);
            $txnId = (string)($data['txnid'] ?? '');

            // 解析批量结算列表（用于关联下注记录和审计）
            $detail = json_decode($data['payoutdetails'], true);
            $betList = $detail['betlist'] ?? [];

            // ✅ PlayerWin/PlayerLost 使用外层 amount 和 txnid 进行单次结算
            if (!empty($txnId) && $payoutAmount > 0) {
                // ✅ 检查 betlist 中的下注订单是否存在
                if (!empty($betList)) {
                    foreach ($betList as $betInfo) {
                        // 优先检查 transferlist（捕鱼游戏等多人游戏）
                        if (isset($betInfo['transferlist']) && is_array($betInfo['transferlist'])) {
                            foreach ($betInfo['transferlist'] as $transfer) {
                                $betOrderNo = (string)($transfer['txnid'] ?? '');
                                if (empty($betOrderNo)) {
                                    continue;
                                }
                                $betRecordKey = "game:record:bet:SP:{$betOrderNo}";
                                if (!\support\Redis::exists($betRecordKey)) {
                                    Log::channel('sp_server')->warning('SP结算失败：transferlist中的下注订单不存在', [
                                        'settle_txnid' => $txnId,
                                        'bet_txnid' => $betOrderNo
                                    ]);
                                    $currentBalance = WalletService::getBalance($player->id);
                                    return $this->error(self::API_CODE_GENERAL_ERROR, [
                                        'username' => $data['username'],
                                        'currency' => $data['currency'],
                                        'amount' => round((float)$currentBalance, 2),
                                    ]);
                                }
                            }
                        } else {
                            // 普通游戏，检查 betlist 中的 txnid
                            $betOrderNo = (string)($betInfo['txnid'] ?? '');
                            if (empty($betOrderNo)) {
                                continue;
                            }
                            $betRecordKey = "game:record:bet:SP:{$betOrderNo}";
                            if (!\support\Redis::exists($betRecordKey)) {
                                Log::channel('sp_server')->warning('SP结算失败：下注订单不存在', [
                                    'settle_txnid' => $txnId,
                                    'bet_txnid' => $betOrderNo
                                ]);
                                $currentBalance = WalletService::getBalance($player->id);
                                return $this->error(self::API_CODE_GENERAL_ERROR, [
                                    'username' => $data['username'],
                                    'currency' => $data['currency'],
                                    'amount' => round((float)$currentBalance, 2),
                                ]);
                            }
                        }
                    }
                }

                // 单次派彩处理（使用外层 txnid 作为结算订单号）
                $orderNo = $txnId;
                $resultAmount = $payoutAmount;

                // Lua 原子结算
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $resultAmount,
                    'diff' => $payoutAmount, // 使用外层 amount 作为 diff
                    'transaction_type' => TransactionType::SETTLE,
                    'original_data' => $data, // 使用完整的请求数据
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric'],
                    'diff' => ['required', 'numeric'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $result = RedisLuaScripts::atomicSettle($player->id, 'SP', $luaParams);

                // 审计日志
                logLuaScriptCall('settle', 'SP', $player->id, $luaParams);

                if ($result['ok'] === 1) {
                    // 保存结算记录到 Redis（供 GameRecordSyncWorker 同步和推送）
                    \app\service\GameRecordCacheService::saveSettle('SP', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $resultAmount,
                        'diff' => $payoutAmount,
                        'game_code' => $data['gamecode'] ?? '',
                        'original_data' => $data,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);

                    // ✅ 结算成功后检查是否爆机，如果爆机则更新状态
                    WalletService::checkMachineCrashAfterTransaction(
                        $player->id,
                        $result['balance'],
                        $result['old_balance'] ?? null
                    );

                    $finalBalance = $result['balance'];
                } elseif ($result['error'] === 'duplicate_order' || $result['error'] === 'duplicate_settle') {
                    // ✅ 重复派彩请求，返回错误码 1005
                    Log::channel('sp_server')->warning('SP结算订单重复（Lua检测）', ['order_no' => $orderNo]);
                    $currentBalance = $result['balance'] ?? WalletService::getBalance($player->id);
                    return $this->error(self::API_CODE_GENERAL_ERROR, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => round((float)$currentBalance, 2),
                    ]);
                } else {
                    // 结算失败，返回当前余额
                    $finalBalance = WalletService::getBalance($player->id);
                }
            } else {
                // PlayerLost 或批量处理（使用 betlist）
                $processedCount = 0;
                $duplicateCount = 0;
                $notFoundCount = 0;
                $lastBalance = null;

                // ✅ PlayerLost 场景：外层有 txnId 但没有 amount，需要检查 betlist
                if (!empty($txnId) && empty($betList)) {
                    // PlayerLost 但 betlist 为空，无法验证下注订单，返回错误
                    Log::channel('sp_server')->warning('SP结算失败：PlayerLost场景但betlist为空', [
                        'txnid' => $txnId
                    ]);
                    $currentBalance = WalletService::getBalance($player->id);
                    return $this->error(self::API_CODE_GENERAL_ERROR, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => round((float)$currentBalance, 2),
                    ]);
                }

                foreach ($betList as $betInfo) {
                    $orderNo = (string)($betInfo['txnid'] ?? '');
                    $resultAmount = max($betInfo['resultamount'], 0);

                    // ✅ 检查对应的 bet 订单是否存在
                    // 优先检查 transferlist（捕鱼游戏等多人游戏）
                    $hasValidBet = false;
                    if (isset($betInfo['transferlist']) && is_array($betInfo['transferlist'])) {
                        foreach ($betInfo['transferlist'] as $transfer) {
                            $betOrderNo = (string)($transfer['txnid'] ?? '');
                            if (empty($betOrderNo)) {
                                continue;
                            }
                            $betRecordKey = "game:record:bet:SP:{$betOrderNo}";
                            if (\support\Redis::exists($betRecordKey)) {
                                $hasValidBet = true;
                                break;
                            }
                        }
                        if (!$hasValidBet) {
                            Log::channel('sp_server')->warning('SP批量结算跳过：transferlist中的下注订单不存在', ['settle_order_no' => $orderNo]);
                            $notFoundCount++;
                            continue;
                        }
                    } else {
                        // 普通游戏，检查 betlist 中的 txnid
                        $betRecordKey = "game:record:bet:SP:{$orderNo}";
                        if (!\support\Redis::exists($betRecordKey)) {
                            Log::channel('sp_server')->warning('SP批量结算跳过：下注订单不存在', ['order_no' => $orderNo]);
                            $notFoundCount++;
                            continue;
                        }
                    }

                    // Lua 原子结算
                    $luaParams = [
                        'order_no' => $orderNo,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $resultAmount,
                        'diff' => $betInfo['resultamount'],
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

                    $result = RedisLuaScripts::atomicSettle($player->id, 'SP', $luaParams);

                    // 审计日志
                    logLuaScriptCall('settle', 'SP', $player->id, $luaParams);

                    if ($result['ok'] === 1) {
                        // 保存结算记录到 Redis
                        \app\service\GameRecordCacheService::saveSettle('SP', [
                            'order_no' => $orderNo,
                            'player_id' => $player->id,
                            'platform_id' => $this->service->platform->id,
                            'amount' => $resultAmount,
                            'diff' => $betInfo['resultamount'],
                            'game_code' => $betInfo['gamecode'] ?? '',
                            'original_data' => $betInfo,
                            'balance_before' => $result['old_balance'] ?? 0,
                            'balance_after' => $result['balance'],
                        ]);

                        // 结算成功后检查爆机
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
                        Log::channel('sp_server')->warning('SP结算订单重复（Lua检测）', ['order_no' => $orderNo]);
                        if (isset($result['balance'])) {
                            $lastBalance = $result['balance'];
                        }
                    }
                }

                // ✅ 检查是否所有订单都失败了（重复或不存在）
                if ($processedCount === 0 && ($duplicateCount > 0 || $notFoundCount > 0)) {
                    // 所有订单都失败了，返回错误
                    $currentBalance = $lastBalance ?? WalletService::getBalance($player->id);
                    Log::channel('sp_server')->warning('SP批量结算全部失败', [
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

                // 获取最终余额
                $finalBalance = $lastBalance ?? WalletService::getBalance($player->id);

                Log::channel('sp_server')->info('SP批量结算成功（Lua原子）', [
                    'total' => count($betList),
                    'processed' => $processedCount,
                    'duplicates' => $duplicateCount,
                    'not_found' => $notFoundCount,
                ]);
            }

            Log::channel('sp_server')->info('SP结算成功（Lua原子）', [
                'txnid' => $txnId ?? 'batch',
                'amount' => $payoutAmount,
                'final_balance' => $finalBalance,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => round((float)$finalBalance, 2),
            ]);
        } catch (Exception $e) {
            Log::error('SP betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '结算异常', $e, ['params' => $request->rawBody()]);
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