<?php

namespace app\service;

use app\model\PlayerPlatformCash;
use support\Log;
use support\Redis;

/**
 * 钱包服务（带 Redis 缓存）
 * 实时缓存方案：读写都通过此服务，确保缓存一致性
 */
class WalletService
{
    // Redis 缓存键前缀
    private const CACHE_PREFIX = 'wallet:balance:';

    // 缓存过期时间（秒）
    private const CACHE_TTL = 3600; // 1小时

    // 短期缓存过期时间（用于高频访问的玩家）
    private const CACHE_TTL_SHORT = 300; // 5分钟

    // 默认平台ID（实体机平台）
    private const DEFAULT_PLATFORM_ID = 1;

    /**
     * 获取余额（带缓存）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param bool $forceRefresh 是否强制刷新缓存
     * @return float 余额
     */
    public static function getBalance(int $playerId, int $platformId = self::DEFAULT_PLATFORM_ID, bool $forceRefresh = false): float
    {
        $cacheKey = self::getCacheKey($playerId, $platformId);

        try {
            // 如果不是强制刷新，尝试从缓存读取
            if (!$forceRefresh) {
                $cached = Redis::get($cacheKey);
                if ($cached !== null && $cached !== false) {
                    return (float)$cached;
                }
            }

            // 缓存未命中或强制刷新，从数据库读取
            $balance = self::getBalanceFromDB($playerId, $platformId);

            // 写入缓存
            Redis::setex($cacheKey, self::CACHE_TTL, $balance);

            return $balance;

        } catch (\Throwable $e) {
            Log::error('WalletService::getBalance 异常', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);

            // 异常时直接从数据库读取
            return self::getBalanceFromDB($playerId, $platformId);
        }
    }

    /**
     * 扣款（原子操作）
     *
     * @param int $playerId 玩家ID
     * @param float $amount 扣款金额
     * @param int $platformId 平台ID
     * @return array ['success' => bool, 'balance' => float, 'old_balance' => float]
     */
    public static function deduct(int $playerId, float $amount, int $platformId = self::DEFAULT_PLATFORM_ID): array
    {
        try {
            // 开始数据库事务
            \support\Db::beginTransaction();

            // 使用悲观锁查询钱包
            $wallet = PlayerPlatformCash::query()
                ->where('player_id', $playerId)
                ->where('platform_id', $platformId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                \support\Db::rollBack();
                return [
                    'success' => false,
                    'balance' => 0,
                    'old_balance' => 0,
                    'error' => '钱包不存在'
                ];
            }

            $oldBalance = (float)$wallet->money;

            // 检查余额是否充足
            if ($oldBalance < $amount) {
                \support\Db::rollBack();
                return [
                    'success' => false,
                    'balance' => $oldBalance,
                    'old_balance' => $oldBalance,
                    'error' => '余额不足'
                ];
            }

            // 扣款
            $newBalance = bcsub($oldBalance, $amount, 2);
            $wallet->money = $newBalance;
            $wallet->save();

            // 提交事务
            \support\Db::commit();

            // 更新缓存
            self::updateCache($playerId, $platformId, $newBalance);

            return [
                'success' => true,
                'balance' => (float)$newBalance,
                'old_balance' => $oldBalance,
            ];

        } catch (\Throwable $e) {
            \support\Db::rollBack();

            Log::error('WalletService::deduct 异常', [
                'player_id' => $playerId,
                'amount' => $amount,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'balance' => 0,
                'old_balance' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 加款（原子操作）
     *
     * @param int $playerId 玩家ID
     * @param float $amount 加款金额
     * @param int $platformId 平台ID
     * @return array ['success' => bool, 'balance' => float, 'old_balance' => float]
     */
    public static function add(int $playerId, float $amount, int $platformId = self::DEFAULT_PLATFORM_ID): array
    {
        try {
            // 开始数据库事务
            \support\Db::beginTransaction();

            // 使用悲观锁查询钱包
            $wallet = PlayerPlatformCash::query()
                ->where('player_id', $playerId)
                ->where('platform_id', $platformId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                \support\Db::rollBack();
                return [
                    'success' => false,
                    'balance' => 0,
                    'old_balance' => 0,
                    'error' => '钱包不存在'
                ];
            }

            $oldBalance = (float)$wallet->money;

            // 加款
            $newBalance = bcadd($oldBalance, $amount, 2);
            $wallet->money = $newBalance;
            $wallet->save();

            // 提交事务
            \support\Db::commit();

            // 更新缓存
            self::updateCache($playerId, $platformId, $newBalance);

            return [
                'success' => true,
                'balance' => (float)$newBalance,
                'old_balance' => $oldBalance,
            ];

        } catch (\Throwable $e) {
            \support\Db::rollBack();

            Log::error('WalletService::add 异常', [
                'player_id' => $playerId,
                'amount' => $amount,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'balance' => 0,
                'old_balance' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 清除缓存
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @return bool
     */
    public static function clearCache(int $playerId, int $platformId = self::DEFAULT_PLATFORM_ID): bool
    {
        try {
            $cacheKey = self::getCacheKey($playerId, $platformId);
            Redis::del($cacheKey);
            return true;
        } catch (\Throwable $e) {
            Log::error('WalletService::clearCache 异常', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 更新缓存
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param float $balance 余额
     * @param int $ttl 过期时间（秒）
     * @return bool
     */
    public static function updateCache(int $playerId, int $platformId, float $balance, int $ttl = self::CACHE_TTL): bool
    {
        try {
            $cacheKey = self::getCacheKey($playerId, $platformId);
            Redis::setex($cacheKey, $ttl, $balance);
            return true;
        } catch (\Throwable $e) {
            Log::error('WalletService::updateCache 异常', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'balance' => $balance,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 从数据库获取余额
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @return float
     */
    private static function getBalanceFromDB(int $playerId, int $platformId): float
    {
        $balance = PlayerPlatformCash::query()
            ->where('player_id', $playerId)
            ->where('platform_id', $platformId)
            ->value('money');

        return (float)($balance ?? 0);
    }

    /**
     * 生成缓存键
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @return string
     */
    private static function getCacheKey(int $playerId, int $platformId): string
    {
        return self::CACHE_PREFIX . "{$playerId}:{$platformId}";
    }

    /**
     * 批量获取余额（用于后台管理等场景）
     *
     * @param array $playerIds 玩家ID数组
     * @param int $platformId 平台ID
     * @return array [player_id => balance]
     */
    public static function getBatchBalance(array $playerIds, int $platformId = self::DEFAULT_PLATFORM_ID): array
    {
        if (empty($playerIds)) {
            return [];
        }

        try {
            $result = [];
            $missingPlayerIds = [];

            // 尝试从缓存批量获取
            $cacheKeys = array_map(fn($id) => self::getCacheKey($id, $platformId), $playerIds);
            $cached = Redis::mget($cacheKeys);

            foreach ($playerIds as $index => $playerId) {
                if (isset($cached[$index]) && $cached[$index] !== null && $cached[$index] !== false) {
                    $result[$playerId] = (float)$cached[$index];
                } else {
                    $missingPlayerIds[] = $playerId;
                }
            }

            // 如果有未命中的，从数据库查询
            if (!empty($missingPlayerIds)) {
                $wallets = PlayerPlatformCash::query()
                    ->whereIn('player_id', $missingPlayerIds)
                    ->where('platform_id', $platformId)
                    ->get(['player_id', 'money']);

                foreach ($wallets as $wallet) {
                    $balance = (float)$wallet->money;
                    $result[$wallet->player_id] = $balance;

                    // 写入缓存
                    self::updateCache($wallet->player_id, $platformId, $balance);
                }
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error('WalletService::getBatchBalance 异常', [
                'player_ids' => $playerIds,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);

            // 降级：直接从数据库查询
            return PlayerPlatformCash::query()
                ->whereIn('player_id', $playerIds)
                ->where('platform_id', $platformId)
                ->pluck('money', 'player_id')
                ->map(fn($v) => (float)$v)
                ->toArray();
        }
    }
}
