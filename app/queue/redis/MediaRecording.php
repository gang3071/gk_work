<?php

namespace app\queue\redis;

use app\model\Machine;
use app\model\MachineMedia;
use app\service\MediaServer;
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
        $log->info('开始处理媒体录制', ['data' => $data]);

        try {
            /** @var Machine $machine */
            $machine = Machine::query()->find($data['machine_id']);
            if (empty($machine)) {
                $log->warning('机器不存在', ['machine_id' => $data['machine_id']]);
                return false;
            }

            /** @var MachineMedia $media */
            $media = $machine->machine_media()->first();
            if (empty($media)) {
                $log->warning('机器视讯流未配置', ['machine_id' => $data['machine_id']]);
                return false;
            }

            switch ($data['action']) {
                case 'start':
                    (new MediaServer($media->push_ip, $media->media_app))->startRecording($media, $data['cmd'],
                        $data['department_id'], $data['player_game_record_id'], $data['player_game_log_id']);
                    $log->info('媒体录制启动成功', [
                        'machine_id' => $data['machine_id'],
                        'cmd' => $data['cmd'],
                        'player_game_record_id' => $data['player_game_record_id']
                    ]);
                    break;
                case 'stop':
                    (new MediaServer($media->push_ip, $media->media_app))->stopRecording($media);
                    $log->info('媒体录制停止成功', ['machine_id' => $data['machine_id']]);
                    break;
                default:
                    throw new Exception('操作错误: ' . ($data['action'] ?? 'null'));
            }

            return true;
        } catch (Exception $e) {
            $log->error('媒体录制处理失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}
