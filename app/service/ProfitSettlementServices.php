<?php

namespace app\service;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerPromoter;
use app\model\PlayerWithdrawRecord;
use app\model\PlayGameRecord;
use app\model\PromoterProfitGameRecord;
use app\model\PromoterProfitRecord;
use app\model\SystemSetting;
use Exception;
use support\Cache;
use support\Db;
use support\Log;
use Webman\RedisQueue\Client;

class ProfitSettlementServices
{
    /**
     * 执行分润计算
     * @return false|void
     */
    public static function doProfitSettlement()
    {
        if (config('app.profit', 'task') == 'task') {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $lock = Cache::get('doProfitSettlement_' . $yesterday);
            if ($lock) {
                Log::info('代理分润结算: ' . date('Y-m-d H:i:s') . '不可以重复结算');
                return;
            }
            $yesterdayStart = $yesterday . ' 00:00:00';
            $yesterdayEnd = $yesterday . ' 23:59:59';
            $perPage = 100;
            $page = 1;
            do {
                // 分页获取玩家列表
                $playerList = Player::query()
                    ->whereHas('machine_wallet', function ($query) use ($yesterday) {
                        $query->where('updated_at', '>=', $yesterday . ' 00:00:00');
                    })
                    ->whereHas('channel', function ($query) {
                        $query->where('is_offline', 0);
                    })
                    ->whereNotNull('recommend_id')
                    ->where('recommend_id', '!=', 0)
                    ->orderBy('id', 'desc')
                    ->paginate($perPage, ['*'], 'page', $page);

                if ($playerList->isEmpty()) {
                    Log::info('代理分润结算: ' . date('Y-m-d H:i:s') . '未产生分润');
                    break; // 如果没有玩家，退出循环
                }
                $playerIds = $playerList->pluck('id')->toArray();
                // 账变记录
                $playerDeliveryRecord = PlayerDeliveryRecord::query()
                    ->where('updated_at', '>=', $yesterdayStart)
                    ->where('updated_at', '<=', $yesterdayEnd)
                    ->whereIn('type', [
                        PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD,
                        PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT,
                        PlayerDeliveryRecord::TYPE_REGISTER_PRESENT,
                        PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS,
                        PlayerDeliveryRecord::TYPE_MACHINE_UP,
                        PlayerDeliveryRecord::TYPE_MACHINE_DOWN,
                        PlayerDeliveryRecord::TYPE_RECHARGE,
                        PlayerDeliveryRecord::TYPE_LOTTERY,
                        PlayerDeliveryRecord::TYPE_MACHINE,
                        PlayerDeliveryRecord::TYPE_REVERSE_WATER,
                    ])
                    ->selectRaw("
                player_id,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD . " THEN `amount` ELSE 0 END) AS admin_add_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT . " THEN `amount` ELSE 0 END) AS admin_deduct_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_REGISTER_PRESENT . " THEN `amount` ELSE 0 END) AS present_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS . " THEN `amount` ELSE 0 END) AS bonus_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MACHINE_UP . " THEN `amount` ELSE 0 END) AS machine_up_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MACHINE_DOWN . " THEN `amount` ELSE 0 END) AS machine_down_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_RECHARGE . " THEN `amount` ELSE 0 END) AS recharge_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_LOTTERY . " THEN `amount` ELSE 0 END) AS lottery_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_REVERSE_WATER . " THEN `amount` ELSE 0 END) AS water_amount
            ")
                    ->whereIn('player_id', $playerIds)
                    ->groupBy('player_id')
                    ->get()
                    ->keyBy('player_id')
                    ->toArray();

                // 提现记录
                $playerWithdrawRecord = PlayerWithdrawRecord::query()
                    ->where('updated_at', '>=', $yesterdayStart)
                    ->where('updated_at', '<=', $yesterdayEnd)
                    ->where('status', PlayerWithdrawRecord::STATUS_SUCCESS)
                    ->whereIn('player_id', $playerIds)
                    ->selectRaw('player_id, SUM(`point`) as withdraw_amount')
                    ->groupBy('player_id')
                    ->get()
                    ->keyBy('player_id')
                    ->toArray();

                // 电子游戏记录
                $playGameRecord = PlayGameRecord::query()
                    ->where('created_at', '>=', $yesterdayStart)
                    ->where('created_at', '<=', $yesterdayEnd)
                    ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
                    ->selectRaw('player_id, platform_id, SUM(`diff`) as total_diff, SUM(`bet`) as total_bet, SUM(`win`) as total_win, SUM(`reward`) as total_reward')
                    ->whereIn('player_id', $playerIds)
                    ->groupBy('player_id', 'platform_id')
                    ->with(['player', 'gamePlatform'])
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
                            'date' => $yesterday,
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

                /** @var Player $player */
                foreach ($playerList as $player) {
                    $data = [
                        'machine_up_amount' => $playerDeliveryRecord[$player->id]['machine_up_amount'] ?? 0,
                        'machine_down_amount' => $playerDeliveryRecord[$player->id]['machine_down_amount'] ?? 0,
                        'recharge_amount' => $playerDeliveryRecord[$player->id]['recharge_amount'] ?? 0,
                        'admin_add_amount' => $playerDeliveryRecord[$player->id]['admin_add_amount'] ?? 0,
                        'admin_deduct_amount' => $playerDeliveryRecord[$player->id]['admin_deduct_amount'] ?? 0,
                        'present_amount' => $playerDeliveryRecord[$player->id]['present_amount'] ?? 0,
                        'bonus_amount' => $playerDeliveryRecord[$player->id]['bonus_amount'] ?? 0,
                        'lottery_amount' => $playerDeliveryRecord[$player->id]['lottery_amount'] ?? 0,
                        'water_amount' => $playerDeliveryRecord[$player->id]['water_amount'] ?? 0,
                        'withdraw_amount' => $playerWithdrawRecord[$player->id]['withdraw_amount'] ?? 0,
                        'game_amount' => $playGameRecordData[$player->id] ?? 0,
                        'date' => $yesterday,
                    ];
                    //当前玩家渠道未开通推广员功能
                    if ($player->channel->promotion_status == 0) {
                        continue;
                    }
                    try {
                        self::calculation($player->recommend_id, $player->id, $player->department_id, $data);
                    } catch (\Exception $e) {
                        Log::info($e->getMessage());
                    }
                }

                $page++; // 增加页码以获取下一页的玩家
            } while ($playerList->hasMorePages());

            Cache::set('doProfitSettlement_' . $yesterday, $yesterday, 60 * 60 * 12);
        }
    }

    /**
     * 计算分润
     * @param $promoterId
     * @param $playerId
     * @param $departmentId
     * @param $data
     * @return void
     * @throws \think\Exception|Exception
     */
    public static function calculation($promoterId, $playerId, $departmentId, $data)
    {
        /** @var PlayerPromoter $playerPromoter */
        $playerPromoter = PlayerPromoter::where('player_id', $promoterId)->first();
        if (empty($playerPromoter)) {
            throw new Exception('未找到推广员信息');
        }

        $systemCommissionRatio = SystemSetting::query()->where('department_id', $departmentId)
            ->where('feature', 'commission')
            ->where('status', 1)
            ->value('num');
        $commissionRatio = $systemCommissionRatio ? bcdiv($systemCommissionRatio, 100, 2) : 0;

        if ($playerPromoter->path) {
            $parentIdList = explode(',', $playerPromoter->path);
            $playerPromoterList = PlayerPromoter::whereIn('player_id', $parentIdList)->orderBy('id', 'desc')->get();
            $subRatio = 0; // 子级分润
            $teamProfitAmount = 0; // 团队分润
            $promoterProfitRecords = []; // 保存所有的PromoterProfitRecord
            /** @var PlayerPromoter $item */
            foreach ($playerPromoterList as $item) {
                // 计算分润比例
                $actualRatio = bcsub($item->ratio, $subRatio, 2); // 实际分润
                $ratio = bcdiv($actualRatio, 100, 2); // 分润比例
                $subRatio = $item->ratio; // 更新子级分润

                $promoterProfitRecord = new PromoterProfitRecord();
                $promoterProfitRecord->player_id = $playerId;
                $promoterProfitRecord->department_id = $departmentId;
                $promoterProfitRecord->promoter_player_id = $item->player_id;
                $promoterProfitRecord->source_player_id = $promoterId;
                $promoterProfitRecord->withdraw_amount = $data['withdraw_amount'];
                $promoterProfitRecord->recharge_amount = $data['recharge_amount'];
                $promoterProfitRecord->bonus_amount = $data['bonus_amount'];
                $promoterProfitRecord->admin_deduct_amount = $data['admin_deduct_amount'];
                $promoterProfitRecord->admin_add_amount = $data['admin_add_amount'];
                $promoterProfitRecord->present_amount = $data['present_amount'];
                $promoterProfitRecord->machine_up_amount = $data['machine_up_amount'];
                $promoterProfitRecord->machine_down_amount = $data['machine_down_amount'];
                $promoterProfitRecord->lottery_amount = $data['lottery_amount'];
                $promoterProfitRecord->game_amount = $data['game_amount'];
                $promoterProfitRecord->water_amount = $data['water_amount'];
                $promoterProfitRecord->ratio = $item->ratio;
                $promoterProfitRecord->actual_ratio = $actualRatio;
                $promoterProfitRecord->commission_ratio = 0;
                $promoterProfitRecord->commission = 0;
                $promoterProfitRecord->date = $data['date'];
                $promoterProfitRecord->model = PromoterProfitRecord::MODEL_TASK;
                // 计算分润(机台上分 + 管理员扣点) - (活动奖励 + 赠送 + 管理员加点 + 机台下分 + 派彩金额 + 电子游戏返水) + 电子游戏
                $allProfit = bcadd(bcsub(bcadd($promoterProfitRecord->machine_up_amount,
                    $promoterProfitRecord->admin_deduct_amount, 2),
                    bcadd(bcadd(bcadd(bcadd($promoterProfitRecord->bonus_amount, $promoterProfitRecord->present_amount,
                        2),
                        bcadd($promoterProfitRecord->admin_add_amount, $promoterProfitRecord->machine_down_amount, 2),
                        2), $promoterProfitRecord->lottery_amount, 2), $promoterProfitRecord->water_amount, 2), 2),
                    $promoterProfitRecord->game_amount, 2);
                //实际分润 = 计算分润 - 充值手续费
                if ($promoterProfitRecord->promoter_player_id == $promoterProfitRecord->source_player_id) {
                    $promoterProfitRecord->commission_ratio = $systemCommissionRatio ?? 0;
                    // 投钞计算手续费
                    $promoterProfitRecord->commission = bcmul(bcmul($data['recharge_amount'], $commissionRatio, 2),
                        $ratio, 2);
                }
                $promoterProfitRecord->profit_amount = bcmul($allProfit, $ratio, 2);
                // 记录直属玩家分润
                if ($promoterProfitRecord->promoter_player_id == $promoterId) {
                    $promoterProfitRecord->player_profit_amount = $promoterProfitRecord->profit_amount;
                }
                $promoterProfitRecords[] = $promoterProfitRecord;
                // 更新推广员信息
                $item->team_withdraw_total_amount = bcadd($item->team_withdraw_total_amount, $data['withdraw_amount'],
                    2);
                $item->team_recharge_total_amount = bcadd($item->team_recharge_total_amount, $data['recharge_amount'],
                    2);
                $item->total_water_amount = bcadd($item->total_water_amount, $data['water_amount'], 2);

                $item->total_profit_amount = bcadd($item->total_profit_amount, $promoterProfitRecord->profit_amount, 2);
                $item->player_profit_amount = bcadd($item->player_profit_amount,
                    $promoterProfitRecord->player_profit_amount, 2);
                $item->profit_amount = bcadd($item->profit_amount, $promoterProfitRecord->profit_amount, 2);
                $teamProfitAmount = bcadd($teamProfitAmount, $promoterProfitRecord->profit_amount, 2);
                $item->team_total_profit_amount = bcadd($item->team_total_profit_amount, $teamProfitAmount, 2);
                $item->team_profit_amount = bcadd($item->team_profit_amount, $teamProfitAmount, 2);
                $item->total_commission = bcadd($item->total_commission, $promoterProfitRecord->commission, 2);
            }
            DB::beginTransaction();
            try {
                foreach ($promoterProfitRecords as $promoterProfitRecord) {
                    $promoterProfitRecord->save();
                }
                foreach ($playerPromoterList as $item) {
                    $item->save();
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('生成分润记录错误: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            }
        }
    }

    /**
     * 执行分润计算
     * @return false|void
     */
    public static function doTestProfitSettlement()
    {
        if (config('app.profit', 'task') == 'task') {
            ini_set('memory_limit', '512M');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $perPage = 50;
            $page = 1;
            do {
                // 分页获取玩家列表
                $playerList = Player::query()
                    ->whereHas('machine_wallet', function ($query) use ($yesterday) {
                        $query->where('updated_at', '>=', $yesterday . ' 00:00:00');
                    })
                    ->orWhereHas('game_record', function ($query) use ($yesterday) {
                        $query->where('updated_at', '>=', $yesterday . ' 00:00:00');
                    })
                    ->paginate($perPage, ['*'], 'page', $page);

                if ($playerList->isEmpty()) {
                    Log::info('代理分润结算: ' . date('Y-m-d H:i:s') . '未产生分润');
                    break;
                }
                /** @var Player $player */
                foreach ($playerList as $player) {
                    if ($player->channel->promotion_status == 0) {
                        continue;
                    }
                    Client::send('day_profit_settlement', ['day' => $yesterday, 'player_id' => $player->id]);
                }

                $page++; // 增加页码以获取下一页的玩家
            } while ($playerList->hasMorePages()); // 继续处理直到没有更多页面
        }
    }
}
