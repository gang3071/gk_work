<?php

namespace process;

use app\model\GameType;
use app\model\Machine;
use app\service\machine\MachineServices;
use GatewayWorker\Lib\Gateway;
use Workerman\Crontab\Crontab;
use yzh52521\WebmanLock\Locker;

class ClearAbnormalMachine
{
    public function onWorkerStart()
    {
        // 每5分钟跑一次
        new Crontab('0 */5 * * * *', function () {
            ini_set('memory_limit', '512M');
            $gamingMachines = Machine::query()
                ->where('gaming', 0)
                ->where('gaming_user_id', 0)
                ->where('status', 1)
                ->where('is_use', 0)
                ->whereIn('type', [GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL])
                ->get();
            /** @var Machine $machine */
            foreach ($gamingMachines as $machine) {
                $actionLockerKey = 'machine_open_lock' . $machine->id;
                $lock = Locker::lock($actionLockerKey);
                if ($lock->isAcquired()) {
                    continue;
                }
                $machineStatus = false;
                switch ($machine->type) {
                    case GameType::TYPE_SLOT:
                        if (Gateway::isUidOnline($machine->domain . ':' . $machine->port) && Gateway::isUidOnline($machine->auto_card_domain . ':' . $machine->auto_card_port)) {
                            $machineStatus = true;
                        }
                        break;
                    case GameType::TYPE_STEEL_BALL:
                        if (Gateway::isUidOnline($machine->domain . ':' . $machine->port)) {
                            $machineStatus = true;
                        }
                        break;
                }
                if ($machineStatus) {
                    $services = MachineServices::createServices($machine);
                    if ($machine->gaming == 0 && $services->reward_status == 0) {
                        switch ($machine->type) {
                            case GameType::TYPE_SLOT:
                                if ($services->point > 0) {
                                    $services->sendCmd($services::WASH_ZERO);
                                }
                                break;
                            case GameType::TYPE_STEEL_BALL:
                                if ($services->score > 0) {
                                    $services->sendCmd($services::SCORE_TO_POINT);
                                }
                                if ($services->turn > 0) {
                                    $services->sendCmd($services::TURN_DOWN_ALL);
                                }
                                if ($services->point > 0) {
                                    $services->sendCmd($services::WASH_ZERO);
                                }
                                break;
                        }
                    }
                }
            }
        });
    }
}
