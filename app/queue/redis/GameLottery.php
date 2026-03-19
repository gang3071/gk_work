<?php

namespace app\queue\redis;

use app\model\Player;
use app\model\PlayGameRecord;
use app\service\GameLotteryServices;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

class GameLottery implements Consumer
{
    // 要消费的队列名
    public $queue = 'game-lottery';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    // 消费
    public function consume($data)
    {
        try {
            /** @var Player $player */
            $player = Player::query()->find($data['player_id']);
            Log::channel('game_lottery')->error('LotteryPool:', [$data]);
            if (!empty($player)) {
                if ($player->channel->lottery_status == 0) {
                    return;
                }

                // 通知后台管理系统玩家正在游戏
                $this->notifyPlayerBetting($player, $data);

                $gameLotteryServices = new GameLotteryServices();
                $gameLotteryServices->setPlayer($player)->setLog()->setLotteryList()->addLotteryPool($data['bet'])->checkLottery($data['bet'], $data['play_game_record_id']);
            }
        } catch (Exception $e) {
            Log::channel('game_lottery')->error('LotteryPool:' . $e->getMessage());
        }
    }

    /**
     * 通知后台管理系统玩家正在游戏
     * @param Player $player
     * @param array $data
     * @return void
     */
    private function notifyPlayerBetting($player, $data)
    {
        try {
            // 获取当前平台信息
            $record = PlayGameRecord::query()
                ->with('gamePlatform')
                ->where('player_id', $player->id)
                ->orderBy('id', 'desc')
                ->first();

            // 获取累计押注（最近5分钟）
            $fiveMinutesAgo = date('Y-m-d H:i:s', time() - 300);
            $totalBet = PlayGameRecord::query()
                ->where('player_id', $player->id)
                ->where('created_at', '>=', $fiveMinutesAgo)
                ->sum('bet');

            sendSocketMessage('group-online-players-game', [
                'msg_type' => 'player_betting',
                'type' => 'game',
                'player' => [
                    'id' => $player->id,
                    'uuid' => $player->uuid,
                    'name' => $player->name ?: $player->uuid,
                    'phone' => $player->phone,
                    'avatar' => $this->getAvatarUrl($player->avatar),
                    'is_test' => $player->is_test,
                    'is_coin' => $player->is_coin,
                    'is_promoter' => $player->is_promoter,
                    'platform_id' => $record?->platform_id,
                    'platform_name' => $record?->gamePlatform?->name,
                    'game_code' => $record?->game_code,
                    'last_bet_time' => date('Y-m-d H:i:s'),
                    'bet_seconds_ago' => 0,
                    'total_bet' => number_format($totalBet, 2),
                    'last_bet' => number_format($data['bet'] ?? 0, 2),
                ],
                'timestamp' => time(),
            ]);
        } catch (Exception $e) {
            Log::channel('game_lottery')->error('通知后台玩家押注失败: ' . $e->getMessage());
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
