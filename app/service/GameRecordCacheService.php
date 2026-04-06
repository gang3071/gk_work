<?php

namespace app\service;

use support\Redis;

/**
 * 游戏记录缓存服务
 *
 * 设计原则：
 * 1. Controller 只写 Redis（<1ms）
 * 2. 定时同步到 MySQL（批量）
 * 3. Redis 持久化保障数据安全
 * 4. 7天 TTL，过期自动清理
 */
class GameRecordCacheService
{
    /**
     * Redis Key 前缀
     */
    private const PREFIX_BET = 'game:record:bet:';
    private const PREFIX_SETTLE = 'game:record:settle:';
    private const PREFIX_SYNC_QUEUE = 'game:sync:queue';
    private const PREFIX_BALANCE = 'wallet:balance:';

    /**
     * TTL 配置
     */
    private const TTL_RECORD = 604800;  // 7天
    private const TTL_BALANCE = 3600;   // 1小时

    /**
     * 保存下注记录到 Redis
     *
     * @param string $platform 平台代码（RSG, MT, BTG 等）
     * @param array $data 下注数据
     *   - order_no: 订单号（必需）
     *   - player_id: 玩家ID（必需）
     *   - platform_id: 平台ID（必需）
     *   - amount: 下注金额（必需）
     *   - game_code: 游戏代码（可选）
     *   - game_type: 游戏类型（可选）
     *   - game_name: 游戏名称（可选）
     *   - bet_type: 下注类型（可选：bet, prepay, 默认 bet）
     *   - original_data: 原始请求数据（可选）
     */
    public static function saveBet(string $platform, array $data): void
    {
        $orderNo = $data['order_no'];
        $key = self::PREFIX_BET . "{$platform}:{$orderNo}";

        $record = [
            'platform' => $platform,
            'order_no' => $orderNo,
            'player_id' => $data['player_id'],
            'platform_id' => $data['platform_id'],
            'amount' => $data['amount'],
            'game_code' => $data['game_code'] ?? '',
            'game_type' => $data['game_type'] ?? '',
            'game_name' => $data['game_name'] ?? '',
            'bet_type' => $data['bet_type'] ?? 'bet',  // bet | prepay
            'bet_time' => time(),
            'original_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',  // pending | synced | failed
            'settlement_status' => 0,  // 未结算
            'win' => 0,
            'diff' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // 写入 Redis Hash
        Redis::hMSet($key, $record);
        Redis::expire($key, self::TTL_RECORD);

        // 加入同步队列
        Redis::zAdd(self::PREFIX_SYNC_QUEUE, time(), $key);

        // 记录统计
        Redis::incr("game:stats:{$platform}:bet:count");
    }

    /**
     * 保存结算记录到 Redis
     *
     * @param string $platform 平台代码
     * @param array $data 结算数据
     *   - order_no: 订单号（必需）
     *   - player_id: 玩家ID（必需）
     *   - platform_id: 平台ID（必需）
     *   - amount: 派彩金额（必需）
     *   - diff: 输赢金额（可选，会自动计算）
     *   - settle_type: 结算类型（可选：settle, refund, jackpot, adjust, reward, 默认 settle）
     *   - original_data: 原始请求数据（可选）
     */
    public static function saveSettle(string $platform, array $data): void
    {
        $orderNo = $data['order_no'];
        $betKey = self::PREFIX_BET . "{$platform}:{$orderNo}";

        // 检查是否存在 bet 记录
        $betExists = Redis::exists($betKey);

        if ($betExists) {
            // 更新 bet 记录
            $betAmount = Redis::hGet($betKey, 'amount') ?? 0;

            Redis::hMSet($betKey, [
                'win' => $data['amount'],
                'diff' => $data['diff'] ?? bcsub($data['amount'], $betAmount, 2),
                'settlement_status' => 1,  // 已结算
                'settle_type' => $data['settle_type'] ?? 'settle',  // settle | refund | jackpot | adjust | reward
                'settle_time' => time(),
                'platform_action_at' => date('Y-m-d H:i:s'),
                'action_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',  // 重新标记待同步
            ]);

            // 更新同步队列（提升优先级）
            Redis::zAdd(self::PREFIX_SYNC_QUEUE, time(), $betKey);

        } else {
            // bet 记录不存在，创建独立 settle 记录
            $settleKey = self::PREFIX_SETTLE . "{$platform}:{$orderNo}";

            $record = [
                'platform' => $platform,
                'order_no' => $orderNo . '_settle',  // 加后缀
                'player_id' => $data['player_id'],
                'platform_id' => $data['platform_id'],
                'amount' => 0,
                'win' => $data['amount'],
                'diff' => $data['amount'],
                'game_code' => $data['game_code'] ?? '',
                'game_type' => $data['game_type'] ?? '',
                'settlement_status' => 1,
                'settle_type' => $data['settle_type'] ?? 'settle',
                'settle_time' => time(),
                'original_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ];

            Redis::hMSet($settleKey, $record);
            Redis::expire($settleKey, self::TTL_RECORD);
            Redis::zAdd(self::PREFIX_SYNC_QUEUE, time(), $settleKey);
        }

        // 记录统计
        Redis::incr("game:stats:{$platform}:settle:count");
    }

    /**
     * 获取待同步记录
     *
     * @param int $limit 每次获取数量
     * @return array
     */
    /**
     * 获取待同步记录（原子性，多进程安全）
     *
     * 使用 Lua 脚本原子性地：
     * 1. 读取记录
     * 2. 标记为 'processing' 状态
     * 3. 设置处理超时（防止进程崩溃导致记录永久锁定）
     *
     * @param int $limit 最大获取数量
     * @return array
     */
    public static function getPendingSyncRecords(int $limit = 100): array
    {
        $queueKey = self::PREFIX_SYNC_QUEUE;
        $processTimeout = 60; // 处理超时时间（秒）
        $currentTime = time();

        // Lua 脚本：原子性地获取并标记记录
        $luaScript = <<<'LUA'
local queue_key = KEYS[1]
local limit = tonumber(ARGV[1])
local current_time = tonumber(ARGV[2])
local timeout = tonumber(ARGV[3])

-- 获取队列中的前 N 条记录
local keys = redis.call('ZRANGE', queue_key, 0, limit - 1)
local result = {}

for i, key in ipairs(keys) do
    -- 读取记录数据
    local data = redis.call('HGETALL', key)

    if #data > 0 then
        -- 转换为表
        local record = {}
        for j = 1, #data, 2 do
            record[data[j]] = data[j + 1]
        end

        local status = record['status'] or ''
        local processing_time = tonumber(record['processing_time']) or 0

        -- 只处理 pending 状态，或处理超时的记录
        if status == 'pending' or (status == 'processing' and current_time - processing_time > timeout) then
            -- 标记为处理中
            redis.call('HSET', key, 'status', 'processing')
            redis.call('HSET', key, 'processing_time', current_time)

            -- 返回记录key
            table.insert(result, key)
        end
    end
end

return result
LUA;

        // 执行 Lua 脚本
        $keys = Redis::eval($luaScript, 1, $queueKey, $limit, $currentTime, $processTimeout);

        if (empty($keys)) {
            return [];
        }

        // 读取完整记录数据
        $records = [];
        foreach ($keys as $key) {
            $data = Redis::hGetAll($key);
            if (!empty($data)) {
                $data['redis_key'] = $key;
                $records[] = $data;
            }
        }

        return $records;
    }

    /**
     * 标记记录为已同步
     */
    public static function markAsSynced(string $redisKey, int $recordId): void
    {
        Redis::hMSet($redisKey, [
            'status' => 'synced',
            'record_id' => $recordId,
            'synced_at' => date('Y-m-d H:i:s'),
        ]);

        // 从同步队列移除
        Redis::zRem(self::PREFIX_SYNC_QUEUE, $redisKey);
    }

    /**
     * 标记记录同步失败
     */
    public static function markAsFailed(string $redisKey, string $error): void
    {
        $retryCount = (int)(Redis::hGet($redisKey, 'retry_count') ?: 0);

        Redis::hMSet($redisKey, [
            'status' => 'failed',
            'error' => $error,
            'retry_count' => $retryCount + 1,
            'failed_at' => date('Y-m-d H:i:s'),
        ]);

        // 如果重试次数 < 3，重置为 pending 状态，重新加入队列（延迟10秒）
        if ($retryCount < 3) {
            // 重置状态为 pending，以便 Lua 脚本可以重新处理
            Redis::hSet($redisKey, 'status', 'pending');
            Redis::zAdd(self::PREFIX_SYNC_QUEUE, time() + 10, $redisKey);
        } else {
            // 重试次数过多，移除队列，等待人工处理
            Redis::zRem(self::PREFIX_SYNC_QUEUE, $redisKey);
        }
    }

    /**
     * 获取缓存余额
     */
    public static function getCachedBalance(int $playerId): float
    {
        $key = self::PREFIX_BALANCE . $playerId;
        $balance = Redis::get($key);

        if ($balance === null) {
            // 从数据库读取并缓存
            $wallet = \app\model\PlayerPlatformCash::where('player_id', $playerId)->first();
            $balance = $wallet ? $wallet->money : 0;
            self::updateCachedBalance($playerId, (float)$balance);
        }

        return (float)$balance;
    }

    /**
     * 更新缓存余额
     */
    public static function updateCachedBalance(int $playerId, float $balance): void
    {
        $key = self::PREFIX_BALANCE . $playerId;
        Redis::setex($key, self::TTL_BALANCE, $balance);
    }

    /**
     * 清理过期记录（定时任务）
     */
    public static function cleanExpiredRecords(): int
    {
        $count = 0;

        // 清理超过7天的同步队列记录
        $cutoffTime = time() - self::TTL_RECORD;
        $removed = Redis::zRemRangeByScore(self::PREFIX_SYNC_QUEUE, 0, $cutoffTime);

        $count += $removed;

        return $count;
    }

    /**
     * 保存取消/退款记录
     *
     * @param string $platform 平台代码
     * @param array $data 取消数据
     *   - order_no: 订单号（必需）
     *   - player_id: 玩家ID（必需）
     *   - platform_id: 平台ID（必需）
     *   - cancel_type: 取消类型（cancel | refund）
     *   - original_data: 原始请求数据（可选）
     */
    public static function saveCancel(string $platform, array $data): void
    {
        $orderNo = $data['order_no'];
        $betKey = self::PREFIX_BET . "{$platform}:{$orderNo}";

        // 检查是否存在 bet 记录
        $betExists = Redis::exists($betKey);

        if ($betExists) {
            // 标记为已取消
            Redis::hMSet($betKey, [
                'cancel_type' => $data['cancel_type'] ?? 'cancel',
                'cancel_time' => time(),
                'action_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
            ]);

            // 更新同步队列
            Redis::zAdd(self::PREFIX_SYNC_QUEUE, time(), $betKey);
        }

        // 记录统计
        Redis::incr("game:stats:{$platform}:cancel:count");
    }

    /**
     * 更新订单（用于 prepay 转正式下注、refund 更新等）
     *
     * @param string $platform 平台代码
     * @param string $orderNo 订单号
     * @param array $updates 更新字段
     */
    public static function updateRecord(string $platform, string $orderNo, array $updates): void
    {
        $betKey = self::PREFIX_BET . "{$platform}:{$orderNo}";

        if (Redis::exists($betKey)) {
            Redis::hMSet($betKey, array_merge($updates, [
                'status' => 'pending',  // 标记待同步
                'updated_at' => date('Y-m-d H:i:s'),
            ]));

            // 更新同步队列
            Redis::zAdd(self::PREFIX_SYNC_QUEUE, time(), $betKey);
        }
    }

    /**
     * 获取统计信息
     */
    public static function getStats(string $platform): array
    {
        return [
            'bet_count' => Redis::get("game:stats:{$platform}:bet:count") ?? 0,
            'settle_count' => Redis::get("game:stats:{$platform}:settle:count") ?? 0,
            'cancel_count' => Redis::get("game:stats:{$platform}:cancel:count") ?? 0,
            'pending_sync' => Redis::zCard(self::PREFIX_SYNC_QUEUE),
        ];
    }
}
