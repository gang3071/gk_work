<?php
/**
 * 机台超时玩家踢出进程
 *
 * 功能：
 * 1. 每分钟检查一次所有游戏中的机台
 * 2. 如果玩家超过设定时间（默认5分钟）没有操作，自动踢出
 * 3. 退还玩家分数
 * 4. 释放机台状态
 * 5. 发送WebSocket通知
 */

namespace process;

use Workerman\Timer;
use support\Log;

class MachineKeepOutPlayer
{
    /**
     * Worker 启动时执行
     * @return void
     */
    public function onWorkerStart(): void
    {
        $log = Log::channel('machine_keeping');
        $log->info('MachineKeepOutPlayer Worker 启动');

        // 获取配置的检查间隔（默认60秒）
        $interval = config('machine.kick.check_interval', 10);

        // 定时执行踢出检查
        Timer::add($interval, function () use ($log) {
            try {
                // 调用全局函数执行踢出逻辑
                machineKeepOutPlayer();

                $log->debug('MachineKeepOutPlayer 执行完成');
            } catch (\Exception $e) {
                $log->error('MachineKeepOutPlayer 执行失败: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        $log->info("MachineKeepOutPlayer 定时器已设置，间隔: {$interval}秒");
    }
}
