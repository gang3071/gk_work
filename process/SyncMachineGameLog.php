<?php
/**
 * 机台游戏日志同步进程
 *
 * 功能：
 * 1. 每天凌晨1点同步一次每日累积转数/压/得
 * 2. 统计7天和30天的数据
 * 3. 针对钢珠机和老虎机分别处理
 */

namespace process;

use app\model\GameType;
use app\model\Machine;
use app\model\MachineGamingLog;
use app\model\MachineOpenCard;
use app\service\MachineServices;
use Exception;
use support\Log;
use Workerman\Crontab\Crontab;
use Workerman\Worker;

class SyncMachineGameLog
{
    /**
     * @var Worker
     */
    private Worker $worker;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $log;

    public function __construct()
    {
        $this->log = Log::channel('machine_log');
    }

    /**
     * Worker 启动时执行
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        $this->worker = $worker;

        $this->log->info('SyncMachineGameLog Worker 启动');

        // 显式绑定 $this，避免闭包作用域问题
        $self = $this;

        // 每天凌晨1点执行（避开高峰期）
        new Crontab('0 1 * * *', function () use ($self) {
            $self->syncMachineGamingLog();
        });

        $this->log->info('SyncMachineGameLog 定时器已设置：每天凌晨1点执行');
    }

    /**
     * 写入每日累积转数/压/得
     * @return void
     */
    private function syncMachineGamingLog(): void
    {
        $this->log->info('开始同步机台游戏日志');

        $machines = Machine::where('status', 1)
            ->orderBy('type', 'asc')
            ->get();

        $date = date('Y-m-d');
        $successCount = 0;
        $errorCount = 0;

        /** @var Machine $machine */
        foreach ($machines as $machine) {
            try {
                // 获取开卡记录（用于确定统计起始点）
                /** @var MachineOpenCard $machineOpenCard */
                $machineOpenCard = MachineOpenCard::where('machine_id', $machine->id)
                    ->orderBy('id', 'desc')
                    ->first();

                // 获取7天前的记录
                if (!empty($machineOpenCard)) {
                    $seventh = MachineGamingLog::where('machine_id', $machine->id)
                        ->where('updated_at', '>=', $machineOpenCard->created_at)
                        ->orderBy('date', 'desc')
                        ->limit(7)
                        ->get()
                        ->last();
                } else {
                    $seventh = MachineGamingLog::where('machine_id', $machine->id)
                        ->orderBy('date', 'desc')
                        ->limit(7)
                        ->get()
                        ->last();
                }

                // 获取30天前的记录
                if (!empty($machineOpenCard)) {
                    $thirty = MachineGamingLog::where('machine_id', $machine->id)
                        ->where('updated_at', '>=', $machineOpenCard->created_at)
                        ->orderBy('date', 'desc')
                        ->limit(30)
                        ->get()
                        ->last();
                } else {
                    $thirty = MachineGamingLog::where('machine_id', $machine->id)
                        ->orderBy('date', 'desc')
                        ->limit(30)
                        ->get()
                        ->last();
                }

                // 钢珠机处理
                if ($machine->type == GameType::TYPE_STEEL_BALL) {
                    $services = MachineServices::createServices($machine);
                    MachineGamingLog::updateOrCreate([
                        'machine_id' => $machine->id,
                        'type' => $machine->type,
                        'date' => $date,
                    ], [
                        'turn_point' => $services->win_number ?? 0,
                        'seventh_turn_point' => $seventh->turn_point ?? 0,
                        'thirty_turn_point' => $thirty->turn_point ?? 0,
                    ]);
                    $successCount++;
                }

                // 老虎机处理
                if ($machine->type == GameType::TYPE_SLOT) {
                    $services = MachineServices::createServices($machine);
                    $services->sendCmd($services::READ_BET, 0, 'admin', 0, 1);
                    $services->sendCmd($services::READ_WIN, 0, 'admin', 0, 1);
                    MachineGamingLog::query()->updateOrCreate([
                        'machine_id' => $machine->id,
                        'type' => $machine->type,
                        'date' => $date,
                    ], [
                        'pressure' => $services->bet,
                        'score' => $services->win,
                        'seventh_pressure' => $seventh->pressure ?? 0,
                        'seventh_score' => $seventh->score ?? 0,
                        'thirty_pressure' => $thirty->pressure ?? 0,
                        'thirty_score' => $thirty->score ?? 0,
                    ]);
                    $successCount++;
                }
            } catch (Exception $e) {
                $errorCount++;
                $this->log->error('syncMachineGamingLog 机台处理失败', [
                    'machine_id' => $machine->id,
                    'machine_code' => $machine->code,
                    'type' => $machine->type,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                continue;
            }
        }

        $this->log->info('同步机台游戏日志完成', [
            'total' => $machines->count(),
            'success' => $successCount,
            'error' => $errorCount,
        ]);
    }
}
