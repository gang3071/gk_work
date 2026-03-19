<?php

namespace app\queue\redis;

use addons\webman\model\Machine;
use addons\webman\model\SystemSetting;
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
        try {
            /** @var Machine $machine */
            $machine = Machine::query()->find($data['machine_id']);
            $services = MachineServices::createServices($machine);
            $changeAmount = $data['change_amount'];
            if (!empty($machine->machineCategory->keep_minutes) && $changeAmount > 0) {
                $nowKeepSeconds = bcadd($services->keep_seconds,
                    bcmul($machine->machineCategory->keep_minutes, $changeAmount));
                /** @var SystemSetting $setting */
                $setting = Cache::get('setting-max_keeping_minutes-0');
                if (!empty($setting) && $setting->num > 0 && $setting->num * 60 <= $nowKeepSeconds) {
                    $nowKeepSeconds = $setting->num * 60;
                }
                $services->keep_seconds = $nowKeepSeconds;
            }
            if ($services->keeping == 1) {
                $services->keeping = 0;
                updateKeepingLog($data['machine_id'], $data['player_id']);
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
        } catch (Exception $e) {
            Log::error('PlayKeepMachine', ['message' => $e->getMessage()]);
        }
    }
}
