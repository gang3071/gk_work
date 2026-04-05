<?php

namespace app\service;

use support\Log;
use support\Redis;

/**
 * Redis 钱包服务
 * 用于快速路径的余额操作，提供极致性能
 *
 * 🚀 核心功能：
 * - Redis 临时钱包（快速扣款/加款）
 * - 分布式锁（防止并发冲突）
 * - 订单幂等性（防止重复处理）
 * - 对账同步（保证最终一致性）
 */
class RedisWalletService
{
    /**
     * 钱包余额键前缀
     */
    private const WALLET_BALANCE_PREFIX = 'wallet:balance:';

    /**
     * 钱包锁前缀
     */
    private const WALLET_LOCK_PREFIX = 'wallet:lock:';

    /**
     * 订单锁前缀
     */
    private const ORDER_LOCK_PREFIX = 'btg:order:lock:';

    /**
     * 锁超时时间（秒）
     */
    private const LOCK_TIMEOUT = 5;

    /**
     * 订单锁超时时间（秒）
     */
    private const ORDER_LOCK_TIMEOUT = 60;

    /**
     * 获取钱包余额（Redis）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID（默认1=实体机）
     * @return float|null 余额，如果不存在返回null
     */
    public static function getBalance(int $playerId, int $platformId = 1): ?float
    {
        try {
            $key = self::WALLET_BALANCE_PREFIX . "{$playerId}:{$platformId}";
            $balance = Redis::get($key);

            if ($balance === null || $balance === false) {
                return null;
            }

            return (float)$balance;
        } catch (\Throwable $e) {
            Log::error('RedisWallet: 获取余额失败', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 设置钱包余额（Redis）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param float $balance 余额
     * @return bool
     */
    public static function setBalance(int $playerId, int $platformId, float $balance): bool
    {
        try {
            $key = self::WALLET_BALANCE_PREFIX . "{$playerId}:{$platformId}";
            Redis::set($key, $balance);

            Log::info('RedisWallet: 设置余额', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'balance' => $balance,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('RedisWallet: 设置余额失败', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'balance' => $balance,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 扣款（带锁）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param float $amount 扣款金额
     * @return array ['success' => bool, 'balance_before' => float, 'balance_after' => float, 'error' => string]
     */
    public static function deduct(int $playerId, int $platformId, float $amount): array
    {
        return self::executeWithLock($playerId, $platformId, function () use ($playerId, $platformId, $amount) {
            $balanceBefore = self::getBalance($playerId, $platformId);

            if ($balanceBefore === null) {
                return [
                    'success' => false,
                    'error' => 'BALANCE_NOT_FOUND',
                    'balance_before' => 0,
                    'balance_after' => 0,
                ];
            }

            if ($balanceBefore < $amount) {
                return [
                    'success' => false,
                    'error' => 'INSUFFICIENT_BALANCE',
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceBefore,
                ];
            }

            $balanceAfter = $balanceBefore - $amount;
            self::setBalance($playerId, $platformId, $balanceAfter);

            Log::info('RedisWallet: 扣款成功', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);

            return [
                'success' => true,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ];
        });
    }

    /**
     * 加款（带锁）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param float $amount 加款金额
     * @return array ['success' => bool, 'balance_before' => float, 'balance_after' => float, 'error' => string]
     */
    public static function credit(int $playerId, int $platformId, float $amount): array
    {
        return self::executeWithLock($playerId, $platformId, function () use ($playerId, $platformId, $amount) {
            $balanceBefore = self::getBalance($playerId, $platformId);

            if ($balanceBefore === null) {
                return [
                    'success' => false,
                    'error' => 'BALANCE_NOT_FOUND',
                    'balance_before' => 0,
                    'balance_after' => 0,
                ];
            }

            $balanceAfter = $balanceBefore + $amount;
            self::setBalance($playerId, $platformId, $balanceAfter);

            Log::info('RedisWallet: 加款成功', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);

            return [
                'success' => true,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ];
        });
    }

    /**
     * 执行带锁的操作（防止并发冲突）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param callable $callback 回调函数
     * @return mixed
     */
    private static function executeWithLock(int $playerId, int $platformId, callable $callback)
    {
        $lockKey = self::WALLET_LOCK_PREFIX . "{$playerId}:{$platformId}";
        $maxRetries = 3;
        $retryDelay = 10; // 10ms

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                // 尝试获取锁
                $locked = Redis::set($lockKey, 1, ['NX', 'EX' => self::LOCK_TIMEOUT]);

                if ($locked) {
                    try {
                        // 执行操作
                        return $callback();
                    } finally {
                        // 释放锁
                        Redis::del($lockKey);
                    }
                }

                // 锁定失败，等待重试
                if ($i < $maxRetries - 1) {
                    usleep($retryDelay * 1000); // 转换为微秒
                }
            } catch (\Throwable $e) {
                // 确保锁被释放
                Redis::del($lockKey);

                Log::error('RedisWallet: 执行带锁操作失败', [
                    'player_id' => $playerId,
                    'platform_id' => $platformId,
                    'retry' => $i + 1,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        // 重试次数用完，返回失败
        return [
            'success' => false,
            'error' => 'LOCK_TIMEOUT',
            'balance_before' => 0,
            'balance_after' => 0,
        ];
    }

    /**
     * 检查订单是否已处理（幂等性）
     *
     * @param string $tranId 订单号
     * @return array|null 如果已处理返回订单信息，否则返回null
     */
    public static function checkOrder(string $tranId): ?array
    {
        try {
            $key = self::ORDER_LOCK_PREFIX . $tranId;
            $data = Redis::get($key);

            if ($data === null || $data === false) {
                return null;
            }

            return json_decode($data, true);
        } catch (\Throwable $e) {
            Log::error('RedisWallet: 检查订单失败', [
                'tran_id' => $tranId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 锁定订单（防止重复处理）
     *
     * @param string $tranId 订单号
     * @param float $balance 处理后余额
     * @param string $status 状态（processing/completed/failed）
     * @param int $gameRecordId 预分配的游戏记录ID（可选）
     * @return bool
     */
    public static function lockOrder(string $tranId, float $balance, string $status = 'processing', int $gameRecordId = 0): bool
    {
        try {
            $key = self::ORDER_LOCK_PREFIX . $tranId;

            $data = [
                'status' => $status,
                'balance' => $balance,
                'game_record_id' => $gameRecordId,  // 记录预分配的ID
                'created_at' => time(),
                'expires_at' => time() + self::ORDER_LOCK_TIMEOUT,
            ];

            // 设置订单锁（60秒过期）
            Redis::setex($key, self::ORDER_LOCK_TIMEOUT, json_encode($data));

            Log::info('RedisWallet: 订单已锁定', [
                'tran_id' => $tranId,
                'status' => $status,
                'balance' => $balance,
                'game_record_id' => $gameRecordId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('RedisWallet: 锁定订单失败', [
                'tran_id' => $tranId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 更新订单状态
     *
     * @param string $tranId 订单号
     * @param string $status 状态（completed/failed）
     * @return bool
     */
    public static function updateOrderStatus(string $tranId, string $status): bool
    {
        try {
            $key = self::ORDER_LOCK_PREFIX . $tranId;
            $data = Redis::get($key);

            if ($data === null || $data === false) {
                return false;
            }

            $orderData = json_decode($data, true);
            $orderData['status'] = $status;
            $orderData['updated_at'] = time();

            Redis::setex($key, self::ORDER_LOCK_TIMEOUT, json_encode($orderData));

            Log::info('RedisWallet: 订单状态已更新', [
                'tran_id' => $tranId,
                'status' => $status,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('RedisWallet: 更新订单状态失败', [
                'tran_id' => $tranId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 同步数据库余额到 Redis（对账）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param float $dbBalance 数据库余额
     * @return bool
     */
    public static function syncFromDatabase(int $playerId, int $platformId, float $dbBalance): bool
    {
        try {
            $redisBalance = self::getBalance($playerId, $platformId);

            if ($redisBalance === null || abs($redisBalance - $dbBalance) > 0.01) {
                // Redis 不存在或不一致，同步数据库余额
                self::setBalance($playerId, $platformId, $dbBalance);

                if ($redisBalance !== null && abs($redisBalance - $dbBalance) > 0.01) {
                    Log::warning('RedisWallet: 余额不一致，已同步', [
                        'player_id' => $playerId,
                        'platform_id' => $platformId,
                        'redis_balance' => $redisBalance,
                        'db_balance' => $dbBalance,
                        'diff' => $redisBalance - $dbBalance,
                    ]);
                }

                return true;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('RedisWallet: 同步数据库余额失败', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'db_balance' => $dbBalance,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 批量同步余额（定期对账任务使用）
     *
     * @param array $wallets 钱包列表 [['player_id' => int, 'platform_id' => int, 'balance' => float], ...]
     * @return array ['synced' => int, 'inconsistent' => int]
     */
    public static function batchSync(array $wallets): array
    {
        $synced = 0;
        $inconsistent = 0;

        Log::info('对账数据',['wallets'=>$wallets]);

        foreach ($wallets as $wallet) {
            $playerId = $wallet['player_id'];
            $platformId = $wallet['platform_id'];
            $dbBalance = $wallet['money'];

            $redisBalance = self::getBalance($playerId, $platformId);

            // Redis 不存在，初始化
            if ($redisBalance === null) {
                self::setBalance($playerId, $platformId, $dbBalance);
                $synced++;
                continue;
            }

            // 检查不一致
            if (abs($redisBalance - $dbBalance) > 0.01) {
                Log::warning('RedisWallet: 对账发现不一致', [
                    'player_id' => $playerId,
                    'platform_id' => $platformId,
                    'redis_balance' => $redisBalance,
                    'db_balance' => $dbBalance,
                    'diff' => $redisBalance - $dbBalance,
                ]);

                // ✅ 以 Redis 为准，修正 MySQL（Redis 是实时数据源）
                \app\model\PlayerPlatformCash::where('player_id', $playerId)
                    ->where('platform_id', $platformId)
                    ->update(['money' => $redisBalance]);

                Log::info('RedisWallet: 已修正 MySQL 钱包', [
                    'player_id' => $playerId,
                    'platform_id' => $platformId,
                    'old_balance' => $dbBalance,
                    'new_balance' => $redisBalance,
                ]);

                $inconsistent++;
            }

            $synced++;
        }

        Log::info('RedisWallet: 批量对账完成', [
            'total' => count($wallets),
            'synced' => $synced,
            'inconsistent' => $inconsistent,
        ]);

        return [
            'synced' => $synced,
            'inconsistent' => $inconsistent,
        ];
    }
}
