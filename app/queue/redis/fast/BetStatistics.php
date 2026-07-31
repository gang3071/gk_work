<?php

namespace app\queue\redis\fast;

use app\service\PlayerBetStatisticsService;
use Carbon\Carbon;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

/**
 * 玩家打码量统计队列消费者
 */
class BetStatistics implements Consumer
{
    // 队列名
    public $queue = 'bet-statistics';

    // 连接名（使用 default 连接）
    public $connection = 'default';

    /**
     * 日志通道
     * @var \Monolog\Logger
     */
    private $log;

    public function __construct()
    {
        $this->log = Log::channel('bet_statistics');
    }

    /**
     * 消费消息
     *
     * @param array $data
     * @return void
     */
    public function consume($data)
    {
        // ✅ 记录收到的消息（用于调试）
        $this->log->info('[BetStats] 收到打码量统计消息', [
            'player_id' => $data['player_id'] ?? null,
            'stat_type' => $data['stat_type'] ?? null,
            'bet_amount' => $data['bet_amount'] ?? null,
            'source' => $data['source'] ?? null,
            'machine_id' => $data['machine_id'] ?? null,
            'created_at' => $data['created_at'] ?? null,  // ⚠️ 关键：显示时间
        ]);

        try {
            // ✅ 验证必要字段（包括 created_at，防止跨天边界错乱）
            if (empty($data['player_id']) || empty($data['stat_type']) || empty($data['bet_amount']) || empty($data['created_at'])) {
                $this->log->error('[BetStats] 队列消息字段缺失（拒绝处理）', ['data' => $data]);
                return;
            }

            $playerId = intval($data['player_id']);
            $statType = $data['stat_type'];
            $betAmount = floatval($data['bet_amount']);
            $source = $data['source'] ?? 'unknown';

            // ✅ 强制使用 created_at，不使用 Carbon::now() 作为后备
            // 原因：防止跨天边界时统计到错误的日期
            // 场景：23:59:59 投递，00:00:05 处理 → 应该统计到前一天，而不是当天
            $createdAt = Carbon::parse($data['created_at']);

            // 防止重复消费（使用唯一标识）
            if ($this->isDuplicate($data)) {
                $this->log->info('[BetStats] 检测到重复消息，已跳过', [
                    'player_id' => $playerId,
                    'stat_type' => $statType,
                ]);
                return;
            }

            $this->log->debug('[BetStats] 开始累加统计', [
                'player_id' => $playerId,
                'stat_type' => $statType,
                'bet_amount' => $betAmount,
            ]);

            // 调用统计服务累加
            $results = PlayerBetStatisticsService::increment(
                $playerId,
                $statType,
                $betAmount,
                $createdAt
            );

            $this->log->debug('[BetStats] 统计服务返回结果', [
                'results' => $results,
            ]);

            $this->log->info('[BetStats] 打码量统计成功', [
                'player_id' => $playerId,
                'stat_type' => $statType,
                'bet_amount' => $betAmount,
                'source' => $source,
                'results' => [
                    'daily' => $results['daily']['after_amount'] ?? 0,
                    'weekly' => $results['weekly']['after_amount'] ?? 0,
                    'monthly' => $results['monthly']['after_amount'] ?? 0,
                ],
            ]);

            // ✅ 统计成功后立即删除去重标记，节省内存
            // 如果统计失败会抛异常进入 catch，不会执行到这里，保留标记用于重试
            $this->cleanupDuplicateFlag($data);

        } catch (Exception $e) {
            $this->log->error('[BetStats] 消费失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 抛出异常触发重试（webman/redis-queue 会自动重试）
            throw $e;
        }
    }

    /**
     * 检测是否重复消费
     *
     * @param array $data
     * @return bool
     */
    private function isDuplicate(array $data): bool
    {
        // 电子游戏：使用 play_game_record_id 去重
        if (!empty($data['play_game_record_id'])) {
            // ✅ 增加项目前缀，防止多项目冲突
            $cacheKey = 'gk_work:bet_stats_processed_game_' . $data['play_game_record_id'];
            $redis = \support\Redis::connection()->client();

            // ✅ PhpRedis exists() 返回存在的 key 数量（整数），不是布尔值
            if ($redis->exists($cacheKey) > 0) {
                return true;
            }

            // ✅ 1小时 TTL：防止重复消费同一条记录
            // 原因：队列重试机制最多 1 小时内重试
            // 内存占用：假设 100 万次/天，100 万 × 100 字节 × 1 小时/24 小时 ≈ 4MB
            $redis->setex($cacheKey, 3600, 1);
            return false;
        }

        // 实体机台：基于玩家ID + 机台ID + 金额 + 时间戳的组合去重（时间窗口5秒）
        if (!empty($data['machine_id'])) {
            $timestamp = strtotime($data['created_at'] ?? 'now');
            // ✅ 增加项目前缀，防止多项目冲突
            $cacheKey = sprintf(
                'gk_work:bet_stats_processed_machine_%d_%d_%s_%d',
                $data['player_id'],
                $data['machine_id'],
                number_format($data['bet_amount'], 2, '', ''),
                floor($timestamp / 5) // 5秒窗口
            );

            $redis = \support\Redis::connection()->client();

            // ✅ PhpRedis exists() 返回存在的 key 数量（整数），不是布尔值
            if ($redis->exists($cacheKey) > 0) {
                return true;
            }

            // ✅ 10秒 TTL：足够覆盖 5 秒窗口 × 2
            // 原因：实体机台同一个动作不会在 10 秒内重复
            // 内存占用：假设 10 万次/天，10 万 × 100 字节 × 10 秒/86400 秒 ≈ 100KB
            $redis->setex($cacheKey, 10, 1);
            return false;
        }

        // 其他情况：不去重（风险自担）
        return false;
    }

    /**
     * 清理去重标记（统计成功后立即删除，节省内存）
     *
     * @param array $data
     * @return void
     */
    private function cleanupDuplicateFlag(array $data): void
    {
        try {
            $redis = \support\Redis::connection()->client();

            // 电子游戏：删除去重标记
            if (!empty($data['play_game_record_id'])) {
                $cacheKey = 'gk_work:bet_stats_processed_game_' . $data['play_game_record_id'];
                $redis->del($cacheKey);
            }

            // 实体机台：删除去重标记
            if (!empty($data['machine_id'])) {
                $timestamp = strtotime($data['created_at'] ?? 'now');
                $cacheKey = sprintf(
                    'gk_work:bet_stats_processed_machine_%d_%d_%s_%d',
                    $data['player_id'],
                    $data['machine_id'],
                    number_format($data['bet_amount'], 2, '', ''),
                    floor($timestamp / 5)
                );
                $redis->del($cacheKey);
            }
        } catch (\Exception $e) {
            // 清理失败不影响业务，只记录日志
            $this->log->warning('[BetStats] 清理去重标记失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
