<?php

namespace app\queue\redis;

use addons\webman\model\GameType;
use addons\webman\model\Machine;
use addons\webman\model\Player;
use app\service\LotteryServices;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

class LotteryMachine implements Consumer
{
    // 要消费的队列名
    public $queue = 'lottery-machine';

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
                if ($player->channel->lottery_status == 0) {
                    return;
                }

                // 通知后台管理系统玩家正在游戏
                $this->notifyPlayerBetting($player, $machine, $data);

                $lotteryServices = new LotteryServices();
                switch ($machine->type) {
                    case GameType::TYPE_STEEL_BALL:
                        $lotteryServices = $lotteryServices->setJackLotteryList();
                        break;
                    case GameType::TYPE_SLOT:
                        $lotteryServices = $lotteryServices->setSlotLotteryList();
                }
                $lotteryServices->setMachine($machine)->setPlayer($player)->addLotteryPool($data['num'], $data['last_num'])->checkLottery();
            }
        } catch (Exception $e) {
            Log::error('LotteryPool:' . $e->getMessage());
        }
    }

    /**
     * 通知后台管理系统玩家正在游戏
     * @param Player $player
     * @param Machine $machine
     * @param array $data
     * @return void
     */
    private function notifyPlayerBetting($player, $machine, $data)
    {
        try {
            // 获取当前游戏记录
            $record = \addons\webman\model\PlayerGameRecord::query()
                ->where('player_id', $player->id)
                ->where('status', \addons\webman\model\PlayerGameRecord::STATUS_START)
                ->orderBy('id', 'desc')
                ->first();

            // 获取累计押注（最近5分钟）
            $fiveMinutesAgo = date('Y-m-d H:i:s', time() - 300);
            $totalPressure = \addons\webman\model\PlayerGameLog::query()
                ->where('player_id', $player->id)
                ->where('created_at', '>=', $fiveMinutesAgo)
                ->sum('pressure');

            sendSocketMessage('group-online-players-machine', [
                'msg_type' => 'player_betting',
                'type' => 'machine',
                'player' => [
                    'id' => $player->id,
                    'uuid' => $player->uuid,
                    'name' => $player->name ?: $player->uuid,
                    'phone' => $player->phone,
                    'avatar' => $this->getAvatarUrl($player->avatar),
                    'is_test' => $player->is_test,
                    'is_coin' => $player->is_coin,
                    'is_promoter' => $player->is_promoter,
                    'machine_id' => $machine->id,
                    'machine_name' => $machine->name,
                    'machine_code' => $machine->code,
                    'last_bet_time' => date('Y-m-d H:i:s'),
                    'bet_seconds_ago' => 0,
                    'total_pressure' => number_format($totalPressure, 2),
                    'last_pressure' => number_format($data['num'] ?? 0, 2),
                ],
                'timestamp' => time(),
            ]);
        } catch (Exception $e) {
            Log::error('通知后台玩家押注失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取头像URL
     * @param $avatar
     * @return string
     */
    private function getAvatarUrl($avatar): string
    {
        if (!$avatar) {
            return '';
        }

        if (is_numeric($avatar)) {
            return config('def_avatar.' . $avatar, '');
        }

        return $avatar;
    }
}
