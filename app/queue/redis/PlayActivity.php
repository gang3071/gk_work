<?php

namespace app\queue\redis;

use app\model\GameType;
use app\model\Machine;
use app\model\Player;
use app\service\ActivityServices;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

class PlayActivity implements Consumer
{
    // 要消费的队列名
    public $queue = 'play-activity';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    // 消费
    public function consume($data)
    {
        $log = Log::channel('play_activity');
        $log->info('开始处理游戏活动', ['data' => $data]);

        try {
            /** @var Machine $machine */
            $machine = Machine::query()->find($data['machine_id']);
            /** @var Player $player */
            $player = Player::query()->find($data['player_id']);

            if (empty($machine)) {
                $log->warning('机台不存在', ['machine_id' => $data['machine_id']]);
                return;
            }

            if (empty($player)) {
                $log->warning('玩家不存在', ['player_id' => $data['player_id']]);
                return;
            }

            if ($player->channel->activity_status == 0) {
                $log->info('渠道活动功能未开启', [
                    'player_id' => $data['player_id'],
                    'channel_id' => $player->channel->id
                ]);
                return;
            }

            switch ($machine->type) {
                case GameType::TYPE_STEEL_BALL:
                case GameType::TYPE_SLOT:
                    $activityServices = new ActivityServices($machine, $player);
                    $activityServices->playerActivityPhaseRecord($data['point']);
                    $log->info('游戏活动处理完成', [
                        'machine_id' => $data['machine_id'],
                        'player_id' => $data['player_id'],
                        'machine_type' => $machine->type,
                        'point' => $data['point']
                    ]);
                    break;
                default:
                    throw new Exception('机台类型错误: ' . $machine->type);
            }
        } catch (Exception $e) {
            $log->error('游戏活动处理失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
