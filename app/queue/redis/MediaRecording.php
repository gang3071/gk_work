<?php

namespace app\queue\redis;

use addons\webman\model\Machine;
use addons\webman\model\MachineMedia;
use addons\webman\service\MediaServer;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

class MediaRecording implements Consumer
{
    // 要消费的队列名
    public $queue = 'media-recording';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    // 消费
    public function consume($data)
    {
        $log = Log::channel('media_recording');
        try {
            /** @var Machine $machine */
            $machine = Machine::query()->find($data['machine_id']);
            if (empty($machine)) {
                throw new Exception('media-recording:机器不存在');
            }
            /** @var MachineMedia $media */
            $media = $machine->machine_media()->first();
            if (empty($media)) {
                throw new Exception('media-recording:机器视讯流未配置');
            }
            $log->info('media-recording:录制操作: ', [$data]);
            switch ($data['action']) {
                case 'start':
                    (new MediaServer($media->push_ip, $media->media_app))->startRecording($media, $data['cmd'],
                        $data['department_id'], $data['player_game_record_id'], $data['player_game_log_id']);
                    break;
                case 'stop':
                    (new MediaServer($media->push_ip, $media->media_app))->stopRecording($media);
                    break;
                default:
                    throw new Exception('操作错误');
            }
        } catch (Exception $e) {
            $log->error('MediaRecording:任务失败', [$e->getMessage()]);
            return false;
        }
        $log->info('MediaRecording:任务成功');
        return true;
    }
}
