<?php

namespace app\service;

use addons\webman\Admin;
use addons\webman\model\Channel;
use addons\webman\model\Player;
use addons\webman\model\PlayerDeliveryRecord;
use addons\webman\model\PlayerPromoter;
use addons\webman\model\PlayerRechargeRecord;
use addons\webman\model\PlayGameRecord;
use addons\webman\model\StoreAgentProfitRecord;
use Exception;
use support\Cache;
use support\Db;
use support\Log;
use Webman\Push\PushException;

class OfflineProfitSettlementServices
{
    /**
     * 执行分润计算
     * @param $data
     * @return void
     * @throws PushException
     */
    public static function doProfitSettlement($data): void
    {
        $key = 'doOfflineProfitSettlementServices_' . $data['id'];
        $hasDo = Cache::get($key);
        if (!empty($hasDo)) {
            sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
                'msg_type' => 'player_offline_profit_settlement_erro',
                'title' => '线下代理结算失败',
                'description' => '线下代理:' . $data['agent_name'] . '正在执行结算操作请勿重复操作!'
            ]);
            return;
        }
        $children = [];
        $presentInAmount = 0;
        $machinePutPoint = 0;
        $presentOutAmount = 0;
        $selfProfitAmount = 0;
        $totalPoint = 0;
        $subProfitAmount = 0;
        try {
            /** @var PlayerPromoter $playerPromoter */
            $playerPromoter = PlayerPromoter::query()->where('player_id', $data['id'])->first();
            if (empty($playerPromoter)) {
                sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
                    'msg_type' => 'player_offline_profit_settlement_erro',
                    'title' => '线下代理结算失败',
                    'description' => "编号({$data['id']}), 线下代理未找到!",
                ]);
                return;
            }
            if ($playerPromoter->status == 0) {
                sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
                    'msg_type' => 'player_offline_profit_settlement_erro',
                    'title' => '线下代理结算失败',
                    'description' => "代理({$playerPromoter->name}), 线下代理已被禁用!",
                ]);
                return;
            }
            /** @var Channel $channel */
            $channel = Channel::query()->where('department_id',
                $data['department_id'])->whereNull('deleted_at')->first();
            if ($channel->promotion_status == 0) {
                sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
                    'msg_type' => 'player_offline_profit_settlement_erro',
                    'title' => '线下代理结算失败',
                    'description' => "渠道({$channel->name}), 已关闭线下代理功能!",
                ]);
                return;
            }
            Cache::set($key, 1, 10 * 60);
            if ($playerPromoter->recommend_id == 0) {
                $children = Player::query()
                    ->where('recommend_id', $playerPromoter->player_id)
                    ->where('is_promoter', 1)
                    ->whereNull('deleted_at')
                    ->pluck('id')->toArray();
                $totalData = PlayerDeliveryRecord::query()
                    ->whereHas('player', function ($query) use ($children) {
                        $query->whereIn('recommend_id', $children);
                    })
                    ->when(!empty($data['start_time']), function ($query) use ($data) {
                        $query->where('player_delivery_record.created_at', '>', $data['start_time']);
                    })
                    ->when(!empty($data['end_time']), function ($query) use ($data) {
                        $query->where('player_delivery_record.created_at', '<=', $data['end_time']);
                    })
                    ->join('player', 'player_delivery_record.player_id', '=', 'player.id')
                    ->join('player_promoter', 'player.recommend_id', '=', 'player_promoter.player_id')
                    ->selectRaw('
                        player.recommend_id,
                        player_promoter.ratio,
                        sum(IF(player_delivery_record.type = ' . PlayerDeliveryRecord::TYPE_PRESENT_IN . ', player_delivery_record.amount, 0)) as total_in,
                        sum(IF(player_delivery_record.type = ' . PlayerDeliveryRecord::TYPE_PRESENT_OUT . ', player_delivery_record.amount, 0)) as total_out,
                        sum(IF(player_delivery_record.type = ' . PlayerDeliveryRecord::TYPE_MACHINE . ', player_delivery_record.amount, 0)) as total_point
                    ')
                    ->groupBy('player.recommend_id', 'player_promoter.ratio')
                    ->get();

                foreach ($totalData as $item) {
                    $totalPoint = bcadd($totalPoint,
                        bcsub(bcadd($item['total_point'], $item['total_in'], 2), $item['total_out'], 2), 2);
                    if (($item['ratio'] - $playerPromoter->ratio) > 0) {
                        $selfProfitAmount = bcadd($selfProfitAmount,
                            bcmul(bcsub(bcadd($item['total_point'], $item['total_in'], 2), $item['total_out'], 2),
                                ($item['ratio'] - $playerPromoter->ratio) / 100, 2), 2);
                    } elseif (($item['ratio'] - $playerPromoter->ratio) < 0) {
                        /** @var PlayerPromoter $agentPromoter */
                        $agentPromoter = PlayerPromoter::query()->where('player_id', $item['recommend_id'])->first();
                        sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
                            'msg_type' => 'player_offline_profit_settlement_erro',
                            'title' => '线下代理结算失败',
                            'description' => '线下代理:' . $data['agent_name'] . "所属下级店家({$agentPromoter->name})上缴比例小于代理上缴比例, 比例设置异常请联系管理员!",
                        ]);
                        Cache::delete($key);
                        return;
                    } else {
                        $selfProfitAmount = bcadd($selfProfitAmount, 0, 2);
                    }
                    $presentInAmount = bcadd($presentInAmount, bcadd(0, $item['total_in'] ?? 0, 2), 2);
                    $machinePutPoint = bcadd($presentInAmount, bcadd(0, $item['total_point'] ?? 0, 2), 2);
                    $presentOutAmount = bcadd($presentInAmount, bcadd(0, $item['total_out'] ?? 0, 2), 2);
                }
                if ($playerPromoter->ratio > 0) {
                    $subProfitAmount = bcmul($totalPoint, $playerPromoter->ratio / 100, 2);
                }
            } else {
                /** @var PlayerPromoter $recommendPromoter */
                $recommendPromoter = PlayerPromoter::query()->where('player_id',
                    $playerPromoter->recommend_id)->first();
                if ($playerPromoter->ratio - $recommendPromoter->ratio < 0) {
                    sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
                        'msg_type' => 'player_offline_profit_settlement_erro',
                        'title' => '线下代理结算失败',
                        'description' => '店家:' . $data['agent_name'] . "所属上级代理({$recommendPromoter->name})上缴比例设置大于店家上缴比例, 比例设置异常请联系管理员!",
                    ]);
                    Cache::delete($key);
                    return;
                }
                $totalData = PlayerDeliveryRecord::query()
                    ->whereHas('player', function ($query) use ($data) {
                        $query->where('recommend_id', $data['id']);
                    })
                    ->when(!empty($data['start_time']), function ($query) use ($data) {
                        $query->where('created_at', '>', $data['start_time']);
                    })
                    ->when(!empty($data['end_time']), function ($query) use ($data) {
                        $query->where('created_at', '<=', $data['end_time']);
                    })->selectRaw('
                    sum(IF(type = ' . PlayerDeliveryRecord::TYPE_PRESENT_IN . ', amount, 0)) as total_in,
                    sum(IF(type = ' . PlayerDeliveryRecord::TYPE_PRESENT_OUT . ', amount, 0)) as total_out,
                    sum(IF(type = ' . PlayerDeliveryRecord::TYPE_MACHINE . ', amount, 0)) as total_point
                ')->first();
                $presentInAmount = bcadd(0, $totalData['total_in'] ?? 0, 2);
                $machinePutPoint = bcadd(0, $totalData['total_point'] ?? 0, 2);
                $presentOutAmount = bcadd(0, $totalData['total_out'] ?? 0, 2);
                $totalPoint = bcsub(bcadd($machinePutPoint, $presentInAmount, 2), $presentOutAmount, 2);
                if (100 - $playerPromoter->ratio > 0) {
                    $selfProfitAmount = bcmul($totalPoint, (100 - $playerPromoter->ratio) / 100, 2);
                } elseif (100 - $playerPromoter->ratio < 0) {
                    sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
                        'msg_type' => 'player_offline_profit_settlement_erro',
                        'title' => '线下代理结算失败',
                        'description' => '店家:' . $data['agent_name'] . "上缴比例设置异常请联系管理员!",
                    ]);
                    Cache::delete($key);
                    return;
                }
                if ($playerPromoter->ratio > 0) {
                    $subProfitAmount = bcmul($totalPoint, $playerPromoter->ratio / 100, 2);
                }
            }
            // 电子游戏记录
            $playGameRecord = PlayGameRecord::query()
                ->when($playerPromoter->recommend_id == 0 && !empty($children), function ($query) use ($children) {
                    $query->whereIn('parent_player_id', $children);
                })
                ->when($playerPromoter->recommend_id > 0, function ($query) use ($playerPromoter) {
                    $query->where('parent_player_id', $playerPromoter->player_id);
                })
                ->where('created_at', '>', $data['start_time'])
                ->where('created_at', '<=', $data['end_time'])
                ->selectRaw('SUM(`diff`) as total_diff, SUM(`bet`) as total_bet, SUM(`win`) as total_win, SUM(`reward`) as total_reward')
                ->first()
                ->toArray();

            $machineAmount = PlayerRechargeRecord::query()
                ->when($playerPromoter->recommend_id == 0 && !empty($children), function ($query) use ($children) {
                    $query->whereHas('player', function ($query) use ($children) {
                        $query->whereIn('recommend_id', $children);
                    });
                })
                ->when($playerPromoter->recommend_id > 0, function ($query) use ($playerPromoter) {
                    $query->whereHas('player', function ($query) use ($playerPromoter) {
                        $query->where('recommend_id', $playerPromoter->player_id);
                    });
                })
                ->where('type', PlayerRechargeRecord::TYPE_MACHINE)
                ->sum('money');
            /** @var StoreAgentProfitRecord $storeAgentProfit */
            $storeAgentProfit = StoreAgentProfitRecord::query()->where('player_id',
                $playerPromoter->player_id)->orderBy('id',
                'desc')->first();
        } catch (Exception $e) {
            sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
                'msg_type' => 'player_offline_profit_settlement_erro',
                'title' => '线下代理结算失败',
                'description' => '店家:' . $data['agent_name'] . "上缴比例设置异常请联系管理员!",
            ]);
            Cache::delete($key);
            return;
        }

        DB::beginTransaction();
        try {
            $storeAgentProfitRecord = new StoreAgentProfitRecord();
            $storeAgentProfitRecord->player_id = $playerPromoter->player_id;
            $storeAgentProfitRecord->agent_id = $playerPromoter->id;
            $storeAgentProfitRecord->department_id = $playerPromoter->department_id;
            $storeAgentProfitRecord->type = $playerPromoter->recommend_id == 0 ? StoreAgentProfitRecord::TYPE_AGENT : StoreAgentProfitRecord::TYPE_STORE;
            $storeAgentProfitRecord->machine_amount = $machineAmount;
            $storeAgentProfitRecord->machine_point = $machinePutPoint;
            $storeAgentProfitRecord->total_diff = $playGameRecord['total_diff'] ?? 0;
            $storeAgentProfitRecord->total_bet = $playGameRecord['total_bet'] ?? 0;
            $storeAgentProfitRecord->total_win = $playGameRecord['total_win'] ?? 0;
            $storeAgentProfitRecord->total_in = $presentInAmount;
            $storeAgentProfitRecord->total_out = $presentOutAmount;
            $storeAgentProfitRecord->total_income = $totalPoint;
            $storeAgentProfitRecord->start_time = $data['start_time'];
            $storeAgentProfitRecord->end_time = $data['end_time'];
            $storeAgentProfitRecord->profit_amount = bcadd($selfProfitAmount, $playerPromoter->adjust_amount, 2);
            $storeAgentProfitRecord->sub_profit_amount = $subProfitAmount;
            $storeAgentProfitRecord->sub_ratio = $playerPromoter->ratio;
            $storeAgentProfitRecord->adjust_amount = $playerPromoter->adjust_amount;
            $storeAgentProfitRecord->user_name = $data['user_name'] ?? '';
            $storeAgentProfitRecord->settlement_tradeno = createOrderNo();
            $storeAgentProfitRecord->user_id = $data['user_id'] ?? 0;
            if ($playerPromoter->recommend_id > 0) {
                $storeAgentProfitRecord->ratio = 100 - $playerPromoter->ratio;
            }
            $storeAgentProfitRecord->user_id = Admin::id() ?? 0;
            $storeAgentProfitRecord->user_name = !empty(Admin::user()) ? Admin::user()->toArray()['username'] : '';
            $settlement = $amount = bcadd($storeAgentProfitRecord->profit_amount,
                $storeAgentProfitRecord->adjust_amount, 2);
            if ($amount > 0) {
                if ($playerPromoter->settlement_amount < 0) {
                    $diffAmount = bcadd($amount, $playerPromoter->settlement_amount, 2);
                    $settlement = max($diffAmount, 0);
                }
            }
            $storeAgentProfitRecord->actual_amount = $settlement;
            $storeAgentProfitRecord->save();
            $playerPromoter->profit_amount = 0;
            $playerPromoter->player_profit_amount = 0;
            $playerPromoter->team_recharge_total_amount = 0;
            $playerPromoter->total_commission = 0;
            $playerPromoter->team_withdraw_total_amount = 0;
            $playerPromoter->adjust_amount = 0;
            // 更新数据
            $playerPromoter->last_profit_amount = $settlement;
            $playerPromoter->settlement_amount = bcadd($playerPromoter->settlement_amount, $amount, 2);
            $playerPromoter->last_settlement_time = !empty($storeAgentProfit->end_time) ? $storeAgentProfit->end_time : ($playerPromoter->last_settlement_timestamp ? $playerPromoter->last_settlement_timestamp : $playerPromoter->created_at);
            $playerPromoter->last_settlement_timestamp = $data['end_time'];
            $playerPromoter->save();
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('生成分润记录错误', [$e->getMessage()]);
            sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
                'msg_type' => 'player_offline_profit_settlement_erro',
                'title' => '线下代理结算失败',
                'description' => '线下代理:' . $data['agent_name'] . '执行结算操作失败请联系管理员!',
            ]);
            Cache::delete($key);
            return;
        }
        sendSocketMessage('private-channel-' . $data['department_id'] . '-' . ($data['user_id'] ?? 0), [
            'msg_type' => 'player_offline_profit_settlement_success',
            'title' => "下线代理{$data['agent_name']}分润结算成功",
            'description' => "结算开始时间: {$storeAgentProfitRecord->start_time}
            结束时间: {$storeAgentProfitRecord->end_time},
            当期总营收: {$storeAgentProfitRecord->total_income},
            分润调整: {$storeAgentProfitRecord->adjust_amount},
            代理实际所得分润: {$storeAgentProfitRecord->actual_amount},
            上缴分润: {$storeAgentProfitRecord->sub_profit_amount},
            上缴比例: {$storeAgentProfitRecord->sub_ratio}%"
        ]);
        Cache::delete($key);
    }
}
