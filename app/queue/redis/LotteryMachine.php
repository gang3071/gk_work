<?php

namespace app\queue\redis;

use app\model\GameType;
use app\model\Machine;
use app\model\Player;
use app\model\PlayerGameLog;
use app\model\PlayerGameRecord;
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
        $log = Log::channel('lottery_machine');
        $log->info('开始处理机台抽奖', ['data' => $data]);

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

            if ($player->channel->lottery_status == 0) {
                $log->info('渠道抽奖功能未开启', [
                    'player_id' => $data['player_id'],
                    'channel_id' => $player->channel->id
                ]);
                return;
            }

            // 通知后台管理系统玩家正在游戏
            $this->notifyPlayerBetting($player, $machine, $data);

            $lotteryServices = new LotteryServices();
            switch ($machine->type) {
                case GameType::TYPE_STEEL_BALL:
                    $lotteryServices = $lotteryServices->setJackLotteryList();
                    $log->info('设置钢珠机抽奖列表', ['machine_id' => $data['machine_id']]);
                    break;
                case GameType::TYPE_SLOT:
                    $lotteryServices = $lotteryServices->setSlotLotteryList();
                    $log->info('设置斯洛机抽奖列表', ['machine_id' => $data['machine_id']]);
                    break;
            }

            $lotteryServices->setMachine($machine)->setPlayer($player)->addLotteryPool($data['num'], $data['last_num'])->checkLottery();

            $log->info('机台抽奖处理完成', [
                'machine_id' => $data['machine_id'],
                'player_id' => $data['player_id'],
                'num' => $data['num'],
                'last_num' => $data['last_num']
            ]);
        } catch (Exception $e) {
            $log->error('机台抽奖处理失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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
            $record = PlayerGameRecord::query()
                ->where('player_id', $player->id)
                ->where('status', PlayerGameRecord::STATUS_START)
                ->orderBy('id', 'desc')
                ->first();

            // 获取累计押注（最近5分钟）
            $fiveMinutesAgo = date('Y-m-d H:i:s', time() - 300);
            $totalPressure = PlayerGameLog::query()
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
            Log::channel('lottery_machine')->error('通知后台玩家押注失败: ' . $e->getMessage());
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
