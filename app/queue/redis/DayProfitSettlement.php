<?php

namespace app\queue\redis;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerWithdrawRecord;
use app\model\PlayGameRecord;
use app\model\PromoterProfitGameRecord;
use app\service\ProfitSettlementServices;
use support\Log;
use Webman\RedisQueue\Consumer;

class DayProfitSettlement implements Consumer
{

    public $queue = 'day_profit_settlement';

    public $connection = 'default';

    /**
     * @param $data
     * @return void|null
     * @throws \Exception
     */
    public function consume($data)
    {
        /** @var Player $player */
        $player = Player::query()->find($data['player_id']);
        // 账变记录
        $playerDeliveryRecord = PlayerDeliveryRecord::query()
            ->whereDate('updated_at', $data['day'])
            ->whereIn('type', [
                PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD,
                PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT,
                PlayerDeliveryRecord::TYPE_REGISTER_PRESENT,
                PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS,
                PlayerDeliveryRecord::TYPE_MACHINE_UP,
                PlayerDeliveryRecord::TYPE_MACHINE_DOWN,
                PlayerDeliveryRecord::TYPE_RECHARGE,
                PlayerDeliveryRecord::TYPE_LOTTERY,
            ])
            ->selectRaw("
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD . " THEN `amount` ELSE 0 END) AS admin_add_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT . " THEN `amount` ELSE 0 END) AS admin_deduct_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_REGISTER_PRESENT . " THEN `amount` ELSE 0 END) AS present_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS . " THEN `amount` ELSE 0 END) AS bonus_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MACHINE_UP . " THEN `amount` ELSE 0 END) AS machine_up_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MACHINE_DOWN . " THEN `amount` ELSE 0 END) AS machine_down_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_RECHARGE . " THEN `amount` ELSE 0 END) AS recharge_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_LOTTERY . " THEN `amount` ELSE 0 END) AS lottery_amount
            ")
            ->where('player_id', $data['player_id'])
            ->get()
            ->toArray();

        // 提现记录
        $playerWithdrawRecord = PlayerWithdrawRecord::query()->whereDate('updated_at', $data['day'])
            ->where('status', PlayerWithdrawRecord::STATUS_SUCCESS)
            ->where('player_id', $data['player_id'])
            ->selectRaw('SUM(`point`) as withdraw_amount')
            ->get()
            ->toArray();

        // 电子游戏记录
        $playGameRecord = PlayGameRecord::query()->whereDate('updated_at', $data['day'])
            ->selectRaw('player_id, platform_id, SUM(`diff`) as total_diff, SUM(`bet`) as total_bet, SUM(`win`) as total_win, SUM(`reward`) as total_reward')
            ->where('player_id', $data['player_id'])
            ->with(['player', 'gamePlatform'])
            ->groupBy('player_id', 'platform_id')
            ->get()
            ->toArray();

        $promoterProfitGameInsertData = [];
        $playGameRecordData = [];

        foreach ($playGameRecord as $item) {
            if (!empty($item['player']['recommend_id'])) {
                $totalDiff = -$item['total_diff'];
                $gameAmount = bcsub($totalDiff,
                    bcmul($totalDiff, bcdiv($item['game_platform']['ratio'], 100, 4), 4), 4);
                $promoterProfitGameInsertData[] = [
                    'player_id' => $item['player_id'],
                    'department_id' => $item['player']['department_id'],
                    'promoter_player_id' => $item['player']['recommend_id'],
                    'platform_id' => $item['platform_id'],
                    'total_bet' => $item['total_bet'],
                    'total_win' => $item['total_win'],
                    'total_reward' => $item['total_reward'],
                    'total_diff' => $totalDiff,
                    'game_amount' => $gameAmount,
                    'game_platform_ratio' => $item['game_platform']['ratio'],
                    'date' => $data['day'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                $playGameRecordData[$item['player_id']] = bcadd($gameAmount,
                    $playGameRecordData[$item['player_id']] ?? 0, 4);
            }
        }

        if (!empty($promoterProfitGameInsertData)) {
            PromoterProfitGameRecord::query()->insert($promoterProfitGameInsertData);
        }

        $info = [
            'machine_up_amount' => $playerDeliveryRecord['machine_up_amount'] ?? 0,
            'machine_down_amount' => $playerDeliveryRecord['machine_down_amount'] ?? 0,
            'recharge_amount' => $playerDeliveryRecord['recharge_amount'] ?? 0,
            'admin_add_amount' => $playerDeliveryRecord['admin_add_amount'] ?? 0,
            'admin_deduct_amount' => $playerDeliveryRecord['admin_deduct_amount'] ?? 0,
            'present_amount' => $playerDeliveryRecord['present_amount'] ?? 0,
            'bonus_amount' => $playerDeliveryRecord['bonus_amount'] ?? 0,
            'lottery_amount' => $playerDeliveryRecord['lottery_amount'] ?? 0,
            'withdraw_amount' => $playerWithdrawRecord['withdraw_amount'] ?? 0,
            'game_amount' => $playGameRecordData[$player->id] ?? 0,
            'date' => $data['day'],
        ];
        try {
            ProfitSettlementServices::calculation($player->recommend_id, $player->id, $player->department_id, $info);
        } catch (\Exception $e) {
            Log::channel('day_profit_settlement')->error($e->getMessage());
            return $this->error($e->getMessage());
        }

        return $this->success();
    }
}