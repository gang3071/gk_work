<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\KTServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
use app\service\WalletService;
use Exception;
use support\Log;
use support\Request;
use support\Response;

/**
 * O8平台
 */
class KTGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_TRANSACTIONID_DUPLICATE = 1;
    public const API_CODE_AMOUNT_OVER_BALANCE = 2;
    public const API_CODE_TOKEN_DOES_NOT_EXIST = 3;
    public const API_CODE_OTHER_ERROR = 99;

    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'Success',
        self::API_CODE_TRANSACTIONID_DUPLICATE => '交易識別碼重複',
        self::API_CODE_AMOUNT_OVER_BALANCE => '餘額不足',
        self::API_CODE_TOKEN_DOES_NOT_EXIST => '认证令牌无效或已过期',
        self::API_CODE_OTHER_ERROR => '其他错误',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    /**
     * @var KTServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_KT);
        $this->logger = Log::channel('kt_server');
    }


    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function auth(Request $request): Response
    {
        try {
            $params = $request->post();
            $hash = $request->get('Hash');

            $this->service->verifyToken($params, $hash);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            if (!isset($params['Token'])) {
                return $this->error(self::API_CODE_OTHER_ERROR, '缺少Token参数');
            }

            $this->service->player = Player::query()->where('uuid', $params['Token'])->first();

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Username' => $this->service->player->uuid,
                'Currency' => 'TWD',
                'Balance' => $this->service->balance(),
            ]);
        } catch (Exception $e) {
            Log::error('KT auth failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '认证异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_TOKEN_DOES_NOT_EXIST);
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
            $hash = $request->get('Hash');

            $this->service->verifyToken($params, $hash);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            if (!isset($params['Username'])) {
                return $this->error(self::API_CODE_OTHER_ERROR, '缺少Username参数');
            }

            $this->service->player = Player::query()->where('uuid', $params['Username'])->first();

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => $this->service->balance(),
            ]);
        } catch (Exception $e) {
            Log::error('KT balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
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
            $hash = $request->get('Hash');

            $this->logger->info('KT交易请求', ['params' => $params, 'hash' => $hash]);

            $this->service->verifyToken($params, $hash);

            // 必填字段验证
            $requiredFields = ['Username', 'Currency', 'GameID', 'MainTxID', 'SubTxID', 'Bet', 'Win', 'BetType', 'TakeWin', 'Reserved'];
            foreach ($requiredFields as $field) {
                if (!isset($params[$field])) {
                    $this->logger->error('KT缺少必填字段', ['field' => $field]);
                    return $this->error(self::API_CODE_OTHER_ERROR, "缺少必填字段: {$field}");
                }
            }

            $this->service->player = Player::query()->where('uuid', $params['Username'])->first();
            $player = $this->service->player;

            if (!$player) {
                $this->logger->error('KT玩家不存在', ['username' => $params['Username']]);
                return $this->error(self::API_CODE_TOKEN_DOES_NOT_EXIST, '玩家不存在');
            }

            // 判断设备是否爆机
            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->error($this->service->error);
            }

            $mainTxID = $params['MainTxID'];
            $subTxID = $params['SubTxID'];
            $bet = $params['Bet'];
            $win = $params['Win'];
            $reserved = $params['Reserved'];
            $takeWin = $params['TakeWin'];

            // ✅ 订单号规则：SubTxID=0（单次游戏）不加后缀，SubTxID>0（多次游戏）加后缀
            $orderNo = ($subTxID == 0) ? $mainTxID : $mainTxID . '_' . $subTxID;

            // ✅ KT核心逻辑：余额变化 = Win - Bet + Reserved
            $balanceChange = bcadd(bcsub($win, $bet, 2), $reserved, 2);

            $this->logger->info('KT交易计算', [
                'order_no' => $orderNo,
                'bet' => $bet,
                'win' => $win,
                'reserved' => $reserved,
                'balance_change' => $balanceChange,
                'take_win' => $takeWin,
            ]);

            // ✅ 使用Lua脚本保证原子性，然后用saveBetForKT统一记录格式
            $finalBalance = null;
            $result = null;

            if (bccomp($balanceChange, '0', 2) < 0) {
                // 余额减少：使用atomicBet
                $deductAmount = bcmul($balanceChange, '-1', 2);

                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $deductAmount,
                    'game_code' => $params['GameID'] ?? '',
                    'transaction_type' => TransactionType::BET,
                    'original_data' => $params,
                ];

                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric', 'min:0'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicBet');

                $result = RedisLuaScripts::atomicBet($player->id, 'KT', $luaParams);
                logLuaScriptCall('bet', 'KT', $player->id, $luaParams);

                if ($result['ok'] === 0) {
                    if ($result['error'] === 'duplicate_order') {
                        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                            'Balance' => (float)$result['balance']
                        ]);
                    } elseif ($result['error'] === 'insufficient_balance') {
                        return $this->error(self::API_CODE_AMOUNT_OVER_BALANCE);
                    }
                    return $this->error(self::API_CODE_OTHER_ERROR, $result['error'] ?? '操作失败');
                }

                $finalBalance = $result['balance'];

            } elseif (bccomp($balanceChange, '0', 2) > 0) {
                // 余额增加：使用atomicSettle（会创建settle记录，稍后由saveBetForKT清理）
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $balanceChange,
                    'diff' => $balanceChange,
                    'transaction_type' => TransactionType::SETTLE,
                    'original_data' => $params,
                ];

                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric', 'min:0'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $result = RedisLuaScripts::atomicSettle($player->id, 'KT', $luaParams);
                logLuaScriptCall('settle', 'KT', $player->id, $luaParams);

                if ($result['ok'] === 0) {
                    if ($result['error'] === 'duplicate_order' || $result['error'] === 'duplicate_settle') {
                        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                            'Balance' => (float)$result['balance']
                        ]);
                    }
                    return $this->error(self::API_CODE_OTHER_ERROR, $result['error'] ?? '操作失败');
                }

                WalletService::checkMachineCrashAfterTransaction(
                    $player->id,
                    $result['balance'],
                    $result['old_balance'] ?? null
                );

                $finalBalance = $result['balance'];

            } else {
                // 余额无变化：使用幂等性锁
                $lockKey = "order:kt:lock:{$orderNo}";
                $redis = \support\Redis::connection();

                $lockSet = $redis->setnx($lockKey, '1');
                if (!$lockSet) {
                    // 重复请求
                    $balance = WalletService::getBalance($player->id);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'Balance' => (float)$balance
                    ]);
                }
                $redis->expire($lockKey, 300);

                $finalBalance = WalletService::getBalance($player->id);
                $result = ['ok' => 1, 'balance' => $finalBalance, 'old_balance' => $finalBalance];
            }

            // ✅ KT专用：统一使用 saveBetForKT 保存（绕过Lua创建的settle记录）
            $recordData = [
                'order_no' => $orderNo,
                'player_id' => $player->id,
                'platform_id' => $this->service->platform->id,
                'amount' => $bet,  // 数据库bet字段 = KT的Bet（押注金额）
                'win' => $win,     // 数据库win字段 = KT的Win（派彩金额）
                'diff' => $win,    // 数据库diff字段 = KT的Win（派彩金额，不扣除Bet）
                'game_code' => $params['GameID'] ?? '',
                'original_data' => $params,
                'balance_before' => $result['old_balance'] ?? $finalBalance,
                'balance_after' => $result['balance'] ?? $finalBalance,
            ];

            // ✅ 先保存/更新bet记录（覆盖Lua可能创建的settle记录）
            \app\service\GameRecordCacheService::saveBetForKT('KT', $recordData);

            if ($takeWin == 1) {
                // TakeWin=1：批量结算所有相同MainTxID的子订单
                $this->logger->info('KT开始批量结算', ['main_tx_id' => $mainTxID, 'order_no' => $orderNo]);
                $settledCount = \app\service\GameRecordCacheService::settleAllSubOrdersForKT('KT', $mainTxID, $recordData);

                $this->logger->info('KT批量结算完成', [
                    'main_tx_id' => $mainTxID,
                    'settled_count' => $settledCount,
                    'current_order' => $orderNo,
                ]);
            } else {
                $this->logger->info('KT保存未结算订单完成', ['order_no' => $orderNo]);
            }

            logGameInteraction('KT', 'bet', $params, [
                'ok' => 1,
                'balance' => $finalBalance,
                'order_no' => $orderNo,
            ]);

            $this->logger->info('KT交易成功', [
                'order_no' => $orderNo,
                'final_balance' => $finalBalance,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$finalBalance
            ]);

        } catch (Exception $e) {
            $this->logger->error('KT交易异常', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'order_no' => $orderNo ?? 'unknown',
                'params' => $params ?? []
            ]);

            logGameInteraction('KT', 'bet', $params ?? [], [
                'error' => $e->getMessage(),
                'ok' => 0,
            ]);

            Log::error('KT交易失败', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '交易异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
        }
    }

    /**
     * 投注与结算取消（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->post();
            $hash = $request->get('Hash');

            $this->logger->info('KT取消投注请求（Lua原子）', ['params' => $params, 'get' => $hash]);

            $this->service->verifyToken($params, $hash);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $this->service->player = \app\model\Player::query()->where('uuid', $params['Username'])->first();
            $player = $this->service->player;

            $orderNo = $params['MainTxID'];
            $subTxID = (string)($data['SubTxID'] ?? '');
            $refundAmount = $params['Amount'] ?? 0;

            // Lua 原子取消
            $luaParams = [
                'order_no' => $orderNo . '_' . $subTxID,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL,
                'original_data' => $params,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'KT', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'KT', $player->id, $luaParams);

            // 保存取消记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveCancel('KT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'original_data' => $params,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            // 处理结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                $this->logger->info('KT取消投注重复请求（Lua检测）', ['order_no' => $orderNo . '_' . $subTxID]);
            }

            $this->logger->info('KT取消投注成功（Lua原子）', ['order_no' => $orderNo . '_' . $subTxID]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => round((float)$result['balance'], 2)
            ]);
        } catch (Exception $e) {
            Log::error('KT cancelBet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '取消投注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
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
        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($data)
        ));
    }

    /**
     * 失败响应方法
     *
     * @param string $code 错误码
     * @param string|null $message 自定义错误信息
     * @param array $data 额外数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function error(string $code, ?string $message = null, array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'ErrorCode' => $code,
            'Message' => $message ?: (self::API_CODE_MAP[$code] ?? '未知错误'),
        ];

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
        ));
    }
}