<?php

namespace app\wallet\controller\game;

use app\service\game\DGServiceInterface;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
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
     * 下注（Redis 缓存版）
     * @param Request $request
     * @param string $agentName
     * @return Response
     */
    public function bet(Request $request, string $agentName): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            Log::channel('dg_server')->info('DG下注请求（Redis缓存）', ['params' => $params, 'name' => $agentName]);
            $this->service->verifyToken($params, $agentName);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $this->service->decrypt($params);

            $player = $this->service->player;
            $type = $params['type'];
            $orderNo = $params['ticketId'];
            $amount = abs($params['member']['amount']);
            $detail = json_decode($params['detail'], true);

            // 幂等性检查
            $lockKey = "order:dg:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复请求
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'member' => [
                        'username' => $params['member']['username'],
                        'balance' => (float)$balance,
                        'amount' => $params['member']['amount'],
                    ]
                ]);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                $beforeBalance = $currentBalance; // DG 返回操作前余额
                $newBalance = $currentBalance;

                //转账类型(1:下注 2:派彩 3:补单 5:红包 6:小费)
                if (in_array($type, [2, 5])) {
                    // type=2:派彩 type=5:红包 → 结算
                    \app\service\GameRecordCacheService::saveSettle('DG', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $amount,
                        'diff' => $amount,
                        'game_code' => $detail['gameId'] ?? '',
                        'settle_type' => $type == 5 ? 'reward' : 'settle',
                        'original_data' => $params,
                    ]);

                    if ($amount > 0) {
                        $newBalance = bcadd($currentBalance, $amount, 2);
                        \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                    }

                } else {
                    // type=1:下注 type=3:补单 type=6:小费 → 下注
                    if ($amount > 0) {
                        // 余额预检查
                        if ($currentBalance < $amount) {
                            \support\Redis::del($lockKey);
                            return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                        }

                        \app\service\GameRecordCacheService::saveBet('DG', [
                            'order_no' => $orderNo,
                            'player_id' => $player->id,
                            'platform_id' => $this->service->platform->id,
                            'amount' => $amount,
                            'game_code' => $detail['gameId'] ?? '',
                            'bet_type' => $type == 3 ? 'adjust' : 'bet',
                            'original_data' => $params,
                        ]);

                        $newBalance = bcsub($currentBalance, $amount, 2);
                        \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                    }
                }

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('dg_server')->info('DG操作成功（Redis缓存）', [
                'order_no' => $orderNo,
                'type' => $type,
                'elapsed_ms' => round($elapsed, 2),
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
     * 通知接口 (取消投注、补偿等) - Redis 缓存版
     * @param Request $request
     * @param string $agentName
     * @return Response
     */
    public function inform(Request $request, string $agentName): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            Log::channel('dg_server')->info('dg通知记录（Redis缓存）', ['params' => $params, 'name' => $agentName]);
            $this->service->verifyToken($params, $agentName);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $this->service->decrypt($params);

            $player = $this->service->player;
            $type = $params['type'];
            $orderNo = $params['ticketId'];
            $amount = abs($params['member']['amount']);
            $detail = json_decode($params['detail'], true);

            // 根据 type 分流处理
            if ($type == 4) {
                // type=4: 取消投注
                $lockKey = "order:cancel:lock:{$orderNo}";
                if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                    // 重复请求
                    $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'member' => [
                            'username' => $params['member']['username'],
                            'balance' => (float)$balance,
                            'amount' => $params['member']['amount'],
                        ]
                    ]);
                }

                try {
                    // 获取当前余额
                    $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                    $beforeBalance = $currentBalance;

                    // 写入取消记录
                    \app\service\GameRecordCacheService::saveCancel('DG', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'cancel_type' => 'cancel',  // type=4 取消投注
                        'game_code' => $detail['gameId'] ?? '',
                        'original_data' => $params,
                    ]);

                    // 更新余额缓存（退回下注金额）
                    $newBalance = bcadd($currentBalance, $amount, 2);
                    \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

                } catch (\Throwable $e) {
                    \support\Redis::del($lockKey);
                    throw $e;
                }

                $return = [
                    'member' => [
                        'username' => $params['member']['username'],
                        'balance' => (float)$beforeBalance, // DG 返回操作前余额
                        'amount' => $params['member']['amount'],
                    ]
                ];

            } elseif ($type == 7) {
                // type=7: 补偿 → 派彩处理
                $lockKey = "order:settle:lock:{$orderNo}";
                if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                    // 重复请求
                    $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'member' => [
                            'username' => $params['member']['username'],
                            'balance' => (float)$balance,
                            'amount' => $params['member']['amount'],
                        ]
                    ]);
                }

                try {
                    // 获取当前余额
                    $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                    $beforeBalance = $currentBalance;

                    // 写入补偿记录
                    \app\service\GameRecordCacheService::saveSettle('DG', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $amount,
                        'diff' => $amount,
                        'settle_type' => 'reward',  // type=7 补偿标记为奖励
                        'game_code' => $detail['gameId'] ?? '',
                        'original_data' => $params,
                    ]);

                    // 更新余额缓存（加钱）
                    if ($amount > 0) {
                        $newBalance = bcadd($currentBalance, $amount, 2);
                        \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                    }

                } catch (\Throwable $e) {
                    \support\Redis::del($lockKey);
                    throw $e;
                }

                $return = [
                    'member' => [
                        'username' => $params['member']['username'],
                        'balance' => (float)$beforeBalance, // DG 返回操作前余额
                        'amount' => $params['member']['amount'],
                    ]
                ];

            } else {
                // 未知类型，记录警告并返回当前余额
                Log::channel('dg_server')->warning('DG inform未知类型', ['type' => $type, 'data' => $params]);
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                $return = [
                    'member' => [
                        'username' => $params['member']['username'],
                        'balance' => (float)$balance,
                        'amount' => $params['member']['amount'],
                    ]
                ];
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('dg_server')->info('DG通知成功（Redis缓存）', [
                'order_no' => $orderNo,
                'type' => $type,
                'elapsed_ms' => round($elapsed, 2),
            ]);

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