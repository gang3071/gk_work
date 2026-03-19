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
        /** @var SystemSetting $systemSetting */
        $systemSetting = SystemSetting::query()
            ->where('feature', 'machine_maintain')
            ->first();
        if ($systemSetting->status == 0) {
            return $this->error('未开启机器维护');
        }
        if (strtotime($systemSetting->updated_at) != $data['setting_time']) {
            return $this->error('过期任务不执行');
        }
        $machineList = Machine::query()
            ->whereIn('type', [GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL])
            ->get();
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
            } catch (\Exception $e) {
                Log::channel('machine_maintain')->error('MachineMaintain', [$e->getMessage()]);
                continue;
            }
        }
        return $this->success();
    }
}