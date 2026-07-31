<?php

namespace process;

use app\service\PlayerBetStatisticsService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 玩家打码量统计同步任务
 *
 * 作用：定期将 Redis 中的实时打码量数据同步到 MySQL
 * - 日维度：每小时同步一次
 * - 周维度：每天凌晨同步
 * - 月维度：每天凌晨同步
 *
 * 数据流向：Redis（实时）→ MySQL（持久化）
 */
class PlayerBetStatisticsSyncTask
{
    public function onWorkerStart()
    {
        // ✅ 每小时第7分钟：同步日维度打码量
        new Crontab('7 * * * *', function () {
            $this->syncDaily();
        });

        // ✅ 每天凌晨3:07：同步周/月维度打码量
        new Crontab('7 3 * * *', function () {
            $this->syncWeeklyAndMonthly();
        });

        Log::info('PlayerBetStatisticsSyncTask: 打码量同步任务已启动', [
            'daily_sync' => '每小时第7分钟',
            'weekly_monthly_sync' => '每天凌晨3:07',
        ]);
    }

    /**
     * 同步日维度打码量
     */
    private function syncDaily()
    {
        try {
            $count = PlayerBetStatisticsService::syncToDatabase(
                PlayerBetStatisticsService::DIMENSION_DAILY
            );
            Log::info('[PlayerBetStatsSync] 日打码量同步完成', ['count' => $count]);
        } catch (\Exception $e) {
            Log::error('[PlayerBetStatsSync] 日打码量同步失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 同步周/月维度打码量
     */
    private function syncWeeklyAndMonthly()
    {
        try {
            // 同步周统计
            $countWeekly = PlayerBetStatisticsService::syncToDatabase(
                PlayerBetStatisticsService::DIMENSION_WEEKLY
            );

            // 同步月统计
            $countMonthly = PlayerBetStatisticsService::syncToDatabase(
                PlayerBetStatisticsService::DIMENSION_MONTHLY
            );

            Log::info('[PlayerBetStatsSync] 周/月打码量同步完成', [
                'weekly' => $countWeekly,
                'monthly' => $countMonthly,
            ]);
        } catch (\Exception $e) {
            Log::error('[PlayerBetStatsSync] 周/月打码量同步失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
