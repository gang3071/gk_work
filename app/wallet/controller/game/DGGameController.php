<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\service\game\DGServiceInterface;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
use support\Log;
use support\Request;
use support\Response;

/**
 * DG
 */
class DGGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_DECRYPT_ERROR = 1;
    public const API_CODE_INSUFFICIENT_BALANCE = 120;
    public const API_CODE_DUPLICATE_TRANSACTION = 323;


    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'Success',
        self::API_CODE_DECRYPT_ERROR => 'Parameter Error',
        self::API_CODE_DUPLICATE_TRANSACTION => 'Used serial numbers for Transfer',
        self::API_CODE_INSUFFICIENT_BALANCE => 'Insufficient balance',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    /**
     * @var DGServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_DG);
    }

    /**
     * 获取玩家钱包
     * @param Request $request
     * @param string $agentName
     * @return Response
     */
    public function balance(Request $request, string $agentName): Response
    {
        try {
            $params = $request->post();

            Log::channel('dg_server')->info('dg余额查询记录', ['params' => $params, 'name' => $agentName]);
            $this->service->verifyToken($params, $agentName);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $this->service->decrypt($params);

            $balance = $this->service->balance();

            $return = [
                'member' => [
                    'username' => $params['member']['username'],
                    'balance' => $balance,
                ]
            ];

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (\Exception $e) {
            Log::error('DG balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('DG', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DECRYPT_ERROR);
        }
    }

    /**
     * 下注（Lua原子操作）
     * @param Request $request
     * @param string $agentName
     * @return Response
     */
    public function bet(Request $request, string $agentName): Response
    {
        try {
            $params = $request->post();

            Log::channel('dg_server')->info('DG下注请求（Lua原子）', ['params' => $params, 'name' => $agentName]);
            $this->service->verifyToken($params, $agentName);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $this->service->decrypt($params);

            $player = $this->service->player;
            $type = $params['type'];
            $orderNo = (string)($params['ticketId'] ?? '');
            $amount = abs($params['member']['amount']);
            $detail = json_decode($params['detail'], true);

            // 获取操作前余额（DG 特性：返回操作前余额）
            $beforeBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

            //转账类型(1:下注 2:派彩 3:补单 5:红包 6:小费)
            if (in_array($type, [2, 5])) {
                // ✅ 修复：区分派彩和红包的 diff 计算
                if ($type == 2) {
                    // type=2 派彩：需要计算 diff = win - bet
                    $betAmount = getBetAmountWithFallback('DG', $orderNo, $player->id, $this->service->platform->id);
                    $diff = bcsub($amount, $betAmount, 2);
                } else {
                    // type=5 红包：额外奖励，diff = amount
                    $diff = $amount;
                }

                // type=2:派彩 type=5:红包 → Lua 原子结算
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $amount,
                    'diff' => $diff,  // ✅ 修正：根据类型计算 diff
                    'game_code' => $detail['gameId'] ?? '',
                    'transaction_type' => $type == 5 ? TransactionType::SETTLE_REWARD : TransactionType::SETTLE,
                    'original_data' => $params,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric'],
                    'diff' => ['required', 'numeric'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $result = RedisLuaScripts::atomicSettle($player->id, 'DG', $luaParams);

                // 审计日志
                logLuaScriptCall('settle', 'DG', $player->id, $luaParams);

                // ✅ 修复：清理重复日志，只记录正确的结算日志
                logGameInteraction('DG', 'settle', $params, [
                    'ok' => $result['ok'],
                    'balance' => $result['balance'],
                    'type' => $type,  // 添加 type 字段便于区分
                ]);


                if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                    Log::channel('dg_server')->info('DG结算重复请求（Lua检测）', ['order_no' => $orderNo, 'type' => $type]);
                }

                // 保存结算记录到 Redis
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveSettle('DG', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $amount,
                        'diff' => $diff,
                        'original_data' => $params,
                    ]);
                }

            } else {
                // type=1:下注 type=3:补单 type=6:小费 → Lua 原子下注
                if ($amount > 0) {
                    $luaParams = [
                        'order_no' => $orderNo,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $amount,
                        'game_code' => $detail['gameId'] ?? '',
                        'transaction_type' => $type == 3 ? TransactionType::BET_ADJUST : TransactionType::BET,
                        'original_data' => $params,
                    ];

                    // 参数验证
                    validateLuaScriptParams($luaParams, [
                        'order_no' => ['required', 'string'],
                        'amount' => ['required', 'numeric', 'min:0'],
                        'platform_id' => ['required', 'integer'],
                        'transaction_type' => ['required', 'string'],
                    ], 'atomicBet');

                    $result = RedisLuaScripts::atomicBet($player->id, 'DG', $luaParams);

                    // 审计日志
                    logLuaScriptCall('bet', 'DG', $player->id, $luaParams);

                    if ($result['ok'] === 0) {
                        if ($result['error'] === 'duplicate_order') {
                            Log::channel('dg_server')->info('DG下注重复请求（Lua检测）', ['order_no' => $orderNo, 'type' => $type]);
                        } elseif ($result['error'] === 'insufficient_balance') {
                            return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                        }
                    }

                    // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步）
                    if ($result['ok'] === 1) {
                        \app\service\GameRecordCacheService::saveBet('DG', [
                            'order_no' => $orderNo,
                            'player_id' => $player->id,
                            'platform_id' => $this->service->platform->id,
                            'amount' => $amount,
                            'game_code' => $detail['gameId'] ?? '',
                            'original_data' => $params,
                        ]);
                    }
                } else {
                    // amount = 0 的特殊情况，直接返回
                    $result = ['ok' => 1];
                }
            }

            Log::channel('dg_server')->info('DG操作成功（Lua原子）', [
                'order_no' => $orderNo,
                'type' => $type,
            ]);

            $return = [
                'member' => [
                    'username' => $params['member']['username'],
                    'balance' => (float)$beforeBalance, // DG 返回操作前余额
                    'amount' => $params['member']['amount'],
                ]
            ];

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (\Exception $e) {
            Log::error('DG bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('DG', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DECRYPT_ERROR);
        }
    }

    /**
     * 結算（同步降级备用方法）
     * @param $data
     * @return Response
     */
    public function betResult($data): Response
    {
        try {
            Log::channel('dg_server')->info('dg结算记录（同步降级）', ['params' => $data]);

            $return = $this->service->betResulet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (\Exception $e) {
            Log::error('DG betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('DG', '结算异常', $e, ['params' => $data]);
            return $this->error(self::API_CODE_DECRYPT_ERROR);
        }
    }

    /**
     * 通知接口 (取消投注、补偿等) - Lua原子操作
     * @param Request $request
     * @param string $agentName
     * @return Response
     */
    public function inform(Request $request, string $agentName): Response
    {
        try {
            $params = $request->post();

            Log::channel('dg_server')->info('dg通知记录（Lua原子）', ['params' => $params, 'name' => $agentName]);
            $this->service->verifyToken($params, $agentName);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $this->service->decrypt($params);

            $player = $this->service->player;
            $type = $params['type'];
            $orderNo = (string)($params['ticketId'] ?? '');
            $amount = abs($params['member']['amount']);
            $detail = json_decode($params['detail'], true);

            // 获取操作前余额（DG 特性：返回操作前余额）
            $beforeBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

            // 根据 type 分流处理
            if ($type == 4) {
                // type=4: 取消投注 → Lua 原子取消
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $amount,
                    'transaction_type' => TransactionType::CANCEL,  // type=4 取消投注
                    'game_code' => $detail['gameId'] ?? '',
                    'original_data' => $params,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'refund_amount' => ['required', 'numeric', 'min:0'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicCancel');

                $result = RedisLuaScripts::atomicCancel($player->id, 'DG', $luaParams);

                // 审计日志
                logLuaScriptCall('cancel', 'DG', $player->id, $luaParams);

                if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                    Log::channel('dg_server')->info('DG取消重复请求（Lua检测）', ['order_no' => $orderNo]);
                }

                // 保存取消记录到 Redis
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveCancel('DG', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'refund_amount' => $amount,
                        'original_data' => $params,
                    ]);
                }

            } elseif ($type == 7) {
                // type=7: 补偿 → Lua 原子结算
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $amount,
                    'diff' => $amount,
                    'transaction_type' => TransactionType::SETTLE_REWARD,  // type=7 补偿标记为奖励
                    'game_code' => $detail['gameId'] ?? '',
                    'original_data' => $params,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric'],
                    'diff' => ['required', 'numeric'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $result = RedisLuaScripts::atomicSettle($player->id, 'DG', $luaParams);

                // 审计日志
                logLuaScriptCall('settle', 'DG', $player->id, $luaParams);

                if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                    Log::channel('dg_server')->info('DG补偿重复请求（Lua检测）', ['order_no' => $orderNo]);
                }

                // 保存补偿记录到 Redis
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveSettle('DG', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $amount,
                        'diff' => $amount,
                        'original_data' => $params,
                    ]);
                }

            } else {
                // 未知类型，记录警告
                Log::channel('dg_server')->warning('DG inform未知类型', ['type' => $type, 'data' => $params]);
            }

            Log::channel('dg_server')->info('DG通知成功（Lua原子）', [
                'order_no' => $orderNo,
                'type' => $type,
            ]);

            $return = [
                'member' => [
                    'username' => $params['member']['username'],
                    'balance' => (float)$beforeBalance, // DG 返回操作前余额
                    'amount' => $params['member']['amount'],
                ]
            ];

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (\Exception $e) {
            Log::error('DG inform failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('DG', '通知接口异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DECRYPT_ERROR);
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
        $responseData = [
            'codeId' => self::API_CODE_SUCCESS, // 使用业务状态码常量
        ];

        $reqBase64 = json_encode(array_merge($responseData, $data));

        Log::channel('dg_server')->info('dg返回记录', ['response' => array_merge($responseData, $data)]);

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            $reqBase64
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
            'codeId' => $code, // 使用业务状态码常量
        ];

        $reqBase64 = json_encode($responseData);

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            $reqBase64
        ));
    }
}