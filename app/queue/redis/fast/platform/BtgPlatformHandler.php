<?php

namespace app\queue\redis\fast\platform;

use app\model\Player;
use app\model\PlayGameRecord;
use Carbon\Carbon;

/**
 * BTG 平台处理器
 * 特殊逻辑：
 * - 免费游戏：amount=0时只创建记录不扣款
 * - Reward（额外奖金）：创建新记录（无下注）
 * - 鱼机游戏：跳过彩金队列
 */
class BtgPlatformHandler extends BasePlatformHandler
{
    public function getPlatformCode(): string
    {
        return 'BTG';
    }

    public function processBet(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);

        $this->log->info("BTG: 处理下注", [
            'order_no' => $orderNo,
            'amount' => $amount,
        ]);

        // BTG免费游戏特殊处理
        if ($amount == 0) {
            $record = $this->createGameRecord($player, $params, $orderNo, 0);

            // 缓存订单 + 清理 pending
            $this->cacheOrderToRedis($orderNo, [
                'id' => $record->id,
                'player_id' => $record->player_id,
                'order_no' => $orderNo,
                'bet' => $record->bet,
                'platform_id' => $platformId,
                'game_code' => $record->game_code,
                'settlement_status' => $record->settlement_status,
                'created_at' => $record->created_at ?? Carbon::now()->toDateTimeString(),
            ]);
            $this->clearPendingStatus($orderNo);

            $this->log->info("BTG: 免费游戏记录创建成功", [
                'order_no' => $orderNo,
                'record_id' => $record->id,
            ]);
            return;
        }

        // 1. 爆机检查
        if ($this->needsMachineCrashCheck()) {
            $this->checkMachineCrash($player, $platformId);
        }

        // 2. 钱包扣款
        $walletInfo = $this->deductWallet($player, $amount, $orderNo);

        // 3. 创建游戏记录
        $record = $this->createGameRecord($player, $params, $orderNo, $amount);

        // 4. 缓存订单 + 清理 pending
        $this->cacheOrderToRedis($orderNo, [
            'id' => $record->id,
            'player_id' => $record->player_id,
            'order_no' => $orderNo,
            'bet' => $record->bet,
            'platform_id' => $platformId,
            'game_code' => $record->game_code,
            'settlement_status' => $record->settlement_status,
            'created_at' => $record->created_at ?? Carbon::now()->toDateTimeString(),
        ]);
        $this->clearPendingStatus($orderNo);

        // 5. 创建交易记录
        $this->createBetDelivery(
            $player,
            $record,
            $amount,
            $walletInfo['before_balance'],
            $walletInfo['after_balance'],
            $orderNo
        );

        $this->log->info("BTG: 下注处理成功", [
            'order_no' => $orderNo,
            'record_id' => $record->id,
        ]);
    }

    public function processSettle(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);
        $isReward = $params['is_reward'] ?? false;

        $this->log->info("BTG: 处理结算", [
            'order_no' => $orderNo,
            'amount' => $amount,
            'is_reward' => $isReward,
        ]);

        // 1. 查找下注记录
        $betRecord = null;
        if ($orderNo) {
            $betRecord = $this->fetchBetRecord($orderNo, 3, 50000);
            if ($betRecord) {
                $betRecord = PlayGameRecord::where('id', $betRecord->id)->lockForUpdate()->first();
            }
        }

        // 2. 钱包加款
        $shouldAddMoney = ($amount > 0);
        $walletInfo = null;
        if ($shouldAddMoney) {
            $walletInfo = $this->addWallet($player, $amount);
        }

        // 3. 更新或创建游戏记录
        if ($isReward) {
            // Reward：创建新记录
            $record = new PlayGameRecord();
            $record->player_id = $player->id;
            $record->parent_player_id = $player->recommend_id ?? 0;
            $record->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
            $record->player_uuid = $player->uuid;
            $record->department_id = $player->department_id ?? 0;
            $record->order_no = $orderNo;
            $record->platform_id = $platformId;
            $record->bet = 0;
            $record->win = $amount;
            $record->diff = $amount;
            $record->game_code = $params['game_code'] ?? '';
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $record->order_time = Carbon::now()->toDateTimeString();
            $record->platform_action_at = $params['play_time'] ?? Carbon::now()->toDateTimeString();
            $record->original_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
            $record->action_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
            $record->save();
            $recordId = $record->id;

            $this->log->info("BTG: Reward 创建成功", [
                'order_no' => $orderNo,
                'amount' => $amount,
            ]);

        } elseif ($betRecord) {
            // 更新下注记录
            $betRecord->win = $amount;
            $betRecord->diff = bcsub($amount, $betRecord->bet, 2);
            $betRecord->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $betRecord->platform_action_at = $params['play_time'] ?? Carbon::now()->toDateTimeString();
            $betRecord->action_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
            $betRecord->save();
            $recordId = $betRecord->id;

        } else {
            // 创建新记录
            $record = new PlayGameRecord();
            $record->player_id = $player->id;
            $record->parent_player_id = $player->recommend_id ?? 0;
            $record->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
            $record->player_uuid = $player->uuid;
            $record->department_id = $player->department_id ?? 0;
            $record->order_no = $orderNo;
            $record->platform_id = $platformId;
            $record->bet = 0;
            $record->win = $amount;
            $record->diff = $amount;
            $record->game_code = $params['game_code'] ?? '';
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $record->order_time = Carbon::now()->toDateTimeString();
            $record->original_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
            $record->platform_action_at = Carbon::now()->toDateTimeString();
            $record->save();
            $recordId = $record->id;
        }

        // 4. 创建交易记录
        if ($shouldAddMoney && $walletInfo) {
            $this->createSettlementDelivery(
                $player,
                $recordId,
                $platformId,
                $amount,
                $walletInfo['before_balance'],
                $walletInfo['after_balance'],
                $orderNo,
                $isReward ? ['source' => 'player_bet_reward', 'remark' => '额外奖金'] : []
            );
        }

        // 5. 发送彩金队列（鱼机游戏除外）
        if ($betRecord) {
            $gameType = $params['game_type'] ?? '';
            if ($gameType !== 'fish') {
                $this->sendLotteryQueue($player, $betRecord);
            } else {
                $this->log->info("BTG: 鱼机游戏跳过彩金队列", [
                    'order_no' => $orderNo,
                    'game_type' => $gameType,
                ]);
            }
        }

        $this->log->info("BTG: 结算处理成功", [
            'order_no' => $orderNo,
            'record_id' => $recordId ?? null,
        ]);
    }
}
