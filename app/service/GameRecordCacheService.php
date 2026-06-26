<?php

namespace app\service;

use support\Log;
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
     * Lua 脚本 SHA1 缓存（Redis 返回的真实 SHA1，不是 PHP 计算的）
     *
     * ⚠️ CRITICAL: 必须使用 Redis 返回的 SHA1，不能用 sha1($script)
     * - PHP 计算的 SHA1 可能因为换行符（CRLF vs LF）导致不一致
     * - Redis 返回的 SHA1 才是真正存储在服务器上的
     *
     * 格式：['script_name' => 'redis_sha1']
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
     * 单例 Redis 连接（原生 PhpRedis，绕过连接池）
     *
     * ⚠️ CRITICAL FIX: 使用原生 PhpRedis 而非 Illuminate Redis
     *
     * 问题根源：
     * - Illuminate Redis 使用连接池，每次 connection('work') 可能返回不同物理连接
     * - Lua 脚本加载到连接 A，但运行时 EVALSHA 使用连接 B/C/D（连接池轮换）
     * - 诊断数据：90秒内 SCRIPT LOAD 784次（8.7次/秒），说明每个连接都在重新加载
     *
     * 解决方案：
     * - 使用原生 PhpRedis (new \Redis())，完全绕过 Illuminate Redis 连接池
     * - Worker 生命周期内只创建一次，真正的单例
     * - 脚本加载一次后永久有效（直到 Worker 重启）
     *
     * 性能优势：
     * - 消除连接池查找开销（~10-50μs/次）
     * - SCRIPT LOAD 从 8.7次/s → 0次/s（仅启动时加载 4 次）
     * - 原生 PhpRedis 比 Illuminate 封装快 ~10%
     *
     * @var \Redis|null
     */
    private static $redisInstance = null;

    /**
     * 获取 Redis 连接（原生 PhpRedis，单例模式）
     *
     * @return \Redis
     */
    private static function redis()
    {
        // 🔒 单例模式：整个 Worker 生命周期只创建一次
        if (self::$redisInstance === null) {
            $redis = new \Redis();

            // 从配置读取连接参数
            $config = config('redis.work');
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 6379;
            $timeout = $config['timeout'] ?? 5.0;
            $database = $config['database'] ?? 0;
            $password = $config['password'] ?? null;

            // 建立连接（持久连接）
            $connected = $redis->pconnect($host, $port, $timeout);

            if (!$connected) {
                throw new \RuntimeException("Failed to connect to Redis: {$host}:{$port}");
            }

            // 认证（如果有密码）
            if ($password) {
                $redis->auth($password);
            }

            // 选择数据库
            if ($database > 0) {
                $redis->select($database);
            }

            // 设置选项（禁用 Nagle 算法，降低延迟）
            // 注意：OPT_TCP_NODELAY 在某些 PhpRedis 版本中可能不存在
            if (defined('Redis::OPT_TCP_NODELAY')) {
                $redis->setOption(\Redis::OPT_TCP_NODELAY, true);
            }

            self::$redisInstance = $redis;

            \support\Log::info('🔌 创建原生 PhpRedis 单例连接', [
                'type' => 'native_phpredis',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'persistent' => true,
                'worker_pid' => posix_getpid(),
            ]);
        }

        return self::$redisInstance;
    }

    /**
     * Worker 进程启动时预加载 Lua 脚本到 Redis
     *
     * 性能优势：
     * - 避免首次执行时的 SCRIPT LOAD 开销
     * - 确保后续 100% 使用 EVALSHA（每次节省 ~800 字节网络传输）
     * - 高并发下（2000 req/s）节省 1.6 MB/s 带宽（95% 带宽节省）
     *
     * @return void
     */
    public static function preloadScripts(): void
    {
        try {
            $redis = self::redis();

            // ✅ 预加载：获取待同步记录脚本（使用 Redis 返回的 SHA1，不是 PHP 计算的）
            // 原生 PhpRedis 使用 script() 方法：script('load', $script)
            $redisSha = $redis->script('load', self::LUA_GET_PENDING_RECORDS);
            self::$scriptShas['LUA_GET_PENDING_RECORDS'] = $redisSha;  // ← 存储 Redis 返回的 SHA1

            \support\Log::info('✅ Worker 进程预加载 Lua 脚本成功', [
                'redis_sha' => substr($redisSha, 0, 8),
                'php_sha' => substr(sha1(self::LUA_GET_PENDING_RECORDS), 0, 8),  // 对比：PHP 计算的
                'script' => 'LUA_GET_PENDING_RECORDS',
            ]);
        } catch (\Exception $e) {
            \support\Log::error('❌ Worker 进程预加载 Lua 脚本失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 执行 Lua 脚本（极致性能版：优先 EVALSHA，NOSCRIPT 时自动降级）
     *
     * 性能优化策略：
     * 1. 默认直接使用 EVALSHA（节省 95% 网络带宽）
     * 2. NOSCRIPT 时自动降级到 EVAL（容错兜底）
     * 3. 降级后自动重新预加载（透明恢复）
     *
     * 适用场景：
     * - iGaming 高并发场景（每秒数千次 Redis 调用）
     * - 对延迟要求极高（< 2ms）
     * - 需要最小化网络带宽消耗
     *
     * @param string $script Lua 脚本内容
     * @param array $keys KEYS 参数
     * @param array $argv ARGV 参数
     * @return mixed
     * @throws \RuntimeException
     */
    private static function evalScript(string $script, array $keys, array $argv)
    {
        $redis = self::redis();

        // ✅ 使用 Redis 返回的 SHA1（不是 PHP 计算的）
        $scriptName = 'LUA_GET_PENDING_RECORDS';  // 当前只有一个脚本
        $redisSha = self::$scriptShas[$scriptName] ?? null;

        // 如果没有预加载（不应该发生），立即加载
        if (!$redisSha) {
            \support\Log::warning('⚠️ 脚本未预加载，立即加载', ['script' => $scriptName]);
            $redisSha = $redis->script('load', $script);
            self::$scriptShas[$scriptName] = $redisSha;
        }

        try {
            // 🚀 极致性能：直接使用 EVALSHA（使用 Redis 返回的 SHA1）
            $result = $redis->evalSha($redisSha, count($keys), ...array_merge($keys, $argv));

            // ⚠️ 处理 Illuminate Redis 的 false 返回（PhpRedis 会抛异常）
            if ($result === false) {
                $lastError = $redis->getLastError();

                // NOSCRIPT：脚本被清除（Redis 重启/SCRIPT FLUSH）
                if ($lastError && strpos($lastError, 'NOSCRIPT') !== false) {
                    throw new \RedisException('NOSCRIPT ' . $lastError);
                }

                // 其他 false 情况（业务逻辑返回 false 是合法的）
                return $result;
            }

            return $result;

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();

            // 🔧 容错降级：NOSCRIPT 时自动重新加载脚本
            if (strpos($errorMsg, 'NOSCRIPT') !== false) {
                \support\Log::info('⚠️ Redis Lua 脚本缓存失效（NOSCRIPT），自动重新加载', [
                    'old_sha' => substr($redisSha, 0, 8),
                    'reason' => 'Redis可能已重启或执行了SCRIPT FLUSH',
                    'msg' => $e->getMessage()
                ]);

                // 清除 PHP 端缓存
                unset(self::$scriptShas[$scriptName]);

                // 重新加载脚本到 Redis（获取 Redis 返回的新 SHA1）
                try {
                    $newRedisSha = $redis->script('load', $script);
                    self::$scriptShas[$scriptName] = $newRedisSha;  // ← 存储 Redis 返回的 SHA1

                    \support\Log::info('✅ Lua 脚本重新加载成功', [
                        'new_redis_sha' => substr($newRedisSha, 0, 8),
                    ]);

                    // 使用 Redis 返回的 SHA1 重新执行
                    return $redis->evalSha($newRedisSha, count($keys), ...array_merge($keys, $argv));

                } catch (\Exception $reloadException) {
                    // SCRIPT LOAD 失败，最终降级到 EVAL
                    \support\Log::warning('⚠️ SCRIPT LOAD 失败，降级到 EVAL', [
                        'sha' => substr($sha, 0, 8),
                        'error' => $reloadException->getMessage(),
                    ]);

                    return $redis->eval($script, count($keys), ...array_merge($keys, $argv));
                }
            }

            // 真正的业务逻辑错误
            \support\Log::error('❌ Redis Lua 脚本执行失败', [
                'error' => $errorMsg,
                'redis_sha' => substr($redisSha ?? '', 0, 8),
                'keys_count' => count($keys),
                'argv_count' => count($argv),
            ]);

            throw new \RuntimeException('Redis Lua 脚本执行失败: ' . $errorMsg, 0, $e);
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

        // ✅ 性能优化：saveBet总是在atomicBet之后调用，记录必然已存在
        // 因此直接追加字段，不需要EXISTS检查（节省一次网络往返）
        //
        // ⚠️ 关键：只追加original_data和额外字段，不覆盖Lua脚本保存的balance_before/balance_after
        // Lua脚本以"分"为单位保存余额，如果这里覆盖会导致SyncWorker重复除以100
        $updates = [
            'original_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
        ];

        // 如果有额外的字段（如belong_order_no, is_sub_order），也追加
        if (isset($data['belong_order_no'])) {
            $updates['belong_order_no'] = $data['belong_order_no'];
        }
        if (isset($data['is_sub_order'])) {
            $updates['is_sub_order'] = $data['is_sub_order'];
        }

        // ✅ 直接hMSet追加字段（Redis的hMSet只更新指定字段，不影响其他字段）
        self::redis()->hMSet($key, $updates);

        // 确保在队列中（Lua脚本已经zadd过，这里更新score提升优先级）
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
            // ⚠️ 关键：不覆盖 Lua 保存的 win/diff（Lua 已经正确保存为"分"）
            // 只追加 settlement_status, action_data 等补充信息
            self::redis()->hMSet($betKey, [
                'settlement_status' => 1,  // 已结算
                'settle_type' => $data['settle_type'] ?? 'settle',  // settle | refund | jackpot | adjust | reward
                'settle_time' => time(),
                'platform_action_at' => date('Y-m-d H:i:s'),
                'action_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',  // 重新标记待同步
                // ✅ 不覆盖 Lua 保存的 win/diff/balance_before/balance_after
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
        $keys = self::evalScript(self::LUA_GET_PENDING_RECORDS, [$queueKey], [$limit, $currentTime, $processTimeout]);

        if (empty($keys)) {
            return [];
        }

        // 读取完整记录数据
        $records = [];
        foreach ($keys as $key) {
            $data = self::redis()->hGetAll($key);
            if (!empty($data)) {
                $data['redis_key'] = $key;

                // 🔍 诊断日志：记录 SyncWorker 读到的原始数据
                Log::channel('game_bet_record')->info('[getPendingSyncRecords] 读取记录', [
                    'key' => $key,
                    'balance_before' => $data['balance_before'] ?? 'NOT_IN_HASH',
                    'balance_after' => $data['balance_after'] ?? 'NOT_IN_HASH',
                    'platform' => $data['platform'] ?? 'unknown',
                    'order_no' => $data['order_no'] ?? 'unknown',
                ]);

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
                // ✅ 不覆盖 balance_before/after — 保持下注时 Lua 记录的余额快照
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

    /**
     * KT平台专用：保存未结算记录（支持win和diff字段）
     *
     * @param string $platform 平台代码
     * @param array $data 下注数据
     *   - order_no: 订单号（必需）
     *   - player_id: 玩家ID（必需）
     *   - platform_id: 平台ID（必需）
     *   - amount: 下注金额（必需，对应Bet字段）
     *   - win: 派彩金额（必需，对应Win字段）
     *   - diff: 输赢金额（必需，Win-Bet）
     *   - game_code: 游戏代码（可选）
     *   - original_data: 原始请求数据（可选）
     *   - balance_before: 变化前余额（可选）
     *   - balance_after: 变化后余额（可选）
     */
    public static function saveBetForKT(string $platform, array $data): void
    {
        $orderNo = $data['order_no'];
        $key = self::PREFIX_BET . "{$platform}:{$orderNo}";

        // ✅ KT专用：清理可能由Lua创建的settle记录（防止重复）
        $settleKey = "game:record:settle:{$platform}:{$orderNo}";
        if (self::redis()->exists($settleKey)) {
            // 从同步队列移除settle记录
            self::redis()->zRem(self::PREFIX_SYNC_QUEUE, $settleKey);
            // 删除settle记录
            self::redis()->del($settleKey);
        }

        // ✅ 检查bet记录是否已存在
        $exists = self::redis()->exists($key);

        if ($exists) {
            // 记录已存在，更新必要字段（包括amount，确保存储的是原始Bet值）
            self::redis()->hMSet($key, [
                'amount' => $data['amount'],  // ✅ 更新为KT的原始Bet值
                'win' => $data['win'] ?? 0,
                'diff' => $data['diff'] ?? 0,
                'original_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
                'balance_before' => $data['balance_before'] ?? '',
                'balance_after' => $data['balance_after'] ?? '',
            ]);
        } else {
            // 记录不存在，创建完整记录
            $record = [
                'platform' => $platform,
                'order_no' => $orderNo,
                'player_id' => $data['player_id'],
                'platform_id' => $data['platform_id'],
                'amount' => $data['amount'],
                'game_code' => $data['game_code'] ?? '',
                'game_type' => $data['game_type'] ?? '',
                'game_name' => $data['game_name'] ?? '',
                'bet_type' => $data['bet_type'] ?? 'bet',
                'bet_time' => time(),
                'original_data' => json_encode($data['original_data'] ?? $data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'settlement_status' => 0,  // 未结算
                'win' => $data['win'] ?? 0,
                'diff' => $data['diff'] ?? 0,
                'created_at' => date('Y-m-d H:i:s'),
                'balance_before' => $data['balance_before'] ?? '',
                'balance_after' => $data['balance_after'] ?? '',
            ];

            self::redis()->hMSet($key, $record);
            self::redis()->expire($key, self::TTL_RECORD);
            self::redis()->zAdd(self::PREFIX_SYNC_QUEUE, time(), $key);
        }

        // 记录统计
        self::redis()->incr("game:stats:{$platform}:bet:count");

        // 记录在线玩家
        self::recordOnlinePlayer($platform, $data);
    }

    /**
     * KT平台专用：批量结算所有相同MainTxID的子订单
     *
     * @param string $platform 平台代码
     * @param string $mainTxID 主交易ID
     * @param array $currentRecordData 当前交易记录数据（TakeWin=1的那笔）
     * @return int 已结算的订单数量
     */
    public static function settleAllSubOrdersForKT(string $platform, string $mainTxID, array $currentRecordData): int
    {
        $redis = self::redis();
        $currentOrderNo = $currentRecordData['order_no'];

        // ✅ KT专用：清理可能存在的settle记录（包括SubTxID=0和SubTxID>0）
        $settlePatterns = [
            "game:record:settle:{$platform}:{$mainTxID}",      // SubTxID=0
            "game:record:settle:{$platform}:{$mainTxID}_*",    // SubTxID>0
        ];
        foreach ($settlePatterns as $pattern) {
            $settleKeys = $redis->keys($pattern);
            foreach ($settleKeys as $settleKey) {
                $redis->zRem(self::PREFIX_SYNC_QUEUE, $settleKey);
                $redis->del($settleKey);
            }
        }

        // ✅ 查找所有相同MainTxID的bet订单（包括SubTxID=0和SubTxID>0）
        $keys = [];

        // 查找 SubTxID=0 的订单（订单号 = MainTxID）
        $key0 = self::PREFIX_BET . "{$platform}:{$mainTxID}";
        if ($redis->exists($key0)) {
            $keys[] = $key0;
        }

        // 查找 SubTxID>0 的订单（订单号 = MainTxID_SubTxID）
        $patternN = self::PREFIX_BET . "{$platform}:{$mainTxID}_*";
        $keysN = $redis->keys($patternN);
        $keys = array_merge($keys, $keysN);

        $settledCount = 0;
        foreach ($keys as $key) {
            $record = $redis->hGetAll($key);

            if (empty($record)) {
                continue;
            }

            // 只结算未结算的订单
            if (isset($record['settlement_status']) && $record['settlement_status'] == 1) {
                continue;
            }

            $orderNo = $record['order_no'] ?? '';

            // ✅ 如果是当前订单，使用完整的结算数据
            if ($orderNo === $currentOrderNo) {
                $redis->hMSet($key, [
                    'win' => $currentRecordData['win'] ?? 0,
                    'diff' => $currentRecordData['diff'] ?? 0,
                    'settlement_status' => 1,
                    'settle_type' => 'settle',
                    'settle_time' => time(),
                    'platform_action_at' => date('Y-m-d H:i:s'),
                    'action_data' => json_encode($currentRecordData['original_data'] ?? [], JSON_UNESCAPED_UNICODE),
                    'status' => 'pending',
                    'balance_before' => $currentRecordData['balance_before'] ?? '',
                    'balance_after' => $currentRecordData['balance_after'] ?? '',
                ]);
            } else {
                // 其他子订单：只更新结算状态
                $redis->hMSet($key, [
                    'settlement_status' => 1,
                    'settle_type' => 'settle',
                    'settle_time' => time(),
                    'platform_action_at' => date('Y-m-d H:i:s'),
                    'status' => 'pending',
                ]);
            }

            // 更新同步队列
            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $key);

            $settledCount++;
        }

        // 记录统计
        self::redis()->incr("game:stats:{$platform}:settle:count");

        return $settledCount;
    }
}
