<?php

namespace process;

use app\model\MachineMediaPush;
use app\model\MachineTencentPlay;
use app\service\MediaServer;
use Exception;
use support\Cache;
use support\Log;
use Workerman\Crontab\Crontab;

class TencentStream
{
    public function onWorkerStart()
    {
        // 每分钟跑一次
        new Crontab('0 */2 * * * *', function () {
            /** @var MachineTencentPlay $machineTencentPlay */
            $machineTencentPlay = MachineTencentPlay::query()->first();
            MachineMediaPush::query()
                ->where('status', 1)
                ->orderBy('id', 'desc')
                ->chunk(100, function ($machineMediaPushList) use ($machineTencentPlay) {
                    /*** @var MachineMediaPush $machineMediaPush */
                    foreach ($machineMediaPushList as $key => $machineMediaPush) {
                        try {
                            $mediaServer = new MediaServer($machineMediaPush->media->push_ip,
                                $machineMediaPush->media->media_app);
                            if (Cache::has("machine_play_" . $machineMediaPush->id)) {
                                $mediaServer->log->info('TencentStream:status跳出',
                                    [$machineMediaPush->machine_code, $machineMediaPush->id]);
                                continue;
                            }
                            if (!$mediaServer->getTencentViewers2($machineTencentPlay->api_appid,
                                $machineTencentPlay->api_key,
                                $machineMediaPush->machine_code . '_' . $machineMediaPush->endpoint_service_id)) {
                                $mediaServer->deleteRtmpEndpoint($machineMediaPush->endpoint_service_id,
                                    $machineMediaPush->media->stream_name);
                                $machineMediaPush->status = 0;
                                $machineMediaPush->save();
                                $mediaServer->log->info('TencentStream:status关闭',
                                    [$machineMediaPush->machine_code, $machineMediaPush->id]);
                            } else {
                                $mediaServer->log->info('TencentStream:status开启',
                                    [$machineMediaPush->machine_code, $machineMediaPush->id]);
                            }
                        } catch (Exception $e) {
                            Log::error('TencentStream', [$e->getMessage(), $machineMediaPush->id]);
                            continue;
                        }
                        Log::info('TencentStream:status共处理', [$key]);
                    }
                });
        });
    }
}
