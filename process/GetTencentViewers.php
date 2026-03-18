<?php

namespace process;

use app\model\MachineMediaPush;
use app\service\MediaServer;
use support\Cache;
use Workerman\Crontab\Crontab;

class GetTencentViewers
{
    public function onWorkerStart()
    {
        // 每分钟跑一次
        new Crontab('0 */1 * * * *', function () {
            $machineMediaPushList = MachineMediaPush::query()->get();
            $totalViewers = 0;
            /** @var MachineMediaPush $machineMediaPush */
            foreach ($machineMediaPushList as $machineMediaPush) {
                try {
                    $num = (new MediaServer())->getTencentViewers($machineMediaPush);
                } catch (\Exception $e) {
                    Cache::set('tencent_viewers_' . $machineMediaPush->machine_id . '_' . $machineMediaPush->id, 0,
                        120);
                    continue;
                }
                Cache::set('tencent_viewers_' . $machineMediaPush->machine_id . '_' . $machineMediaPush->id, $num, 120);
                $totalViewers = $totalViewers + $num;
            }
            Cache::set('tencent_total_viewers', $totalViewers, 120);
        });
    }
}
