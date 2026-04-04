<?php

namespace process;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\service\GameRecordCacheService;
use Carbon\Carbon;
use support\Db;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 游戏记录同步进程
 *
 * 功能：
 * 1. 定时从 Redis 读取待同步记录
 * 2. 批量写入 MySQL
 * 3. 更新同步状态
 *
 * 性能：
 * - 每秒同步 100-500 条记录
 * - 批量事务处理
 * - 失败自动重试
 */
class GameRecordSyncWorker
{
    /**
     * @var Worker
     */
    private $worker;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $log;

    /**
     * 同步间隔（秒）
     */
    private const SYNC_INTERVAL = 1;  // 每秒同步一次

    /**
     * 每次同步数量
     */
    private const BATCH_SIZE = 100;

    public function __construct()
    {
        $this->log = Log::channel('game_bet_record');
    }

    /**
     * Worker 启动时回调
     */
    public function onWorkerStart(Worker $worker): void
    {
        $this->worker = $worker;

        $this->log->info("游戏记录同步进程启动", [
            'worker_id' => $worker->id,
            'interval' => self::SYNC_INTERVAL . 's',
            'batch_size' => self::BATCH_SIZE,
        ]);

        // 启动定时器
        Timer::add(self::SYNC_INTERVAL, function () {
            $this->syncRecords();
        });

        // 每分钟清理过期记录
        Timer::add(60, function () {
            $this->cleanExpired();
        });
    }

    /**
     * 同步记录
     */
    private function syncRecords(): void
    {
        $startTime = microtime(true);

        try {
            // 1. 获取待同步记录
            $records = GameRecordCacheService::getPendingSyncRecords(self::BATCH_SIZE);

            if (empty($records)) {
                return;  // 无待同步记录
            }

            $this->log->info("开始同步", [
                'count' => count($records),
            ]);

            // 2. 批量同步
            $synced = 0;
            $failed = 0;

            foreach ($records as $record) {
                try {
                    $this->syncSingleRecord($record);
                    $synced++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->log->error("同步失败", [
                        'order_no' => $record['order_no'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);

                    // 标记失败
                    GameRecordCacheService::markAsFailed(
                        $record['redis_key'],
                        $e->getMessage()
                    );
                }
            }

            $elapsed = (microtime(true) - $startTime) * 1000;

            $this->log->info("同步完成", [
                'total' => count($records),
                'synced' => $synced,
                'failed' => $failed,
                'elapsed_ms' => round($elapsed, 2),
            ]);

        } catch (\Throwable $e) {
            $this->log->error("同步进程异常", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 同步单条记录
     */
    private function syncSingleRecord(array $record): void
    {
        $orderNo = $record['order_no'];
        $playerId = $record['player_id'];
        $platformId = $record['platform_id'];
        $settlementStatus = $record['settlement_status'] ?? 0;

        // 开启事务
        Db::beginTransaction();

        try {
            // 1. 检查是否已存在
            $existing = PlayGameRecord::query()->where('order_no', $orderNo)->first();

            if ($existing) {
                // 已存在，更新
                if ($settlementStatus == 1) {
                    $existing->win = $record['win'] ?? 0;
                    $existing->diff = $record['diff'] ?? 0;
                    $existing->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
                    if (isset($record['platform_action_at'])) {
                        $existing->platform_action_at = $record['platform_action_at'];
                    }
                    if (isset($record['action_data'])) {
                        $existing->action_data = $record['action_data'];
                    }
                    $existing->save();

                    $this->log->info("更新结算记录", [
                        'order_no' => $orderNo,
                        'record_id' => $existing->id,
                    ]);
                }

                // 标记为已同步
                GameRecordCacheService::markAsSynced($record['redis_key'], $existing->id);

            } else {
                // 不存在，创建新记录
                $player = Player::query()->find($playerId);
                if (!$player) {
                    throw new \Exception("玩家不存在: {$playerId}");
                }

                // 2. 钱包处理（仅下注需要扣款）
                if ($settlementStatus == 0 && ($record['amount'] ?? 0) > 0) {
                    /** @var PlayerPlatformCash $wallet */
                    $wallet = PlayerPlatformCash::query()->where('player_id', $playerId)
                        ->lockForUpdate()
                        ->first();

                    if (!$wallet) {
                        throw new \Exception("钱包不存在");
                    }

                    $beforeBalance = $wallet->money;
                    $amount = (float)$record['amount'];

                    if ($beforeBalance < $amount) {
                        throw new \Exception("余额不足: balance={$beforeBalance}, amount={$amount}");
                    }

                    $wallet->money = bcsub($wallet->money, $amount, 2);
                    $wallet->save();

                    // 更新余额缓存
                    GameRecordCacheService::updateCachedBalance($playerId, (float)$wallet->money);
                }

                // 3. 创建游戏记录
                $gameRecord = new PlayGameRecord();
                $gameRecord->player_id = $playerId;
                $gameRecord->parent_player_id = $player->recommend_id ?? 0;
                $gameRecord->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
                $gameRecord->player_uuid = $player->uuid;
                $gameRecord->department_id = $player->department_id ?? 0;
                $gameRecord->order_no = $orderNo;
                $gameRecord->platform_id = $platformId;
                $gameRecord->bet = $record['amount'] ?? 0;
                $gameRecord->win = $record['win'] ?? 0;
                $gameRecord->diff = $record['diff'] ?? 0;
                $gameRecord->game_code = $record['game_code'] ?? '';
                $gameRecord->settlement_status = $settlementStatus;
                $gameRecord->order_time = $record['created_at'] ?? Carbon::now()->toDateTimeString();
                $gameRecord->original_data = $record['original_data'] ?? '{}';

                if (isset($record['action_data'])) {
                    $gameRecord->action_data = $record['action_data'];
                }
                if (isset($record['platform_action_at'])) {
                    $gameRecord->platform_action_at = $record['platform_action_at'];
                }

                $gameRecord->save();

                // 4. 创建交易记录（如果有扣款）
                if (isset($wallet) && isset($beforeBalance)) {
                    $delivery = new PlayerDeliveryRecord();
                    $delivery->player_id = $playerId;
                    $delivery->department_id = $player->department_id ?? 0;
                    $delivery->target = $gameRecord->getTable();
                    $delivery->target_id = $gameRecord->id;
                    $delivery->platform_id = $platformId;
                    $delivery->type = PlayerDeliveryRecord::TYPE_BET;
                    $delivery->source = 'player_bet';
                    $delivery->remark = '游戏下注';
                    $delivery->amount = $amount;
                    $delivery->amount_before = $beforeBalance;
                    $delivery->amount_after = $wallet->money;
                    $delivery->tradeno = $orderNo;
                    $delivery->user_id = 0;
                    $delivery->user_name = '';
                    $delivery->save();
                }

                $this->log->info("创建游戏记录", [
                    'order_no' => $orderNo,
                    'record_id' => $gameRecord->id,
                ]);

                // 标记为已同步
                GameRecordCacheService::markAsSynced($record['redis_key'], $gameRecord->id);
            }

            // 提交事务
            Db::commit();

        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    /**
     * 清理过期记录
     */
    private function cleanExpired(): void
    {
        try {
            $count = GameRecordCacheService::cleanExpiredRecords();

            if ($count > 0) {
                $this->log->info("清理过期记录", ['count' => $count]);
            }
        } catch (\Throwable $e) {
            $this->log->error("清理失败", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
