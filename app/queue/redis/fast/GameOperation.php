<?php

namespace app\queue\redis\fast;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use Carbon\Carbon;
use support\Db;
use support\Log;
use Webman\RedisQueue\Client;
use Webman\RedisQueue\Consumer;

/**
 * 游戏操作队列消费者（简化版 - 完整事务）
 * 在一个事务中完成：钱包操作 + 游戏记录 + 交易记录
 */
class GameOperation implements Consumer
{
    public $queue = 'game-operation';
    public $connection = 'default';
    public $maxAttempts = 3;

    // MT平台状态常量
    private const BET_STATUS_NOT = 2;  // 未中奖
    private const BET_STATUS_WIN = 3;  // 中奖
    private const BET_STATUS_TIE = 4;  // 和局

    /**
     * 消费队列消息
     *
     * @param array $data 队列数据
     * @return void
     */
    public function consume($data)
    {
        $platform = $data['platform'] ?? 'unknown';
        $operation = $data['operation'] ?? 'unknown';
        $orderNo = $data['order_no'] ?? '';
        $playerId = $data['player_id'] ?? 0;

        $startTime = microtime(true);

        Log::info("GameOperation: 开始处理", [
            'platform' => $platform,
            'operation' => $operation,
            'order_no' => $orderNo,
            'player_id' => $playerId,
        ]);

        // 开启数据库事务
        Db::beginTransaction();

        try {
            // 1. 幂等性检查（数据库）
            $exists = PlayGameRecord::where('order_no', $orderNo)->exists();
            if ($exists) {
                Log::warning("GameOperation: 订单已存在，跳过", [
                    'order_no' => $orderNo,
                ]);
                Db::rollBack();
                return;
            }

            // 2. 查询玩家
            $player = Player::find($playerId);
            if (!$player) {
                throw new \Exception("玩家不存在: {$playerId}");
            }

            // 3. 根据操作类型处理（在事务中完成所有操作）
            match ($operation) {
                'bet' => $this->processBet($data, $player),
                'settle' => $this->processSettle($data, $player),
                'cancel' => $this->processCancel($data, $player),
                'refund' => $this->processRefund($data, $player),
                default => throw new \Exception("未知操作类型: {$operation}"),
            };

            // 4. 提交事务
            Db::commit();

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::info("GameOperation: 处理完成", [
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

        } catch (\Throwable $e) {
            // 回滚事务
            Db::rollBack();

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::error("GameOperation: 处理失败", [
                'platform' => $platform,
                'operation' => $operation,
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 重新抛出异常，触发队列重试
            throw $e;
        }
    }

    /**
     * 处理下注（事务中完成：钱包 + 游戏记录 + 交易记录）
     *
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @return void
     */
    private function processBet(array $data, Player $player)
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);

        Log::info("GameOperation: 处理下注", [
            'order_no' => $orderNo,
            'amount' => $amount,
        ]);

        // 1. 检查设备是否爆机（MT、RSG、BTG、QT、DG、O8、SA、SP、ATG、KT、T9SLOT、RSGLIVE、SPSDY平台）
        if (in_array($data['platform'], ['MT', 'RSG', 'BTG', 'QT', 'DG', 'O8', 'SA', 'SP', 'ATG', 'KT', 'T9SLOT', 'RSGLIVE', 'SPSDY'])) {
            $this->checkAndHandleMachineCrash($player, $platformId);
        }

        // BTG免费游戏特殊处理：amount=0时只创建记录不扣款
        if ($data['platform'] === 'BTG' && $amount == 0) {
            $record = new PlayGameRecord();
            $record->player_id = $player->id;
            $record->parent_player_id = $player->recommend_id ?? 0;
            $record->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
            $record->player_uuid = $player->uuid;
            $record->department_id = $player->department_id ?? 0;
            $record->order_no = $orderNo;
            $record->platform_id = $platformId;
            $record->bet = 0;
            $record->bet_amount = 0;
            $record->win = 0;
            $record->diff = 0;
            $record->game_code = $params['game_code'] ?? '';
            $record->game_type = $params['game_type'] ?? '';
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED;
            $record->order_time = $params['order_time'] ?? Carbon::now()->toDateTimeString();
            $record->original_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
            $record->platform_created_at = Carbon::now()->toDateTimeString();
            $record->save();

            Log::info("GameOperation: BTG免费游戏记录创建成功", [
                'order_no' => $orderNo,
                'record_id' => $record->id,
            ]);
            return;
        }

        // 2. 钱包扣款（带锁）
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \Exception("钱包不存在: player_id={$player->id}");
        }

        $beforeBalance = $wallet->money;

        // QT 奖金回合特殊处理：不扣款
        $bonusType = $params['bonus_type'] ?? null;
        if ($data['platform'] === 'QT' && $bonusType) {
            // 奖金回合不扣款
            $actualAmount = 0;
            Log::info("GameOperation: QT 奖金回合，不扣款", [
                'order_no' => $orderNo,
                'bonus_type' => $bonusType,
                'amount' => $amount,
            ]);
        } // RSG prepay 特殊处理：余额不足时扣除所有金额
        elseif ($params['type'] ?? '' === 'prepay') {
            if ($beforeBalance < $amount) {
                // RSG打鱼机预扣：余额不足时扣除所有现有金额
                $actualAmount = $beforeBalance;
                $wallet->money = 0;
                $wallet->save();

                Log::info("GameOperation: RSG prepay 余额不足，扣除所有金额", [
                    'request_amount' => $amount,
                    'actual_amount' => $actualAmount,
                    'order_no' => $orderNo,
                ]);
            } else {
                $actualAmount = $amount;
                $wallet->money = bcsub($wallet->money, $amount, 2);
                $wallet->save();
            }
        } else {
            // 普通下注：余额不足抛异常
            if ($beforeBalance < $amount) {
                throw new \Exception("余额不足: balance={$beforeBalance}, amount={$amount}");
            }

            $actualAmount = $amount;
            $wallet->money = bcsub($wallet->money, $amount, 2);
            $wallet->save();
        }

        // 3. 创建游戏记录（包含推荐关系字段）
        // 使用实际扣除金额（prepay可能少于请求金额）
        $betAmount = $actualAmount ?? $amount;

        $record = new PlayGameRecord();
        $record->player_id = $player->id;
        $record->parent_player_id = $player->recommend_id ?? 0;
        $record->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
        $record->player_uuid = $player->uuid;
        $record->department_id = $player->department_id ?? 0;
        $record->order_no = $orderNo;
        $record->platform_id = $platformId;
        $record->bet = $betAmount;  // 主字段
        $record->win = 0;
        $record->diff = 0;
        $record->game_code = $params['game_code'] ?? '';
        $record->order_time = $params['order_time'] ?? Carbon::now()->toDateTimeString();
        $record->original_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);

        // 判断是否为特殊类型
        if (($params['game_type'] ?? '') === 'gift') {
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;  // 打赏立即结算
            $record->type = defined('PlayGameRecord::TYPE_GIFT') ? PlayGameRecord::TYPE_GIFT : 2;  // TYPE_GIFT
        } elseif (($params['type'] ?? '') === 'prepay') {
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED;
            $record->type = defined('PlayGameRecord::TYPE_PREPAY') ? PlayGameRecord::TYPE_PREPAY : 3;  // TYPE_PREPAY
        } else {
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED;
        }

        $record->save();

        // 3.5 写入 Redis 完成缓存 + 清理 pending 状态
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

        // 清理 pending 状态（订单已完成）
        try {
            \support\Redis::del("order:pending:{$orderNo}");
        } catch (\Throwable $e) {
            // 清理失败不影响主流程
        }

        // 4. 创建交易记录（使用实际扣除金额）
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = $record->getTable();
        $deliveryRecord->target_id = $record->id;
        $deliveryRecord->platform_id = $platformId;

        // 根据类型设置交易类型
        if ($params['type'] ?? '' === 'prepay') {
            $deliveryRecord->type = defined('PlayerDeliveryRecord::TYPE_PREPAY') ? PlayerDeliveryRecord::TYPE_PREPAY : PlayerDeliveryRecord::TYPE_BET;
            $deliveryRecord->source = 'player_prepay';
            $deliveryRecord->remark = '游戏预付';
        } else {
            $deliveryRecord->type = PlayerDeliveryRecord::TYPE_BET;
            $deliveryRecord->source = 'player_bet';
            $deliveryRecord->remark = '游戏下注';
        }

        $deliveryRecord->amount = $betAmount;  // 使用实际扣除金额
        $deliveryRecord->amount_before = $beforeBalance;
        $deliveryRecord->amount_after = $wallet->money;
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        Log::info("GameOperation: 下注处理成功", [
            'order_no' => $orderNo,
            'record_id' => $record->id,
            'delivery_id' => $deliveryRecord->id,
            'balance_before' => $beforeBalance,
            'balance_after' => $wallet->money,
        ]);
    }

    /**
     * 检查设备是否爆机（MT平台）
     *
     * @param Player $player
     * @param int $platformId
     * @return void
     * @throws \Exception
     */
    private function checkAndHandleMachineCrash(Player $player, int $platformId)
    {
        // 获取玩家所在设备
        $machine = $player->machine;
        if (!$machine) {
            return;
        }

        // 检查设备是否爆机（余额为负）
        $wallet = PlayerPlatformCash::where('player_id', $machine->id)
            ->first();

        if ($wallet && $wallet->money < 0) {
            throw new \Exception("设备已爆机，禁止下注");
        }
    }

    /**
     * 处理结算（事务中完成：钱包 + 游戏记录 + 交易记录）
     *
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @return void
     */
    private function processSettle(array $data, Player $player)
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);
        $betOrderNo = $params['bet_order_no'] ?? '';
        $status = $params['status'] ?? null;
        $settleTime = $params['settle_time'] ?? null;

        // 特殊类型标记
        $isJackpot = $params['is_jackpot'] ?? false;  // RSG Jackpot
        $isReward = $params['is_reward'] ?? false;  // BTG Reward
        $isCompensation = $params['is_compensation'] ?? false;  // DG Compensation
        $isRefund = ($params['type'] ?? '') === 'refund';  // RSG refund
        $sessionId = $params['session_id'] ?? '';  // RSG SessionId

        Log::info("GameOperation: 处理结算", [
            'platform' => $data['platform'],
            'order_no' => $orderNo,
            'bet_order_no' => $betOrderNo,
            'amount' => $amount,
            'status' => $status,
            'is_jackpot' => $isJackpot,
            'is_reward' => $isReward,
            'is_compensation' => $isCompensation,
            'is_refund' => $isRefund,
        ]);

        // RSG refund 特殊处理：更新原 prepay 记录
        if ($isRefund && $sessionId) {
            $this->processRSGRefund($data, $player, $sessionId, $amount);
            return;
        }

        // 1. 查找下注记录（带重试机制 + Redis 缓存优先）
        $betRecord = null;
        if ($betOrderNo) {
            // 使用带重试的查询方法，解决"订单未入库"问题
            $betRecord = $this->fetchBetRecordWithRetry($betOrderNo, 3, 50000);

            // 找到后需要加锁（因为后续要更新）
            if ($betRecord) {
                $betRecord = PlayGameRecord::where('id', $betRecord->id)->lockForUpdate()->first();
            }
        }

        // 2. 钱包加款（带锁）
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \Exception("钱包不存在: player_id={$player->id}");
        }

        $beforeBalance = $wallet->money;

        // 判断是否应该加钱
        // MT: status !== 2 才加钱
        // RSG: amount > 0 才加钱
        // BTG: amount > 0 才加钱
        // O8: txtype=510 且 amount>0 才加钱
        // jackpot/reward: 总是加钱（如果amount>0）
        $shouldAddMoney = false;
        if ($data['platform'] === 'MT') {
            $shouldAddMoney = ($status !== self::BET_STATUS_NOT && $amount > 0);
        } elseif ($data['platform'] === 'O8') {
            $txtype = $params['txtype'] ?? null;
            $shouldAddMoney = ($txtype == 510 && $amount > 0);
        } else {
            $shouldAddMoney = ($amount > 0);
        }

        if ($shouldAddMoney) {
            $wallet->money = bcadd($wallet->money, $amount, 2);
            $wallet->save();
        }

        // 3. 更新或创建游戏记录
        if ($isJackpot || $isReward || $isCompensation) {
            // RSG Jackpot、BTG Reward 或 DG Compensation：创建新记录（无下注）
            $record = new PlayGameRecord();
            $record->player_id = $player->id;
            $record->parent_player_id = $player->recommend_id ?? 0;
            $record->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
            $record->player_uuid = $player->uuid;
            $record->department_id = $player->department_id ?? 0;
            $record->order_no = $orderNo;
            $record->platform_id = $platformId;
            $record->bet = 0;  // Jackpot/Reward/Compensation无下注
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

            $logType = $isJackpot ? 'RSG Jackpot' : ($isReward ? 'BTG Reward' : 'DG Compensation');
            Log::info("GameOperation: {$logType} 创建成功", [
                'order_no' => $orderNo,
                'amount' => $amount,
                'record_id' => $recordId,
            ]);

        } elseif ($betRecord) {
            // 更新下注记录
            $betRecord->win = $amount <= $betRecord->bet ? 0 : bcsub($amount, $betRecord->bet, 2);
            $betRecord->win_amount = $amount;
            $betRecord->diff = bcsub($amount, $betRecord->bet, 2);
            $betRecord->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $betRecord->platform_action_at = $params['play_time'] ?? $settleTime ?? Carbon::now()->toDateTimeString();
            $betRecord->action_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
            $betRecord->save();
            $recordId = $betRecord->id;

            // RSG 游戏流程判断
            if ($data['platform'] === 'RSG' && isset($params['is_game_flow_end']) && isset($params['belong_sequen_number'])) {
                if ($params['is_game_flow_end'] && $params['belong_sequen_number'] != $orderNo) {
                    $betRecord = PlayGameRecord::where('order_no', $params['belong_sequen_number'])->first();
                    $recordId = $betRecord ? $betRecord->id : $recordId;

                    Log::info("GameOperation: RSG 游戏流程结束，切换到主记录", [
                        'current_order' => $orderNo,
                        'belong_order' => $params['belong_sequen_number'],
                    ]);
                }
            }

        } else {
            // 创建新记录（没有找到下注记录）
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
            $record->platform_action_at = $settleTime ?? Carbon::now()->toDateTimeString();
            $record->save();
            $recordId = $record->id;
        }

        // 4. 创建交易记录（只在有加钱时创建）
        if ($shouldAddMoney) {
            $deliveryRecord = new PlayerDeliveryRecord();
            $deliveryRecord->player_id = $player->id;
            $deliveryRecord->department_id = $player->department_id ?? 0;
            $deliveryRecord->target = $betRecord ? $betRecord->getTable() : '';
            $deliveryRecord->target_id = $recordId;
            $deliveryRecord->platform_id = $platformId;
            $deliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;

            // 根据类型设置 source 和 remark
            if ($isJackpot) {
                $deliveryRecord->source = 'jackpot_result';
                $deliveryRecord->remark = '游戏彩池结算';
            } elseif ($isReward) {
                $deliveryRecord->source = 'player_bet_reward';
                $deliveryRecord->remark = '额外奖金';
            } elseif ($isCompensation) {
                $deliveryRecord->source = 'player_compensation';
                $deliveryRecord->remark = '游戏补偿';
            } else {
                $deliveryRecord->source = 'player_settlement';
                $deliveryRecord->remark = '游戏结算';
            }

            $deliveryRecord->amount = $amount;
            $deliveryRecord->amount_before = $beforeBalance;
            $deliveryRecord->amount_after = $wallet->money;
            $deliveryRecord->tradeno = $orderNo;
            $deliveryRecord->user_id = 0;
            $deliveryRecord->user_name = '';
            $deliveryRecord->save();

            Log::info("GameOperation: 结算处理成功（已加款）", [
                'order_no' => $orderNo,
                'record_id' => $recordId,
                'delivery_id' => $deliveryRecord->id,
                'balance_before' => $beforeBalance,
                'balance_after' => $wallet->money,
            ]);
        } else {
            Log::info("GameOperation: 结算处理成功（未中奖，不加款）", [
                'order_no' => $orderNo,
                'record_id' => $recordId ?? null,
                'status' => $status,
            ]);
        }

        // 5. 发送彩金队列（RSG、BTG、QT、DG、O8、SA、SP、ATG、KT、T9SLOT、RSGLIVE、SPSDY平台）
        if (in_array($data['platform'], ['RSG', 'BTG', 'QT', 'DG', 'O8', 'SA', 'SP', 'ATG', 'KT', 'T9SLOT', 'RSGLIVE', 'SPSDY']) && isset($betRecord) && $betRecord->bet > 0) {
            // BTG平台需要过滤鱼机游戏
            $gameType = $params['game_type'] ?? '';
            $shouldSendLottery = true;

            if ($data['platform'] === 'BTG' && $gameType === 'fish') {
                $shouldSendLottery = false;
                Log::info("GameOperation: BTG 鱼机游戏跳过彩金队列", [
                    'order_no' => $orderNo,
                    'game_type' => $gameType,
                ]);
            }

            if ($shouldSendLottery) {
                Client::send('game-lottery', [
                    'player_id' => $player->id,
                    'bet' => $betRecord->bet,
                    'play_game_record_id' => $betRecord->id
                ]);

                Log::info("GameOperation: {$data['platform']} 彩金队列已发送", [
                    'order_no' => $orderNo,
                    'bet' => $betRecord->bet,
                    'record_id' => $betRecord->id,
                ]);
            }
        }
    }

    /**
     * 处理取消（事务中完成：钱包 + 游戏记录 + 交易记录）
     *
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @return void
     */
    private function processCancel(array $data, Player $player)
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);
        $betOrderNo = $params['bet_order_no'] ?? $orderNo;

        Log::info("GameOperation: 处理取消", [
            'order_no' => $orderNo,
            'bet_order_no' => $betOrderNo,
            'amount' => $amount,
        ]);

        // 1. 查找下注记录（Redis缓存优先 + 重试机制）
        $betRecord = $this->fetchBetRecordWithRetry($betOrderNo, 5, 50000);

        if (!$betRecord) {
            throw new \Exception("下注记录不存在（已重试5次）: {$betOrderNo}");
        }

        // 加锁查询（准备更新）
        $betRecord = PlayGameRecord::where('id', $betRecord->id)->lockForUpdate()->first();
        if (!$betRecord) {
            throw new \Exception("下注记录已被删除: {$betOrderNo}");
        }

        // 2. 钱包退款（带锁）
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \Exception("钱包不存在");
        }

        $beforeBalance = $wallet->money;

        // 退款金额优先使用原始下注金额（$betRecord->bet）
        // 兼容传入的 amount 参数（如果有的话）
        $refundAmount = $betRecord->bet > 0 ? $betRecord->bet : $amount;

        $wallet->money = bcadd($wallet->money, $refundAmount, 2);
        $wallet->save();

        // 3. 更新游戏记录状态
        $betRecord->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_CANCELLED;
        $betRecord->platform_action_at = Carbon::now()->toDateTimeString();
        $betRecord->action_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);
        $betRecord->save();

        // 4. 创建交易记录
        $isRollback = $params['is_rollback'] ?? false;

        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = $betRecord->getTable();
        $deliveryRecord->target_id = $betRecord->id;
        $deliveryRecord->platform_id = $platformId;
        $deliveryRecord->type = PlayerDeliveryRecord::TYPE_CANCEL_BET;

        // 根据是否是rollback设置source和remark
        if ($isRollback) {
            $deliveryRecord->source = 'qt_rollback';
            $deliveryRecord->remark = '回滚交易';
        } else {
            $deliveryRecord->source = 'player_cancel_bet';
            $deliveryRecord->remark = '取消下注';
        }

        $deliveryRecord->amount = $refundAmount;
        $deliveryRecord->amount_before = $beforeBalance;
        $deliveryRecord->amount_after = $wallet->money;
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        Log::info("GameOperation: 取消处理成功", [
            'order_no' => $orderNo,
            'record_id' => $betRecord->id,
            'delivery_id' => $deliveryRecord->id,
            'refund_amount' => $refundAmount,
            'balance_after' => $wallet->money,
        ]);
    }

    /**
     * 处理退款（事务中完成：钱包 + 交易记录）
     *
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @return void
     */
    private function processRefund(array $data, Player $player)
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);

        Log::info("GameOperation: 处理退款", [
            'order_no' => $orderNo,
            'amount' => $amount,
        ]);

        // 1. 钱包退款（带锁）
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->where('department_id', 1)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \Exception("钱包不存在");
        }

        $beforeBalance = $wallet->money;
        $wallet->money = bcadd($wallet->money, $amount, 2);
        $wallet->save();

        // 2. 创建交易记录
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = '';
        $deliveryRecord->target_id = 0;
        $deliveryRecord->platform_id = $platformId;
        $deliveryRecord->type = PlayerDeliveryRecord::TYPE_REFUND;
        $deliveryRecord->source = 'player_refund';
        $deliveryRecord->amount = $amount;
        $deliveryRecord->amount_before = $beforeBalance;
        $deliveryRecord->amount_after = $wallet->money;
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->remark = '游戏退款';
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        Log::info("GameOperation: 退款处理成功", [
            'order_no' => $orderNo,
            'delivery_id' => $deliveryRecord->id,
            'balance_after' => $wallet->money,
        ]);
    }

    /**
     * 处理 RSG 打鱼机退款（更新原 prepay 记录）
     *
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @param string $sessionId SessionId
     * @param float $amount 退款金额
     * @return void
     */
    private function processRSGRefund(array $data, Player $player, string $sessionId, float $amount)
    {
        $params = $data['params'];
        $platformId = $params['platform_id'] ?? 1;

        Log::info("GameOperation: 处理RSG打鱼机退款", [
            'session_id' => $sessionId,
            'amount' => $amount,
        ]);
        /** @var PlayGameRecord $record */
        // 1. 查找原 prepay 记录（使用 SessionId）
        $record = PlayGameRecord::where('order_no', $sessionId)
            ->where('platform_id', $platformId)
            ->lockForUpdate()
            ->first();

        if (!$record) {
            throw new \Exception("找不到原预扣记录: session_id={$sessionId}");
        }

        // 2. 钱包加款（带锁）
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \Exception("钱包不存在");
        }

        $beforeBalance = $wallet->money;
        $wallet->money = bcadd($wallet->money, $amount, 2);
        $wallet->save();

        // 3. 更新原记录（不创建新记录）
        $win = $amount <= $record->bet ? 0 : bcsub($amount, $record->bet, 2);
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
        $deliveryRecord->amount = $amount;
        $deliveryRecord->amount_before = $beforeBalance;
        $deliveryRecord->amount_after = $wallet->money;
        $deliveryRecord->tradeno = $record->order_no;
        $deliveryRecord->remark = '游戏退款';
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        Log::info("GameOperation: RSG打鱼机退款处理成功", [
            'session_id' => $sessionId,
            'record_id' => $record->id,
            'amount' => $amount,
            'balance_after' => $wallet->money,
        ]);
    }

    /**
     * 缓存订单到 Redis（用于快速查询）
     *
     * @param string $orderNo 订单号
     * @param array $orderData 订单数据
     * @param int $ttl 过期时间（秒），默认1小时
     * @return void
     */
    private function cacheOrderToRedis(string $orderNo, array $orderData, int $ttl = 3600): void
    {
        try {
            $cacheKey = "order:cache:{$orderNo}";
            \support\Redis::hMSet($cacheKey, $orderData);
            \support\Redis::expire($cacheKey, $ttl);

            Log::debug("GameOperation: 订单已缓存到Redis", [
                'order_no' => $orderNo,
                'ttl' => $ttl,
            ]);
        } catch (\Throwable $e) {
            // Redis 缓存失败不影响主流程
            Log::warning("GameOperation: Redis缓存失败", [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 从 Redis 获取订单缓存
     *
     * @param string $orderNo 订单号
     * @return array|null 订单数据或 null
     */
    private function fetchOrderFromRedis(string $orderNo): ?array
    {
        try {
            $cacheKey = "order:cache:{$orderNo}";
            $orderData = \support\Redis::hGetAll($cacheKey);

            if (!empty($orderData)) {
                Log::debug("GameOperation: Redis缓存命中", [
                    'order_no' => $orderNo,
                ]);
                return $orderData;
            }
        } catch (\Throwable $e) {
            Log::warning("GameOperation: Redis查询失败", [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 带重试机制的订单查询（解决"订单未入库"问题）
     *
     * @param string $orderNo 订单号
     * @param int $maxRetries 最大重试次数
     * @param int $delayUs 重试延迟（微秒）
     * @return PlayGameRecord|null
     */
    private function fetchBetRecordWithRetry(string $orderNo, int $maxRetries = 3, int $delayUs = 50000): ?PlayGameRecord
    {
        // 第零步：检查 Redis pending 状态（控制器层写入的预占）
        $pendingKey = "order:pending:{$orderNo}";
        $pendingOrder = null;
        try {
            $pendingOrder = \support\Redis::hGetAll($pendingKey);
        } catch (\Throwable $e) {
            Log::warning("GameOperation: Redis pending查询失败", ['error' => $e->getMessage()]);
        }

        // 第一步：尝试从 Redis 完成缓存获取（processBet 写入的）
        $cachedOrder = $this->fetchOrderFromRedis($orderNo);
        if ($cachedOrder && isset($cachedOrder['id'])) {
            // Redis 命中，直接从 DB 按 ID 查询（更快）
            $record = PlayGameRecord::find($cachedOrder['id']);
            if ($record) {
                Log::info("GameOperation: 通过Redis缓存快速定位订单", [
                    'order_no' => $orderNo,
                    'record_id' => $cachedOrder['id'],
                ]);
                return $record;
            }
        }

        // 第二步：查询 DB + 智能重试机制
        for ($i = 0; $i < $maxRetries; $i++) {
            $record = PlayGameRecord::where('order_no', $orderNo)->first();

            if ($record) {
                if ($i > 0) {
                    Log::info("GameOperation: 订单查询成功（重试后）", [
                        'order_no' => $orderNo,
                        'retry_count' => $i,
                        'elapsed_ms' => $i * ($delayUs / 1000),
                    ]);
                }
                return $record;
            }

            // 未找到，检查是否应该重试
            if ($i < $maxRetries - 1) {
                // 如果 Redis pending 存在，说明订单确实存在，继续重试
                if (!empty($pendingOrder)) {
                    Log::warning("GameOperation: 订单未入库（pending状态存在），等待重试", [
                        'order_no' => $orderNo,
                        'retry' => $i + 1,
                        'pending_status' => $pendingOrder['status'] ?? 'unknown',
                    ]);
                    usleep($delayUs);
                } else {
                    // Redis pending 也不存在，可能订单根本不存在，减少重试次数
                    Log::warning("GameOperation: 订单未入库（pending状态也不存在），快速失败", [
                        'order_no' => $orderNo,
                        'retry' => $i + 1,
                    ]);
                    usleep($delayUs / 2); // 等待时间减半
                }
            }
        }

        // 所有重试都失败
        Log::error("GameOperation: 订单查询失败（所有重试已用尽）", [
            'order_no' => $orderNo,
            'max_retries' => $maxRetries,
            'total_wait_ms' => ($maxRetries - 1) * ($delayUs / 1000),
        ]);

        return null;
    }
}
