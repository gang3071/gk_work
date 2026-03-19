<?php

namespace app\queue\redis;

use app\model\GameType;
use app\model\Machine;
use app\model\SystemSetting;
use app\service\machine\MachineServices;
use Exception;
use support\Log;
use Webman\Push\PushException;
use Webman\RedisQueue\Consumer;

class MachineMaintain implements Consumer
{
    public $queue = 'machine-maintain';

    public $connection = 'default';

    /**
     * @param $data
     * @return void|null
     * @throws PushException
     */
    public function consume($data)
    {
        $log = Log::channel('machine_maintain');

        /** @var SystemSetting $systemSetting */
        $systemSetting = SystemSetting::query()
            ->where('feature', 'machine_maintain')
            ->first();
        if ($systemSetting->status == 0) {
            $log->warning('未开启机器维护');
            return;
        }
        if (strtotime($systemSetting->updated_at) != $data['setting_time']) {
            $log->warning('过期任务不执行', ['setting_time' => $data['setting_time'], 'updated_at' => $systemSetting->updated_at]);
            return;
        }

        $machineList = Machine::query()
            ->whereIn('type', [GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL])
            ->get();

        $total = $machineList->count();
        $successCount = 0;
        $failCount = 0;

        $log->info('开始机器维护', ['total' => $total]);

        /** @var Machine $machine */
        foreach ($machineList as $machine) {
            try {
                switch ($machine->type) {
                    case GameType::TYPE_SLOT:
                        $services = MachineServices::createServices($machine);
                        if ($services->auto == 1) {
                            $services->sendCmd($services::OUT_OFF, 0, 'player', $machine->gaming_user_id, 1);
                        }
                        break;
                    case GameType::TYPE_STEEL_BALL:
                        $services = MachineServices::createServices($machine);
                        if ($services->auto == 1) {
                            $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $machine->gaming_user_id, 1);
                        }
                        break;
                    default:
                        throw new Exception('机台类型错误');
                }
                $successCount++;
            } catch (\Exception $e) {
                $failCount++;
                $log->error('机器维护失败', [
                    'machine_id' => $machine->id,
                    'machine_code' => $machine->code,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        $log->info('机器维护完成', [
            'total' => $total,
            'success' => $successCount,
            'fail' => $failCount
        ]);
    }
}