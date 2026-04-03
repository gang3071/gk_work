<?php

namespace app\queue\redis\fast\platform;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayGameRecord;
use Carbon\Carbon;

/**
 * DG 平台处理器
 * 特殊逻辑：
 * - 支持累计下注（同一订单号多次下注）
 * - Compensation（补偿）：创建新记录（无下注）
 */
class DgPlatformHandler extends BasePlatformHandler
{
    public function getPlatformCode(): string
    {
        return 'DG';
    }

    public function supportsAccumulatedBet(): bool
    {
        return true;
    }

    public function processBet(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);

        $this->log->info("DG: 处理下注", [
            'order_no' => $orderNo,
            'amount' => $amount,
        ]);

        // 0. 累计下注检查
        $existingRecord = PlayGameRecord::where('order_no', $orderNo)
            ->lockForUpdate()
            ->first();

        if ($existingRecord) {
            $this->handleAccumulatedBet($existingRecord, $data, $player, $amount, $orderNo);
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

        $this->log->info("DG: 下注处理成功", [
            'order_no' => $orderNo,
            'record_id' => $record->id,
        ]);
    }

    /**
     * 处理累计下注
     */
    private function handleAccumulatedBet(PlayGameRecord $existingRecord, array $data, Player $player, float $amount, string $orderNo): void
    {
        $params = $data['params'];
        $platformId = $params['platform_id'] ?? 1;

        // 1. MD5幂等性检查
        $originalData = json_decode($existingRecord->original_data, true) ?? [];
        $currentDataHash = md5(json_encode($params['original_data'] ?? $params));

        foreach ($originalData as $existingData) {
            $existingHash = md5(json_encode($existingData));
            if ($existingHash === $currentDataHash) {
                $this->log->warning("DG: 重复下注数据，跳过累加（幂等性保护）", [
                    'order_no' => $orderNo,
                    'data_hash' => substr($currentDataHash, 0, 8),
                ]);
                return;
            }
        }

        // 2. 钱包扣款
        $walletInfo = $this->deductWallet($player, $amount, $orderNo);

        // 3. 累加bet字段，更新original_data
        $originalData[] = $params['original_data'] ?? $params;
        $oldBet = $existingRecord->bet;
        $existingRecord->bet = bcadd($existingRecord->bet, $amount, 2);
        $existingRecord->original_data = json_encode($originalData, JSON_UNESCAPED_UNICODE);
        $existingRecord->save();

        // 4. 创建交易记录
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = $existingRecord->getTable();
        $deliveryRecord->target_id = $existingRecord->id;
        $deliveryRecord->platform_id = $platformId;
        $deliveryRecord->type = PlayerDeliveryRecord::TYPE_BET;
        $deliveryRecord->source = 'player_bet';
        $deliveryRecord->remark = '游戏累计下注';
        $deliveryRecord->amount = $amount;
        $deliveryRecord->amount_before = $walletInfo['before_balance'];
        $deliveryRecord->amount_after = $walletInfo['after_balance'];
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        $this->log->info("DG: 累计下注成功", [
            'order_no' => $orderNo,
            'old_bet' => $oldBet,
            'add_bet' => $amount,
            'new_bet' => $existingRecord->bet,
        ]);
    }

    public function processSettle(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);
        $isCompensation = $params['is_compensation'] ?? false;

        $this->log->info("DG: 处理结算", [
            'order_no' => $orderNo,
            'amount' => $amount,
            'is_compensation' => $isCompensation,
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
        if ($isCompensation) {
            // Compensation：创建新记录
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

            $this->log->info("DG: Compensation 创建成功", [
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
                $isCompensation ? ['source' => 'player_compensation', 'remark' => '游戏补偿'] : []
            );
        }

        // 5. 发送彩金队列
        if ($betRecord) {
            $this->sendLotteryQueue($player, $betRecord);
        }

        $this->log->info("DG: 结算处理成功", [
            'order_no' => $orderNo,
            'record_id' => $recordId ?? null,
        ]);
    }
}
