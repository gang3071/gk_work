<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\KTServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
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

            $this->logger->info('KT下注请求（Lua原子）', ['params' => $params, 'get' => $hash]);

            $this->service->verifyToken($params, $hash);

            $this->service->player = Player::query()->where('uuid', $params['Username'])->first();
            $player = $this->service->player;

            $orderNo = $params['MainTxID'];
            $bet = $params['Bet'];
            $takeWin = $params['TakeWin'] ?? 0;

            // Lua 原子下注
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $bet,
                'game_code' => $params['GameCode'] ?? '',
                'transaction_type' => TransactionType::BET,
                'original_data' => $params,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $result = RedisLuaScripts::atomicBet($player->id, 'KT', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'KT', $player->id, $luaParams);

            // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步）
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveBet('KT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $bet,
                    'game_code' => $params['GameID'] ?? '',
                    'original_data' => $params,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            // 游戏交互日志
            logGameInteraction('KT', 'bet', $params, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);

            // 处理下注结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    $this->logger->info('KT下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'Balance' => (float)$result['balance'],
                    ]);
                } elseif ($result['error'] === 'insufficient_balance') {
                    return $this->error(self::API_CODE_AMOUNT_OVER_BALANCE);
                }
            }

            $finalBalance = $result['balance'];

            // TakeWin=1 表示立即结算
            if ($takeWin == 1) {
                $winAmount = $params['Win'] ?? 0;

                // ✅ 问题3修复：使用降级方案获取下注金额（传入 platform_id 优化性能）
                $betAmount = getBetAmountWithFallback('KT', $orderNo, $player->id, $this->service->platform->id);

                // 计算 diff = win - bet
                $diff = bcsub($winAmount, $betAmount, 2);

                // Lua 原子结算（使用独立的结算订单号避免冲突）
                $settleLuaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $winAmount,
                    'diff' => $diff,  // ✅ 修正：diff = win - bet
                    'transaction_type' => TransactionType::SETTLE,
                    'original_data' => $params,
                ];

                // 参数验证
                validateLuaScriptParams($settleLuaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric', 'min:0'],
                    'diff' => ['required', 'numeric'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $settleResult = RedisLuaScripts::atomicSettle($player->id, 'KT', $settleLuaParams);

                // 审计日志
                logLuaScriptCall('settle', 'KT', $player->id, $settleLuaParams);

                // 保存结算记录到 Redis
                if ($settleResult['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveSettle('KT', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $winAmount,
                        'diff' => $diff,
                        'game_code' => $params['GameID'] ?? '',
                        'original_data' => $params,
                        'balance_before' => $settleResult['old_balance'] ?? 0,
                        'balance_after' => $settleResult['balance'],
                    ]);
                    $finalBalance = $settleResult['balance'];
                } elseif ($settleResult['error'] === 'duplicate_order') {
                    $this->logger->info('KT立即结算重复请求（Lua检测）', ['order_no' => $orderNo]);
                    $finalBalance = $settleResult['balance'];
                }
            }

            $this->logger->info('KT下注成功（Lua原子）', [
                'order_no' => $orderNo,
                'take_win' => $takeWin,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$finalBalance
            ]);
        } catch (Exception $e) {
            // 游戏交互日志
            logGameInteraction('KT', 'bet', $params ?? [], [
                'error' => $e->getMessage(),
                'ok' => 0,
            ]);

            Log::error('KT bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
        }
    }

    /**
     * 結算（Lua 原子操作版本 - 延迟结算）
     * @param $params
     * @return Response
     */
    public function betResult($params)
    {
        try {
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('KT延迟结算请求（Lua原子）', ['data' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['MainTxID'] ?? '');
            $winAmount = $data['Win'] ?? 0;

            // ✅ 问题3修复：使用降级方案获取下注金额（传入 platform_id 优化性能）
            $betAmount = getBetAmountWithFallback('KT', $orderNo, $player->id, $this->service->platform->id);

            // 计算 diff = win - bet
            $diff = bcsub($winAmount, $betAmount, 2);

            // Lua 原子结算
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $winAmount,
                'diff' => $diff,  // ✅ 修正：diff = win - bet
                'transaction_type' => TransactionType::SETTLE,
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

            $result = RedisLuaScripts::atomicSettle($player->id, 'KT', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'KT', $player->id, $luaParams);

            // 保存结算记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveSettle('KT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $winAmount,
                    'diff' => $diff,
                    'game_code' => $data['GameID'] ?? '',
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            // 游戏交互日志
            logGameInteraction('KT', 'settle', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
                'win_amount' => $winAmount,
            ]);

            // 处理返回结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_settle') {
                $this->logger->info('KT延迟结算重复请求（Lua检测）', ['order_no' => $orderNo]);
            }

            $this->logger->info('KT延迟结算成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$result['balance']
            ]);

        } catch (Exception $e) {
            // 游戏交互日志
            logGameInteraction('KT', 'settle', $data ?? [], [
                'error' => $e->getMessage(),
                'ok' => 0,
            ]);

            Log::error('KT betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '延迟结算异常', $e, ['params' => $params]);
            return $this->error(self::API_CODE_OTHER_ERROR);
        }
    }

    /**
     * 重新結算
     * @param Request $request
     * @return Response
     */
    public function reBetResult(Request $request): Response
    {
        try {
            $params = $request->post();

            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('rsg_live余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->reBetResulet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['bet_sn' => $data['bet_sn'], 'balance' => $balance]);
        } catch (Exception $e) {
            Log::error('KT reBetResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '重新结算异常', $e, ['params' => $request->post()]);
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
            $refundAmount = $params['Amount'] ?? 0;

            // Lua 原子取消
            $luaParams = [
                'order_no' => $orderNo,
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
                $this->logger->info('KT取消投注重复请求（Lua检测）', ['order_no' => $orderNo]);
            }

            $this->logger->info('KT取消投注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$result['balance']
            ]);
        } catch (Exception $e) {
            Log::error('KT cancelBet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '取消投注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
        }
    }

    /**
     * 打鱼机预扣金额
     * @param Request $request
     * @return Response
     */
    /**
     * 退款（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
        try {
            $params = $request->post();

            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('KT打鱼退款请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['MainTxID'] ?? $data['TxID'] ?? '');
            $refundAmount = $data['Amount'] ?? 0;

            // Lua 原子退款
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
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            // 处理结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_cancel') {
                $this->logger->info('KT退款重复请求（Lua检测）', ['order_no' => $orderNo]);
            }

            $this->logger->info('KT退款成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$result['balance']
            ]);
        } catch (Exception $e) {
            Log::error('KT refund failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '退款异常', $e, ['params' => $request->post()]);
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
    public function error(string $code, ?string $message = null, array $data = [], int $httpCode = 400): Response
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