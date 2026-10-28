<?php

namespace process;

use app\model\MachineMedia;
use app\service\MediaServer;
use support\Cache;
use Workerman\Crontab\Crontab;

class GetAmsViewers
{
    // 防止上一轮还没跑完时重入
    private bool $running = false;

    public function onWorkerStart()
    {
        // 每分钟跑一次
        new Crontab('0 */1 * * * *', function () {
            if ($this->running) {
                return;
            }
            $this->running = true;
            try {
                $machineMediaList = MachineMedia::query()
                    ->where('status', 1)
                    ->get();
                /** @var MachineMedia $media */
                foreach ($machineMediaList as $media) {
                    $num = (new MediaServer($media->push_ip, $media->media_app))->getViewers($media->stream_name);
                    Cache::set('ams_viewers_' . $media->machine_id . '_' . $media->id, $num ?: 0, 120);
                }
            } finally {
                $this->running = false;
            }
        });
    }
}
