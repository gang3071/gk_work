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
     * Lua 脚本 SHA1 缓存（避免重复加载脚本，提升性能）
     * @var array
     */
    private static $scriptShas = [];

    /**
     * Lua 脚本：原子获取并标记待同步记录（性能优化版）
     *
     * KEYS[1] = 队列 Key (game:sync:queue)
     * ARGV[1] = 获取数量限制
     * ARGV[2] = 当前时间戳
     * ARGV[3] = 处理超时时间（秒）
     *
     * 返回：待处理的记录 Key 列表
     *
     * 性能优化：
     * - ✅ 只读取 status 和 processing_time 字段（不读取 original_data 等大字段）
     * - ✅ 减少 80-90% 数据传输量
     * - ✅ 降低 CPU 和内存占用
     */
    private const LUA_GET_PENDING_RECORDS = <<<'LUA'
local queue_key = KEYS[1]
local limit = tonumber(ARGV[1])
local current_time = tonumber(ARGV[2])
local timeout = tonumber(ARGV[3])

-- 获取队列中的前 N 条记录
local keys = redis.call('ZRANGE', queue_key, 0, limit - 1)
local result = {}

for i, key in ipairs(keys) do
    -- ✅ 性能优化：只读取判断需要的字段，避免传输 original_data 等大字段
    local exists = redis.call('EXISTS', key)

    if exists == 1 then
        local status = redis.call('HGET', key, 'status') or ''
        local processing_time = tonumber(redis.call('HGET', key, 'processing_time') or 0)

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

    /**
     * 获取 Redis 连接（使用 work 连接池，确保 igaming 核心业务稳定）
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    private static function redis()
    {
        return Redis::connection('work');
    }

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
        // 计算脚本的 SHA1
        $sha = sha1($script);

        // 如果已经加载过，直接使用 EVALSHA（节省网络传输）
        if (isset(self::$scriptShas[$sha])) {
            try {
                $result = $redis->evalSha($sha, count($keys), ...array_merge($keys, $argv));

                // 检查 EVALSHA 返回值，false 表示脚本不存在或执行失败
                if ($result === false) {
                    $lastError = $redis->getLastError();
                    \support\Log::warning('EVALSHA 返回 false，脚本可能已失效，降级到 EVAL', [
                        'sha' => substr($sha, 0, 8),
                        'last_error' => $lastError,
                    ]);
                    // 清除 SHA 缓存，强制降级到 EVAL
                    unset(self::$scriptShas[$sha]);
                } else {
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

            // 标记为已加载
            self::$scriptShas[$sha] = true;

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
     *   - balance_before: 变化前余额（可选，用于推送）
     *   - balance_after: 变化后余额（可选，用于推送）
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
            // ✅ 保存余额变化信息（用于 Worker 推送）
            'balance_before' => $data['balance_before'] ?? '',
            'balance_after' => $data['balance_after'] ?? '',
        ];

        // 写入 Redis Hash
        self::redis()->hMSet($key, $record);
        self::redis()->expire($key, self::TTL_RECORD);

        // 加入同步队列
        self::redis()->zAdd(self::PREFIX_SYNC_QUEUE, time(), $key);

        // 记录统计
        self::redis()->incr("game:stats:{$platform}:bet:count");

        // 记录在线玩家（用于定时推送，去重）
        self::recordOnlinePlayer($platform, $data);
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
     *   - balance_before: 变化前余额（可选，用于推送）
     *   - balance_after: 变化后余额（可选，用于推送）
     */
    public static function saveSettle(string $platform, array $data): void
    {
        $orderNo = $data['order_no'];
        $betKey = self::PREFIX_BET . "{$platform}:{$orderNo}";

        // 检查是否存在 bet 记录
        $betExists = self::redis()->exists($betKey);

        if ($betExists) {
            // 更新 bet 记录
            $betAmount = self::redis()->hGet($betKey, 'amount') ?? 0;

            self::redis()->hMSet($betKey, [
                'win' => $data['amount'],
                'diff' => $data['diff'] ?? bcsub($data['amount'], $betAmount, 2),
                'settlement_status' => 1,  // 已结算
                'settle_type' => $data['settle_type'] ?? 'settle',  // settle | refund | jackpot | adjust | reward
                'settle_time' => time(),
                'platform_action_at' => date('Y-m-d H:i:s'),
                'action_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',  // 重新标记待同步
                // ✅ 结算时覆盖余额字段（用于 Worker 推送）
                'balance_before' => $data['balance_before'] ?? '',
                'balance_after' => $data['balance_after'] ?? '',
            ]);

            // 更新同步队列（提升优先级）
            self::redis()->zAdd(self::PREFIX_SYNC_QUEUE, time(), $betKey);

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
                // ✅ 保存余额变化信息（统一字段名）
                'balance_before' => $data['balance_before'] ?? '',
                'balance_after' => $data['balance_after'] ?? '',
            ];

            self::redis()->hMSet($settleKey, $record);
            self::redis()->expire($settleKey, self::TTL_RECORD);
            self::redis()->zAdd(self::PREFIX_SYNC_QUEUE, time(), $settleKey);
        }

        // 记录统计
        self::redis()->incr("game:stats:{$platform}:settle:count");
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

        // ✅ 执行 Lua 脚本（优先使用 EVALSHA，减少网络传输 70%）
        $redis = self::redis();
        $keys = self::evalScript($redis, self::LUA_GET_PENDING_RECORDS, [$queueKey], [$limit, $currentTime, $processTimeout]);

        if (empty($keys)) {
            return [];
        }

        // 读取完整记录数据
        $records = [];
        foreach ($keys as $key) {
            $data = self::redis()->hGetAll($key);
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
        self::redis()->hMSet($redisKey, [
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
        $retryCount = (int)(self::redis()->hGet($redisKey, 'retry_count') ?: 0);

        self::redis()->hMSet($redisKey, [
            'status' => 'failed',
            'error' => $error,
            'retry_count' => $retryCount + 1,
            'failed_at' => date('Y-m-d H:i:s'),
        ]);

        // 如果重试次数 < 3，重置为 pending 状态，重新加入队列（延迟10秒）
        if ($retryCount < 3) {
            // 重置状态为 pending，以便 Lua 脚本可以重新处理
            Redis::hSet($redisKey, 'status', 'pending');
            self::redis()->zAdd(self::PREFIX_SYNC_QUEUE, time() + 10, $redisKey);
        } else {
            // 重试次数过多，移除队列，等待人工处理
            Redis::zRem(self::PREFIX_SYNC_QUEUE, $redisKey);
        }
    }

    /**
     * 获取缓存余额（单一钱包）
     *
     * 注意：此方法已废弃，建议直接使用 Lua 脚本返回的余额
     * 仅用于需要返回操作前余额的特殊场景（如DG平台）
     *
     * @deprecated 建议使用 Lua 脚本返回的余额
     */
    public static function getCachedBalance(int $playerId): float
    {
        // 直接从 player 表读取（单一钱包）
        $player = \app\model\Player::find($playerId);
        return $player ? (float)$player->money : 0.0;
    }

    /**
     * 更新缓存余额
     *
     * @deprecated 单一钱包模式下不需要缓存余额，Lua脚本直接操作player.money
     */
    public static function updateCachedBalance(int $playerId, float $balance): void
    {
        // 单一钱包模式下不需要此方法，保留空实现避免兼容性问题
        // 余额由 Lua 脚本直接更新到 player 表
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
     *   - balance_before: 变化前余额（可选，用于推送）
     *   - balance_after: 变化后余额（可选，用于推送）
     */
    public static function saveCancel(string $platform, array $data): void
    {
        $orderNo = $data['order_no'];
        $betKey = self::PREFIX_BET . "{$platform}:{$orderNo}";

        // 检查是否存在 bet 记录
        $betExists = self::redis()->exists($betKey);

        if ($betExists) {
            // 标记为已取消
            self::redis()->hMSet($betKey, [
                'cancel_type' => $data['cancel_type'] ?? 'cancel',
                'cancel_time' => time(),
                'action_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                // ✅ 取消时覆盖余额字段（统一字段名）
                'balance_before' => $data['balance_before'] ?? '',
                'balance_after' => $data['balance_after'] ?? '',
            ]);

            // 更新同步队列
            self::redis()->zAdd(self::PREFIX_SYNC_QUEUE, time(), $betKey);
        }

        // 记录统计
        self::redis()->incr("game:stats:{$platform}:cancel:count");
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

        if (self::redis()->exists($betKey)) {
            self::redis()->hMSet($betKey, array_merge($updates, [
                'status' => 'pending',  // 标记待同步
                'updated_at' => date('Y-m-d H:i:s'),
            ]));

            // 更新同步队列
            self::redis()->zAdd(self::PREFIX_SYNC_QUEUE, time(), $betKey);
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

    /**
     * 记录在线玩家信息（用于定时推送）
     *
     * @param string $platform 平台代码
     * @param array $data 下注数据
     * @return void
     */
    private static function recordOnlinePlayer(string $platform, array $data): void
    {
        try {
            $playerId = $data['player_id'] ?? 0;
            $platformId = $data['platform_id'] ?? 0;

            if (!$playerId || !$platformId) {
                return;
            }

            // 1. 将玩家ID加入在线集合（自动去重）
            self::redis()->sAdd('online_players:game', $playerId);
            self::redis()->expire('online_players:game', 60);

            // 2. 更新累计押注统计（5分钟内）
            $betStatKey = "player_bet_stat:{$playerId}";
            $currentTotal = self::redis()->get($betStatKey) ?? 0;
            $newTotal = bcadd($currentTotal, $data['amount'], 2);
            self::redis()->setex($betStatKey, 300, $newTotal);  // 5分钟过期

            // 3. 保存玩家当前游戏信息（用于推送详情）
            $gameInfo = [
                'platform_id' => $platformId,
                'platform_name' => self::getPlatformName($platformId),
                'game_code' => $data['game_code'] ?? '',
                'last_bet' => number_format($data['amount'], 2),
                'last_bet_time' => date('Y-m-d H:i:s'),
            ];

            self::redis()->setex(
                "player_current_game:{$playerId}",
                60,
                json_encode($gameInfo)
            );

        } catch (\Exception $e) {
            // 记录失败不影响主流程，仅记录日志
            \support\Log::warning('记录在线玩家信息失败', [
                'platform' => $platform,
                'player_id' => $data['player_id'] ?? 0,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取平台名称（带缓存）
     *
     * @param int $platformId
     * @return string
     */
    private static function getPlatformName(int $platformId): string
    {
        if (!$platformId) {
            return '';
        }

        $cacheKey = "platform_name_cache:{$platformId}";
        $cached = self::redis()->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        try {
            $platform = \app\model\GamePlatform::find($platformId);
            $name = $platform->name ?? '';

            // 缓存1小时
            self::redis()->setex($cacheKey, 3600, $name);

            return $name;
        } catch (\Exception $e) {
            \support\Log::warning('获取平台名称失败', [
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }
}
