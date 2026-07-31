<?php

namespace app\service;

use app\model\PlayerBetStatistics;
use Carbon\Carbon;
use support\Log;
use support\Redis;

/**
 * 玩家打码量统计服务
 *
 * 【重要】数据单位说明：
 * - Redis 存储：整数"分"（避免浮点数精度问题）
 * - 数据库存储：DECIMAL "元"（方便直接查询）
 * - 接口传参：float "元"（业务层使用）
 *
 * 转换规则：
 * - 写入 Redis: 元 × 100 → 分（整数）
 * - 读取显示: 分 ÷ 100 → 元（浮点）
 * - 同步数据库: 分 ÷ 100 → 元（DECIMAL）
 */
class PlayerBetStatisticsService
{
    // Redis Key 前缀
    // ✅ 增加项目前缀，防止多项目共用 Redis 时数据冲突
    // 格式：gk_work:player_bet_stats:{player_id}:{stat_type}:{dimension}:{date}
    // 存储字段：bet_amount（分，整数）、bet_count（次数）、last_update（时间戳）
    const REDIS_KEY_PREFIX = 'gk_work:player_bet_stats:';

    // 统计类型
    const TYPE_MACHINE = 'machine';  // 实体机台
    const TYPE_GAME = 'game';        // 电子游戏

    // 统计维度
    const DIMENSION_DAILY = 'daily';      // 日
    const DIMENSION_WEEKLY = 'weekly';    // 周
    const DIMENSION_MONTHLY = 'monthly';  // 月

    // 过期时间（秒）
    // ✅ 优化策略：
    // - daily: 仅保留当天（凌晨2点清理，给跨天统计留缓冲）
    // - weekly: 保留当周+1周（最多14天）
    // - monthly: 保留当月+1个月（最多62天）
    const TTL_DAILY = 86400 * 2;      // 2天（当天+缓冲）
    const TTL_WEEKLY = 86400 * 14;    // 14天（当周+上周）
    const TTL_MONTHLY = 86400 * 62;   // 62天（当月+上月）

    /**
     * 累加玩家打码量（原子操作）
     *
     * @param int $playerId 玩家ID
     * @param string $statType 统计类型：machine | game
     * @param float $betAmount 打码金额（元）
     * @param Carbon|null $dateTime 时间（默认当前时间）
     * @return array 返回三个维度的统计结果，金额单位为"元"
     * @throws \InvalidArgumentException 当参数无效时
     */
    public static function increment(
        int $playerId,
        string $statType,
        float $betAmount,
        ?Carbon $dateTime = null
    ): array {
        // ✅ 验证 playerId
        if ($playerId <= 0) {
            throw new \InvalidArgumentException('Invalid player ID: ' . $playerId);
        }

        // ✅ 验证 statType
        if (!in_array($statType, [self::TYPE_MACHINE, self::TYPE_GAME])) {
            throw new \InvalidArgumentException('Invalid stat type: ' . $statType);
        }

        // ✅ 验证 betAmount
        if ($betAmount <= 0) {
            return [];
        }

        $dateTime = $dateTime ?? Carbon::now();

        // 计算三个维度的日期键
        $dimensions = [
            self::DIMENSION_DAILY => $dateTime->format('Y-m-d'),      // 2026-07-31
            self::DIMENSION_WEEKLY => $dateTime->format('o-\WW'),     // 2026-W31
            self::DIMENSION_MONTHLY => $dateTime->format('Y-m'),      // 2026-07
        ];

        $results = [];

        foreach ($dimensions as $dimension => $date) {
            $key = self::buildKey($playerId, $statType, $dimension, $date);
            $ttl = self::getTTL($dimension);

            // 使用 Lua 脚本原子性累加
            $result = self::incrementByLua($key, $betAmount, $ttl);

            // ✅ 将返回值从"分"转换为"元"，保持接口一致性
            $results[$dimension] = [
                'date' => $date,
                'before_amount' => round($result[0] / 100, 2),  // 分 → 元
                'after_amount' => round($result[1] / 100, 2),   // 分 → 元
                'before_count' => $result[2],
                'after_count' => $result[3],
            ];
        }

        return $results;
    }

    /**
     * Lua 脚本原子性累加
     *
     * @param string $key Redis Key
     * @param float $betAmount 打码金额（元）
     * @param int $ttl 过期时间
     * @return array [旧金额(分), 新金额(分), 旧次数, 新次数]
     * @throws \RuntimeException 当 Lua 脚本执行失败或返回格式错误时
     */
    private static function incrementByLua(string $key, float $betAmount, int $ttl): array
    {
        $redis = Redis::connection()->client();

        // ✅ 修复：金额乘以100转为"分"存储，确保精度
        // 避免浮点数精度问题，统一以整数"分"为单位存储
        $betAmountInCents = intval(round($betAmount * 100));

        $lua = <<<'LUA'
local key = KEYS[1]
local bet_amount_cents = tonumber(ARGV[1])  -- 金额（分）
local ttl = tonumber(ARGV[2])
local current_time = tonumber(ARGV[3])

-- 读取旧值（整数"分"）
local old_amount = tonumber(redis.call('HGET', key, 'bet_amount') or 0)
local old_count = tonumber(redis.call('HGET', key, 'bet_count') or 0)

-- 累加（整数运算，无精度损失）
local new_amount = old_amount + bet_amount_cents
local new_count = old_count + 1

-- 更新（存储整数"分"）
redis.call('HSET', key, 'bet_amount', new_amount)
redis.call('HSET', key, 'bet_count', new_count)
redis.call('HSET', key, 'last_update', current_time)

-- 设置过期时间
redis.call('EXPIRE', key, ttl)

return {old_amount, new_amount, old_count, new_count}
LUA;

        try {
            $result = $redis->eval($lua, [$key, $betAmountInCents, $ttl, time()], 1);

            // ✅ 验证返回值格式
            if (!is_array($result) || count($result) !== 4) {
                throw new \RuntimeException('Lua 脚本返回格式错误: ' . json_encode($result));
            }

            return [
                intval($result[0]),    // old_amount (分)
                intval($result[1]),    // new_amount (分)
                intval($result[2]),    // old_count
                intval($result[3]),    // new_count
            ];
        } catch (\Exception $e) {
            Log::error('[PlayerBetStatistics] Lua 脚本执行失败', [
                'key' => $key,
                'bet_amount' => $betAmount,
                'ttl' => $ttl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // ✅ 重新抛出异常，让队列消费者重试
            throw $e;
        }
    }

    /**
     * 获取玩家打码量统计
     *
     * @param int $playerId 玩家ID
     * @param string $statType 统计类型
     * @param string $dimension 维度
     * @param string|null $date 日期（可选，默认当前）
     * @return array|null ['bet_amount' => int(分), 'bet_count' => int, 'last_update' => int]
     */
    public static function getStats(
        int $playerId,
        string $statType,
        string $dimension,
        ?string $date = null
    ): ?array {
        if (!$date) {
            $carbon = Carbon::now();
            $date = match($dimension) {
                self::DIMENSION_DAILY => $carbon->format('Y-m-d'),
                self::DIMENSION_WEEKLY => $carbon->format('o-\WW'),
                self::DIMENSION_MONTHLY => $carbon->format('Y-m'),
                default => $carbon->format('Y-m-d'),
            };
        }

        $key = self::buildKey($playerId, $statType, $dimension, $date);
        $redis = Redis::connection()->client();

        $data = $redis->hGetAll($key);

        if (empty($data)) {
            return null;
        }

        // ✅ Redis 存储的是"分"（整数），返回时保持原值
        return [
            'bet_amount' => intval($data['bet_amount'] ?? 0),  // 单位：分
            'bet_count' => intval($data['bet_count'] ?? 0),
            'last_update' => intval($data['last_update'] ?? 0),
        ];
    }

    /**
     * 同步 Redis 数据到数据库
     *
     * @param string $dimension 维度
     * @param string|null $date 日期（可选）
     * @return int 同步记录数
     */
    public static function syncToDatabase(string $dimension, ?string $date = null): int
    {
        $redis = Redis::connection()->client();

        // 使用 SCAN 查找匹配的 keys
        $pattern = self::REDIS_KEY_PREFIX . "*:{$dimension}:*";
        $cursor = 0;
        $syncCount = 0;
        $failedCount = 0;

        // ✅ 防止 SCAN 无限循环（最多迭代 10000 次）
        $maxIterations = 10000;
        $iterations = 0;

        // ✅ 批量插入缓冲区（每 100 条批量写入一次）
        $batch = [];
        $batchSize = 100;

        do {
            $result = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            $cursor = $result[0];
            $keys = $result[1] ?? [];

            foreach ($keys as $key) {
                try {
                    // 解析 key: gk_work:player_bet_stats:{player_id}:{stat_type}:{dimension}:{date}
                    $parts = explode(':', $key);

                    // ✅ 修正：gk_work 前缀后有 6 个部分
                    if (count($parts) !== 6) {
                        Log::warning('[PlayerBetStatistics] Redis Key 格式错误', ['key' => $key]);
                        continue;
                    }

                    $playerId = intval($parts[2]);  // 注意：索引从 2 开始（0=gk_work, 1=player_bet_stats）
                    $statType = $parts[3];
                    $keyDimension = $parts[4];
                    $keyDate = $parts[5];

                    // ✅ 验证 player_id
                    if ($playerId <= 0) {
                        Log::warning('[PlayerBetStatistics] 无效的 player_id', [
                            'key' => $key,
                            'player_id' => $playerId,
                        ]);
                        continue;
                    }

                    // ✅ 验证 stat_type
                    if (!in_array($statType, [self::TYPE_MACHINE, self::TYPE_GAME])) {
                        Log::warning('[PlayerBetStatistics] 无效的 stat_type', [
                            'key' => $key,
                            'stat_type' => $statType,
                        ]);
                        continue;
                    }

                    // ✅ 验证 dimension
                    if (!in_array($keyDimension, [self::DIMENSION_DAILY, self::DIMENSION_WEEKLY, self::DIMENSION_MONTHLY])) {
                        Log::warning('[PlayerBetStatistics] 无效的 dimension', [
                            'key' => $key,
                            'dimension' => $keyDimension,
                        ]);
                        continue;
                    }

                    // ✅ 严格验证日期格式（必须与维度匹配）
                    $expectedFormat = match ($keyDimension) {
                        self::DIMENSION_DAILY => '/^\d{4}-\d{2}-\d{2}$/',        // 2026-07-31
                        self::DIMENSION_WEEKLY => '/^\d{4}-W\d{2}$/',           // 2026-W31
                        self::DIMENSION_MONTHLY => '/^\d{4}-\d{2}$/',           // 2026-07
                        default => null,
                    };

                    if ($expectedFormat && !preg_match($expectedFormat, $keyDate)) {
                        Log::warning('[PlayerBetStatistics] 日期格式与维度不匹配', [
                            'key' => $key,
                            'dimension' => $keyDimension,
                            'date' => $keyDate,
                            'expected_format' => $expectedFormat,
                        ]);
                        continue;
                    }

                    // 验证日期有效性
                    try {
                        Carbon::parse($keyDate);
                    } catch (\Exception $e) {
                        Log::warning('[PlayerBetStatistics] 无效的日期格式', [
                            'key' => $key,
                            'date' => $keyDate,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    // 如果指定了日期，只同步该日期
                    if ($date && $keyDate !== $date) {
                        continue;
                    }

                    // 获取 Redis 数据
                    $data = $redis->hGetAll($key);
                    if (empty($data)) {
                        continue;
                    }

                    // ✅ Redis 存储的是"分"（整数），转换为"元"（小数）存入数据库
                    $betAmountInCents = intval($data['bet_amount'] ?? 0);  // 分
                    $betAmountInYuan = round($betAmountInCents / 100, 2);  // 转为元
                    $betCount = intval($data['bet_count'] ?? 0);

                    if ($betAmountInYuan <= 0) {
                        continue;
                    }

                    // ✅ 加入批量插入缓冲区
                    $batch[] = [
                        'player_id' => $playerId,
                        'stat_type' => $statType,
                        'dimension' => $keyDimension,
                        'stat_date' => $keyDate,
                        'bet_amount' => $betAmountInYuan,  // 存储"元"
                        'bet_count' => $betCount,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ];

                    // 每 100 条批量插入一次
                    if (count($batch) >= $batchSize) {
                        try {
                            PlayerBetStatistics::upsert(
                                $batch,
                                ['player_id', 'stat_type', 'dimension', 'stat_date'],
                                ['bet_amount', 'bet_count', 'updated_at']
                            );
                            $syncCount += count($batch);
                            $batch = []; // 清空缓冲区
                        } catch (\Exception $e) {
                            $failedCount += count($batch);
                            Log::error('[PlayerBetStatistics] 批量同步失败', [
                                'batch_size' => count($batch),
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                            $batch = []; // 清空缓冲区
                        }
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    Log::error('[PlayerBetStatistics] 处理 Redis Key 失败', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                    // 继续处理下一条
                }
            }

            // ✅ 防止 SCAN 无限循环
            $iterations++;
            if ($iterations >= $maxIterations) {
                Log::warning('[PlayerBetStatistics] SCAN 迭代次数超限，停止同步', [
                    'iterations' => $iterations,
                    'dimension' => $dimension,
                    'sync_count' => $syncCount,
                    'failed_count' => $failedCount,
                ]);
                break;
            }
        } while ($cursor != 0);

        // ✅ 处理剩余的批量数据
        if (!empty($batch)) {
            try {
                PlayerBetStatistics::upsert(
                    $batch,
                    ['player_id', 'stat_type', 'dimension', 'stat_date'],
                    ['bet_amount', 'bet_count', 'updated_at']
                );
                $syncCount += count($batch);
            } catch (\Exception $e) {
                $failedCount += count($batch);
                Log::error('[PlayerBetStatistics] 批量同步剩余数据失败', [
                    'batch_size' => count($batch),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('[PlayerBetStatistics] 同步完成', [
            'dimension' => $dimension,
            'date' => $date,
            'sync_count' => $syncCount,
            'failed_count' => $failedCount,
            'iterations' => $iterations,
        ]);

        return $syncCount;
    }

    /**
     * 获取排行榜
     *
     * @param string $statType 统计类型
     * @param string $dimension 维度
     * @param string $date 日期
     * @param int $limit 数量
     * @return array
     */
    public static function getRanking(
        string $statType,
        string $dimension,
        string $date,
        int $limit = 100
    ): array {
        return PlayerBetStatistics::query()
            ->where('stat_type', $statType)
            ->where('dimension', $dimension)
            ->where('stat_date', $date)
            ->orderBy('bet_amount', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * 构建 Redis Key
     *
     * @param int $playerId 玩家ID
     * @param string $statType 统计类型
     * @param string $dimension 维度
     * @param string $date 日期
     * @return string
     */
    private static function buildKey(int $playerId, string $statType, string $dimension, string $date): string
    {
        return self::REDIS_KEY_PREFIX . "{$playerId}:{$statType}:{$dimension}:{$date}";
    }

    /**
     * 获取 TTL
     *
     * @param string $dimension 维度
     * @return int
     */
    private static function getTTL(string $dimension): int
    {
        return match($dimension) {
            self::DIMENSION_DAILY => self::TTL_DAILY,
            self::DIMENSION_WEEKLY => self::TTL_WEEKLY,
            self::DIMENSION_MONTHLY => self::TTL_MONTHLY,
            default => self::TTL_DAILY,
        };
    }
}
