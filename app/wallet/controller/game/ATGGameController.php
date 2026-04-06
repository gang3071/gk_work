<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * ATG电子平台
 */
class ATGGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_FAIL = 1;
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
        self::API_CODE_SUCCESS => 'success',
        self::API_CODE_FAIL => 'fail',
        self::API_CODE_INVALID_PARAM => '無效參數',
        self::API_CODE_DECRYPT_ERROR => '解密異常',
        self::API_CODE_PLAYER_NOT_EXIST => '此玩家帳戶不存在',
        self::API_CODE_DUPLICATE_ORDER => '重複的订单',
        self::API_CODE_DUPLICATE_TRANSACTION => '重複的订单',
        self::API_CODE_INSUFFICIENT_BALANCE => '餘額不足',
        self::API_CODE_ORDER_SETTLED => '此订单已被結算',
        self::API_CODE_ORDER_NOT_EXIST => '此订单不存在',
        self::API_CODE_ORDER_CANCELLED => '此订单已被取消',
        self::API_CODE_DENY_PREPAY => '拒絕預扣，其他原因',
        self::API_CODE_TRANSACTION_NOT_FOUND => '找不到交易結果',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $log;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_ATG);
        $this->log = Log::channel('atg_server');
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
            $this->log->info('atg余额查询记录', ['params' => $params]);
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('atg余额查询解密', ['data' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->balance();
            return $this->success(['balance' => $balance]);
        } catch (Exception $e) {
            Log::error('ATG balance failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }

    /**
     * 下注（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     * @throws Exception|Throwable
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('ATG下注请求（Lua原子）', ['order_no' => $data['betId'] ?? '']);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['betId'] ?? '');
            $bet = $data['amount'];

            // ========== 核心：Lua 原子下注 ==========
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $bet,
                'game_code' => $data['gameCode'] ?? '',
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

            $result = RedisLuaScripts::atomicBet($player->id, 'ATG', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'ATG', $player->id, $luaParams);

            // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步）
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveBet('ATG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $bet,
                    'game_code' => $data['gameCode'] ?? '',
                    'original_data' => $data,
                ]);
            }

            // 游戏交互日志
            logGameInteraction('ATG', 'bet', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);


            // 处理返回结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    // 幂等性：重复订单返回当前余额
                    $this->log->info('ATG重复订单（Lua检测）', [
                        'order_no' => $orderNo,
                        'balance' => $result['balance'],
                    ]);
                    return $this->success(['balanceOld' => $result['balance'], 'balance' => $result['balance']]);

                } elseif ($result['error'] === 'insufficient_balance') {
                    // 余额不足
                    $this->log->warning('ATG余额不足（Lua检测）', [
                        'order_no' => $orderNo,
                        'bet' => $bet,
                        'balance' => $result['balance'],
                    ]);
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                }
            }

            $this->log->info('ATG下注成功（Lua原子）', [
                'order_no' => $orderNo,
                'balance_before' => $result['old_balance'],
                'balance_after' => $result['balance'],
            ]);
            return $this->success(['balanceOld' => $result['old_balance'], 'balance' => $result['balance']]);
        } catch (Exception $e) {
            $this->log->error('ATG bet failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }

    /**
     * 結算（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('ATG结算请求（Lua原子）', ['order_no' => $data['betId'] ?? '']);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['betId'] ?? '');
            $winAmount = $data['amount'] ?? 0;

            // ✅ 问题3修复：使用降级方案获取下注金额（传入 platform_id 优化性能）
            $betAmount = getBetAmountWithFallback('ATG', $orderNo, $player->id, $this->service->platform->id);

            // 计算 diff = win - bet
            $diff = bcsub($winAmount, $betAmount, 2);

            // ========== 核心：Lua 原子结算 ==========
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => max($winAmount, 0),
                'diff' => $diff,  // ✅ 修正：diff = win - bet
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

            $result = RedisLuaScripts::atomicSettle($player->id, 'ATG', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'ATG', $player->id, $luaParams);

            // 保存结算记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveSettle('ATG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => max($winAmount, 0),
                    'diff' => $diff,
                    'game_code' => $data['gameCode'] ?? '',
                    'original_data' => $data,
                ]);
            }

            // 处理返回结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_settle') {
                    // 幂等性：重复结算返回当前余额
                    $this->log->info('ATG重复结算（Lua检测）', [
                        'order_no' => $orderNo,
                        'balance' => $result['balance'],
                    ]);
                    return $this->success(['balanceOld' => $result['balance'], 'balance' => $result['balance']]);
                }
            }

            $this->log->info('ATG结算成功（Lua原子）', [
                'order_no' => $orderNo,
                'win_amount' => $winAmount,
                'balance_before' => $result['old_balance'],
                'balance_after' => $result['balance'],
            ]);

            return $this->success(['balanceOld' => $result['old_balance'], 'balance' => $result['balance']]);
        } catch (Exception $e) {
            $this->log->error('ATG betResult failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }


    /**
     * 退款（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('ATG退款请求（Lua原子）', ['order_no' => $data['betId'] ?? '']);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['betId'] ?? '');
            $refundAmount = $data['amount'] ?? 0;

            // ========== 核心：Lua 原子退款 ==========
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

            $result = RedisLuaScripts::atomicCancel($player->id, 'ATG', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'ATG', $player->id, $luaParams);

            // 保存取消记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveCancel('ATG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'original_data' => $data,
                ]);
            }

            // 处理返回结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_cancel') {
                    // 幂等性：重复退款返回当前余额
                    $this->log->info('ATG重复退款（Lua检测）', [
                        'order_no' => $orderNo,
                        'balance' => $result['balance'],
                    ]);
                    return $this->success(['balanceOld' => $result['balance'], 'balance' => $result['balance']]);
                }
            }

            $this->log->info('ATG退款成功（Lua原子）', [
                'order_no' => $orderNo,
                'refund_amount' => $refundAmount,
                'balance_before' => $result['old_balance'],
                'balance_after' => $result['balance'],
            ]);

            return $this->success(['balanceOld' => $result['old_balance'], 'balance' => $result['balance']]);
        } catch (Exception $e) {
            Log::error('ATG refund failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '退款异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }

    /**
     * 成功响应方法
     *
     * @param array $data 响应数据
     * @return Response
     */
    public function success(array $data = []): Response
    {
        $responseData = [
            'status' => self::API_CODE_MAP[self::API_CODE_SUCCESS],
            'data' => $data,
        ];

        return (new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($responseData, JSON_UNESCAPED_UNICODE)
        ));
    }

    /**
     * 失败响应方法
     *
     * @param string $code
     * @return Response
     */
    public function error(string $code): Response
    {
        $responseData = [
            'status' => self::API_CODE_MAP[self::API_CODE_FAIL],
            'data' => [
                'message' => self::API_CODE_MAP[$code]
            ],
        ];

        return (new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($responseData, JSON_UNESCAPED_UNICODE)
        ));
    }
}