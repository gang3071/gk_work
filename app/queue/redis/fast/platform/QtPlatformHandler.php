<?php

namespace app\queue\redis\fast\platform;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use Carbon\Carbon;
use Exception;

/**
 * QT 平台处理器
 * 特殊逻辑：
 * - 奖金回合（bonus_type）：不扣款
 * - rollback：取消下注，特殊标记
 */
class QtPlatformHandler extends BasePlatformHandler
{
    public function getPlatformCode(): string
    {
        return 'QT';
    }

    public function processBet(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);
        $bonusType = $params['bonus_type'] ?? null;

        $this->log->info("QT: 处理下注", [
            'order_no' => $orderNo,
            'amount' => $amount,
            'bonus_type' => $bonusType,
        ]);

        // 1. 爆机检查
        if ($this->needsMachineCrashCheck()) {
            $this->checkMachineCrash($player, $platformId);
        }

        // 2. 钱包扣款（奖金回合不扣款）
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new Exception("钱包不存在: player_id={$player->id}");
        }

        $beforeBalance = $wallet->money;
        $actualAmount = $amount;

        if ($bonusType) {
            // 奖金回合不扣款
            $actualAmount = 0;
            $this->log->info("QT: 奖金回合，不扣款", [
                'order_no' => $orderNo,
                'bonus_type' => $bonusType,
                'amount' => $amount,
            ]);
        } else {
            // 普通下注：余额检查 + 扣款
            if ($beforeBalance < $amount) {
                throw new Exception("余额不足: balance={$beforeBalance}, amount={$amount}");
            }
            $wallet->money = bcsub($wallet->money, $amount, 2);
            $wallet->save();
        }

        // 3. 创建游戏记录
        $record = $this->createGameRecord($player, $params, $orderNo, $actualAmount);

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

        // 5. 创建交易记录（奖金回合amount=0也创建，用于财务对账）
        $this->createBetDelivery(
            $player,
            $record,
            $actualAmount,
            $beforeBalance,
            $wallet->money,
            $orderNo
        );

        $this->log->info("QT: 下注处理成功", [
            'order_no' => $orderNo,
            'record_id' => $record->id,
            'actual_amount' => $actualAmount,
        ]);
    }

    public function processSettle(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);

        $this->log->info("QT: 处理结算", [
            'order_no' => $orderNo,
            'amount' => $amount,
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
        if ($betRecord) {
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

        $this->log->info("QT: 结算处理成功", [
            'order_no' => $orderNo,
            'record_id' => $recordId ?? null,
        ]);
    }

    public function processCancel(array $data, Player $player): void
    {
        $params = $data['params'];
        $isRollback = $params['is_rollback'] ?? false;

        if ($isRollback) {
            // rollback 特殊处理
            $this->processRollback($data, $player);
        } else {
            // 默认取消处理
            parent::processCancel($data, $player);
        }
    }

    /**
     * 处理 QT rollback
     */
    private function processRollback(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;

        $this->log->info("QT: 处理rollback", ['order_no' => $orderNo]);

        // 1. 查找下注记录
        $betRecord = $this->fetchBetRecord($orderNo, 5, 50000);
        if (!$betRecord) {
            throw new Exception("下注记录不存在: {$orderNo}");
        }

        $betRecord = PlayGameRecord::where('id', $betRecord->id)->lockForUpdate()->first();
        if (!$betRecord) {
            throw new Exception("下注记录已被删除: {$orderNo}");
        }

        // 2. 钱包退款
        $refundAmount = $betRecord->bet;
        $walletInfo = $this->addWallet($player, $refundAmount);

        // 3. 更新游戏记录
        $betRecord->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_CANCELLED;
        $betRecord->platform_action_at = Carbon::now()->toDateTimeString();
        $betRecord->action_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
        $betRecord->save();

        // 4. 创建交易记录（rollback标记）
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = $betRecord->getTable();
        $deliveryRecord->target_id = $betRecord->id;
        $deliveryRecord->platform_id = $platformId;
        $deliveryRecord->type = PlayerDeliveryRecord::TYPE_CANCEL_BET;
        $deliveryRecord->source = 'qt_rollback';
        $deliveryRecord->remark = '回滚交易';
        $deliveryRecord->amount = $refundAmount;
        $deliveryRecord->amount_before = $walletInfo['before_balance'];
        $deliveryRecord->amount_after = $walletInfo['after_balance'];
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        $this->log->info("QT: rollback处理成功", [
            'order_no' => $orderNo,
            'refund_amount' => $refundAmount,
        ]);
    }
}
