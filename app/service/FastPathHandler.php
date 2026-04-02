<?php

namespace app\service;

use app\model\Player;
use support\Log;
use Webman\RedisQueue\Client;

/**
 * 快速路径处理器
 * 用于游戏平台 API 的快速响应（10-30ms）
 *
 * 核心功能：
 * - Redis 幂等性检查
 * - Redis 临时钱包操作
 * - 发送到队列
 * - 返回快速响应
 */
class FastPathHandler
{
    /**
     * 处理下注请求（快速路径）
     *
     * @param string $platform 平台代码（MT, RSG, BTG等）
     * @param array $params 请求参数
     * @param Player $player 玩家对象
     * @return array ['success' => bool, 'balance' => float, 'error' => string]
     */
    public static function handleBet(
        string $platform,
        array  $params,
        Player $player
    ): array
    {
        $orderNo = $params['order_no'] ?? '';
        $amount = (float)($params['amount'] ?? 0);
        $platformId = (int)($params['platform_id'] ?? 1);

        Log::info('FastPath: 处理下注请求', [
            'platform' => $platform,
            'player_id' => $player->id,
            'order_no' => $orderNo,
            'amount' => $amount,
        ]);

        try {
            // 1. 幂等性检查
            $cached = self::checkIdempotency($platform, $orderNo);
            if ($cached) {
                Log::info('FastPath: 订单已处理（幂等性）', [
                    'order_no' => $orderNo,
                    'balance' => $cached['balance'],
                ]);
                return $cached;
            }

            // 2. 预分配游戏记录ID（用于后续异步创建）
            $gameRecordId = self::allocateGameRecordId($platform);

            // 3. Redis 扣款
            $result = RedisWalletService::deduct($player->id, $platformId, $amount);

            if (!$result['success']) {
                Log::warning('FastPath: 扣款失败', [
                    'order_no' => $orderNo,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'DEDUCT_FAILED',
                ];
            }

            // 4. 锁定订单（幂等性，记录预分配的ID）
            RedisWalletService::lockOrder(
                $orderNo,
                $result['balance_after'],
                'processing',
                $gameRecordId  // 记录游戏记录ID
            );

            // 5. 发送到队列
            Client::send('game-operation', [
                'platform' => $platform,
                'operation' => 'bet',
                'player_id' => $player->id,
                'order_no' => $orderNo,
                'game_record_id' => $gameRecordId,  // 传递预分配的ID
                'params' => $params,
                'redis_balance_before' => $result['balance_before'],
                'redis_balance_after' => $result['balance_after'],
                'created_at' => time(),
            ]);

            Log::info('FastPath: 下注请求已入队', [
                'order_no' => $orderNo,
                'game_record_id' => $gameRecordId,
                'balance_after' => $result['balance_after'],
            ]);

            // 6. 返回成功
            return [
                'success' => true,
                'balance' => $result['balance_after'],
                'balance_before' => $result['balance_before'],
            ];

        } catch (\Throwable $e) {
            Log::error('FastPath: 下注处理异常', [
                'platform' => $platform,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'FAST_PATH_ERROR',
            ];
        }
    }

    /**
     * 处理结算请求（快速路径）
     *
     * @param string $platform 平台代码
     * @param array $params 请求参数
     * @param Player $player 玩家对象
     * @return array ['success' => bool, 'balance' => float, 'error' => string]
     */
    public static function handleSettle(
        string $platform,
        array  $params,
        Player $player
    ): array
    {
        $orderNo = $params['order_no'] ?? '';
        $amount = (float)($params['amount'] ?? 0);
        $platformId = (int)($params['platform_id'] ?? 1);

        Log::info('FastPath: 处理结算请求', [
            'platform' => $platform,
            'player_id' => $player->id,
            'order_no' => $orderNo,
            'amount' => $amount,
        ]);

        try {
            // 1. 幂等性检查
            $cached = self::checkIdempotency($platform, $orderNo);
            if ($cached) {
                Log::info('FastPath: 订单已处理（幂等性）', [
                    'order_no' => $orderNo,
                    'balance' => $cached['balance'],
                ]);
                return $cached;
            }

            // 2. Redis 加款
            $result = RedisWalletService::credit($player->id, $platformId, $amount);

            if (!$result['success']) {
                Log::warning('FastPath: 加款失败', [
                    'order_no' => $orderNo,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'CREDIT_FAILED',
                ];
            }

            // 3. 锁定订单（幂等性）
            RedisWalletService::lockOrder(
                $orderNo,
                $result['balance_after'],
                'processing'
            );

            // 4. 发送到队列
            Client::send('game-operation', [
                'platform' => $platform,
                'operation' => 'settle',
                'player_id' => $player->id,
                'order_no' => $orderNo,
                'params' => $params,
                'redis_balance_before' => $result['balance_before'],
                'redis_balance_after' => $result['balance_after'],
                'created_at' => time(),
            ]);

            Log::info('FastPath: 结算请求已入队', [
                'order_no' => $orderNo,
                'balance_after' => $result['balance_after'],
            ]);

            // 5. 返回成功
            return [
                'success' => true,
                'balance' => $result['balance_after'],
                'balance_before' => $result['balance_before'],
            ];

        } catch (\Throwable $e) {
            Log::error('FastPath: 结算处理异常', [
                'platform' => $platform,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'FAST_PATH_ERROR',
            ];
        }
    }

    /**
     * 处理取消请求（快速路径）
     *
     * @param string $platform 平台代码
     * @param array $params 请求参数
     * @param Player $player 玩家对象
     * @return array ['success' => bool, 'balance' => float, 'error' => string]
     */
    public static function handleCancel(
        string $platform,
        array  $params,
        Player $player
    ): array
    {
        $orderNo = $params['order_no'] ?? '';
        $amount = (float)($params['amount'] ?? 0);
        $platformId = (int)($params['platform_id'] ?? 1);

        Log::info('FastPath: 处理取消请求', [
            'platform' => $platform,
            'player_id' => $player->id,
            'order_no' => $orderNo,
            'amount' => $amount,
        ]);

        try {
            // 1. 幂等性检查
            $cached = self::checkIdempotency($platform, $orderNo);
            if ($cached) {
                return $cached;
            }

            // 2. Redis 退款（取消下注）
            $result = RedisWalletService::credit($player->id, $platformId, $amount);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'REFUND_FAILED',
                ];
            }

            // 3. 锁定订单
            RedisWalletService::lockOrder(
                $orderNo,
                $result['balance_after'],
                'processing'
            );

            // 4. 发送到队列
            Client::send('game-operation', [
                'platform' => $platform,
                'operation' => 'cancel',
                'player_id' => $player->id,
                'order_no' => $orderNo,
                'params' => $params,
                'redis_balance_before' => $result['balance_before'],
                'redis_balance_after' => $result['balance_after'],
                'created_at' => time(),
            ]);

            Log::info('FastPath: 取消请求已入队', [
                'order_no' => $orderNo,
                'balance_after' => $result['balance_after'],
            ]);

            return [
                'success' => true,
                'balance' => $result['balance_after'],
                'balance_before' => $result['balance_before'],
            ];

        } catch (\Throwable $e) {
            Log::error('FastPath: 取消处理异常', [
                'platform' => $platform,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'FAST_PATH_ERROR',
            ];
        }
    }

    /**
     * 处理退款请求（快速路径）
     *
     * @param string $platform 平台代码
     * @param array $params 请求参数
     * @param Player $player 玩家对象
     * @return array ['success' => bool, 'balance' => float, 'error' => string]
     */
    public static function handleRefund(
        string $platform,
        array  $params,
        Player $player
    ): array
    {
        $orderNo = $params['order_no'] ?? '';
        $amount = (float)($params['amount'] ?? 0);
        $platformId = (int)($params['platform_id'] ?? 1);

        Log::info('FastPath: 处理退款请求', [
            'platform' => $platform,
            'player_id' => $player->id,
            'order_no' => $orderNo,
            'amount' => $amount,
        ]);

        try {
            // 1. 幂等性检查
            $cached = self::checkIdempotency($platform, $orderNo);
            if ($cached) {
                return $cached;
            }

            // 2. Redis 退款
            $result = RedisWalletService::credit($player->id, $platformId, $amount);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'REFUND_FAILED',
                ];
            }

            // 3. 锁定订单
            RedisWalletService::lockOrder(
                $orderNo,
                $result['balance_after'],
                'processing'
            );

            // 4. 发送到队列
            Client::send('game-operation', [
                'platform' => $platform,
                'operation' => 'refund',
                'player_id' => $player->id,
                'order_no' => $orderNo,
                'params' => $params,
                'redis_balance_before' => $result['balance_before'],
                'redis_balance_after' => $result['balance_after'],
                'created_at' => time(),
            ]);

            Log::info('FastPath: 退款请求已入队', [
                'order_no' => $orderNo,
                'balance_after' => $result['balance_after'],
            ]);

            return [
                'success' => true,
                'balance' => $result['balance_after'],
                'balance_before' => $result['balance_before'],
            ];

        } catch (\Throwable $e) {
            Log::error('FastPath: 退款处理异常', [
                'platform' => $platform,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'FAST_PATH_ERROR',
            ];
        }
    }

    /**
     * 检查订单幂等性
     *
     * @param string $platform 平台代码
     * @param string $orderNo 订单号
     * @return array|null 如果订单已处理返回结果，否则返回null
     */
    private static function checkIdempotency(
        string $platform,
        string $orderNo
    ): ?array
    {
        $cached = RedisWalletService::checkOrder($orderNo);

        if ($cached && $cached['status'] === 'completed') {
            // 订单已完成，返回缓存结果
            return [
                'success' => true,
                'balance' => $cached['balance'],
                'from_cache' => true,
            ];
        }

        if ($cached && $cached['status'] === 'processing') {
            // 订单正在处理中，返回当前余额
            return [
                'success' => true,
                'balance' => $cached['balance'],
                'from_cache' => true,
                'processing' => true,
            ];
        }

        return null;
    }

    /**
     * 预分配游戏记录ID
     * 使用 Redis INCR 生成全局唯一ID，避免数据库自增冲突
     *
     * @param string $platform 平台代码
     * @return int 预分配的游戏记录ID
     */
    private static function allocateGameRecordId(string $platform): int
    {
        try {
            $key = "game:record:id:{$platform}";
            $id = \support\Redis::incr($key);

            Log::info('FastPath: 预分配游戏记录ID', [
                'platform' => $platform,
                'id' => $id,
            ]);

            return $id;

        } catch (\Throwable $e) {
            Log::error('FastPath: 预分配ID失败', [
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            // 失败时返回0，让队列消费者使用数据库自增
            return 0;
        }
    }
}
