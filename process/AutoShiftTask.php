<?php

namespace process;

use app\model\StoreAutoShiftConfig;
use app\service\store\AutoShiftService;
use Workerman\Crontab\Crontab;

/**
 * 自动交班定时任务
 */
class AutoShiftTask
{
    public function onWorkerStart()
    {
        // 每5分钟检查一次自动交班任务
        new Crontab('*/5 * * * *', function () {
            $this->executeAutoShift();
        });

        \Log::info('自动交班定时任务已启动', [
            'cron' => '*/5 * * * *',
            'description' => '每5分钟检查一次'
        ]);
    }

    /**
     * 执行自动交班
     */
    private function executeAutoShift()
    {
        $startTime = microtime(true);
        $service = new AutoShiftService();
        $configs = $service->getPendingConfigs();

        \Log::info('自动交班任务开始', [
            'count' => count($configs),
            'time' => date('Y-m-d H:i:s')
        ]);

        $successCount = 0;
        $failedCount = 0;

        foreach ($configs as $configData) {
            $config = StoreAutoShiftConfig::find($configData['id']);
            if (!$config) {
                \Log::warning('配置不存在', ['config_id' => $configData['id']]);
                continue;
            }

            \Log::info('开始执行自动交班', [
                'config_id' => $config->id,
                'department_id' => $config->department_id,
                'next_shift_time' => $config->next_shift_time
            ]);

            $result = $service->executeAutoShift($config);

            if ($result['code'] == 0) {
                $successCount++;
                \Log::info('自动交班执行成功', [
                    'config_id' => $config->id,
                    'shift_record_id' => $result['data']['shift_record_id'] ?? null
                ]);
            } else {
                $failedCount++;
                \Log::error('自动交班执行失败', [
                    'config_id' => $config->id,
                    'error' => $result['msg']
                ]);
            }

            // 避免并发过多，每次执行间隔0.5秒
            usleep(500000);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        \Log::info('自动交班任务完成', [
            'total' => count($configs),
            'success' => $successCount,
            'failed' => $failedCount,
            'duration' => $duration . 'ms'
        ]);
    }
}
