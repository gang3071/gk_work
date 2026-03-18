<?php

namespace process;

use app\model\MachineRecording;
use app\service\MediaServer;
use DateTime;
use support\Log;
use Workerman\Crontab\Crontab;

class MediaRecordingClear
{
    /**
     * 发送彩金变化消息
     * @return void
     */
    public function onWorkerStart()
    {
        new Crontab('* */10 * * * *', function () {
            $result = MachineRecording::query()
                ->where('created_at', '<=', (new DateTime())->modify('-3 day'))
                ->limit(100)
                ->get();
            Log::channel('media_recording')->info('清理过期视频开始');
            /** @var MachineRecording $item */
            foreach ($result as $item) {
                try {
                    (new MediaServer($item->media->push_ip, $item->media->media_app))->deleteRecording($item);
                } catch (\Exception $e) {
                    Log::channel('media_recording')->error($e->getMessage());
                }
                $item->delete();
            }
        });
    }
}

