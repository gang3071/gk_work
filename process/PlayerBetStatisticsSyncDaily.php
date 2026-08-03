<?php

namespace process;

use app\service\PlayerBetStatisticsService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 玩家打码量统计同步任务（每天）
 *
 * 作用：将 Redis 中的周/月维度打码量数据同步到 MySQL
 * 执行时间：每天凌晨3:07
 */
class PlayerBetStatisticsSyncDaily
{
    public function onWorkerStart()
    {
        // 每天凌晨3:07执行
        new Crontab('7 3 * * *', function () {
            $this->sync();
        });

        Log::info('PlayerBetStatisticsSyncDaily: 已启动', [
            'schedule' => '每天凌晨3:07',
            'dimensions' => 'weekly, monthly',
        ]);
    }

    /**
     * 同步周/月维度打码量
     */
    private function sync()
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
