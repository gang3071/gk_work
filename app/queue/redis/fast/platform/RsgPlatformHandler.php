<?php

namespace app\queue\redis\fast\platform;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use Carbon\Carbon;
use Exception;

/**
 * RSG 平台处理器
 * 特殊逻辑：
 * - prepay（打鱼机预扣）：余额不足时扣除所有金额
 * - refund（打鱼机退款）：更新原prepay记录
 * - Jackpot：创建新记录（无下注）
 * - 游戏流程结束判断
 */
class RsgPlatformHandler extends BasePlatformHandler
{
    public function getPlatformCode(): string
    {
        return 'RSG';
    }

    public function supportsAccumulatedBet(): bool
    {
        // RSGLive 支持累计下注，RSG 不支持
        return false;
    }

    public function processBet(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);
        $isPrepay = ($params['type'] ?? '') === 'prepay';

        $this->log->info("RSG: 处理下注", [
            'order_no' => $orderNo,
            'amount' => $amount,
            'is_prepay' => $isPrepay,
        ]);

        // 1. 爆机检查
        if ($this->needsMachineCrashCheck()) {
            $this->checkMachineCrash($player, $platformId);
        }

        // 2. 钱包扣款
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new Exception("钱包不存在: player_id={$player->id}");
        }

        $beforeBalance = $wallet->money;
        $actualAmount = $amount;

        // prepay 特殊处理：余额不足时扣除所有金额
        if ($isPrepay) {
            if ($beforeBalance < $amount) {
                $actualAmount = $beforeBalance;
                $wallet->money = 0;
                $this->log->info("RSG prepay 余额不足，扣除所有金额", [
                    'request_amount' => $amount,
                    'actual_amount' => $actualAmount,
                    'order_no' => $orderNo,
                ]);
            } else {
                $wallet->money = bcsub($wallet->money, $amount, 2);
            }
            $wallet->save();
        } else {
            // 普通下注：余额不足抛异常
            if ($beforeBalance < $amount) {
                throw new Exception("余额不足: balance={$beforeBalance}, amount={$amount}");
            }
            $wallet->money = bcsub($wallet->money, $amount, 2);
            $wallet->save();
        }

        // 3. 创建游戏记录
        $record = new PlayGameRecord();
        $record->player_id = $player->id;
        $record->parent_player_id = $player->recommend_id ?? 0;
        $record->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
        $record->player_uuid = $player->uuid;
        $record->department_id = $player->department_id ?? 0;
        $record->order_no = $orderNo;
        $record->platform_id = $platformId;
        $record->bet = $actualAmount;
        $record->win = 0;
        $record->diff = 0;
        $record->game_code = $params['game_code'] ?? '';
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED;
        $record->order_time = $params['order_time'] ?? Carbon::now()->toDateTimeString();
        $record->original_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);

        // prepay 类型标记
        if ($isPrepay) {
            $record->type = defined('PlayGameRecord::TYPE_PREPAY') ? PlayGameRecord::TYPE_PREPAY : 3;
        }

        $record->save();

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
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = $record->getTable();
        $deliveryRecord->target_id = $record->id;
        $deliveryRecord->platform_id = $platformId;
        $deliveryRecord->amount = $actualAmount;
        $deliveryRecord->amount_before = $beforeBalance;
        $deliveryRecord->amount_after = $wallet->money;
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';

        if ($isPrepay) {
            $deliveryRecord->type = defined('PlayerDeliveryRecord::TYPE_PREPAY') ? PlayerDeliveryRecord::TYPE_PREPAY : PlayerDeliveryRecord::TYPE_BET;
            $deliveryRecord->source = 'player_prepay';
            $deliveryRecord->remark = '游戏预付';
        } else {
            $deliveryRecord->type = PlayerDeliveryRecord::TYPE_BET;
            $deliveryRecord->source = 'player_bet';
            $deliveryRecord->remark = '游戏下注';
        }

        $deliveryRecord->save();

        $this->log->info("RSG: 下注处理成功", [
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

        $isJackpot = $params['is_jackpot'] ?? false;
        $isRefund = ($params['type'] ?? '') === 'refund';
        $sessionId = $params['session_id'] ?? '';

        $this->log->info("RSG: 处理结算", [
            'order_no' => $orderNo,
            'amount' => $amount,
            'is_jackpot' => $isJackpot,
            'is_refund' => $isRefund,
        ]);

        // refund 特殊处理：更新原 prepay 记录
        if ($isRefund && $sessionId) {
            $this->processRSGRefund($data, $player, $sessionId, $amount, $params, $platformId);
            return;
        }

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
        if ($isJackpot) {
            // Jackpot：创建新记录
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

            $this->log->info("RSG: Jackpot 创建成功", [
                'order_no' => $orderNo,
                'amount' => $amount,
                'record_id' => $recordId,
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

            // 游戏流程判断
            if (isset($params['is_game_flow_end']) && isset($params['belong_sequen_number'])) {
                if ($params['is_game_flow_end'] && $params['belong_sequen_number'] != $orderNo) {
                    $belongRecord = PlayGameRecord::where('order_no', $params['belong_sequen_number'])->first();
                    if ($belongRecord) {
                        $recordId = $belongRecord->id;
                        $this->log->info("RSG: 游戏流程结束，切换到主记录", [
                            'current_order' => $orderNo,
                            'belong_order' => $params['belong_sequen_number'],
                        ]);
                    }
                }
            }

        } else {
            // 创建新记录（未找到下注记录）
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
                $orderNo,
                $isJackpot ? ['source' => 'jackpot_result', 'remark' => '游戏彩池结算'] : []
            );
        }

        // 5. 发送彩金队列
        if ($betRecord) {
            $this->sendLotteryQueue($player, $betRecord);
        }

        $this->log->info("RSG: 结算处理成功", [
            'order_no' => $orderNo,
            'record_id' => $recordId ?? null,
        ]);
    }

    /**
     * 处理 RSG 打鱼机退款
     */
    private function processRSGRefund(array $data, Player $player, string $sessionId, float $amount, array $params, int $platformId): void
    {
        $this->log->info("RSG: 处理打鱼机退款", [
            'session_id' => $sessionId,
            'amount' => $amount,
        ]);

        // 1. 查找原 prepay 记录
        $record = PlayGameRecord::where('order_no', $sessionId)
            ->where('platform_id', $platformId)
            ->lockForUpdate()
            ->first();

        if (!$record) {
            throw new Exception("找不到原预扣记录: session_id={$sessionId}");
        }

        // 2. 钱包加款
        $walletInfo = $this->addWallet($player, $amount);

        // 3. 更新原记录
        $record->win = $amount;
        $record->diff = bcsub($amount, $record->bet, 2);
        $record->action_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->type = defined('PlayGameRecord::TYPE_REFUND') ? PlayGameRecord::TYPE_REFUND : 4;
        $record->platform_action_at = Carbon::now()->toDateTimeString();
        $record->save();

        // 4. 创建退款交易记录
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = $record->getTable();
        $deliveryRecord->target_id = $record->id;
        $deliveryRecord->platform_id = $platformId;
        $deliveryRecord->type = defined('PlayerDeliveryRecord::TYPE_REFUND') ? PlayerDeliveryRecord::TYPE_REFUND : PlayerDeliveryRecord::TYPE_SETTLEMENT;
        $deliveryRecord->source = 'player_refund';
        $deliveryRecord->remark = '游戏退款';
        $deliveryRecord->amount = $amount;
        $deliveryRecord->amount_before = $walletInfo['before_balance'];
        $deliveryRecord->amount_after = $walletInfo['after_balance'];
        $deliveryRecord->tradeno = $record->order_no;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        $this->log->info("RSG: 打鱼机退款处理成功", [
            'session_id' => $sessionId,
            'record_id' => $record->id,
        ]);
    }
}
