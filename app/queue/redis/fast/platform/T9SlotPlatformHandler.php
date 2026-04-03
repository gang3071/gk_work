<?php

namespace app\queue\redis\fast\platform;

use app\model\Player;
use app\model\PlayGameRecord;

/**
 * T9SLOT 平台处理器
 * 特殊逻辑：
 * - 下注和结算同时发生（类似KT的TakeWin=1）
 * - winlose/payoutAmount是净盈亏，不是派彩总额
 * - win = bet + winlose（派彩总额）
 * - diff = winlose（净盈亏，不是amount - bet）
 * - betKind=3是免费游戏，不扣款，只派彩
 */
class T9SlotPlatformHandler extends BasePlatformHandler
{
    public function getPlatformCode(): string
    {
        return 'T9SLOT';
    }

    public function processBet(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);

        $this->log->info("T9SLOT: 处理下注", [
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

        $this->log->info("T9SLOT: 下注处理成功", [
            'order_no' => $orderNo,
            'record_id' => $record->id,
        ]);
    }

    public function processSettle(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $betOrderNo = $data['bet_order_no'] ?? $orderNo;
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);  // max(winlose, 0)
        $resultAmount = (float)($params['result_amount'] ?? $amount);  // 净盈亏（可为负数）

        $this->log->info("T9SLOT: 处理结算", [
            'order_no' => $orderNo,
            'bet_order_no' => $betOrderNo,
            'amount' => $amount,
            'result_amount' => $resultAmount,
        ]);

        // 1. 查找下注记录
        $betRecord = null;
        if ($betOrderNo) {
            $betRecord = $this->fetchBetRecord($betOrderNo, 3, 50000);
            if ($betRecord) {
                $betRecord = PlayGameRecord::where('id', $betRecord->id)->lockForUpdate()->first();
            }
        }

        // 2. 钱包加款（只在有派彩时）
        $shouldAddMoney = ($amount > 0);
        $walletInfo = null;
        if ($shouldAddMoney) {
            $walletInfo = $this->addWallet($player, $amount);
        }

        // 3. 更新或创建游戏记录
        if ($betRecord) {
            // T9SLOT特殊逻辑：
            // - win = bet + winlose  派彩总额
            // - diff = winlose  净盈亏（可能为负数）
            $betRecord->win = bcadd($betRecord->bet, $resultAmount, 2);  // ← 关键：bet + 净盈亏
            $betRecord->diff = $resultAmount;  // ← 关键：直接使用净盈亏，不计算！
            $betRecord->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;

            // T9SLOT使用settleTime作为结算时间
            $originalData = $params['original_data'] ?? $params;
            if (isset($originalData['settleTime'])) {
                $betRecord->platform_action_at = $originalData['settleTime'];
            } else {
                $betRecord->platform_action_at = \Carbon\Carbon::now()->toDateTimeString();
            }

            $betRecord->action_data = json_encode($originalData, JSON_UNESCAPED_UNICODE);
            $betRecord->save();
            $recordId = $betRecord->id;

        } else {
            // 免费游戏（betKind=3）或未找到下注记录：创建新记录
            $originalData = $params['original_data'] ?? $params;

            $record = new PlayGameRecord();
            $record->player_id = $player->id;
            $record->parent_player_id = $player->recommend_id ?? 0;
            $record->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
            $record->player_uuid = $player->uuid;
            $record->department_id = $player->department_id ?? 0;
            $record->order_no = $betOrderNo;
            $record->platform_id = $platformId;
            $record->bet = 0;  // 免费游戏
            $record->win = max($resultAmount, 0);  // 免费游戏的win = 中奖金额
            $record->diff = $resultAmount;  // ← 关键：使用净盈亏
            $record->game_code = $params['game_code'] ?? '';
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;

            // 使用T9SLOT的betTime作为下注时间
            $record->order_time = $originalData['betTime'] ?? \Carbon\Carbon::now()->toDateTimeString();
            $record->original_data = json_encode($originalData, JSON_UNESCAPED_UNICODE);

            // 使用settleTime作为结算时间
            $record->platform_action_at = $originalData['settleTime'] ?? \Carbon\Carbon::now()->toDateTimeString();

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

        $this->log->info("T9SLOT: 结算处理成功", [
            'order_no' => $orderNo,
            'bet_order_no' => $betOrderNo,
            'record_id' => $recordId ?? null,
            'win' => $betRecord->win ?? ($record->win ?? 0),
            'diff' => $resultAmount,
        ]);
    }
}
