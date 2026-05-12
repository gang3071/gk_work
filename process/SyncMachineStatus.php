<?php
/**
 * 机台状态同步进程
 *
 * 功能：
 * 1. 每5秒从硬件读取机台实时状态
 * 2. 更新 Redis 缓存
 * 3. 推送状态变化到前端（WebSocket）
 * 4. 监控异常状态
 */

namespace process;

use app\model\Machine;
use app\service\machine\MachineServices;
use Workerman\Timer;
use support\Log;
use support\Cache;

class SyncMachineStatus
{
    /**
     * Worker 启动时执行
     * @return void
     */
    public function onWorkerStart(): void
    {
        $log = Log::channel('machine');
        $log->info('SyncMachineStatus Worker 启动');

        // 获取配置的同步间隔（默认5秒）
        $interval = config('machine.sync.status_interval', 5);

        // 定时执行状态同步
        Timer::add($interval, function () use ($log) {
            try {
                $this->syncMachineStatus($log);
            } catch (\Exception $e) {
                $log->error('SyncMachineStatus 执行失败: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        $log->info("SyncMachineStatus 定时器已设置，间隔: {$interval}秒");
    }

    /**
     * 同步机台状态
     * @param $log
     * @return void
     */
    private function syncMachineStatus($log): void
    {
        // 只同步游戏中或使用中的机台
        $machines = Machine::query()
            ->where(function ($query) {
                $query->where('gaming', 1)
                    ->orWhere('is_use', 1);
            })
            ->where('status', 1)
            ->where('maintaining', 0)
            ->get();

        if ($machines->isEmpty()) {
            return;
        }

        $log->debug('开始同步机台状态', ['count' => $machines->count()]);

        foreach ($machines as $machine) {
            try {
                // 创建机台服务
                $service = MachineServices::createServices($machine);

                // 读取机台实时状态
                $status = $this->readMachineStatus($service, $machine);

                if (empty($status)) {
                    continue;
                }

                // 更新 Redis 缓存
                $this->updateCache($machine->id, $status);

                // 检测状态变化并推送
                $this->pushStatusChange($machine, $status);

            } catch (\Exception $e) {
                $log->error("机台 {$machine->code} 状态同步失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 读取机台状态
     * @param $service
     * @param Machine $machine
     * @return array
     */
    private function readMachineStatus($service, Machine $machine): array
    {
        // 根据机台类型读取不同的状态
        $status = [];

        try {
            // 通用状态
            $status['machine_id'] = $machine->id;
            $status['code'] = $machine->code;
            $status['gaming'] = $machine->gaming;
            $status['keeping'] = $machine->keeping;

            // 从服务读取实时数据（如果服务支持）
            if (method_exists($service, 'getAllData')) {
                $realtimeData = $service->getAllData();
                if (!empty($realtimeData)) {
                    $status = array_merge($status, $realtimeData);
                }
            }

        } catch (\Exception $e) {
            Log::channel('machine')->warning("读取机台 {$machine->code} 状态失败: " . $e->getMessage());
        }

        return $status;
    }

    /**
     * 更新缓存
     * @param int $machineId
     * @param array $status
     * @return void
     */
    private function updateCache(int $machineId, array $status): void
    {
        $cacheKey = "machine_status_{$machineId}";
        Cache::set($cacheKey, $status, 60); // 缓存60秒
    }

    /**
     * 推送状态变化
     * @param Machine $machine
     * @param array $newStatus
     * @return void
     */
    private function pushStatusChange(Machine $machine, array $newStatus): void
    {
        // 获取旧状态
        $cacheKey = "machine_status_prev_{$machine->id}";
        $oldStatus = Cache::get($cacheKey, []);

        // 检测变化
        $hasChange = false;
        $changes = [];

        foreach (['point', 'score', 'turn', 'pressure', 'gaming', 'keeping'] as $field) {
            if (isset($newStatus[$field]) && isset($oldStatus[$field])) {
                if ($newStatus[$field] != $oldStatus[$field]) {
                    $hasChange = true;
                    $changes[$field] = [
                        'old' => $oldStatus[$field],
                        'new' => $newStatus[$field],
                    ];
                }
            }
        }

        // 如果有变化，推送到前端
        if ($hasChange && config('machine.push.enabled', true)) {
            try {
                sendSocketMessage("machine-{$machine->id}", [
                    'msg_type' => 'machine_status_update',
                    'machine_id' => $machine->id,
                    'code' => $machine->code,
                    'status' => $newStatus,
                    'changes' => $changes,
                    'timestamp' => time(),
                ]);

                // 如果有玩家在游戏，也推送给玩家
                if ($machine->gaming && $machine->gaming_user_id) {
                    sendSocketMessage("player-{$machine->gaming_user_id}", [
                        'msg_type' => 'my_machine_status_update',
                        'machine_id' => $machine->id,
                        'status' => $newStatus,
                        'timestamp' => time(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('machine')->error("推送机台状态失败: " . $e->getMessage());
            }
        }

        // 保存当前状态为旧状态
        Cache::set($cacheKey, $newStatus, 60);
    }
}
