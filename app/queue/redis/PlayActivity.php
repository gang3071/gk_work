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
        try {
            /** @var Machine $machine */
            $machine = Machine::query()->find($data['machine_id']);
            /** @var Player $player */
            $player = Player::query()->find($data['player_id']);
            if (!empty($machine) && !empty($player)) {
                if ($player->channel->activity_status == 0) {
                    return;
                }
                switch ($machine->type) {
                    case GameType::TYPE_STEEL_BALL:
                    case GameType::TYPE_SLOT:
                        $activityServices = new ActivityServices($machine, $player);
                        $activityServices->playerActivityPhaseRecord($data['point']);
                        break;
                    default:
                        throw new Exception('机台类型错误');
                }
            }
        } catch (Exception $e) {
            Log::channel('play_activity')->error('PlayActivity', [$e->getMessage()]);
        }
    }
}
