<?php

namespace app\queue\redis\fast\platform;

use app\model\Player;
use app\model\PlayGameRecord;

/**
 * O8 平台处理器
 * 特殊逻辑：
 * - 结算加款判断：txtype=510 且 amount>0 才加钱
 */
class O8PlatformHandler extends BasePlatformHandler
{
    public function getPlatformCode(): string
    {
        return 'O8';
    }

    public function processBet(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);

        $this->log->info("O8: 处理下注", [
            'order_no' => $orderNo,
            'amount' => $amount,
        ]);

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
            'created_at' => $record->created_at ?? \Carbon\Carbon::now()->toDateTimeString(),
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

        $this->log->info("O8: 下注处理成功", [
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
        $txtype = $params['txtype'] ?? null;

        $this->log->info("O8: 处理结算", [
            'order_no' => $orderNo,
            'amount' => $amount,
            'txtype' => $txtype,
        ]);

        // 1. 查找下注记录
        $betRecord = null;
        if ($orderNo) {
            $betRecord = $this->fetchBetRecord($orderNo, 3, 50000);
            if ($betRecord) {
                $betRecord = PlayGameRecord::where('id', $betRecord->id)->lockForUpdate()->first();
            }
        }

        // 2. O8特殊判断：txtype=510 且 amount>0 才加钱
        $shouldAddMoney = ($txtype == 510 && $amount > 0);
        $walletInfo = null;
        if ($shouldAddMoney) {
            $walletInfo = $this->addWallet($player, $amount);
        }

        // 3. 更新或创建游戏记录
        if ($betRecord) {
            $betRecord->win = $amount;
            $betRecord->diff = bcsub($amount, $betRecord->bet, 2);
            $betRecord->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $betRecord->platform_action_at = $params['play_time'] ?? \Carbon\Carbon::now()->toDateTimeString();
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
            $record->order_time = \Carbon\Carbon::now()->toDateTimeString();
            $record->original_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
            $record->platform_action_at = \Carbon\Carbon::now()->toDateTimeString();
            $record->save();
            $recordId = $record->id;
        }

        // 4. 创建交易记录（只在有加钱时）
        if ($shouldAddMoney && $walletInfo) {
            $this->createSettlementDelivery(
                $player,
                $recordId,
                $platformId,
                $amount,
                $walletInfo['before_balance'],
                $walletInfo['after_balance'],
                $orderNo
            );
        }

        // 5. 发送彩金队列
        if ($betRecord) {
            $this->sendLotteryQueue($player, $betRecord);
        }

        $this->log->info("O8: 结算处理成功", [
            'order_no' => $orderNo,
            'record_id' => $recordId ?? null,
            'txtype' => $txtype,
            'should_add_money' => $shouldAddMoney,
        ]);
    }
}
