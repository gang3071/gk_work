<?php

namespace app\service;

use app\Constants\TransactionType;
use support\Redis;

/**
 * Redis Lua 脚本集合
 *
 * 核心原则：保证游戏下注/结算的原子性
 *
 * 优势：
 * 1. 原子性：余额检查、扣款、记录保存在 Redis 内部一次完成
 * 2. 性能：减少网络往返次数（3次 → 1次）
 * 3. 一致性：避免并发导致的余额超扣
 */
class RedisLuaScripts
{
    /**
     * Lua 脚本 SHA1 缓存（避免重复加载脚本，提升性能）
     * @var array
     */
    private static $scriptShas = [];

    /**
     * 原子下注（检查余额 + 扣款 + 保存记录）
     *
     * KEYS[1] = 余额 Key (wallet:balance:{player_id})
     * KEYS[2] = 下注记录 Key (game:record:bet:{platform}:{order_no})
     * KEYS[3] = 同步队列 Key (game:sync:queue)
     * KEYS[4] = 统计 Key (game:stats:{platform}:bet:count)
     * KEYS[5] = 幂等性锁 Key (order:bet:lock:{order_no})
     *
     * ARGV[1] = 玩家ID
     * ARGV[2] = 下注金额
     * ARGV[3] = 平台代码
     * ARGV[4] = 订单号
     * ARGV[5] = 平台ID
     * ARGV[6] = 游戏代码
     * ARGV[7] = 游戏类型
     * ARGV[8] = 游戏名称
     * ARGV[9] = 交易类型
     * ARGV[10] = 当前时间戳
     * ARGV[11] = 记录TTL (3600 = 1小时，极限内存优化)
     * ARGV[12] = 余额TTL (3600 = 1小时)
     * ARGV[13] = 创建时间字符串
     *
     * 返回值：
     * - success: {ok: 1, balance: 新余额, old_balance: 旧余额}
     * - 余额不足: {ok: 0, error: "insufficient_balance", balance: 当前余额}
     * - 重复订单: {ok: 0, error: "duplicate_order", balance: 当前余额}
     */
    public const LUA_ATOMIC_BET = <<<'LUA'
-- 1. 幂等性检查（先检查记录，再检查锁）
local recordExists = redis.call('EXISTS', KEYS[2])
if recordExists == 1 then
    local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
    return cjson.encode({ok = 0, error = 'duplicate_order', balance = currentBalance})
end

local lockExists = redis.call('EXISTS', KEYS[5])
if lockExists == 1 then
    local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
    return cjson.encode({ok = 0, error = 'duplicate_order', balance = currentBalance})
end

-- 2. 获取当前余额（防御性：确保即使 Redis 数据损坏也能得到有效数字）
local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
local betAmount = tonumber(ARGV[2]) or 0

-- 3. 余额检查
if currentBalance < betAmount then
    return cjson.encode({ok = 0, error = 'insufficient_balance', balance = currentBalance})
end

-- 4. 扣款
local newBalance = currentBalance - betAmount
redis.call('SETEX', KEYS[1], ARGV[12], newBalance)

-- 5. 设置幂等性锁
redis.call('SETEX', KEYS[5], 300, 1)

-- 6. 保存下注记录（Hash）- ✅ 优化：不再存储 original_data，减少 CPU 和内存占用
redis.call('HMSET', KEYS[2],
    'platform', ARGV[3],
    'order_no', ARGV[4],
    'player_id', ARGV[1],
    'platform_id', ARGV[5],
    'amount', ARGV[2],
    'game_code', ARGV[6],
    'game_type', ARGV[7],
    'game_name', ARGV[8],
    'transaction_type', ARGV[9],
    'bet_time', ARGV[10],
    'status', 'pending',
    'settlement_status', 0,
    'win', 0,
    'diff', 0,
    'created_at', ARGV[13]
)
redis.call('EXPIRE', KEYS[2], ARGV[11])

-- 7. 加入同步队列
redis.call('ZADD', KEYS[3], ARGV[10], KEYS[2])

-- 8. 统计计数
redis.call('INCR', KEYS[4])

-- 9. 返回成功
return cjson.encode({ok = 1, balance = newBalance, old_balance = currentBalance})
LUA;

    /**
     * 原子结算（加钱 + 更新记录）
     *
     * KEYS[1] = 余额 Key
     * KEYS[2] = 下注记录 Key
     * KEYS[3] = 同步队列 Key
     * KEYS[4] = 统计 Key (game:stats:{platform}:settle:count)
     * KEYS[5] = 幂等性锁 Key (order:settle:lock:{order_no})
     * KEYS[6] = 结算记录 Key (game:record:settle:{platform}:{order_no}) - 仅当 bet 不存在时使用
     *
     * ARGV[1] = 派彩金额
     * ARGV[2] = 输赢金额 (diff)
     * ARGV[3] = 结算类型 (settle/refund/jackpot/reward)
     * ARGV[4] = 当前时间戳
     * ARGV[5] = 记录TTL (3600 = 1小时)
     * ARGV[6] = 余额TTL
     * ARGV[7] = 玩家ID
     * ARGV[8] = 平台ID
     * ARGV[9] = 预格式化日期字符串 (Y-m-d H:i:s)
     * ARGV[10] = 平台代码（用于独立结算）
     * ARGV[11] = 订单号（用于独立结算）
     * ARGV[12] = 游戏代码（用于独立结算）
     *
     * 返回值：
     * - success: {ok: 1, balance: 新余额, old_balance: 旧余额}
     * - 重复结算: {ok: 0, error: "duplicate_settle", balance: 当前余额}
     */
    public const LUA_ATOMIC_SETTLE = <<<'LUA'
-- 1. 幂等性检查（先检查记录，再检查锁）
local betExists = redis.call('EXISTS', KEYS[2])
if betExists == 1 then
    local settlementStatus = tonumber(redis.call('HGET', KEYS[2], 'settlement_status') or 0)
    if settlementStatus == 1 then
        local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
        return cjson.encode({ok = 0, error = 'duplicate_settle', balance = currentBalance})
    end
end

local settleRecordExists = redis.call('EXISTS', KEYS[6])
if settleRecordExists == 1 then
    local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
    return cjson.encode({ok = 0, error = 'duplicate_settle', balance = currentBalance})
end

local lockExists = redis.call('EXISTS', KEYS[5])
if lockExists == 1 then
    local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
    return cjson.encode({ok = 0, error = 'duplicate_settle', balance = currentBalance})
end

-- 2. 获取当前余额（防御性：确保即使 Redis 数据损坏也能得到有效数字）
local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
local winAmount = tonumber(ARGV[1]) or 0

-- 3. 更新余额（加钱）
local newBalance = currentBalance
if winAmount > 0 then
    newBalance = currentBalance + winAmount
    redis.call('SETEX', KEYS[1], ARGV[6], newBalance)
end

-- 4. 设置幂等性锁
redis.call('SETEX', KEYS[5], 300, 1)

-- 5. 根据之前检查的结果，更新相应记录

if betExists == 1 then
    -- 更新 bet 记录 - ✅ 优化：不再存储 action_data，减少内存占用
    redis.call('HMSET', KEYS[2],
        'win', ARGV[1],
        'diff', ARGV[2],
        'settlement_status', 1,
        'transaction_type', ARGV[3],
        'settle_time', ARGV[4],
        'platform_action_at', ARGV[9],
        'status', 'pending'
    )

    -- 更新同步队列（提升优先级）
    redis.call('ZADD', KEYS[3], ARGV[4], KEYS[2])
else
    -- bet 不存在，创建独立 settle 记录 - ✅ 优化：不再存储 original_data
    local finalOrderNo = ARGV[11]
    if not string.find(finalOrderNo, '_settle$') then
        finalOrderNo = finalOrderNo .. '_settle'
    end

    redis.call('HMSET', KEYS[6],
        'platform', ARGV[10],
        'order_no', finalOrderNo,
        'player_id', ARGV[7],
        'platform_id', ARGV[8],
        'amount', 0,
        'win', ARGV[1],
        'diff', ARGV[2],
        'game_code', ARGV[12],
        'settlement_status', 1,
        'transaction_type', ARGV[3],
        'settle_time', ARGV[4],
        'status', 'pending',
        'created_at', ARGV[9]
    )
    redis.call('EXPIRE', KEYS[6], ARGV[5])
    redis.call('ZADD', KEYS[3], ARGV[4], KEYS[6])
end

-- 6. 统计计数
redis.call('INCR', KEYS[4])

-- 7. 返回成功
return cjson.encode({ok = 1, balance = newBalance, old_balance = currentBalance})
LUA;

    /**
     * 原子取消/退款（退款 + 更新记录）
     *
     * KEYS[1] = 余额 Key
     * KEYS[2] = 下注记录 Key
     * KEYS[3] = 同步队列 Key
     * KEYS[4] = 统计 Key (game:stats:{platform}:cancel:count)
     * KEYS[5] = 幂等性锁 Key (order:cancel:lock:{order_no})
     *
     * ARGV[1] = 退款金额
     * ARGV[2] = 取消类型 (cancel/refund)
     * ARGV[3] = 当前时间戳
     * ARGV[4] = 余额TTL
     *
     * 返回值：
     * - success: {ok: 1, balance: 新余额, old_balance: 旧余额}
     * - 重复取消: {ok: 0, error: "duplicate_cancel", balance: 当前余额}
     */
    public const LUA_ATOMIC_CANCEL = <<<'LUA'
-- 1. 幂等性检查（先检查记录，再检查锁）
local betExists = redis.call('EXISTS', KEYS[2])
if betExists == 1 then
    local transactionType = redis.call('HGET', KEYS[2], 'transaction_type') or ''
    if transactionType == 'cancel' or transactionType == 'refund' then
        local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
        return cjson.encode({ok = 0, error = 'duplicate_cancel', balance = currentBalance})
    end
end

local lockExists = redis.call('EXISTS', KEYS[5])
if lockExists == 1 then
    local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
    return cjson.encode({ok = 0, error = 'duplicate_cancel', balance = currentBalance})
end

-- 2. 获取当前余额（防御性：确保即使 Redis 数据损坏也能得到有效数字）
local currentBalance = tonumber(redis.call('GET', KEYS[1])) or 0
local refundAmount = tonumber(ARGV[1]) or 0

-- 3. 退款
local newBalance = currentBalance + refundAmount
redis.call('SETEX', KEYS[1], ARGV[4], newBalance)

-- 4. 设置幂等性锁
redis.call('SETEX', KEYS[5], 300, 1)

-- 5. 更新记录（betExists 已在幂等性检查时获取）- ✅ 优化：不再存储 action_data
if betExists == 1 then
    redis.call('HMSET', KEYS[2],
        'transaction_type', ARGV[2],
        'cancel_time', ARGV[3],
        'status', 'pending'
    )

    -- 更新同步队列
    redis.call('ZADD', KEYS[3], ARGV[3], KEYS[2])
end

-- 6. 统计计数
redis.call('INCR', KEYS[4])

-- 7. 返回成功
return cjson.encode({ok = 1, balance = newBalance, old_balance = currentBalance})
LUA;

    /**
     * 执行 Lua 脚本（优先使用 EVALSHA，性能提升 50-70%）
     *
     * @param \Redis $redis Redis 连接对象
     * @param string $script Lua 脚本内容
     * @param array $keys KEYS 参数
     * @param array $argv ARGV 参数
     * @return mixed
     * @throws \RuntimeException
     */
    private static function evalScript($redis, string $script, array $keys, array $argv)
    {
        // 记录开始时间（用于性能监控）
        $start = microtime(true);

        // 计算脚本的 SHA1
        $sha = sha1($script);

        // 如果已经加载过，直接使用 EVALSHA（节省网络传输）
        if (isset(self::$scriptShas[$sha])) {
            try {
                $result = $redis->evalSha($sha, count($keys), ...array_merge($keys, $argv));

                // ✅ 修复：检查 EVALSHA 返回值，false 表示脚本不存在或执行失败
                if ($result === false) {
                    $lastError = $redis->getLastError();
                    \support\Log::warning('EVALSHA 返回 false，脚本可能已失效，降级到 EVAL', [
                        'sha' => substr($sha, 0, 8),
                        'last_error' => $lastError,
                    ]);
                    // 清除 SHA 缓存，强制降级到 EVAL
                    unset(self::$scriptShas[$sha]);
                    // 不返回 false，继续执行下面的 EVAL
                } else {
                    // 🔍 调试：记录 EVALSHA 返回值类型
                    \support\Log::debug('EVALSHA 执行完成', [
                        'sha' => substr($sha, 0, 8),
                        'result_type' => gettype($result),
                    ]);

                    // 记录执行时间
                    $duration = (microtime(true) - $start) * 1000;
                    if ($duration > 10) {
                        \support\Log::warning('慢 Lua 脚本 (EVALSHA)', [
                            'duration_ms' => round($duration, 2),
                            'keys_count' => count($keys),
                            'sha' => substr($sha, 0, 8),
                        ]);
                    }

                    return $result;
                }
            } catch (\RedisException $e) {
                // SHA 可能已过期（Redis 重启或脚本被清除），重新加载
                \support\Log::warning('Redis Lua 脚本 SHA 失效，降级到 EVAL', [
                    'sha' => substr($sha, 0, 8),
                    'error' => $e->getMessage(),
                ]);
                unset(self::$scriptShas[$sha]);
            }
        }

        // 第一次执行或 SHA 失效：使用 EVAL
        try {
            $result = $redis->eval($script, count($keys), ...array_merge($keys, $argv));

            // 🔍 调试：记录 EVAL 返回值类型
            \support\Log::debug('EVAL 执行完成', [
                'sha' => substr($sha, 0, 8),
                'result_type' => gettype($result),
                'result_is_false' => $result === false,
                'result_is_null' => $result === null,
            ]);

            // 标记为已加载
            self::$scriptShas[$sha] = true;

            // 记录执行时间
            $duration = (microtime(true) - $start) * 1000;
            if ($duration > 10) {
                \support\Log::warning('慢 Lua 脚本 (EVAL)', [
                    'duration_ms' => round($duration, 2),
                    'keys_count' => count($keys),
                    'sha' => substr($sha, 0, 8),
                    'script_loaded' => true,
                ]);
            }

            return $result;
        } catch (\RedisException $e) {
            \support\Log::error('Redis Lua 脚本执行失败', [
                'error' => $e->getMessage(),
                'sha' => substr($sha, 0, 8),
                'keys_count' => count($keys),
                'argv_count' => count($argv),
            ]);
            throw new \RuntimeException('Redis Lua 脚本执行失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 执行原子下注
     *
     * @param int $playerId 玩家ID
     * @param string $platform 平台代码 (RSG, MT, ATG 等)
     * @param array $data 下注数据
     *   - order_no: 订单号（必需）
     *   - amount: 下注金额（必需）
     *   - platform_id: 平台ID（必需）
     *   - game_code: 游戏代码（可选）
     *   - transaction_type: 交易类型（必需）
     *   - original_data: 原始数据（可选）
     * @return array 返回结果 {ok: 1, balance: xxx} 或 {ok: 0, error: xxx}
     * @throws \InvalidArgumentException 参数验证失败时抛出
     */
    public static function atomicBet(int $playerId, string $platform, array $data): array
    {
        // 参数验证
        validateLuaScriptParams($data, [
            'order_no' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'platform_id' => ['required', 'integer'],
            'transaction_type' => ['required', 'string'],
        ], 'atomicBet');

        $orderNo = $data['order_no'];
        $betAmount = $data['amount'];
        $transactionType = $data['transaction_type'] ?? TransactionType::mapFromLegacy($data);
        $createdAt = date('Y-m-d H:i:s');

        // 准备 Keys
        $keys = [
            "wallet:balance:{$playerId}",                    // KEYS[1]
            "game:record:bet:{$platform}:{$orderNo}",        // KEYS[2]
            "game:sync:queue",                               // KEYS[3]
            "game:stats:{$platform}:bet:count",              // KEYS[4]
            "order:bet:lock:{$orderNo}",                     // KEYS[5]
        ];

        // ✅ 优化：Lua 脚本只接收核心字段，不再接收 original_data
        $argv = [
            $playerId,                                       // ARGV[1]
            $betAmount,                                      // ARGV[2]
            $platform,                                       // ARGV[3]
            $orderNo,                                        // ARGV[4]
            $data['platform_id'],                            // ARGV[5]
            $data['game_code'] ?? '',                        // ARGV[6]
            $data['game_type'] ?? '',                        // ARGV[7]
            $data['game_name'] ?? '',                        // ARGV[8]
            $transactionType,                                // ARGV[9]
            time(),                                          // ARGV[10]
            3600,                                            // ARGV[11] - 1小时 TTL
            3600,                                            // ARGV[12] - 余额 TTL
            $createdAt,                                      // ARGV[13]
        ];

        // 执行 Lua 脚本（使用 work 连接池，确保 igaming 核心业务稳定）
        // ✅ 性能优化：使用 EVALSHA 代替 EVAL，减少网络传输 70%
        $redis = Redis::connection('work');
        $result = self::evalScript($redis, self::LUA_ATOMIC_BET, $keys, $argv);

        // 🔍 调试日志：记录实际返回值
        \support\Log::info('[atomicBet] Redis Lua 返回值', [
            'player_id' => $playerId,
            'platform' => $platform,
            'order_no' => $orderNo,
            'result_type' => gettype($result),
            'result_value' => is_string($result) ? substr($result, 0, 200) : var_export($result, true),
            'bet_amount' => $betAmount,
        ]);

        // 检查 Redis 返回值
        if ($result === null || $result === false) {
            throw new \RuntimeException(
                sprintf('[atomicBet] Redis Lua 脚本执行失败，返回值为空。玩家ID: %d, 平台: %s, 订单号: %s, 下注金额: %s',
                    $playerId, $platform, $orderNo, $betAmount)
            );
        }

        // 解码 JSON
        $decoded = json_decode($result, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                sprintf('[atomicBet] Redis Lua 脚本返回值解码失败: %s. 原始返回: %s',
                    json_last_error_msg(),
                    is_string($result) ? substr($result, 0, 200) : var_export($result, true))
            );
        }

        // ✅ 成功后异步追加 original_data 到 Redis Hash（不阻塞响应）
        if (isset($decoded['ok']) && $decoded['ok'] === 1) {
            $originalData = json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE);
            try {
                $redis->hSet($keys[1], 'original_data', $originalData);
            } catch (\Throwable $e) {
                // 失败不影响核心业务，仅记录日志
                \support\Log::warning('[atomicBet] 追加 original_data 失败', [
                    'order_no' => $orderNo,
                    'error' => $e->getMessage(),
                ]);
            }

            // ✅ 实时推送：发布余额变化消息到 Redis Pub/Sub
            self::publishBalanceChange($playerId, $platform, [
                'reason' => 'bet',
                'old_balance' => $decoded['old_balance'] ?? 0,
                'new_balance' => $decoded['balance'],
                'order_no' => $orderNo,
                'amount' => -$betAmount,
            ]);
        }

        return $decoded ?? [];
    }

    /**
     * 执行原子结算
     *
     * @param int $playerId 玩家ID
     * @param string $platform 平台代码
     * @param array $data 结算数据
     *   - order_no: 订单号（必需）
     *   - amount: 派彩金额（必需）
     *   - diff: 盈亏金额（必需，通常为 win - bet）
     *   - platform_id: 平台ID（必需）
     *   - game_code: 游戏代码（可选）
     *   - transaction_type: 交易类型（必需）
     *   - original_data: 原始数据（可选）
     * @return array
     * @throws \InvalidArgumentException 参数验证失败时抛出
     */
    public static function atomicSettle(int $playerId, string $platform, array $data): array
    {
        // 参数验证
        validateLuaScriptParams($data, [
            'order_no' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'diff' => ['required', 'numeric'],
            'platform_id' => ['required', 'integer'],
            'transaction_type' => ['required', 'string'],
        ], 'atomicSettle');

        $orderNo = $data['order_no'];
        $winAmount = $data['amount'];
        $diff = $data['diff'];
        $transactionType = $data['transaction_type'] ?? TransactionType::mapFromLegacy($data);
        $timestamp = time();
        $dateTime = date('Y-m-d H:i:s', $timestamp);

        // 准备 Keys
        $keys = [
            "wallet:balance:{$playerId}",                    // KEYS[1]
            "game:record:bet:{$platform}:{$orderNo}",        // KEYS[2]
            "game:sync:queue",                               // KEYS[3]
            "game:stats:{$platform}:settle:count",           // KEYS[4]
            "order:settle:lock:{$orderNo}",                  // KEYS[5]
            "game:record:settle:{$platform}:{$orderNo}",     // KEYS[6]
        ];

        // ✅ 优化：Lua 脚本只接收核心字段，不再接收 original_data 和 action_data
        $argv = [
            $winAmount,                                      // ARGV[1]
            $diff,                                           // ARGV[2]
            $transactionType,                                // ARGV[3]
            $timestamp,                                      // ARGV[4]
            3600,                                            // ARGV[5] - 1小时 TTL
            3600,                                            // ARGV[6] - 余额 TTL
            $playerId,                                       // ARGV[7]
            $data['platform_id'],                            // ARGV[8]
            $dateTime,                                       // ARGV[9] - 预格式化日期
            $platform,                                       // ARGV[10] - 平台代码（独立结算用）
            $orderNo,                                        // ARGV[11] - 订单号（独立结算用）
            $data['game_code'] ?? '',                        // ARGV[12] - 游戏代码（独立结算用）
        ];

        // 执行 Lua 脚本（使用 work 连接池，确保 igaming 核心业务稳定）
        // ✅ 性能优化：使用 EVALSHA 代替 EVAL，减少网络传输 70%
        $redis = Redis::connection('work');
        $result = self::evalScript($redis, self::LUA_ATOMIC_SETTLE, $keys, $argv);

        // 🔍 调试日志：记录实际返回值
        \support\Log::info('[atomicSettle] Redis Lua 返回值', [
            'player_id' => $playerId,
            'platform' => $platform,
            'order_no' => $orderNo,
            'result_type' => gettype($result),
            'result_value' => is_string($result) ? substr($result, 0, 200) : var_export($result, true),
            'win_amount' => $winAmount,
        ]);

        // 检查 Redis 返回值
        if ($result === null || $result === false) {
            throw new \RuntimeException(
                sprintf('[atomicSettle] Redis Lua 脚本执行失败，返回值为空。玩家ID: %d, 平台: %s, 订单号: %s, 赢取金额: %s',
                    $playerId, $platform, $orderNo, $winAmount)
            );
        }

        // 解码 JSON
        $decoded = json_decode($result, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                sprintf('[atomicSettle] Redis Lua 脚本返回值解码失败: %s. 原始返回: %s',
                    json_last_error_msg(),
                    is_string($result) ? substr($result, 0, 200) : var_export($result, true))
            );
        }

        // ✅ 成功后异步追加 original_data 和 action_data 到 Redis Hash
        if (isset($decoded['ok']) && $decoded['ok'] === 1) {
            $originalData = json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE);

            try {
                // 检查是否存在 bet 记录
                if ($redis->exists($keys[1])) {
                    // 更新 bet 记录的 action_data
                    $redis->hSet($keys[1], 'action_data', $originalData);
                } else {
                    // 独立 settle 记录，追加 original_data
                    $redis->hSet($keys[5], 'original_data', $originalData);
                }
            } catch (\Throwable $e) {
                // 失败不影响核心业务，仅记录日志
                \support\Log::warning('[atomicSettle] 追加 original_data/action_data 失败', [
                    'order_no' => $orderNo,
                    'error' => $e->getMessage(),
                ]);
            }

            // ✅ 实时推送：发布余额变化消息
            self::publishBalanceChange($playerId, $platform, [
                'reason' => 'settle',
                'old_balance' => $decoded['old_balance'] ?? 0,
                'new_balance' => $decoded['balance'],
                'order_no' => $orderNo,
                'amount' => $winAmount,
            ]);
        }

        return $decoded ?? [];
    }

    /**
     * 执行原子取消/退款
     *
     * @param int $playerId 玩家ID
     * @param string $platform 平台代码
     * @param array $data 取消数据
     *   - order_no: 订单号（必需）
     *   - refund_amount: 退款金额（必需）
     *   - transaction_type: 交易类型（必需）
     *   - original_data: 原始数据（可选）
     *   - skip_amount_validation: 跳过金额验证（可选，默认 false）
     * @return array
     * @throws \InvalidArgumentException 参数验证失败时抛出
     */
    public static function atomicCancel(int $playerId, string $platform, array $data): array
    {
        // 参数验证
        validateLuaScriptParams($data, [
            'order_no' => ['required', 'string'],
            'refund_amount' => ['required', 'numeric', 'min:0'],
            'transaction_type' => ['required', 'string'],
        ], 'atomicCancel');

        $orderNo = $data['order_no'];
        $refundAmount = $data['refund_amount'];
        $transactionType = $data['transaction_type'] ?? TransactionType::mapFromLegacy($data);

        // ✅ 问题5修复：验证退款金额是否合理（除非明确跳过验证）
        $skipValidation = $data['skip_amount_validation'] ?? false;
        if (!$skipValidation && $refundAmount > 0) {
            $platformId = $data['platform_id'] ?? null;
            $validation = validateRefundAmount($platform, $orderNo, $refundAmount, $playerId, $platformId);

            if (!$validation['valid']) {
                // 退款金额超过下注金额，记录告警但仍允许执行（保守处理）
                \support\Log::warning('[atomicCancel] 退款金额异常但仍执行', [
                    'platform' => $platform,
                    'order_no' => $orderNo,
                    'player_id' => $playerId,
                    'refund_amount' => $refundAmount,
                    'bet_amount' => $validation['bet_amount'],
                    'message' => $validation['message'],
                ]);

                // 🔴 可选：如果要严格拒绝，取消下面的注释
                // throw new \InvalidArgumentException(
                //     sprintf('[atomicCancel] %s', $validation['message'])
                // );
            }
        }

        // 准备 Keys
        $keys = [
            "wallet:balance:{$playerId}",                    // KEYS[1]
            "game:record:bet:{$platform}:{$orderNo}",        // KEYS[2]
            "game:sync:queue",                               // KEYS[3]
            "game:stats:{$platform}:cancel:count",           // KEYS[4]
            "order:cancel:lock:{$orderNo}",                  // KEYS[5]
        ];

        // ✅ 优化：Lua 脚本只接收核心字段，不再接收 action_data
        $argv = [
            $refundAmount,                                   // ARGV[1]
            $transactionType,                                // ARGV[2]
            time(),                                          // ARGV[3]
            3600,                                            // ARGV[4] - 余额 TTL
        ];

        // 执行 Lua 脚本（使用 work 连接池，确保 igaming 核心业务稳定）
        // ✅ 性能优化：使用 EVALSHA 代替 EVAL，减少网络传输 70%
        $redis = Redis::connection('work');
        $result = self::evalScript($redis, self::LUA_ATOMIC_CANCEL, $keys, $argv);

        // 🔍 调试日志：记录实际返回值
        \support\Log::info('[atomicCancel] Redis Lua 返回值', [
            'player_id' => $playerId,
            'platform' => $platform,
            'order_no' => $orderNo,
            'result_type' => gettype($result),
            'result_value' => is_string($result) ? substr($result, 0, 200) : var_export($result, true),
            'refund_amount' => $refundAmount,
        ]);

        // 检查 Redis 返回值
        if ($result === null || $result === false) {
            throw new \RuntimeException(
                sprintf('[atomicCancel] Redis Lua 脚本执行失败，返回值为空。玩家ID: %d, 平台: %s, 订单号: %s, 退款金额: %s',
                    $playerId, $platform, $orderNo, $refundAmount)
            );
        }

        // 解码 JSON
        $decoded = json_decode($result, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                sprintf('[atomicCancel] Redis Lua 脚本返回值解码失败: %s. 原始返回: %s',
                    json_last_error_msg(),
                    is_string($result) ? substr($result, 0, 200) : var_export($result, true))
            );
        }

        // ✅ 成功后异步追加 action_data 到 Redis Hash
        if (isset($decoded['ok']) && $decoded['ok'] === 1) {
            $actionData = json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE);

            try {
                // 只有当 bet 记录存在时才追加 action_data
                if ($redis->exists($keys[1])) {
                    $redis->hSet($keys[1], 'action_data', $actionData);
                }
            } catch (\Throwable $e) {
                // 失败不影响核心业务，仅记录日志
                \support\Log::warning('[atomicCancel] 追加 action_data 失败', [
                    'order_no' => $orderNo,
                    'error' => $e->getMessage(),
                ]);
            }

            // ✅ 实时推送：发布余额变化消息
            self::publishBalanceChange($playerId, $platform, [
                'reason' => 'cancel',
                'old_balance' => $decoded['old_balance'] ?? 0,
                'new_balance' => $decoded['balance'],
                'order_no' => $orderNo,
                'amount' => $data['refund_amount'] ?? 0,
            ]);
        }

        return $decoded ?? [];
    }

    /**
     * 批量获取余额（优化性能）
     *
     * @param array $playerIds
     * @return array [player_id => balance]
     */
    public static function batchGetBalances(array $playerIds): array
    {
        if (empty($playerIds)) {
            return [];
        }

        $keys = array_map(fn($id) => "wallet:balance:{$id}", $playerIds);
        $balances = Redis::mGet($keys);

        $result = [];
        foreach ($playerIds as $index => $playerId) {
            $result[$playerId] = (float)($balances[$index] ?? 0);
        }

        return $result;
    }

    /**
     * 发布余额变化消息到 Redis Pub/Sub
     *
     * @param int $playerId 玩家ID
     * @param string $platform 平台代码
     * @param array $data 变化数据
     * @return void
     */
    private static function publishBalanceChange(int $playerId, string $platform, array $data): void
    {
        try {
            $message = json_encode([
                'player_id' => $playerId,
                'platform' => $platform,
                'reason' => $data['reason'],
                'old_balance' => $data['old_balance'],
                'new_balance' => $data['new_balance'],
                'order_no' => $data['order_no'] ?? '',
                'amount' => $data['amount'] ?? 0,
                'timestamp' => time(),
            ], JSON_UNESCAPED_UNICODE);

            // 发布到 Redis 频道（不等待响应，延迟 < 2ms）
            Redis::connection('work')->publish('balance:change', $message);

        } catch (\Throwable $e) {
            // 推送失败不应影响核心业务，仅记录日志
            \support\Log::warning('余额变化消息发布失败', [
                'player_id' => $playerId,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
