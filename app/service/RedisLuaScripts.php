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
     * ARGV[3] = 记录JSON (包含 platform, order_no, player_id, platform_id, game_code 等)
     * ARGV[4] = 当前时间戳
     * ARGV[5] = 记录TTL (604800 = 7天)
     * ARGV[6] = 余额TTL (3600 = 1小时)
     *
     * 返回值：
     * - success: {ok: 1, balance: 新余额, old_balance: 旧余额}
     * - 余额不足: {ok: 0, error: "insufficient_balance", balance: 当前余额}
     * - 重复订单: {ok: 0, error: "duplicate_order", balance: 当前余额}
     */
    public const LUA_ATOMIC_BET = <<<'LUA'
-- 1. 幂等性检查
local lockExists = redis.call('EXISTS', KEYS[5])
if lockExists == 1 then
    local currentBalance = tonumber(redis.call('GET', KEYS[1]) or 0)
    return cjson.encode({ok = 0, error = 'duplicate_order', balance = currentBalance})
end

-- 2. 获取当前余额
local currentBalance = tonumber(redis.call('GET', KEYS[1]) or 0)
local betAmount = tonumber(ARGV[2])

-- 3. 余额检查
if currentBalance < betAmount then
    return cjson.encode({ok = 0, error = 'insufficient_balance', balance = currentBalance})
end

-- 4. 扣款
local newBalance = currentBalance - betAmount
redis.call('SETEX', KEYS[1], ARGV[6], newBalance)

-- 5. 设置幂等性锁
redis.call('SETEX', KEYS[5], 300, 1)

-- 6. 保存下注记录（Hash）
local recordData = cjson.decode(ARGV[3])
recordData.bet_time = ARGV[4]
recordData.status = 'pending'
recordData.settlement_status = 0
recordData.win = 0
recordData.diff = 0

redis.call('HMSET', KEYS[2],
    'platform', recordData.platform,
    'order_no', recordData.order_no,
    'player_id', recordData.player_id,
    'platform_id', recordData.platform_id,
    'amount', recordData.amount,
    'game_code', recordData.game_code or '',
    'game_type', recordData.game_type or '',
    'game_name', recordData.game_name or '',
    'transaction_type', recordData.transaction_type or 'bet',
    'bet_time', recordData.bet_time,
    'original_data', recordData.original_data or '{}',
    'status', 'pending',
    'settlement_status', 0,
    'win', 0,
    'diff', 0,
    'created_at', recordData.created_at
)
redis.call('EXPIRE', KEYS[2], ARGV[5])

-- 7. 加入同步队列
redis.call('ZADD', KEYS[3], ARGV[4], KEYS[2])

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
     * ARGV[5] = 记录TTL
     * ARGV[6] = 余额TTL
     * ARGV[7] = action_data JSON
     * ARGV[8] = 玩家ID
     * ARGV[9] = 平台ID
     * ARGV[10] = settle 记录 JSON（用于独立结算）
     *
     * 返回值：
     * - success: {ok: 1, balance: 新余额, old_balance: 旧余额}
     * - 重复结算: {ok: 0, error: "duplicate_settle", balance: 当前余额}
     */
    public const LUA_ATOMIC_SETTLE = <<<'LUA'
-- 1. 幂等性检查
local lockExists = redis.call('EXISTS', KEYS[5])
if lockExists == 1 then
    local currentBalance = tonumber(redis.call('GET', KEYS[1]) or 0)
    return cjson.encode({ok = 0, error = 'duplicate_settle', balance = currentBalance})
end

-- 2. 获取当前余额
local currentBalance = tonumber(redis.call('GET', KEYS[1]) or 0)
local winAmount = tonumber(ARGV[1])

-- 3. 更新余额（加钱）
local newBalance = currentBalance
if winAmount > 0 then
    newBalance = currentBalance + winAmount
    redis.call('SETEX', KEYS[1], ARGV[6], newBalance)
end

-- 4. 设置幂等性锁
redis.call('SETEX', KEYS[5], 300, 1)

-- 5. 检查 bet 记录是否存在
local betExists = redis.call('EXISTS', KEYS[2])

if betExists == 1 then
    -- 更新 bet 记录
    local betAmount = tonumber(redis.call('HGET', KEYS[2], 'amount') or 0)

    redis.call('HMSET', KEYS[2],
        'win', ARGV[1],
        'diff', ARGV[2],
        'settlement_status', 1,
        'transaction_type', ARGV[3],
        'settle_time', ARGV[4],
        'platform_action_at', os.date('%Y-%m-%d %H:%M:%S', ARGV[4]),
        'action_data', ARGV[7],
        'status', 'pending'
    )

    -- 更新同步队列（提升优先级）
    redis.call('ZADD', KEYS[3], ARGV[4], KEYS[2])
else
    -- bet 不存在，创建独立 settle 记录
    local settleData = cjson.decode(ARGV[10])

    redis.call('HMSET', KEYS[6],
        'platform', settleData.platform,
        'order_no', settleData.order_no .. '_settle',
        'player_id', ARGV[8],
        'platform_id', ARGV[9],
        'amount', 0,
        'win', ARGV[1],
        'diff', ARGV[2],
        'game_code', settleData.game_code or '',
        'settlement_status', 1,
        'transaction_type', ARGV[3],
        'settle_time', ARGV[4],
        'original_data', settleData.original_data or '{}',
        'status', 'pending',
        'created_at', os.date('%Y-%m-%d %H:%M:%S', ARGV[4])
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
     * ARGV[5] = action_data JSON
     *
     * 返回值：
     * - success: {ok: 1, balance: 新余额, old_balance: 旧余额}
     * - 重复取消: {ok: 0, error: "duplicate_cancel", balance: 当前余额}
     */
    public const LUA_ATOMIC_CANCEL = <<<'LUA'
-- 1. 幂等性检查
local lockExists = redis.call('EXISTS', KEYS[5])
if lockExists == 1 then
    local currentBalance = tonumber(redis.call('GET', KEYS[1]) or 0)
    return cjson.encode({ok = 0, error = 'duplicate_cancel', balance = currentBalance})
end

-- 2. 获取当前余额
local currentBalance = tonumber(redis.call('GET', KEYS[1]) or 0)
local refundAmount = tonumber(ARGV[1])

-- 3. 退款
local newBalance = currentBalance + refundAmount
redis.call('SETEX', KEYS[1], ARGV[4], newBalance)

-- 4. 设置幂等性锁
redis.call('SETEX', KEYS[5], 300, 1)

-- 5. 更新记录（如果存在）
local betExists = redis.call('EXISTS', KEYS[2])
if betExists == 1 then
    redis.call('HMSET', KEYS[2],
        'transaction_type', ARGV[2],
        'cancel_time', ARGV[3],
        'action_data', ARGV[5],
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

        // 准备 Keys
        $keys = [
            "wallet:balance:{$playerId}",                    // KEYS[1]
            "game:record:bet:{$platform}:{$orderNo}",        // KEYS[2]
            "game:sync:queue",                               // KEYS[3]
            "game:stats:{$platform}:bet:count",              // KEYS[4]
            "order:bet:lock:{$orderNo}",                     // KEYS[5]
        ];

        // 准备 Arguments（支持新旧参数兼容）
        $transactionType = $data['transaction_type'] ?? TransactionType::mapFromLegacy($data);

        $recordJson = json_encode([
            'platform' => $platform,
            'order_no' => $orderNo,
            'player_id' => $playerId,
            'platform_id' => $data['platform_id'],
            'amount' => $betAmount,
            'game_code' => $data['game_code'] ?? '',
            'game_type' => $data['game_type'] ?? '',
            'game_name' => $data['game_name'] ?? '',
            'transaction_type' => $transactionType,
            'original_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        $argv = [
            $playerId,                  // ARGV[1]
            $betAmount,                 // ARGV[2]
            $recordJson,                // ARGV[3]
            time(),                     // ARGV[4]
            604800,                     // ARGV[5] - 7天 TTL
            3600,                       // ARGV[6] - 1小时 TTL
        ];

        // 执行 Lua 脚本
        $result = Redis::eval(self::LUA_ATOMIC_BET, count($keys), ...array_merge($keys, $argv));

        return json_decode($result, true);
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

        // 准备 Keys
        $keys = [
            "wallet:balance:{$playerId}",                    // KEYS[1]
            "game:record:bet:{$platform}:{$orderNo}",        // KEYS[2]
            "game:sync:queue",                               // KEYS[3]
            "game:stats:{$platform}:settle:count",           // KEYS[4]
            "order:settle:lock:{$orderNo}",                  // KEYS[5]
            "game:record:settle:{$platform}:{$orderNo}",     // KEYS[6]
        ];

        // 准备独立结算记录 JSON（用于 bet 不存在的情况）
        $settleRecordJson = json_encode([
            'platform' => $platform,
            'order_no' => $orderNo,
            'game_code' => $data['game_code'] ?? '',
            'original_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
        ], JSON_UNESCAPED_UNICODE);

        // 支持新旧参数兼容
        $transactionType = $data['transaction_type'] ?? TransactionType::mapFromLegacy($data);

        $argv = [
            $winAmount,                                      // ARGV[1]
            $diff,                                           // ARGV[2]
            $transactionType,                                // ARGV[3]
            time(),                                          // ARGV[4]
            604800,                                          // ARGV[5]
            3600,                                            // ARGV[6]
            json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE), // ARGV[7]
            $playerId,                                       // ARGV[8]
            $data['platform_id'],                            // ARGV[9]
            $settleRecordJson,                               // ARGV[10]
        ];

        $result = Redis::eval(self::LUA_ATOMIC_SETTLE, count($keys), ...array_merge($keys, $argv));

        return json_decode($result, true);
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

        // 准备 Keys
        $keys = [
            "wallet:balance:{$playerId}",                    // KEYS[1]
            "game:record:bet:{$platform}:{$orderNo}",        // KEYS[2]
            "game:sync:queue",                               // KEYS[3]
            "game:stats:{$platform}:cancel:count",           // KEYS[4]
            "order:cancel:lock:{$orderNo}",                  // KEYS[5]
        ];

        // 支持新旧参数兼容
        $transactionType = $data['transaction_type'] ?? TransactionType::mapFromLegacy($data);

        $argv = [
            $refundAmount,                                   // ARGV[1]
            $transactionType,                                // ARGV[2]
            time(),                                          // ARGV[3]
            3600,                                            // ARGV[4]
            json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE), // ARGV[5]
        ];

        $result = Redis::eval(self::LUA_ATOMIC_CANCEL, count($keys), ...array_merge($keys, $argv));

        return json_decode($result, true);
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
}
