<?php

namespace app\queue\redis\fast\platform;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use Carbon\Carbon;
use Exception;
use Psr\Log\LoggerInterface;
use Webman\RedisQueue\Client;

/**
 * 平台处理器基类
 * 包含所有平台的公共逻辑
 */
abstract class BasePlatformHandler implements PlatformHandlerInterface
{
    protected LoggerInterface $log;

    public function __construct(LoggerInterface $log)
    {
        $this->log = $log;
    }

    /**
     * 默认：不支持累计下注
     */
    public function supportsAccumulatedBet(): bool
    {
        return false;
    }

    /**
     * 默认：需要爆机检查
     */
    public function needsMachineCrashCheck(): bool
    {
        return true;
    }

    /**
     * 默认：需要发送彩金队列
     */
    public function needsLotteryQueue(): bool
    {
        return true;
    }

    /**
     * 默认取消实现
     */
    public function processCancel(array $data, Player $player): void
    {
        $this->defaultProcessCancel($data, $player);
    }

    /**
     * 默认退款实现
     */
    public function processRefund(array $data, Player $player): void
    {
        $this->defaultProcessRefund($data, $player);
    }

    // ========== 公共工具方法 ==========

    /**
     * 检查设备是否爆机
     */
    protected function checkMachineCrash(Player $player, int $platformId): void
    {
        try {
            $machineWallet = $player->machine_wallet;

            if (!$machineWallet) {
                return;
            }

            // 只检查实体机平台
            if ($machineWallet->platform_id != PlayerPlatformCash::PLATFORM_SELF) {
                return;
            }

            if ((bool)$machineWallet->is_crashed) {
                throw new Exception("玩家钱包已爆机，禁止下注");
            }

        } catch (Exception $e) {
            $this->log->error("爆机检查失败", [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
            ]);

            if (strpos($e->getMessage(), '爆机') !== false) {
                throw $e;
            }
        }
    }

    /**
     * 钱包扣款
     */
    protected function deductWallet(Player $player, float $amount, string $orderNo): array
    {
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new Exception("钱包不存在: player_id={$player->id}");
        }

        $beforeBalance = $wallet->money;

        if ($beforeBalance < $amount) {
            throw new Exception("余额不足: balance={$beforeBalance}, amount={$amount}");
        }

        $wallet->money = bcsub($wallet->money, $amount, 2);
        $wallet->save();

        return [
            'wallet' => $wallet,
            'before_balance' => $beforeBalance,
            'after_balance' => $wallet->money,
        ];
    }

    /**
     * 钱包加款
     */
    protected function addWallet(Player $player, float $amount): array
    {
        $wallet = PlayerPlatformCash::where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new Exception("钱包不存在: player_id={$player->id}");
        }

        $beforeBalance = $wallet->money;
        $wallet->money = bcadd($wallet->money, $amount, 2);
        $wallet->save();

        return [
            'wallet' => $wallet,
            'before_balance' => $beforeBalance,
            'after_balance' => $wallet->money,
        ];
    }

    /**
     * 创建游戏记录
     */
    protected function createGameRecord(Player $player, array $params, string $orderNo, float $betAmount): PlayGameRecord
    {
        $record = new PlayGameRecord();
        $record->player_id = $player->id;
        $record->parent_player_id = $player->recommend_id ?? 0;
        $record->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
        $record->player_uuid = $player->uuid;
        $record->department_id = $player->department_id ?? 0;
        $record->order_no = $orderNo;
        $record->platform_id = $params['platform_id'] ?? 1;
        $record->bet = $betAmount;
        $record->win = 0;
        $record->diff = 0;
        $record->game_code = $params['game_code'] ?? '';
        $record->order_time = $params['order_time'] ?? Carbon::now()->toDateTimeString();
        $record->original_data = json_encode($params['original_data'] ?? $params, JSON_UNESCAPED_UNICODE);

        // 判断特殊类型（gift打赏/prepay预付）
        if (($params['game_type'] ?? '') === 'gift') {
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;  // 打赏立即结算
            $record->type = defined('PlayGameRecord::TYPE_GIFT') ? PlayGameRecord::TYPE_GIFT : 2;
        } elseif (($params['type'] ?? '') === 'prepay') {
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED;
            $record->type = defined('PlayGameRecord::TYPE_PREPAY') ? PlayGameRecord::TYPE_PREPAY : 3;
        } else {
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED;
        }

        $record->save();

        return $record;
    }

    /**
     * 创建交易记录（下注）
     */
    protected function createBetDelivery(Player $player, PlayGameRecord $record, float $amount, float $beforeBalance, float $afterBalance, string $orderNo, ?string $remark = null): PlayerDeliveryRecord
    {
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = $record->getTable();
        $deliveryRecord->target_id = $record->id;
        $deliveryRecord->platform_id = $record->platform_id;
        $deliveryRecord->type = PlayerDeliveryRecord::TYPE_BET;
        $deliveryRecord->source = 'player_bet';
        $deliveryRecord->remark = $remark ?? '游戏下注';
        $deliveryRecord->amount = $amount;
        $deliveryRecord->amount_before = $beforeBalance;
        $deliveryRecord->amount_after = $afterBalance;
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        return $deliveryRecord;
    }

    /**
     * 创建交易记录（结算）
     */
    protected function createSettlementDelivery(Player $player, int $recordId, int $platformId, float $amount, float $beforeBalance, float $afterBalance, string $orderNo, array $options = []): PlayerDeliveryRecord
    {
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = 'play_game_record';
        $deliveryRecord->target_id = $recordId;
        $deliveryRecord->platform_id = $platformId;
        $deliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
        $deliveryRecord->source = $options['source'] ?? 'player_settlement';
        $deliveryRecord->remark = $options['remark'] ?? '游戏结算';
        $deliveryRecord->amount = $amount;
        $deliveryRecord->amount_before = $beforeBalance;
        $deliveryRecord->amount_after = $afterBalance;
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        return $deliveryRecord;
    }

    /**
     * 查询下注记录（带重试 + Redis缓存）
     */
    protected function fetchBetRecord(string $orderNo, int $maxRetries = 3, int $delayUs = 50000): ?PlayGameRecord
    {
        // 1. 尝试从 Redis 缓存获取
        $cachedOrder = $this->fetchOrderFromRedis($orderNo);
        if ($cachedOrder && isset($cachedOrder['id'])) {
            $record = PlayGameRecord::find($cachedOrder['id']);
            if ($record) {
                $this->log->info("通过Redis缓存快速定位订单", [
                    'order_no' => $orderNo,
                    'record_id' => $cachedOrder['id'],
                ]);
                return $record;
            }
        }

        // 2. 数据库查询 + 重试
        for ($i = 0; $i < $maxRetries; $i++) {
            $record = PlayGameRecord::where('order_no', $orderNo)->first();

            if ($record) {
                if ($i > 0) {
                    $this->log->info("订单查询成功（重试后）", [
                        'order_no' => $orderNo,
                        'retry_count' => $i,
                    ]);
                }
                return $record;
            }

            if ($i < $maxRetries - 1) {
                usleep($delayUs);
            }
        }

        $this->log->error("订单查询失败（所有重试已用尽）", [
            'order_no' => $orderNo,
            'max_retries' => $maxRetries,
        ]);

        return null;
    }

    /**
     * 缓存订单到 Redis
     */
    protected function cacheOrderToRedis(string $orderNo, array $orderData, int $ttl = 3600): void
    {
        try {
            $cacheKey = "order:cache:{$orderNo}";
            \support\Redis::hMSet($cacheKey, $orderData);
            \support\Redis::expire($cacheKey, $ttl);
        } catch (\Throwable $e) {
            // Redis 缓存失败不影响主流程
        }
    }

    /**
     * 从 Redis 获取订单缓存
     */
    protected function fetchOrderFromRedis(string $orderNo): ?array
    {
        try {
            $cacheKey = "order:cache:{$orderNo}";
            $orderData = \support\Redis::hGetAll($cacheKey);
            return !empty($orderData) ? $orderData : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 清理 pending 状态
     */
    protected function clearPendingStatus(string $orderNo): void
    {
        try {
            \support\Redis::del("order:pending:{$orderNo}");
        } catch (\Throwable $e) {
            // 忽略失败
        }
    }

    /**
     * 发送彩金队列
     */
    protected function sendLotteryQueue(Player $player, PlayGameRecord $record): void
    {
        if (!$this->needsLotteryQueue() || $record->bet <= 0) {
            return;
        }

        Client::send('game-lottery', [
            'player_id' => $player->id,
            'bet' => $record->bet,
            'play_game_record_id' => $record->id
        ]);

        $this->log->info("{$this->getPlatformCode()} 彩金队列已发送", [
            'order_no' => $record->order_no,
            'bet' => $record->bet,
            'record_id' => $record->id,
        ]);
    }

    /**
     * 默认取消处理
     */
    protected function defaultProcessCancel(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;

        $this->log->info("处理取消", ['order_no' => $orderNo]);

        // 1. 查找下注记录
        $betRecord = $this->fetchBetRecord($orderNo, 5, 50000);
        if (!$betRecord) {
            throw new Exception("下注记录不存在: {$orderNo}");
        }

        // 加锁
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

        // 4. 创建交易记录
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = $betRecord->getTable();
        $deliveryRecord->target_id = $betRecord->id;
        $deliveryRecord->platform_id = $platformId;
        $deliveryRecord->type = PlayerDeliveryRecord::TYPE_CANCEL_BET;
        $deliveryRecord->source = 'player_cancel_bet';
        $deliveryRecord->remark = '取消下注';
        $deliveryRecord->amount = $refundAmount;
        $deliveryRecord->amount_before = $walletInfo['before_balance'];
        $deliveryRecord->amount_after = $walletInfo['after_balance'];
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        $this->log->info("取消处理成功", [
            'order_no' => $orderNo,
            'refund_amount' => $refundAmount,
        ]);
    }

    /**
     * 默认退款处理
     */
    protected function defaultProcessRefund(array $data, Player $player): void
    {
        $params = $data['params'];
        $orderNo = $data['order_no'];
        $platformId = $params['platform_id'] ?? 1;
        $amount = (float)($params['amount'] ?? 0);

        $this->log->info("处理退款", ['order_no' => $orderNo, 'amount' => $amount]);

        // 1. 钱包退款
        $walletInfo = $this->addWallet($player, $amount);

        // 2. 创建交易记录
        $deliveryRecord = new PlayerDeliveryRecord();
        $deliveryRecord->player_id = $player->id;
        $deliveryRecord->department_id = $player->department_id ?? 0;
        $deliveryRecord->target = '';
        $deliveryRecord->target_id = 0;
        $deliveryRecord->platform_id = $platformId;
        $deliveryRecord->type = PlayerDeliveryRecord::TYPE_REFUND;
        $deliveryRecord->source = 'player_refund';
        $deliveryRecord->remark = '游戏退款';
        $deliveryRecord->amount = $amount;
        $deliveryRecord->amount_before = $walletInfo['before_balance'];
        $deliveryRecord->amount_after = $walletInfo['after_balance'];
        $deliveryRecord->tradeno = $orderNo;
        $deliveryRecord->user_id = 0;
        $deliveryRecord->user_name = '';
        $deliveryRecord->save();

        $this->log->info("退款处理成功", ['order_no' => $orderNo]);
    }
}
