<?php

namespace process;

use app\model\Machine;
use app\service\LotteryServices;
use Exception;
use support\Log;
use Workerman\Crontab\Crontab;

class LotteryPool
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
            if (!empty($gamingMachines)) {
                $lotteryServices = (new LotteryServices())->setJackLotteryList()->setSlotLotteryList();
                /** @var Machine $machine */
                foreach ($gamingMachines as $machine) {
                    try {
                        $lotteryServices->setMachine($machine)->setPlayer($machine->gamingPlayer)->getMachineCacheData()->checkLottery();
                    } catch (Exception $e) {
                        Log::error('LotteryPool:' . $e->getMessage());
                    }
                }
            }
        });
    }
}
