<?php

namespace app\service;

use app\model\Channel;
use app\model\ChannelProfitRecord;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerRechargeRecord;
use app\model\PlayerWithdrawRecord;
use app\model\PlayGameRecord;
use support\Cache;
use support\Db;
use support\Log;

class ChannelSettlementServices
{
    /**
     * 执行渠道结算
     * @return bool
     */
    public static function doProfitSettlement(): bool
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $lock = Cache::get('doChannelProfitSettlement_' . $yesterday);
        if ($lock) {
            Log::info('渠道代理分润结算: ' . date('Y-m-d H:i:s') . '不可以重复结算');
            return false;
        }
        $channelList = Channel::query()
            ->where('type', Channel::TYPE_API)
            ->where('ratio', '>', 0)
            ->where('status', 1)->get();
        $departmentIds = $channelList->pluck('department_id')->toArray();
        $yesterdayStart = $yesterday . ' 00:00:00';
        $yesterdayEnd = $yesterday . ' 23:59:59';
        // 账变记录
        $playerDeliveryRecord = PlayerDeliveryRecord::query()
            ->where('updated_at', '>=', $yesterdayStart)
            ->where('updated_at', '<=', $yesterdayEnd)
            ->whereIn('department_id', $departmentIds)
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
                department_id,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD . " THEN `amount` ELSE 0 END) AS admin_add_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT . " THEN `amount` ELSE 0 END) AS admin_deduct_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_REGISTER_PRESENT . " THEN `amount` ELSE 0 END) AS present_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS . " THEN `amount` ELSE 0 END) AS bonus_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MACHINE_UP . " THEN `amount` ELSE 0 END) AS machine_up_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MACHINE_DOWN . " THEN `amount` ELSE 0 END) AS machine_down_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_RECHARGE . " THEN `amount` ELSE 0 END) AS recharge_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_LOTTERY . " THEN `amount` ELSE 0 END) AS lottery_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_MACHINE . " THEN `amount` ELSE 0 END) AS machine_amount,
                SUM(CASE WHEN `type` = " . PlayerDeliveryRecord::TYPE_REVERSE_WATER . " THEN `amount` ELSE 0 END) AS water_amount
            ")
            ->groupBy('department_id')
            ->get()
            ->keyBy('department_id')
            ->toArray();
        // 提现记录
        $playerWithdrawRecord = PlayerWithdrawRecord::query()
            ->where('updated_at', '>=', $yesterdayStart)
            ->where('updated_at', '<=', $yesterdayEnd)
            ->where('status', PlayerWithdrawRecord::STATUS_SUCCESS)
            ->whereIn('department_id', $departmentIds)
            ->selectRaw('department_id, SUM(`point`) as withdraw_amount')
            ->groupBy('department_id')
            ->get()
            ->keyBy('department_id')
            ->toArray();
        // 电子游戏记录
        $playGameRecord = PlayGameRecord::query()
            ->where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<=', $yesterdayEnd)
            ->whereIn('department_id', $departmentIds)->with(['gamePlatform:id,ratio'])
            ->selectRaw('department_id, platform_id, SUM(`diff`) as total_diff, SUM(`bet`) as total_bet, SUM(`win`) as total_win, SUM(`reward`) as total_reward')
            ->groupBy('department_id', 'platform_id')
            ->get()
            ->toArray();
        $playGameRecordData = [];
        foreach ($playGameRecord as $item) {
            $totalDiff = -$item['total_diff'];
            $gameAmount = bcsub($totalDiff, bcmul($totalDiff, bcdiv($item['game_platform']['ratio'], 100, 4), 4), 4);
            $playGameRecordData[$item['department_id']] = bcadd($gameAmount, $playGameRecordData[$item['department_id']] ?? 0, 4);
        }

        /** @var Channel $channel */
        foreach ($channelList as $channel) {
            DB::beginTransaction();
            try {
                $channelProfitRecord = new ChannelProfitRecord();
                $channelProfitRecord->department_id = $channel->department_id;
                $channelProfitRecord->withdraw_amount = $playerWithdrawRecord[$channel->department_id]['withdraw_amount'] ?? 0;
                $channelProfitRecord->recharge_amount = $playerDeliveryRecord[$channel->department_id]['recharge_amount'] ?? 0;
                $channelProfitRecord->bonus_amount = $playerDeliveryRecord[$channel->department_id]['bonus_amount'] ?? 0;
                $channelProfitRecord->admin_deduct_amount = $playerDeliveryRecord[$channel->department_id]['admin_deduct_amount'] ?? 0;
                $channelProfitRecord->admin_add_amount = $playerDeliveryRecord[$channel->department_id]['admin_add_amount'] ?? 0;
                $channelProfitRecord->present_amount = $playerDeliveryRecord[$channel->department_id]['present_amount'] ?? 0;
                $channelProfitRecord->machine_up_amount = $playerDeliveryRecord[$channel->department_id]['machine_up_amount'] ?? 0;
                $channelProfitRecord->machine_down_amount = $playerDeliveryRecord[$channel->department_id]['machine_down_amount'] ?? 0;
                $channelProfitRecord->lottery_amount = $playerDeliveryRecord[$channel->department_id]['lottery_amount'] ?? 0;
                $channelProfitRecord->machine_amount = $playerWithdrawRecord[$channel->department_id]['machine_amount'] ?? 0;
                $channelProfitRecord->machine_point = PlayerRechargeRecord::query()->where('type',
                    PlayerRechargeRecord::TYPE_MACHINE)->where('department_id', $channel->department_id)->sum('point');
                $channelProfitRecord->game_amount = $playGameRecordData[$channel->department_id] ?? 0;
                $channelProfitRecord->water_amount = $playerDeliveryRecord[$channel->department_id]['water_amount'] ?? 0;
                $channelProfitRecord->date = $yesterday;
                $channelProfitRecord->ratio = $channel->ratio;
                // 计算分润(机台上分 + 管理员扣点) - (活动奖励 + 赠送 + 管理员加点 + 机台下分 + 派彩金额 + 电子游戏返水) + 电子游戏
                $allProfit = bcadd(bcsub(bcadd($channelProfitRecord->machine_up_amount,
                    $channelProfitRecord->admin_deduct_amount, 2),
                    bcadd(bcadd(bcadd(bcadd($channelProfitRecord->bonus_amount, $channelProfitRecord->present_amount,
                        2),
                        bcadd($channelProfitRecord->admin_add_amount, $channelProfitRecord->machine_down_amount, 2),
                        2), $channelProfitRecord->lottery_amount, 2), $channelProfitRecord->water_amount, 2), 2),
                    $channelProfitRecord->game_amount, 2);
                $channelProfitRecord->profit_amount = bcmul($allProfit, bcdiv($channel->ratio, 100, 4), 2);
                $channelProfitRecord->self_profit_amount = bcmul($allProfit, bcsub(1, bcdiv($channel->ratio, 100, 4), 4), 2);
                $channelProfitRecord->save();
                $channel->total_profit_amount = bcadd($channel->total_profit_amount,
                    $channelProfitRecord->profit_amount, 2);
                $channel->profit_amount = bcadd($channel->profit_amount, $channelProfitRecord->profit_amount, 2);
                $channel->save();
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('渠道生成分润记录错误:' . $channel->department_id, [$e->getMessage()]);
            }
        }

        Cache::set('doChannelProfitSettlement_' . $yesterday, $yesterday, 60 * 60 * 12);
        return true;
    }
}
