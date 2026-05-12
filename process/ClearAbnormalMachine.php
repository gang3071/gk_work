<?php
/**
 * 清理异常机台进程
 *
 * 功能：
 * 1. 每小时检查一次异常机台
 * 2. 清理长时间未响应的机台
 * 3. 重置异常状态
 * 4. 释放被占用的资源
 */

namespace process;

use app\model\Machine;
use Workerman\Timer;
use support\Log;
use support\Db;

class ClearAbnormalMachine
{
    /**
     * Worker 启动时执行
     * @return void
     */
    public function onWorkerStart(): void
    {
        $log = Log::channel('machine');
        $log->info('ClearAbnormalMachine Worker 启动');

        // 获取配置的清理间隔（默认3600秒 = 1小时）
        $interval = config('machine.cleanup.cleanup_interval', 3600);

        // 定时执行清理
        Timer::add($interval, function () use ($log) {
            try {
                $this->clearAbnormalMachines($log);
            } catch (\Exception $e) {
                $log->error('ClearAbnormalMachine 执行失败: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        $log->info("ClearAbnormalMachine 定时器已设置，间隔: {$interval}秒");
    }

    /**
     * 清理异常机台
     * @param $log
     * @return void
     */
    private function clearAbnormalMachines($log): void
    {
        $abnormalTimeout = config('machine.cleanup.abnormal_timeout', 3600);
        $timeThreshold = date('Y-m-d H:i:s', time() - $abnormalTimeout);

        // 查找异常机台
        // 1. 长时间处于 gaming=1 但没有玩家
        // 2. 长时间处于 keeping=1 但保留时间已过
        // 3. 长时间没有更新状态

        $abnormalMachines = Machine::query()
            ->where(function ($query) use ($timeThreshold) {
                // 游戏中但没有玩家
                $query->where(function ($q) use ($timeThreshold) {
                    $q->where('gaming', 1)
                        ->where('gaming_user_id', 0)
                        ->where('updated_at', '<', $timeThreshold);
                })
                // 或者保留中但保留时间已过
                ->orWhere(function ($q) use ($timeThreshold) {
                    $q->where('keeping', 1)
                        ->where('keeping_user_id', 0)
                        ->where('last_keep_at', '<', $timeThreshold);
                });
            })
            ->where('status', 1)
            ->get();

        if ($abnormalMachines->isEmpty()) {
            $log->info('没有发现异常机台');
            return;
        }

        $log->warning('发现异常机台', ['count' => $abnormalMachines->count()]);

        foreach ($abnormalMachines as $machine) {
            try {
                $this->clearMachine($machine, $log);
            } catch (\Exception $e) {
                $log->error("清理机台 {$machine->code} 失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 清理单个机台
     * @param Machine $machine
     * @param $log
     * @return void
     */
    private function clearMachine(Machine $machine, $log): void
    {
        Db::transaction(function () use ($machine, $log) {
            $changes = [];

            // 重置游戏状态
            if ($machine->gaming == 1 && $machine->gaming_user_id == 0) {
                $machine->gaming = 0;
                $machine->gaming_user_id = 0;
                $changes[] = 'gaming';
            }

            // 重置保留状态
            if ($machine->keeping == 1 && $machine->keeping_user_id == 0) {
                $machine->keeping = 0;
                $machine->keeping_user_id = 0;
                $changes[] = 'keeping';
            }

            // 重置使用状态
            if ($machine->is_use == 1) {
                $machine->is_use = 0;
                $changes[] = 'is_use';
            }

            if (!empty($changes)) {
                $machine->save();

                $log->warning("机台 {$machine->code} 已清理", [
                    'machine_id' => $machine->id,
                    'changes' => implode(', ', $changes),
                ]);

                // 推送状态变化
                try {
                    sendSocketMessage("machine-{$machine->id}", [
                        'msg_type' => 'machine_cleared',
                        'machine_id' => $machine->id,
                        'code' => $machine->code,
                        'reason' => 'abnormal_timeout',
                        'timestamp' => time(),
                    ]);
                } catch (\Exception $e) {
                    $log->error("推送机台清理消息失败: " . $e->getMessage());
                }
            }
        });
    }
}
