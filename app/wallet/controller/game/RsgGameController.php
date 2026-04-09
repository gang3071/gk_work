<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
use app\service\WalletService;
use Exception;
use Monolog\Logger;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * RSG皇家电子
 */
class RsgGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_DECRYPT_ERROR = 2002;
    public const API_CODE_INVALID_PARAM = 2001;
    public const API_CODE_PLAYER_NOT_EXIST = 4001;
    public const API_CODE_DUPLICATE_ORDER = 4002;
    public const API_CODE_INSUFFICIENT_BALANCE = 4003;
    public const API_CODE_ORDER_NOT_EXIST = 4004;
    public const API_CODE_ORDER_SETTLED = 4005;
    public const API_CODE_ORDER_CANCELLED = 4006;
    public const API_CODE_DUPLICATE_TRANSACTION = 4007;
    public const API_CODE_DENY_PREPAY = 4008;
    public const API_CODE_TRANSACTION_NOT_FOUND = 4009;


    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'OK',
        self::API_CODE_INVALID_PARAM => '無效參數',
        self::API_CODE_DECRYPT_ERROR => '解密異常',
        self::API_CODE_PLAYER_NOT_EXIST => '此玩家帳戶不存在',
        self::API_CODE_DUPLICATE_ORDER => '重複的 SequenNumber',
        self::API_CODE_DUPLICATE_TRANSACTION => '重複的TransactionId',
        self::API_CODE_INSUFFICIENT_BALANCE => '餘額不足',
        self::API_CODE_ORDER_SETTLED => '此 SequenNumber 已被結算',
        self::API_CODE_ORDER_NOT_EXIST => '此 SequenNumber 不存在',
        self::API_CODE_ORDER_CANCELLED => '此 SequenNumber 已被取消',
        self::API_CODE_DENY_PREPAY => '拒絕預扣，其他原因',
        self::API_CODE_TRANSACTION_NOT_FOUND => '找不到交易結果',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    private GameServiceInterface|SingleWalletServiceInterface $service;

    private null|Logger $logger;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_RSG);
        $this->logger = Log::channel('rsg_server');
    }

    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function balance(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('RSG余额查询请求', ['params' => $params]);
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG余额查询（解密后）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $balance = $this->service->balance();
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => round((float)$balance, 2)]);
        } catch (Throwable $e) {
            $this->logger->error('RSG余额查询异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 下注（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG下注请求（Lua原子）', ['order_no' => $data['SequenNumber'] ?? '']);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }
            $this->service->player = $player;

            $orderNo = (string)($data['SequenNumber'] ?? '');

            //判断当前设备是否爆机
            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->error($this->service->error);
            }

            // ========== 核心：Lua 原子下注（1次调用完成所有操作）==========
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $data['Amount'],
                'game_code' => $data['GameId'] ?? '',
                'game_type' => $data['GameType'] ?? '',
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

            $result = RedisLuaScripts::atomicBet($player->id, 'RSG', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'RSG', $player->id, $luaParams);

            // 处理返回结果
            if ($result['ok'] === 0) {
                // 失败场景
                if ($result['error'] === 'duplicate_order') {
                    // 幂等性：重复订单返回当前余额
                    $this->logger->warning('RSG重复订单（Lua检测）', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'balance' => $result['balance'],
                    ]);

                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'Balance' => round((float)$result['balance'], 2)
                    ]);

                } elseif ($result['error'] === 'insufficient_balance') {
                    // 余额不足
                    $this->logger->warning('RSG余额不足（Lua检测）', [
                        'order_no' => $orderNo,
                        'amount' => $data['Amount'],
                        'balance' => $result['balance'],
                    ]);

                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                }
            }

            // 成功场景
            $this->logger->info('RSG下注成功（Lua原子）', [
                'username' => $data['UserId'],
                'order_no' => $orderNo,
                'balance_before' => $result['old_balance'],
                'balance_after' => $result['balance'],
                'amount' => $data['Amount'],
            ]);

            // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步和推送）
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveBet('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $data['Amount'],
                    'game_code' => $data['GameId'] ?? '',
                    'game_type' => $data['GameType'] ?? '',
                    'original_data' => $data,
                    // ✅ 传入余额数据供 Worker 推送
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => round((float)$result['balance'], 2)
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG下注异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 取消下注（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG取消下注请求（Lua原子）', ['order_no' => $data['SequenNumber'] ?? '']);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['SequenNumber'] ?? '');
            $refundAmount = $data['BetAmount'] ?? 0;

            // ========== 核心：Lua 原子取消 ==========
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'RSG', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'RSG', $player->id, $luaParams);

            // 处理返回结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_cancel') {
                    // 幂等性：重复取消返回当前余额
                    $this->logger->info('RSG重复取消（Lua检测）', [
                        'order_no' => $orderNo,
                        'balance' => $result['balance'],
                    ]);

                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'Balance' => round((float)$result['balance'], 2)
                    ]);
                }
            }

            $this->logger->info('RSG取消下注成功（Lua原子）', [
                'order_no' => $orderNo,
                'refund_amount' => $refundAmount,
                'balance_before' => $result['old_balance'],
                'balance_after' => $result['balance'],
            ]);

            // 保存取消记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveCancel('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => round((float)$result['balance'], 2)
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG取消下注异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '取消下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 結算（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();
            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG结算请求（Lua原子）', ['order_no' => $data['SequenNumber'] ?? '']);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['SequenNumber'] ?? '');
            $winAmount = $data['Amount'] ?? 0;

            // ========== 核心：Lua 原子结算 ==========
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $winAmount,
                'diff' => bcsub($winAmount, $data['BetAmount'] ?? 0, 2),
                'transaction_type' => TransactionType::SETTLE,
                'game_code' => $data['GameId'] ?? '',
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $result = RedisLuaScripts::atomicSettle($player->id, 'RSG', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'RSG', $player->id, $luaParams);

            // 处理返回结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_settle') {
                    // 幂等性：重复结算返回当前余额
                    $this->logger->warning('RSG重复结算（Lua检测）', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'balance' => $result['balance'],
                    ]);

                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'Balance' => round((float)$result['balance'], 2)
                    ]);
                }
            }

            $this->logger->info('RSG结算成功（Lua原子）', [
                'order_no' => $orderNo,
                'win_amount' => $winAmount,
                'balance_before' => $result['old_balance'],
                'balance_after' => $result['balance'],
            ]);

            // 保存结算记录到 Redis（供 GameRecordSyncWorker 同步和推送）
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveSettle('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $winAmount,
                    'diff' => bcsub($winAmount, $data['BetAmount'] ?? 0, 2),
                    'game_code' => $data['GameId'] ?? '',
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
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => round((float)$result['balance'], 2)
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG结算异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 重新結算（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     */
    public function reBetResult(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG重新结算请求（Lua原子）', ['order_no' => $data['SequenNumber'] ?? '']);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['SequenNumber'] ?? '');
            $winAmount = ($data['Amount'] ?? 0) - ($data['BetAmount'] ?? 0);

            // ========== 核心：Lua 原子重新结算 ==========
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $data['Amount'] ?? 0,
                'diff' => $winAmount,
                'transaction_type' => TransactionType::SETTLE_ADJUST,  // 重新结算标记为调整
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $result = RedisLuaScripts::atomicSettle($player->id, 'RSG', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'RSG', $player->id, $luaParams);

            if ($result['ok'] === 0 && $result['error'] === 'duplicate_settle') {
                $this->logger->info('RSG重复重新结算（Lua检测）', ['order_no' => $orderNo]);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => round((float)$result['balance'], 2)
                ]);
            }

            $this->logger->info('RSG重新结算成功（Lua原子）', [
                'order_no' => $orderNo,
                'win_amount' => $winAmount,
                'balance_after' => $result['balance'],
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => round((float)$result['balance'], 2)
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG重新结算异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '重新结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * Jackpot 中獎（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     */
    public function jackpotResult(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG Jackpot中奖请求（Lua原子）', ['order_no' => $data['SequenNumber'] ?? '']);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['SequenNumber'] ?? '');
            $jackpotAmount = $data['Amount'] ?? 0;

            // ========== 核心：Lua 原子 Jackpot ==========
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $jackpotAmount,
                'diff' => $jackpotAmount,
                'transaction_type' => TransactionType::SETTLE_JACKPOT,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $result = RedisLuaScripts::atomicSettle($player->id, 'RSG', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'RSG', $player->id, $luaParams);

            if ($result['ok'] === 0 && $result['error'] === 'duplicate_settle') {
                $this->logger->info('RSG重复Jackpot（Lua检测）', ['order_no' => $orderNo]);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => round((float)$result['balance'], 2)
                ]);
            }

            $this->logger->info('RSG Jackpot成功（Lua原子）', [
                'order_no' => $orderNo,
                'jackpot_amount' => $jackpotAmount,
                'balance_after' => $result['balance'],
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => round((float)$result['balance'], 2)
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG Jackpot中奖异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', 'Jackpot异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 打鱼机预扣金额（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     */
    public function prepay(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG打鱼机预扣金额请求（Lua原子）', ['session_id' => $data['SessionId'] ?? '']);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }
            $this->service->player = $player;

            $orderNo = (string)($data['SessionId'] ?? '');  // prepay使用SessionId作为订单号
            $requestAmount = $data['Amount'] ?? 0;

            //判断当前设备是否爆机
            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->error($this->service->error);
            }

            // ========== 核心：Lua 原子预扣 ==========
            // 注意：prepay 特殊逻辑 - 余额不足时扣除所有余额
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $requestAmount,  // Lua 脚本内部会处理余额不足
                'game_code' => $data['GameId'] ?? '',
                'transaction_type' => TransactionType::BET_PREPAY,  // 标记为prepay类型
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $result = RedisLuaScripts::atomicBet($player->id, 'RSG', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'RSG', $player->id, $luaParams);

            // 处理返回结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    // 重复请求，返回当前余额和0扣款
                    $this->logger->warning('RSG重复预扣请求（Lua检测）', ['session_id' => $orderNo]);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'Balance' => round((float)$result['balance'], 2),
                        'Amount' => 0
                    ]);
                } elseif ($result['error'] === 'insufficient_balance') {
                    // prepay 特殊处理：余额不足时扣除所有余额
                    $actualDeductAmount = $result['balance'];  // 当前全部余额

                    // 重新执行扣除全部余额
                    if ($actualDeductAmount > 0) {
                        $retryLuaParams = [
                            'order_no' => $orderNo,
                            'platform_id' => $this->service->platform->id,
                            'amount' => $actualDeductAmount,
                            'game_code' => $data['GameId'] ?? '',
                            'transaction_type' => TransactionType::BET_PREPAY,
                            'original_data' => $data,
                        ];

                        // 参数验证
                        validateLuaScriptParams($retryLuaParams, [
                            'order_no' => ['required', 'string'],
                            'amount' => ['required', 'numeric', 'min:0'],
                            'platform_id' => ['required', 'integer'],
                            'transaction_type' => ['required', 'string'],
                        ], 'atomicBet');

                        $result = RedisLuaScripts::atomicBet($player->id, 'RSG', $retryLuaParams);

                        // 审计日志
                        logLuaScriptCall('bet', 'RSG', $player->id, $retryLuaParams);

                        $this->logger->info('RSG预扣不足扣全部（Lua原子）', [
                            'session_id' => $orderNo,
                            'request' => $requestAmount,
                            'actual' => $actualDeductAmount,
                        ]);

                        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                            'Balance' => round((float)$result['balance'], 2),
                            'Amount' => $actualDeductAmount
                        ]);
                    } else {
                        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                            'Balance' => 0.0,
                            'Amount' => 0
                        ]);
                    }
                }
            }

            $this->logger->info('RSG打鱼机预扣成功（Lua原子）', [
                'session_id' => $orderNo,
                'request_amount' => $requestAmount,
                'actual_deduct' => $requestAmount,
                'balance_after' => $result['balance'],
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => round((float)$result['balance'], 2),
                'Amount' => $requestAmount
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG打鱼机预扣金额异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 打鱼机退款（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG打鱼机退款请求（Lua原子）', ['session_id' => $data['SessionId'] ?? '']);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['SessionId'] ?? '');
            $refundAmount = $data['Amount'] ?? 0;

            // ========== 核心：Lua 原子退款 ==========
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $refundAmount,
                'diff' => $refundAmount,
                'transaction_type' => TransactionType::SETTLE_REFUND,  // 标记为refund类型
                'game_code' => $data['GameId'] ?? '',
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $result = RedisLuaScripts::atomicSettle($player->id, 'RSG', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'RSG', $player->id, $luaParams);

            // 处理返回结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_settle') {
                    // 重复退款请求
                    $this->logger->warning('RSG重复退款请求（Lua检测）', ['session_id' => $orderNo]);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'Balance' => round((float)$result['balance'], 2),
                        'Amount' => 0
                    ]);
                }
            }

            $this->logger->info('RSG打鱼机退款成功（Lua原子）', [
                'session_id' => $orderNo,
                'refund_amount' => $refundAmount,
                'balance_after' => $result['balance'],
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => round((float)$result['balance'], 2),
                'Amount' => round((float)$refundAmount, 2)
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG打鱼机退款异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '退款异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }


    /**
     * 检查交易
     * @param Request $request
     * @return Response
     */
    public function checkTransaction(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG检查交易请求', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $result = $this->service->checkTransaction($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $result);
        } catch (Throwable $e) {
            $this->logger->error('RSG检查交易异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '检查交易异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
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
            'ErrorCode' => self::API_CODE_SUCCESS, // 使用业务状态码常量
            'ErrorMessage' => $message ?: self::API_CODE_MAP[self::API_CODE_SUCCESS],
            'Timestamp' => $timestamp,
            'Data' => $data,
        ];

        $reqBase64 = $this->service->encrypt(json_encode($responseData));

        return (new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
            $reqBase64
        ));
    }

    /**
     * 失败响应方法
     *
     * @param int $code 错误码
     * @param string|null $message 自定义错误信息
     * @param array $data 额外数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function error(int $code, ?string $message = null, array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'ErrorCode' => $code, // 使用业务状态码常量
            'ErrorMessage' => $message ?: (self::API_CODE_MAP[$code] ?? '未知错误'),
            'Timestamp' => time(),
            'Data' => null,
        ];

        $reqBase64 = $this->service->encrypt(json_encode($responseData));

        return (new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
            $reqBase64
        ));
    }
}