<?php

namespace process;

use app\model\MachineMedia;
use app\service\MediaServer;
use support\Cache;
use Workerman\Crontab\Crontab;

class GetAmsViewers
{
    public function onWorkerStart()
    {
        // 每分钟跑一次
        new Crontab('0 */1 * * * *', function () {
            $machineMediaList = MachineMedia::query()
                ->where('status', 1)
                ->get();
            /** @var MachineMedia $media */
            foreach ($machineMediaList as $media) {
                $num = (new MediaServer($media->push_ip, $media->media_app))->getViewers($media->stream_name);
                if ($num) {
                    Cache::set('ams_viewers_' . $media->machine_id . '_' . $media->id, $num, 120);
                } else {
                    Cache::set('ams_viewers_' . $media->machine_id . '_' . $media->id, 0, 120);
                }
            }
        });
    }
}
