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
    // ⚠️ 已废弃：余额缓存现在永不过期（Redis as Single Source of Truth）
    // private const CACHE_TTL = 5184000; // 60天 (60 * 24 * 3600)

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
     * ✅ 整数化改造：Redis 存储"分"（整数），返回"元"（浮点数）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param bool $forceRefresh 是否强制刷新缓存
     * @return float 余额（元）
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
                    // ✅ 整数化：Redis 存储"分"，转换为"元"
                    return round((int)$cached / 100, 2);
                }
            }

            // 缓存未命中或强制刷新，从数据库读取
            $balance = self::getBalanceFromDB($playerId, $platformId);

            // ✅ 整数化：写入缓存时转换为"分"
            // ⚠️ 永不过期：Redis 是余额的唯一实时标准
            $balanceInCents = (int)round($balance * 100);
            Redis::set($cacheKey, $balanceInCents);

            return round($balance, 2);

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
                    'balance' => round((float)$result['balance'], 2),
                    'old_balance' => round((float)$result['balance'], 2),
                    'error' => $result['error'] ?? 'insufficient_balance',  // ← 保持错误码，不翻译
                    'error_code' => $result['error'] ?? 'insufficient_balance',  // ← 新增：明确的错误码字段
                ];
            }

            return [
                'success' => true,
                'balance' => round((float)$result['balance'], 2),
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
            $incrementResult = self::atomicIncrement($playerId, $amount);

            return [
                'success' => true,
                'balance' => round($incrementResult['balance'], 2),
                'old_balance' => round($incrementResult['old'] ?? 0, 2),
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
     * ✅ 整数化改造：接收"元"，存储"分"（整数）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param int $ttl (已废弃) 过期时间 - 余额已改为永不过期
     * @param float $balance 余额（元）
     * @return bool
     */
    public static function updateCache(int $playerId, int $platformId, float $balance, int $ttl = 0): bool
    {
        $startTime = microtime(true);

        try {
            $cacheKey = self::getCacheKey($playerId);

            // ✅ 整数化：转换为"分"（整数）后存储
            // ⚠️ 永不过期：Redis 是余额的唯一实时标准
            $balanceInCents = (int)round($balance * 100);
            Redis::set($cacheKey, $balanceInCents);

            $duration = (microtime(true) - $startTime) * 1000;

            Log::channel('wallet_service')->info('💾 缓存更新成功', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'balance_yuan' => $balance,
                'balance_cents' => $balanceInCents,
                'balance' => $balance,
                'ttl' => 'never_expire',
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

        return round((float)($balance ?? 0), 2);
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
                    // ✅ 整数化：Redis 存储"分"，转换为"元"
                    $result[$playerId] = round((int)$cached[$index] / 100, 2);
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
                    $balance = round((float)$wallet->money, 2);
                    $result[$wallet->player_id] = $balance;
                    $foundPlayerIds[] = $wallet->player_id;

                    // 写入缓存
                    self::updateCache($wallet->player_id, $platformId, $balance);
                }

                // 补充数据库中不存在的玩家（余额为0）
                $notFoundPlayerIds = array_diff($missingPlayerIds, $foundPlayerIds);
                foreach ($notFoundPlayerIds as $playerId) {
                    $result[$playerId] = 0.00;
                    // 缓存不存在的玩家（避免缓存穿透）
                    self::updateCache($playerId, $platformId, 0.00);
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
     *
     * ✅ 整数化改造：使用整数运算（分），避免浮点数精度问题
     */
    private const LUA_ATOMIC_INCREMENT = <<<'LUA'
local key = KEYS[1]
local amount = tonumber(ARGV[1])  -- 金额（分，整数）

-- ✅ 整数化：Redis 存储整数（分）
local currentBalance = tonumber(redis.call('GET', key)) or 0
local newBalance = currentBalance + amount

-- ✅ 整数化：存储整数（永不过期，Redis 是唯一实时标准）
redis.call('SET', key, tostring(math.floor(newBalance)))
return cjson.encode({ok = 1, balance = newBalance, old = currentBalance, new = newBalance})
LUA;

    /**
     * Lua 脚本：原子性减少余额（带余额检查）
     *
     * ✅ 整数化改造：使用整数运算（分），避免浮点数精度问题
     */
    private const LUA_ATOMIC_DECREMENT = <<<'LUA'
local key = KEYS[1]
local amount = tonumber(ARGV[1])  -- 金额（分，整数）

-- ✅ 整数化：Redis 存储整数（分）
local currentBalance = tonumber(redis.call('GET', key)) or 0

-- ✅ 整数化：余额不足检查（整数比较，无需容差）
if currentBalance < amount then
    return cjson.encode({ok = 0, error = "insufficient_balance", balance = currentBalance, old = currentBalance})
end

local newBalance = currentBalance - amount

-- 防止负数余额
if newBalance < 0 then
    newBalance = 0
end

-- ✅ 整数化：存储整数（永不过期，Redis 是唯一实时标准）
redis.call('SET', key, tostring(math.floor(newBalance)))
return cjson.encode({ok = 1, balance = newBalance, old = currentBalance, new = newBalance})
LUA;

    /**
     * Lua 脚本：原子性洗分操作
     *
     * 功能：在 Redis 中原子性完成"读取-计算-扣款"
     * - 读取当前余额
     * - 计算可洗分金额（向下取整到百位）
     * - 检查余额是否足够
     * - 原子性扣款
     *
     * ✅ 整数化改造：使用整数运算（分），避免浮点数精度问题
     *
     * 优势：
     * - 完全避免 TOCTOU 问题
     * - 保证并发安全
     * - 整数运算，无精度问题
     */
    private const LUA_ATOMIC_WASH = <<<'LUA'
local key = KEYS[1]
local minWashAmount = tonumber(ARGV[1]) or 10000  -- ✅ 最小洗分金额（分，默认10000=100元）

-- ✅ 整数化：Redis 存储整数（分）
local currentBalance = tonumber(redis.call('GET', key)) or 0

-- ✅ 整数化：计算可洗分金额（向下取整到百位，即10000分=100元的整数倍）
local washAmount = math.floor(currentBalance / 10000) * 10000

-- 检查最小洗分金额
if washAmount < minWashAmount then
    return cjson.encode({
        ok = 0,
        error = "insufficient_wash_amount",
        balance = currentBalance,
        wash_amount = 0,
        min_required = minWashAmount
    })
end

-- ✅ 整数化：余额检查（整数比较，无需容差）
if currentBalance < washAmount then
    return cjson.encode({
        ok = 0,
        error = "insufficient_balance",
        balance = currentBalance,
        wash_amount = washAmount
    })
end

-- 扣除洗分金额
local newBalance = currentBalance - washAmount

-- 防止负数余额
if newBalance < 0 then
    newBalance = 0
end

-- ✅ 整数化：存储整数（永不过期，Redis 是唯一实时标准）
redis.call('SET', key, tostring(math.floor(newBalance)))

return cjson.encode({
    ok = 1,
    balance = newBalance,           -- 扣款后余额（分）
    old_balance = currentBalance,   -- 扣款前余额（分）
    wash_amount = washAmount        -- 实际洗分金额（分）
})
LUA;

    /**
     * 原子性增加余额（使用 Lua 脚本）
     *
     * 核心功能：
     * - 在 Redis 中原子性地增加玩家余额
     * - 保证并发安全（单个 Lua 脚本执行是原子的）
     * - 自动更新缓存过期时间
     *
     * ✅ 整数化改造：接收"元"，转换为"分"后调用 Lua 脚本，返回"元"
     *
     * 使用场景：
     * - 彩金发放
     * - 活动奖励发放
     * - 游戏赢钱
     * - 充值
     *
     * @param int $playerId 玩家ID
     * @param float $amount 增加金额（元，必须 > 0）
     * @param int $ttl Redis 缓存过期时间（秒），默认 3600
     * @return array ['ok' => 1, 'balance' => 新余额(元), 'old' => 旧余额(元)]
     */
    public static function atomicIncrement(int $playerId, float $amount, int $ttl = 3600): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        try {
            $cacheKey = self::getCacheKey($playerId);

            // ✅ 整数化：将"元"转换为"分"
            $amountInCents = (int)round($amount * 100);

            // 执行 Lua 脚本，原子性增加余额
            $resultJson = Redis::eval(
                self::LUA_ATOMIC_INCREMENT,
                1,  // KEYS 数量
                $cacheKey,         // KEYS[1]
                $amountInCents,    // ARGV[1] - 金额（分）
                $ttl               // ARGV[2]
            );

            // 解析返回的 JSON：{ok, balance, old, new}（单位：分）
            $result = json_decode($resultJson, true);

            // ✅ 整数化：将"分"转换回"元"
            if (isset($result['balance'])) {
                $result['balance'] = round((int)$result['balance'] / 100, 2);
            }
            if (isset($result['old'])) {
                $result['old'] = round((int)$result['old'] / 100, 2);
            }
            if (isset($result['new'])) {
                $result['new'] = round((int)$result['new'] / 100, 2);
            }

            // ✅ 异步同步数据库（Redis 是实时标准，数据库用于持久化）
            self::asyncUpdateDB($playerId, $result['balance']);

            Log::info('WalletService::atomicIncrement 成功', [
                'player_id' => $playerId,
                'amount_yuan' => $amount,
                'amount_cents' => $amountInCents,
                'old_balance' => $result['old'] ?? 0,
                'new_balance' => $result['balance'],
            ]);

            return $result;

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
     * ✅ 整数化改造：接收"元"，转换为"分"后调用 Lua 脚本，返回"元"
     *
     * 使用场景：
     * - 游戏下注
     * - 提现
     * - 转账
     *
     * @param int $playerId 玩家ID
     * @param float $amount 减少金额（元，必须 > 0）
     * @return array ['ok' => 1, 'balance' => 新余额(元)] 或 ['ok' => 0, 'error' => 'insufficient_balance', 'balance' => 当前余额(元)]
     * @param int $ttl (已废弃) Redis 缓存过期时间 - 余额已改为永不过期
     * @return array ['ok' => 1, 'balance' => 新余额] 或 ['ok' => 0, 'error' => 'insufficient_balance', 'balance' => 当前余额]
     */
    public static function atomicDecrement(int $playerId, float $amount, int $ttl = 0): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        try {
            $cacheKey = self::getCacheKey($playerId);

            // ✅ 整数化：将"元"转换为"分"
            $amountInCents = (int)round($amount * 100);

            // 执行 Lua 脚本，原子性减少余额
            $resultJson = Redis::eval(
                self::LUA_ATOMIC_DECREMENT,
                1,  // KEYS 数量
                $cacheKey,         // KEYS[1]
                $amountInCents,    // ARGV[1] - 金额（分）
                $ttl               // ARGV[2]
            );

            $result = json_decode($resultJson, true);

            if ($result['ok'] == 1) {
                // ✅ 整数化：将"分"转换回"元"
                $oldBalance = round((int)($result['old'] ?? 0) / 100, 2);
                $newBalance = round((int)($result['new'] ?? 0) / 100, 2);

                // ✅ 异步同步数据库（仅在扣款成功时）
                self::asyncUpdateDB($playerId, $newBalance);

                Log::info('WalletService::atomicDecrement 成功', [
                    'player_id' => $playerId,
                    'amount_yuan' => $amount,
                    'amount_cents' => $amountInCents,
                    'old_balance' => $oldBalance,
                    'new_balance' => $newBalance,
                ]);

                // 格式化返回值
                $result['balance'] = $newBalance;
                $result['old'] = $oldBalance;
            } else {
                // ✅ 整数化：余额不足时也转换
                $result['balance'] = round((int)($result['balance'] ?? 0) / 100, 2);

                Log::warning('WalletService::atomicDecrement 失败 - 余额不足', [
                    'player_id' => $playerId,
                    'amount_yuan' => $amount,
                    'amount_cents' => $amountInCents,
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
     * 原子性洗分操作（完全避免 TOCTOU 问题）
     *
     * 在 Redis 中原子性完成：
     * 1. 读取当前余额
     * 2. 计算可洗分金额（向下取整到百位）
     * 3. 检查余额是否足够
     * 4. 原子性扣款
     *
     * ✅ 整数化改造：接收"元"，转换为"分"后调用 Lua 脚本，返回"元"
     *
     * 优势：
     * - 完全避免 Time-of-Check to Time-of-Use (TOCTOU) 竞态条件
     * - 保证并发安全
     * - 整数运算，无精度问题
     * - 消除两次读取余额之间的时间窗口
     *
     * @param int $playerId 玩家ID
     * @param int $minWashAmount 最小洗分金额（元，默认100）
     * @param int $ttl (已废弃) Redis 缓存过期时间 - 余额已改为永不过期
     * @return array [
     *   'ok' => 1,                 // 成功标志
     *   'balance' => 新余额(元),
     *   'old_balance' => 扣款前余额(元),
     *   'wash_amount' => 实际洗分金额(元)
     * ] 或 [
     *   'ok' => 0,
     *   'error' => 错误类型,
     *   'balance' => 当前余额(元)
     * ]
     */
    public static function atomicWash(int $playerId, int $minWashAmount = 100, int $ttl = 0): array
    {
        try {
            $cacheKey = self::getCacheKey($playerId);

            // ✅ 整数化：将"元"转换为"分"
            $minWashAmountInCents = $minWashAmount * 100;

            // 执行 Lua 脚本，原子性完成洗分操作
            $resultJson = Redis::eval(
                self::LUA_ATOMIC_WASH,
                1,  // KEYS 数量
                $cacheKey,              // KEYS[1]
                $minWashAmountInCents,  // ARGV[1] - 最小洗分金额（分）
                $ttl                    // ARGV[2]
            );

            $result = json_decode($resultJson, true);

            // ✅ 整数化：将"分"转换回"元"
            if (isset($result['balance'])) {
                $result['balance'] = round((int)$result['balance'] / 100, 2);
            }
            if (isset($result['old_balance'])) {
                $result['old_balance'] = round((int)$result['old_balance'] / 100, 2);
            }
            if (isset($result['wash_amount'])) {
                $result['wash_amount'] = round((int)$result['wash_amount'] / 100, 2);
            }
            if (isset($result['min_required'])) {
                $result['min_required'] = round((int)$result['min_required'] / 100, 2);
            }

            if ($result['ok'] == 1) {
                // ✅ 异步同步数据库
                self::asyncUpdateDB($playerId, $result['balance']);

                Log::info('WalletService::atomicWash 成功', [
                    'player_id' => $playerId,
                    'old_balance' => $result['old_balance'],
                    'wash_amount' => $result['wash_amount'],
                    'new_balance' => $result['balance'],
                ]);
            } else {
                Log::warning('WalletService::atomicWash 失败', [
                    'player_id' => $playerId,
                    'error' => $result['error'] ?? 'unknown',
                    'current_balance' => $result['balance'] ?? 0,
                    'wash_amount' => $result['wash_amount'] ?? 0,
                    'min_required' => $result['min_required'] ?? $minWashAmount,
                ]);
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error('WalletService::atomicWash 异常', [
                'player_id' => $playerId,
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

    /**
     * 在事务提交后检查爆机状态
     *
     * ⚠️ 必须在数据库事务提交后调用，避免嵌套事务冲突
     *
     * @param int $playerId 玩家ID
     * @param float $currentBalance 当前余额（来自 Redis）
     * @param float|null $previousBalance 之前的余额（用于判断状态变化）
     * @return void
     */
    public static function checkMachineCrashAfterTransaction(int $playerId, float $currentBalance, ?float $previousBalance = null): void
    {
        try {
            // 获取玩家信息
            $player = \app\model\Player::find($playerId);
            if (!$player) {
                return;
            }

            // 获取爆机配置
            $adminUserId = $player->store_admin_id ?? null;
            if (!$adminUserId) {
                return;
            }

            $crashSetting = \app\model\StoreSetting::getSetting(
                'machine_crash_amount',
                $player->department_id,
                null,
                $adminUserId
            );

            // 如果没有配置或配置被禁用，不处理
            if (!$crashSetting || $crashSetting->status != 1) {
                return;
            }

            $crashAmount = $crashSetting->num ?? 0;
            if ($crashAmount <= 0) {
                return;
            }

            // 检查爆机状态变化
            $wasCrashed = $previousBalance !== null ? $previousBalance >= $crashAmount : false;
            $isCrashed = $currentBalance >= $crashAmount;

            // 状态没有变化，不处理
            if ($wasCrashed === $isCrashed) {
                return;
            }

            // 更新爆机状态字段
            \support\Db::table('player_platform_cash')
                ->where('player_id', $playerId)
                ->where('platform_id', 1) // 实体机平台
                ->update(['is_crashed' => $isCrashed ? 1 : 0]);

            // 清除爆机状态缓存
            clearMachineCrashCache($playerId);

            Log::info('WalletService: 爆机状态变化', [
                'player_id' => $playerId,
                'old_status' => $wasCrashed ? '已爆机' : '未爆机',
                'new_status' => $isCrashed ? '已爆机' : '未爆机',
                'current_balance' => $currentBalance,
                'crash_amount' => $crashAmount,
            ]);

            // 从未爆机变为爆机 -> 发送爆机通知
            if (!$wasCrashed && $isCrashed) {
                $crashInfo = [
                    'crashed' => true,
                    'crash_amount' => $crashAmount,
                    'current_amount' => $currentBalance,
                ];
                notifyMachineCrash($player, $crashInfo);
            }

            // 从爆机变为未爆机 -> 发送解锁通知
            if ($wasCrashed && !$isCrashed) {
                checkAndNotifyCrashUnlock($player, $previousBalance);
            }
        } catch (\Throwable $e) {
            Log::error('WalletService: checkMachineCrash failed', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
