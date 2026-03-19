<?php

namespace app\queue\redis;

use app\model\Machine;
use app\model\SystemSetting;
use app\service\machine\MachineServices;
use Exception;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Consumer;

class PlayKeepMachine implements Consumer
{
    // 要消费的队列名
    public $queue = 'play-keep-machine';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';


    /**
     * @param $data
     * @return void
     */
    public function consume($data)
    {
        $log = Log::channel('play_keep_machine');
        $log->info('开始处理机台保留', ['data' => $data]);

        try {
            /** @var Machine $machine */
            $machine = Machine::query()->find($data['machine_id']);
            if (empty($machine)) {
                $log->warning('机台不存在', ['machine_id' => $data['machine_id']]);
                return;
            }

            $services = MachineServices::createServices($machine);
            $changeAmount = $data['change_amount'];
            $oldKeepSeconds = $services->keep_seconds;

            if (!empty($machine->machineCategory->keep_minutes) && $changeAmount > 0) {
                $nowKeepSeconds = bcadd($services->keep_seconds,
                    bcmul($machine->machineCategory->keep_minutes, $changeAmount));
                /** @var SystemSetting $setting */
                $setting = Cache::get('setting-max_keeping_minutes-0');
                if (!empty($setting) && $setting->num > 0 && $setting->num * 60 <= $nowKeepSeconds) {
                    $nowKeepSeconds = $setting->num * 60;
                    $log->info('保留时间已达上限', ['max_seconds' => $nowKeepSeconds]);
                }
                $services->keep_seconds = $nowKeepSeconds;
                $log->info('更新保留时间', [
                    'old_seconds' => $oldKeepSeconds,
                    'new_seconds' => $nowKeepSeconds,
                    'change_amount' => $changeAmount
                ]);
            }

            if ($services->keeping == 1) {
                $services->keeping = 0;
                updateKeepingLog($data['machine_id'], $data['player_id']);
                $log->info('结束保留状态', [
                    'machine_id' => $data['machine_id'],
                    'player_id' => $data['player_id']
                ]);
            }

            sendSocketMessage('player-' . $machine->gaming_user_id . '-' . $machine->id, [
                'msg_type' => 'player_machine_keeping',
                'player_id' => $machine->gaming_user_id,
                'machine_id' => $machine->id,
                'keep_seconds' => $services->keep_seconds,
                'keeping' => $services->keeping
            ]);
            sendSocketMessage('player-' . $machine->gaming_user_id, [
                'msg_type' => 'player_machine_keeping',
                'player_id' => $machine->gaming_user_id,
                'machine_id' => $machine->id,
                'keep_seconds' => $services->keep_seconds,
                'keeping' => $services->keeping
            ]);

            $log->info('机台保留处理完成', [
                'machine_id' => $data['machine_id'],
                'player_id' => $data['player_id'],
                'keep_seconds' => $services->keep_seconds,
                'keeping' => $services->keeping
            ]);
        } catch (Exception $e) {
            $log->error('机台保留处理失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
