<?php
/**
 * 机台游戏日志同步进程
 *
 * 功能：
 * 1. 每10秒同步一次机台游戏日志
 * 2. 记录玩家游戏行为
 * 3. 统计游戏数据
 * 4. 用于后续数据分析
 */

namespace process;

use app\model\Machine;
use app\model\MachineGamingLog;
use Workerman\Timer;
use support\Log;
use support\Db;

class SyncMachineGameLog
{
    /**
     * Worker 启动时执行
     * @return void
     */
    public function onWorkerStart(): void
    {
        $log = Log::channel('machine');
        $log->info('SyncMachineGameLog Worker 启动');

        // 获取配置的同步间隔（默认10秒）
        $interval = config('machine.sync.log_interval', 10);

        // 定时执行日志同步
        Timer::add($interval, function () use ($log) {
            try {
                $this->syncGameLogs($log);
            } catch (\Exception $e) {
                $log->error('SyncMachineGameLog 执行失败: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        $log->info("SyncMachineGameLog 定时器已设置，间隔: {$interval}秒");
    }

    /**
     * 同步游戏日志
     * @param $log
     * @return void
     */
    private function syncGameLogs($log): void
    {
        // 获取所有游戏中的机台
        $machines = Machine::query()
            ->where('gaming', 1)
            ->where('gaming_user_id', '>', 0)
            ->where('status', 1)
            ->get();

        if ($machines->isEmpty()) {
            return;
        }

        $log->debug('开始同步游戏日志', ['count' => $machines->count()]);

        foreach ($machines as $machine) {
            try {
                $this->createGameLog($machine);
            } catch (\Exception $e) {
                $log->error("机台 {$machine->code} 日志同步失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 创建游戏日志
     * @param Machine $machine
     * @return void
     */
    private function createGameLog(Machine $machine): void
    {
        // 检查是否已有最近的日志记录
        $lastLog = MachineGamingLog::query()
            ->where('machine_id', $machine->id)
            ->where('player_id', $machine->gaming_user_id)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 60)) // 1分钟内
            ->first();

        // 如果1分钟内已有记录，跳过
        if ($lastLog) {
            return;
        }

        // 创建新的游戏日志
        Db::transaction(function () use ($machine) {
            MachineGamingLog::create([
                'machine_id' => $machine->id,
                'player_id' => $machine->gaming_user_id,
                'department_id' => $machine->gamingPlayer->department_id ?? 0,
                'type' => $machine->type,
                'pressure' => $machine->pressure ?? 0,
                'score' => $machine->score ?? 0,
                'point' => $machine->point ?? 0,
                'turn' => $machine->now_turn_point ?? 0,
                'gaming_time' => time() - ($machine->last_game_at ? strtotime($machine->last_game_at) : time()),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        });

        Log::channel('machine')->debug("机台 {$machine->code} 游戏日志已创建");
    }
}
