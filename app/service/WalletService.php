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
    // Redis 缓存键前缀（包含项目标识避免跨项目冲突）
    private const CACHE_PREFIX = 'gk_work:wallet:balance:';

    // 缓存版本号（修改此值可批量失效所有缓存）
    private const CACHE_VERSION = 'v1';

    // 缓存过期时间（秒）
    private const CACHE_TTL = 3600; // 1小时

    // 短期缓存过期时间（用于高频访问的玩家）
    private const CACHE_TTL_SHORT = 300; // 5分钟

    // 默认平台ID（实体机平台）
    private const DEFAULT_PLATFORM_ID = 1;

    /**
     * 🚨 紧急开关：禁用 Redis 缓存
     * 在 .env 中设置 WALLET_CACHE_ENABLED=false 可立即禁用缓存
     * 用于紧急情况下快速回滚到纯数据库查询
     */
    private static function isCacheEnabled(): bool
    {
        return env('WALLET_CACHE_ENABLED', true);
    }

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
        // 🚨 紧急开关：缓存被禁用时直接查询数据库
        if (!self::isCacheEnabled()) {
            return self::getBalanceFromDB($playerId, $platformId);
        }

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
            $wallet->save(); // 触发 updated 事件自动更新缓存

            // 提交事务
            \support\Db::commit();

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
            $wallet->save(); // 触发 updated 事件自动更新缓存

            // 提交事务
            \support\Db::commit();

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
     * 批量清除缓存
     *
     * @param array $playerIds 玩家ID数组
     * @param int $platformId 平台ID
     * @return int 成功清除的数量
     */
    public static function clearBatchCache(array $playerIds, int $platformId = self::DEFAULT_PLATFORM_ID): int
    {
        if (empty($playerIds)) {
            return 0;
        }

        try {
            $cacheKeys = [];
            foreach ($playerIds as $playerId) {
                $cacheKeys[] = self::getCacheKey($playerId, $platformId);
            }

            // 批量删除
            $deletedCount = Redis::del(...$cacheKeys);

            Log::info('WalletService::clearBatchCache 批量清除', [
                'count' => count($playerIds),
                'deleted' => $deletedCount,
                'platform_id' => $platformId,
            ]);

            return $deletedCount;

        } catch (\Throwable $e) {
            Log::error('WalletService::clearBatchCache 异常', [
                'player_ids' => $playerIds,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            return 0;
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
        $startTime = microtime(true);

        try {
            $cacheKey = self::getCacheKey($playerId, $platformId);
            Redis::setex($cacheKey, $ttl, $balance);

            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('wallet_service')->info('💾 缓存更新成功', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'balance' => $balance,
                'ttl' => $ttl,
                'cache_time' => round($duration, 2) . 'ms',
            ]);

            return true;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('wallet_service')->error('❌ 缓存更新失败', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'balance' => $balance,
                'error' => $e->getMessage(),
                'cache_time' => round($duration, 2) . 'ms',
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
            ->value('money');

        return (float)($balance ?? 0);
    }

    /**
     * 生成缓存键（包含版本号）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @return string
     */
    private static function getCacheKey(int $playerId, int $platformId): string
    {
        return self::CACHE_PREFIX . self::CACHE_VERSION . ":{$playerId}:{$platformId}";
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

            // 重建索引确保数组键是连续的 0, 1, 2...
            $playerIds = array_values($playerIds);

            // 尝试从缓存批量获取
            $cacheKeys = array_map(fn($id) => self::getCacheKey($id, $platformId), $playerIds);
            $cached = Redis::mget($cacheKeys);

            foreach ($playerIds as $index => $playerId) {
                if (isset($cached[$index]) && $cached[$index] !== false) {
                    $result[$playerId] = (float)$cached[$index];
                } else {
                    $missingPlayerIds[] = $playerId;
                }
            }

            // 如果有未命中的，从数据库查询
            if (!empty($missingPlayerIds)) {
                $wallets = PlayerPlatformCash::query()
                    ->whereIn('player_id', $missingPlayerIds)
                    ->get(['player_id', 'money']);

                $foundPlayerIds = [];
                foreach ($wallets as $wallet) {
                    $balance = (float)$wallet->money;
                    $result[$wallet->player_id] = $balance;
                    $foundPlayerIds[] = $wallet->player_id;

                    // 写入缓存
                    self::updateCache($wallet->player_id, $platformId, $balance);
                }

                // 补充数据库中不存在的玩家（余额为0）
                $notFoundPlayerIds = array_diff($missingPlayerIds, $foundPlayerIds);
                foreach ($notFoundPlayerIds as $playerId) {
                    $result[$playerId] = 0.0;
                    // 缓存不存在的玩家（避免缓存穿透）
                    self::updateCache($playerId, $platformId, 0.0);
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
                ->pluck('money', 'player_id')
                ->map(fn($v) => (float)$v)
                ->toArray();
        }
    }

    /**
     * 缓存预热（批量加载玩家余额到缓存）
     *
     * @param array $playerIds 玩家ID数组
     * @param int $platformId 平台ID
     * @return array ['success' => int, 'failed' => int]
     */
    public static function warmupCache(array $playerIds, int $platformId = self::DEFAULT_PLATFORM_ID): array
    {
        if (empty($playerIds)) {
            return ['success' => 0, 'failed' => 0];
        }

        $successCount = 0;
        $failedCount = 0;

        try {
            // 从数据库批量查询
            $wallets = PlayerPlatformCash::query()
                ->whereIn('player_id', $playerIds)
                ->get(['player_id', 'money']);

            $foundPlayerIds = [];

            // 批量写入缓存
            foreach ($wallets as $wallet) {
                $balance = (float)$wallet->money;
                $foundPlayerIds[] = $wallet->player_id;

                if (self::updateCache($wallet->player_id, $platformId, $balance)) {
                    $successCount++;
                } else {
                    $failedCount++;
                }
            }

            // 为不存在的玩家缓存 0 余额
            $notFoundPlayerIds = array_diff($playerIds, $foundPlayerIds);
            foreach ($notFoundPlayerIds as $playerId) {
                if (self::updateCache($playerId, $platformId, 0.0)) {
                    $successCount++;
                } else {
                    $failedCount++;
                }
            }

            Log::info('WalletService::warmupCache 缓存预热完成', [
                'requested' => count($playerIds),
                'success' => $successCount,
                'failed' => $failedCount,
                'platform_id' => $platformId,
            ]);

        } catch (\Throwable $e) {
            Log::error('WalletService::warmupCache 异常', [
                'player_ids' => $playerIds,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            $failedCount = count($playerIds) - $successCount;
        }

        return ['success' => $successCount, 'failed' => $failedCount];
    }
}
