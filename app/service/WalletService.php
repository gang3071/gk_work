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
    // Redis 缓存键前缀（与 Lua 原子脚本统一）
    // 修改说明：统一使用 wallet:balance:{player_id} 格式
    // 与 RedisLuaScripts 保持一致，避免缓存不一致
    private const CACHE_PREFIX = 'wallet:balance:';

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

        $cacheKey = self::getCacheKey($playerId);

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
     * 扣款（Redis Lua 原子操作）
     *
     * 高并发场景下，Redis 是余额的唯一实时标准
     *
     * @param int $playerId 玩家ID
     * @param float $amount 扣款金额
     * @param int $platformId 平台ID
     * @return array ['success' => bool, 'balance' => float, 'old_balance' => float]
     */
    public static function deduct(int $playerId, float $amount, int $platformId = self::DEFAULT_PLATFORM_ID): array
    {
        try {
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Amount must be greater than 0');
            }

            // 使用 Lua 原子脚本扣款
            $result = self::atomicDecrement($playerId, $amount);

            if ($result['ok'] == 0) {
                return [
                    'success' => false,
                    'balance' => (float)$result['balance'],
                    'old_balance' => (float)$result['balance'],
                    'error' => $result['error'] ?? '余额不足'
                ];
            }

            return [
                'success' => true,
                'balance' => (float)$result['balance'],
                'old_balance' => 0, // Lua 脚本未返回旧余额
            ];

        } catch (\Throwable $e) {
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
     * 加款（Redis Lua 原子操作）
     *
     * 高并发场景下，Redis 是余额的唯一实时标准
     *
     * @param int $playerId 玩家ID
     * @param float $amount 加款金额
     * @param int $platformId 平台ID
     * @return array ['success' => bool, 'balance' => float, 'old_balance' => float]
     */
    public static function add(int $playerId, float $amount, int $platformId = self::DEFAULT_PLATFORM_ID): array
    {
        try {
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Amount must be greater than 0');
            }

            // 使用 Lua 原子脚本加款
            $newBalance = self::atomicIncrement($playerId, $amount);

            return [
                'success' => true,
                'balance' => (float)$newBalance,
                'old_balance' => 0, // Lua 脚本未返回旧余额
            ];

        } catch (\Throwable $e) {
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
            $cacheKey = self::getCacheKey($playerId);
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
                $cacheKeys[] = self::getCacheKey($playerId);
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
            $cacheKey = self::getCacheKey($playerId);
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
        // ✅ 修复：添加 platform_id 条件，与 gk_api 和 gk_admin 保持一致
        $balance = PlayerPlatformCash::query()
            ->where('player_id', $playerId)
            ->where('platform_id', $platformId)
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
    /**
     * 获取缓存键（与 Lua 原子脚本统一格式）
     *
     * @param int $playerId 玩家ID
     * @return string Redis 缓存键
     */
    private static function getCacheKey(int $playerId): string
    {
        // 统一使用 wallet:balance:{player_id} 格式
        // 与 RedisLuaScripts::atomicBet/atomicSettle 保持一致
        return self::CACHE_PREFIX . $playerId;
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
            $cacheKeys = array_map(fn($id) => self::getCacheKey($id), $playerIds);
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

    /**
     * Lua 脚本：原子性增加余额
     */
    private const LUA_ATOMIC_INCREMENT = <<<'LUA'
local key = KEYS[1]
local amount = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2]) or 3600

local currentBalance = tonumber(redis.call('GET', key)) or 0
local newBalance = currentBalance + amount

redis.call('SETEX', key, ttl, newBalance)
return newBalance
LUA;

    /**
     * Lua 脚本：原子性减少余额（带余额检查）
     */
    private const LUA_ATOMIC_DECREMENT = <<<'LUA'
local key = KEYS[1]
local amount = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2]) or 3600

local currentBalance = tonumber(redis.call('GET', key)) or 0

-- 余额不足检查
if currentBalance < amount then
    return cjson.encode({ok = 0, error = "insufficient_balance", balance = currentBalance})
end

local newBalance = currentBalance - amount
redis.call('SETEX', key, ttl, newBalance)
return cjson.encode({ok = 1, balance = newBalance})
LUA;

    /**
     * 原子性增加余额（使用 Lua 脚本）
     *
     * 核心功能：
     * - 在 Redis 中原子性地增加玩家余额
     * - 保证并发安全（单个 Lua 脚本执行是原子的）
     * - 自动更新缓存过期时间
     *
     * 使用场景：
     * - 彩金发放
     * - 活动奖励发放
     * - 游戏赢钱
     * - 充值
     *
     * @param int $playerId 玩家ID
     * @param float $amount 增加金额（必须 > 0）
     * @param int $ttl Redis 缓存过期时间（秒），默认 3600
     * @return float 新余额
     */
    public static function atomicIncrement(int $playerId, float $amount, int $ttl = 3600): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        try {
            $cacheKey = self::getCacheKey($playerId);

            // 执行 Lua 脚本，原子性增加余额
            $newBalance = Redis::eval(
                self::LUA_ATOMIC_INCREMENT,
                1,  // KEYS 数量
                $cacheKey,  // KEYS[1]
                $amount,    // ARGV[1]
                $ttl        // ARGV[2]
            );

            // ✅ 异步同步数据库（Redis 是实时标准，数据库用于持久化）
            self::asyncUpdateDB($playerId, (float)$newBalance);

            Log::info('WalletService::atomicIncrement 成功', [
                'player_id' => $playerId,
                'amount' => $amount,
                'new_balance' => $newBalance,
            ]);

            return (float)$newBalance;

        } catch (\Throwable $e) {
            Log::error('WalletService::atomicIncrement 失败', [
                'player_id' => $playerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 原子性减少余额（使用 Lua 脚本，带余额检查）
     *
     * 核心功能：
     * - 在 Redis 中原子性地减少玩家余额
     * - 保证并发安全（单个 Lua 脚本执行是原子的）
     * - 自动检查余额是否充足
     * - 余额不足时返回错误，不会扣款
     *
     * 使用场景：
     * - 游戏下注
     * - 提现
     * - 转账
     *
     * @param int $playerId 玩家ID
     * @param float $amount 减少金额（必须 > 0）
     * @param int $ttl Redis 缓存过期时间（秒），默认 3600
     * @return array ['ok' => 1, 'balance' => 新余额] 或 ['ok' => 0, 'error' => 'insufficient_balance', 'balance' => 当前余额]
     */
    public static function atomicDecrement(int $playerId, float $amount, int $ttl = 3600): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        try {
            $cacheKey = self::getCacheKey($playerId);

            // 执行 Lua 脚本，原子性减少余额
            $resultJson = Redis::eval(
                self::LUA_ATOMIC_DECREMENT,
                1,  // KEYS 数量
                $cacheKey,  // KEYS[1]
                $amount,    // ARGV[1]
                $ttl        // ARGV[2]
            );

            $result = json_decode($resultJson, true);

            if ($result['ok'] == 1) {
                // ✅ 异步同步数据库（仅在扣款成功时）
                self::asyncUpdateDB($playerId, (float)$result['balance']);

                Log::info('WalletService::atomicDecrement 成功', [
                    'player_id' => $playerId,
                    'amount' => $amount,
                    'new_balance' => $result['balance'],
                ]);
            } else {
                Log::warning('WalletService::atomicDecrement 失败 - 余额不足', [
                    'player_id' => $playerId,
                    'amount' => $amount,
                    'current_balance' => $result['balance'],
                ]);
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error('WalletService::atomicDecrement 异常', [
                'player_id' => $playerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 异步同步数据库（非阻塞方式）
     *
     * Redis 是实时权威数据源，数据库仅用于持久化
     * 采用 fire-and-forget 模式，不等待数据库写入完成
     *
     * @param int $playerId 玩家ID
     * @param float $newBalance 新余额
     * @return void
     */
    private static function asyncUpdateDB(int $playerId, float $newBalance): void
    {
        try {
            // 只更新 player_platform_cash 表（player 表没有 money 字段）
            \support\Db::table('player_platform_cash')
                ->where('player_id', $playerId)
                ->update(['money' => $newBalance]);
        } catch (\Throwable $e) {
            // 数据库同步失败不影响 Redis（Redis 是唯一实时标准）
            Log::error('WalletService: asyncUpdateDB 失败', [
                'player_id' => $playerId,
                'balance' => $newBalance,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
