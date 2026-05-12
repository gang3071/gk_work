<?php
/**
 * 机台活动同步进程
 *
 * 功能：
 * 1. 每分钟同步一次机台活动数据
 * 2. 统计机台运行时间
 * 3. 统计玩家游戏次数
 * 4. 用于活动奖励计算
 */

namespace process;

use app\model\Machine;
use Workerman\Timer;
use support\Log;
use support\Cache;

class SyncMachineActivity
{
    /**
     * Worker 启动时执行
     * @return void
     */
    public function onWorkerStart(): void
    {
        $log = Log::channel('machine');
        $log->info('SyncMachineActivity Worker 启动');

        // 获取配置的同步间隔（默认60秒）
        $interval = config('machine.sync.activity_interval', 60);

        // 定时执行活动同步
        Timer::add($interval, function () use ($log) {
            try {
                $this->syncMachineActivity($log);
            } catch (\Exception $e) {
                $log->error('SyncMachineActivity 执行失败: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        $log->info("SyncMachineActivity 定时器已设置，间隔: {$interval}秒");
    }

    /**
     * 同步机台活动
     * @param $log
     * @return void
     */
    private function syncMachineActivity($log): void
    {
        // 获取所有启用的机台
        $machines = Machine::query()
            ->where('status', 1)
            ->where('maintaining', 0)
            ->get();

        if ($machines->isEmpty()) {
            return;
        }

        $log->debug('开始同步机台活动', ['count' => $machines->count()]);

        $today = date('Y-m-d');

        foreach ($machines as $machine) {
            try {
                // 统计今日游戏次数
                $todayGamingCount = Cache::get("machine_gaming_count_{$machine->id}_{$today}", 0);

                // 统计今日运行时间（秒）
                $todayRuntime = Cache::get("machine_runtime_{$machine->id}_{$today}", 0);

                // 如果机台正在游戏中，增加运行时间
                if ($machine->gaming == 1) {
                    $todayRuntime += 60; // 增加60秒
                    Cache::set("machine_runtime_{$machine->id}_{$today}", $todayRuntime, 86400);
                }

                // 更新机台的 amount 字段（运转次数）
                if ($machine->gaming == 1 && $machine->gaming_user_id > 0) {
                    $machine->increment('amount');
                }

            } catch (\Exception $e) {
                $log->error("机台 {$machine->code} 活动同步失败: " . $e->getMessage());
            }
        }
    }
}
