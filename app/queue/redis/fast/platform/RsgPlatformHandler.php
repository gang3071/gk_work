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

        // ✅ 0. 时序检测：检查是否已有 settle 记录（先到达）
        // 优化：先查 Redis，避免无谓的数据库查询
        $settleOrderNo = $orderNo . '_settle';
        $settlePendingKey = "order:settle_pending:{$orderNo}";

        // 只有 settle 处理时会设置这个标记
        $existingSettle = null;
        if (\support\Redis::exists($settlePendingKey)) {
            // 有标记，说明 settle 先到达，查数据库
            $existingSettle = PlayGameRecord::where('order_no', $settleOrderNo)
                ->where('platform_id', $platformId)
                ->lockForUpdate()
                ->first();
        }

        if ($existingSettle) {
            // 时序倒置：settle 先到达，现在补充 bet 信息并合并记录
            \support\Redis::del($settlePendingKey);  // 清理标记
            $this->mergeSettleRecordWithBet($existingSettle, $data, $player, $orderNo, $platformId, $amount, $isPrepay, $params);
            return;
        }

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

        // 6. 强制更新缓存（确保缓存与数据库一致 - 双重保险）
        try {
            \app\service\WalletService::updateCache($player->id, \app\model\PlayerPlatformCash::PLATFORM_SELF,(float)$wallet->money);
        } catch (\Throwable $e) {
            $this->log->warning("RSG: 下注后缓存更新失败", [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }

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
        $betAmount = (float)($params['bet_amount'] ?? 0);  // ← 原始下注金额

        $isJackpot = $params['is_jackpot'] ?? false;
        $isRefund = ($params['type'] ?? '') === 'refund';
        $sessionId = $params['session_id'] ?? '';

        $this->log->info("RSG: 处理结算", [
            'order_no' => $orderNo,
            'amount' => $amount,
            'bet_amount' => $betAmount,
            'is_jackpot' => $isJackpot,
            'is_refund' => $isRefund,
        ]);

        // refund 特殊处理：更新原 prepay 记录
        if ($isRefund && $sessionId) {
            $this->processRSGRefund($data, $player, $sessionId, $amount, $params, $platformId);
            return;
        }

        // 1. 查找下注记录（✅ 优化：快速重试，避免长时间等待）
        $betRecord = null;
        if ($orderNo) {
            // 优先从 Redis 缓存获取（<1ms）
            $betRecord = $this->fetchBetRecord($orderNo, 5, 20000);  // 5次重试，间隔20ms = 最多100ms

            if (!$betRecord && !$isJackpot && !$isRefund) {
                // 仍未找到，检查 pending 状态
                $pendingKey = "order:pending:{$orderNo}";
                if (\support\Redis::exists($pendingKey)) {
                    $this->log->info("RSG: bet 还在处理中，短暂等待", [
                        'order_no' => $orderNo,
                    ]);
                    usleep(50000);  // 等50ms（而非200ms）
                    $betRecord = $this->fetchBetRecord($orderNo, 1, 0);
                }
            }

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
            $betRecord->diff = bcsub($amount, $betAmount, 2);  // ← 关键修复
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
            // ⚠️ 使用后缀避免和可能存在的bet记录冲突
            $settleOrderNo = $orderNo . '_settle';

            $this->log->warning("RSG: 未找到下注记录，创建新结算记录", [
                'bet_order_no' => $orderNo,
                'settle_order_no' => $settleOrderNo,
            ]);

            $record = new PlayGameRecord();
            $record->player_id = $player->id;
            $record->parent_player_id = $player->recommend_id ?? 0;
            $record->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
            $record->player_uuid = $player->uuid;
            $record->department_id = $player->department_id ?? 0;
            $record->order_no = $settleOrderNo;  // ← 使用带后缀的订单号
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

            // ✅ 设置 Redis 标记（通知后续 bet 请求这是时序倒置）
            $settlePendingKey = "order:settle_pending:{$orderNo}";
            \support\Redis::setex($settlePendingKey, 300, 1);  // 5分钟过期
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

        // 6. 强制更新缓存（确保缓存与数据库一致 - 双重保险）
        try {
            // 重新读取最新余额并更新缓存
            $latestWallet = \app\model\PlayerPlatformCash::where('player_id', $player->id)->first();
            if ($latestWallet) {
                \app\service\WalletService::updateCache($player->id, \app\model\PlayerPlatformCash::PLATFORM_SELF,(float)$latestWallet->money);
            }
        } catch (\Throwable $e) {
            $this->log->warning("RSG: 结算后缓存更新失败", [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
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

        // 5. 强制更新缓存（确保缓存与数据库一致 - 双重保险）
        try {
            // 重新读取最新余额并更新缓存
            $latestWallet = \app\model\PlayerPlatformCash::where('player_id', $player->id)->first();
            if ($latestWallet) {
                \app\service\WalletService::updateCache($player->id, \app\model\PlayerPlatformCash::PLATFORM_SELF,(float)$latestWallet->money);
            }
        } catch (\Throwable $e) {
            $this->log->warning("RSG: 退款后缓存更新失败", [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->log->info("RSG: 打鱼机退款处理成功", [
            'session_id' => $sessionId,
            'record_id' => $record->id,
        ]);
    }

    /**
     * 合并 settle 记录（时序倒置处理）
     * 当 bet 请求晚于 settle 到达时，将 settle 记录更新为完整记录
     */
    private function mergeSettleRecordWithBet(
        PlayGameRecord $settleRecord,
        array          $data,
        Player         $player,
        string         $orderNo,
        int            $platformId,
        float          $amount,
        bool           $isPrepay,
        array          $params
    ): void
    {
        $this->log->warning("RSG: 时序倒置，合并 settle 记录", [
            'order_no' => $orderNo,
            'settle_order_no' => $settleRecord->order_no,
            'record_id' => $settleRecord->id,
        ]);

        // 1. 钱包扣款
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new Exception("钱包不存在: player_id={$player->id}");
        }

        $beforeBalance = $wallet->money;
        $actualAmount = $amount;

        // prepay 特殊处理
        if ($isPrepay) {
            if ($beforeBalance < $amount) {
                $actualAmount = $beforeBalance;
                $wallet->money = 0;
                $this->log->info("RSG prepay 余额不足（时序修正）", [
                    'request_amount' => $amount,
                    'actual_amount' => $actualAmount,
                    'order_no' => $orderNo,
                ]);
            } else {
                $wallet->money = bcsub($wallet->money, $amount, 2);
            }
        } else {
            // 普通下注：爆机检查 + 余额检查
            if ($this->needsMachineCrashCheck()) {
                $this->checkMachineCrash($player, $platformId);
            }
            if ($beforeBalance < $amount) {
                throw new Exception("余额不足: balance={$beforeBalance}, amount={$amount}");
            }
            $wallet->money = bcsub($wallet->money, $amount, 2);
        }
        $wallet->save();

        // 2. 更新 settle 记录
        $settleRecord->order_no = $orderNo;  // ✅ 去掉 _settle 后缀
        $settleRecord->bet = $actualAmount;
        $settleRecord->diff = bcsub($settleRecord->win, $actualAmount, 2);  // ✅ 重新计算 diff
        $settleRecord->order_time = $params['order_time'] ?? Carbon::now()->toDateTimeString();

        // 合并 original_data
        $originalData = json_decode($settleRecord->original_data, true) ?? [];
        $originalData['bet_info'] = $params['original_data'] ?? $params;
        $settleRecord->original_data = json_encode($originalData, JSON_UNESCAPED_UNICODE);

        if ($isPrepay) {
            $settleRecord->type = defined('PlayGameRecord::TYPE_PREPAY') ? PlayGameRecord::TYPE_PREPAY : 3;
        }

        $settleRecord->save();

        // 3. 更新 Redis 缓存
        $this->cacheOrderToRedis($orderNo, [
            'id' => $settleRecord->id,
            'player_id' => $settleRecord->player_id,
            'order_no' => $orderNo,
            'bet' => $settleRecord->bet,
            'platform_id' => $platformId,
            'game_code' => $settleRecord->game_code,
            'settlement_status' => $settleRecord->settlement_status,
            'created_at' => $settleRecord->created_at ?? Carbon::now()->toDateTimeString(),
        ]);
        $this->clearPendingStatus($orderNo);

        // 4. 创建交易记录（下注）
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = $settleRecord->getTable();
        $deliveryRecord->target_id = $settleRecord->id;
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
            $deliveryRecord->remark = 'RSG预付（时序修正）';
        } else {
            $deliveryRecord->type = PlayerDeliveryRecord::TYPE_BET;
            $deliveryRecord->source = 'player_bet';
            $deliveryRecord->remark = 'RSG下注（时序修正）';
        }

        $deliveryRecord->save();

        // 5. 更新余额缓存
        try {
            \app\service\WalletService::updateCache($player->id, \app\model\PlayerPlatformCash::PLATFORM_SELF, (float)$wallet->money);
        } catch (\Throwable $e) {
            $this->log->warning("RSG: 时序修正后缓存更新失败", [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }

        $this->log->info("RSG: 时序倒置修正完成", [
            'order_no' => $orderNo,
            'record_id' => $settleRecord->id,
            'bet' => $actualAmount,
            'win' => $settleRecord->win,
            'diff' => $settleRecord->diff,
        ]);
    }
}
