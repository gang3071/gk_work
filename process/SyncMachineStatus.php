<?php

namespace process;

use app\model\Machine;
use Workerman\Crontab\Crontab;

class SyncMachineStatus
{
    public function onWorkerStart()
    {
        // 每秒执行一次获取机台状态并保存缓存
        new Crontab('*/1 * * * * *', function () {
            //遊戲中机台
            $gamingMachines = Machine::with(['machineCategory', 'gamingPlayer'])
                ->where('gaming', 1)
                ->where('gaming_user_id', '!=', 0)
                ->orderBy('type')
                ->get();
            /** @var Machine $machine */
            foreach ($gamingMachines as $machine) {
                setMachineLive($machine);
            }
        });
    }
}
