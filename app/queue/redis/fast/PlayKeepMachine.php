<?php

namespace app\queue\redis\fast;

use app\model\Machine;
use app\model\SystemSetting;
use app\service\machine\MachineServices;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Consumer;

/**
 * 玩家保留机台队列消费者
 *
 * 功能：
 * 1. 玩家活动（押注/转数增加）时增加保留时间
 * 2. 解除保留状态
 * 3. 推送保留时间变化到客户端
 */
class PlayKeepMachine implements Consumer
{
    /**
     * 队列名称
     */
    public $queue = 'play-keep-machine';

    /**
     * 连接名称
     */
    public $connection = 'default';

    /**
     * 消费消息
     *
     * @param array $data 消息数据
     * @return void
     */
    public function consume($data)
    {
        try {
            $machineId = $data['machine_id'] ?? 0;
            $playerId = $data['player_id'] ?? 0;
            $changeAmount = $data['change_amount'] ?? 0;

            if (!$machineId || !$playerId) {
                Log::warning('[PlayKeepMachine] 数据不完整', $data);
                return;
            }

            /** @var Machine $machine */
            $machine = Machine::query()->find($machineId);
            if (!$machine) {
                Log::warning('[PlayKeepMachine] 机台不存在', ['machine_id' => $machineId]);
                return;
            }

            $services = MachineServices::createServices($machine);

            // ✅ 从 Redis 读取 gaming_user_id（实时数据，避免使用数据库缓存值）
            $gamingUserId = $services->gaming_user_id ?? 0;

            if (empty($gamingUserId)) {
                Log::info('[PlayKeepMachine] 机台无游戏中玩家，跳过', [
                    'machine_id' => $machineId,
                    'machine_code' => $machine->code,
                    'queue_player_id' => $playerId,
                ]);
                return;
            }

            // 增加保留时间
            if (!empty($machine->machineCategory->keep_minutes) && $changeAmount > 0) {
                $nowKeepSeconds = bcadd(
                    $services->keep_seconds,
                    bcmul($machine->machineCategory->keep_minutes, $changeAmount)
                );

                // 检查最大保留时间限制
                /** @var SystemSetting $setting */
                $setting = Cache::get('setting-max_keeping_minutes-0');
                if (!empty($setting) && $setting->num > 0 && $setting->num * 60 <= $nowKeepSeconds) {
                    $nowKeepSeconds = $setting->num * 60;
                }

                $services->keep_seconds = $nowKeepSeconds;

                Log::info('[PlayKeepMachine] 保留时间已增加', [
                    'machine_id' => $machineId,
                    'machine_code' => $machine->code,
                    'player_id' => $gamingUserId,
                    'change_amount' => $changeAmount,
                    'keep_minutes' => $machine->machineCategory->keep_minutes,
                    'new_keep_seconds' => $nowKeepSeconds,
                ]);
            }

            // 解除保留状态
            if ($services->keeping == 1) {
                $services->keeping = 0;
                updateKeepingLog($machineId, $playerId);

                Log::info('[PlayKeepMachine] 保留状态已解除', [
                    'machine_id' => $machineId,
                    'machine_code' => $machine->code,
                    'player_id' => $gamingUserId,
                ]);
            }

            // ✅ 推送保留时间变化到客户端（使用 Redis 的 gaming_user_id）
            sendSocketMessage('player-' . $gamingUserId . '-' . $machine->id, [
                'msg_type' => 'player_machine_keeping',
                'player_id' => $gamingUserId,
                'machine_id' => $machine->id,
                'keep_seconds' => $services->keep_seconds,
                'keeping' => $services->keeping
            ]);

            sendSocketMessage('player-' . $gamingUserId, [
                'msg_type' => 'player_machine_keeping',
                'player_id' => $gamingUserId,
                'machine_id' => $machine->id,
                'keep_seconds' => $services->keep_seconds,
                'keeping' => $services->keeping
            ]);

        } catch (\Throwable $e) {
            Log::error('[PlayKeepMachine] 处理异常', [
                'data' => $data,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            // 不抛出异常，避免重试
        }
    }
}
