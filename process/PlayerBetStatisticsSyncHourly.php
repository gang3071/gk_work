<?php

namespace process;

use app\service\PlayerBetStatisticsService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 玩家打码量统计同步任务（每小时）
 *
 * 作用：将 Redis 中的日维度打码量数据同步到 MySQL
 * 执行时间：每小时第7分钟
 */
class PlayerBetStatisticsSyncHourly
{
    public function onWorkerStart()
    {
        // 每小时第7分钟执行
        new Crontab('7 * * * *', function () {
            $this->sync();
        });

        Log::info('PlayerBetStatisticsSyncHourly: 已启动', [
            'schedule' => '每小时第7分钟',
            'dimension' => 'daily',
        ]);
    }

    /**
     * 同步日维度打码量
     */
    private function sync()
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
}
