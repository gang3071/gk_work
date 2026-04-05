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
use Webman\RedisQueue\Client;
use Workerman\Crontab\Crontab;
use Workerman\Worker;

/**
 * 游戏记录同步进程
 *
 * 职责：
 * - 定时从 Redis 读取待同步记录
 * - 批量写入 MySQL
 * - 更新同步状态
 *
 * 性能：
 * - 每秒同步 100 条记录
 * - 批量事务处理
 * - 失败自动重试
 */
class GameRecordSyncWorker
{
    /**
     * @var Worker
     */
    private Worker $worker;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $log;

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
            'batch_size' => self::BATCH_SIZE,
        ]);

        // ✅ 显式绑定 $this，避免闭包作用域问题
        $self = $this;

        // 每秒同步记录（6 位 Crontab 格式支持秒级）
        new Crontab('*/1 * * * * *', function () use ($self) {
            $self->syncRecords();
        });
    }

    /**
     * 同步记录（批量处理）
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
            $result = $this->syncBatchRecords($records);

            $elapsed = (microtime(true) - $startTime) * 1000;

            $this->log->info("同步完成", [
                'total' => count($records),
                'inserted' => $result['inserted'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
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
     * 批量同步记录（减少数据库交互）
     */
    private function syncBatchRecords(array $records): array
    {
        $inserted = 0;
        $updated = 0;
        $failed = 0;

        // 开启单个大事务
        Db::beginTransaction();

        try {
            // 1. 批量查询已存在的记录（一次性查询）
            $orderNos = array_column($records, 'order_no');
            $existingRecords = PlayGameRecord::query()
                ->whereIn('order_no', $orderNos)
                ->get()
                ->keyBy('order_no');

            // 2. 分组：需要新增 vs 需要更新
            $toInsert = [];
            $toUpdate = [];

            foreach ($records as $record) {
                $orderNo = $record['order_no'];

                if ($existingRecords->has($orderNo)) {
                    // 已存在，准备更新
                    $toUpdate[] = $record;
                } else {
                    // 不存在，准备插入
                    $toInsert[] = $record;
                }
            }

            // 3. 批量插入新记录
            if (!empty($toInsert)) {
                $inserted = $this->batchInsertRecords($toInsert);
            }

            // 4. 批量更新已存在记录
            if (!empty($toUpdate)) {
                $updated = $this->batchUpdateRecords($toUpdate, $existingRecords);
            }

            // 5. 批量触发彩金检查
            $this->batchTriggerLottery($toInsert, $toUpdate, $existingRecords);

            // 6. 批量标记已同步（需要重新查询以获取新插入记录的ID）
            if (!empty($toInsert)) {
                // 重新查询新插入的记录以获取ID
                $insertedOrderNos = array_column($toInsert, 'order_no');
                $newlyInserted = PlayGameRecord::query()
                    ->whereIn('order_no', $insertedOrderNos)
                    ->get()
                    ->keyBy('order_no');

                // 合并到 $existingRecords
                foreach ($newlyInserted as $orderNo => $record) {
                    $existingRecords[$orderNo] = $record;
                }
            }

            foreach ($records as $record) {
                $orderNo = $record['order_no'];
                $recordId = $existingRecords[$orderNo]->id ?? null;

                if ($recordId) {
                    GameRecordCacheService::markAsSynced($record['redis_key'], $recordId);
                } else {
                    $this->log->warning("无法标记为已同步，记录未找到", [
                        'order_no' => $orderNo,
                        'redis_key' => $record['redis_key'],
                    ]);
                }
            }

            // 提交事务
            Db::commit();

        } catch (\Throwable $e) {
            Db::rollBack();

            // 回退到逐条处理
            $this->log->warning("批量同步失败，回退到逐条处理", [
                'error' => $e->getMessage(),
            ]);

            return $this->syncRecordsFallback($records);
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    /**
     * 批量插入新记录
     */
    private function batchInsertRecords(array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        // 1. 批量查询玩家信息（一次性查询）
        $playerIds = array_unique(array_column($records, 'player_id'));
        $players = Player::query()
            ->whereIn('id', $playerIds)
            ->get()
            ->keyBy('id');

        // 2. 批量读取 Redis 余额（用于钱包同步）
        $betPlayerIds = []; // 需要同步钱包的玩家ID
        foreach ($records as $record) {
            if (($record['settlement_status'] ?? 0) == 0 && ($record['amount'] ?? 0) > 0) {
                $betPlayerIds[] = $record['player_id'];
            }
        }

        $redisBalances = [];
        if (!empty($betPlayerIds)) {
            // 批量读取 Redis 余额
            $balanceKeys = array_map(fn($id) => "wallet:balance:{$id}", array_unique($betPlayerIds));
            $balanceValues = \support\Redis::mGet($balanceKeys);

            foreach (array_unique($betPlayerIds) as $index => $playerId) {
                if (isset($balanceValues[$index]) && $balanceValues[$index] !== false) {
                    $redisBalances[$playerId] = (float)$balanceValues[$index];
                }
            }
        }

        // 3. 批量查询钱包（用于同步）
        $wallets = [];
        if (!empty($betPlayerIds)) {
            $wallets = PlayerPlatformCash::query()
                ->whereIn('player_id', array_unique($betPlayerIds))
                ->get()
                ->keyBy('player_id');
        }

        // 4. 构建插入数据
        $insertData = [];
        $now = Carbon::now()->toDateTimeString();

        foreach ($records as $record) {
            $playerId = $record['player_id'];
            $player = $players[$playerId] ?? null;

            if (!$player) {
                $this->log->warning("玩家不存在，跳过插入", [
                    'player_id' => $playerId,
                    'order_no' => $record['order_no'],
                ]);
                continue;
            }

            $insertData[] = [
                'player_id' => $playerId,
                'parent_player_id' => $player->recommend_id ?? 0,
                'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $player->uuid,
                'department_id' => $player->department_id ?? 0,
                'order_no' => $record['order_no'],
                'platform_id' => $record['platform_id'],
                'bet' => $record['amount'] ?? 0,
                'win' => $record['win'] ?? 0,
                'diff' => $record['diff'] ?? 0,
                'game_code' => $record['game_code'] ?? '',
                'settlement_status' => $record['settlement_status'] ?? 0,
                'order_time' => $record['created_at'] ?? $now,
                'original_data' => $record['original_data'] ?? '{}',
                'action_data' => $record['action_data'] ?? null,
                'platform_action_at' => $record['platform_action_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // 5. 同步钱包余额（从 Redis 同步到 MySQL）
            if (($record['settlement_status'] ?? 0) == 0 && ($record['amount'] ?? 0) > 0) {
                if (isset($redisBalances[$playerId]) && isset($wallets[$playerId])) {
                    $wallet = $wallets[$playerId];
                    $beforeBalance = $wallet->money;
                    $wallet->money = $redisBalances[$playerId];
                    $wallet->save();

                    $this->log->info("批量同步钱包余额", [
                        'player_id' => $playerId,
                        'before' => $beforeBalance,
                        'after' => $wallet->money,
                        'order_no' => $record['order_no'],
                    ]);
                }
            }
        }

        if (empty($insertData)) {
            return 0;
        }

        // 6. 批量插入（一次性插入所有记录）
        PlayGameRecord::query()->insert($insertData);

        $this->log->info("批量插入记录", [
            'count' => count($insertData),
        ]);

        return count($insertData);
    }

    /**
     * 批量更新已存在记录
     */
    private function batchUpdateRecords(array $records, $existingRecords): int
    {
        $updated = 0;

        foreach ($records as $record) {
            $orderNo = $record['order_no'];
            $settlementStatus = $record['settlement_status'] ?? 0;

            if ($settlementStatus != 1) {
                continue;  // 只更新已结算的记录
            }

            /** @var PlayGameRecord $existing */
            $existing = $existingRecords[$orderNo] ?? null;

            if (!$existing) {
                continue;
            }

            // 更新结算状态
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
            $updated++;
        }

        if ($updated > 0) {
            $this->log->info("批量更新记录", [
                'count' => $updated,
            ]);
        }

        return $updated;
    }

    /**
     * 批量触发彩金检查
     */
    private function batchTriggerLottery(array $insertedRecords, array $updatedRecords, $existingRecords): void
    {
        $lotteryTriggers = [];

        // 1. 检查新插入的已结算记录
        foreach ($insertedRecords as $record) {
            if (($record['settlement_status'] ?? 0) == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                if (($record['amount'] ?? 0) > 0) {  // 快速过滤
                    $lotteryTriggers[] = [
                        'order_no' => $record['order_no'],
                        'player_id' => $record['player_id'],
                        'bet' => $record['amount'] ?? 0,
                        'original_data' => $record['original_data'] ?? '{}',
                    ];
                }
            }
        }

        // 2. 检查更新后的已结算记录
        foreach ($updatedRecords as $record) {
            if (($record['settlement_status'] ?? 0) == 1) {
                /** @var PlayGameRecord $existing */
                $existing = $existingRecords[$record['order_no']] ?? null;

                if ($existing && $existing->bet > 0) {
                    $lotteryTriggers[] = [
                        'order_no' => $existing->order_no,
                        'player_id' => $existing->player_id,
                        'bet' => $existing->bet,
                        'original_data' => $existing->original_data ?? '{}',
                        'record_id' => $existing->id,
                    ];
                }
            }
        }

        // 3. 批量发送到彩金队列
        foreach ($lotteryTriggers as $trigger) {
            // 应用过滤规则
            if (!$this->shouldTriggerLotteryFromData($trigger)) {
                continue;
            }

            try {
                Client::send('game-lottery', [
                    'player_id' => $trigger['player_id'],
                    'bet' => $trigger['bet'],
                    'play_game_record_id' => $trigger['record_id'] ?? 0,
                ]);
            } catch (\Throwable $e) {
                $this->log->warning('⚠️ 彩金队列触发失败', [
                    'order_no' => $trigger['order_no'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (count($lotteryTriggers) > 0) {
            $this->log->info('🎰 批量触发彩金检查', [
                'count' => count($lotteryTriggers),
            ]);
        }
    }

    /**
     * 检查是否应该触发彩金（从原始数据）
     */
    private function shouldTriggerLotteryFromData(array $data): bool
    {
        // 1. 下注金额必须大于0
        if (($data['bet'] ?? 0) <= 0) {
            return false;
        }

        // 2. 过滤BTG鱼机游戏
        $originalData = json_decode($data['original_data'] ?? '{}', true);
        if (is_array($originalData)) {
            // 处理关联数组和索引数组两种情况
            $gameType = null;

            // 索引数组：[{...}, {...}]
            if (isset($originalData[0]) && is_array($originalData[0])) {
                $gameType = $originalData[0]['game_type'] ?? null;
            } // 关联数组：{game_type: "fish", ...}
            elseif (isset($originalData['game_type'])) {
                $gameType = $originalData['game_type'];
            }

            if ($gameType === 'fish') {
                return false;
            }
        }

        return true;
    }

    /**
     * 回退到逐条处理（批量失败时）
     */
    private function syncRecordsFallback(array $records): array
    {
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

                GameRecordCacheService::markAsFailed(
                    $record['redis_key'],
                    $e->getMessage()
                );
            }
        }

        return [
            'inserted' => 0,
            'updated' => $synced,
            'failed' => $failed,
        ];
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

                    // ✅ 触发彩金检查
                    $this->triggerLotteryCheck($existing);
                }

                // 标记为已同步
                GameRecordCacheService::markAsSynced($record['redis_key'], $existing->id);

            } else {
                // 不存在，创建新记录
                $player = Player::query()->find($playerId);
                if (!$player) {
                    throw new \Exception("玩家不存在: {$playerId}");
                }

                // 2. 钱包同步（从 Redis 同步到 MySQL）
                // ✅ Lua 脚本已经在 Redis 中扣款，这里只需要同步到 MySQL
                if ($settlementStatus == 0 && ($record['amount'] ?? 0) > 0) {
                    // 从 Redis 读取 Lua 脚本扣款后的最新余额
                    $redisBalance = \support\Redis::get("wallet:balance:{$playerId}");

                    if ($redisBalance !== null && $redisBalance !== false) {
                        /** @var PlayerPlatformCash $wallet */
                        $wallet = PlayerPlatformCash::query()->where('player_id', $playerId)
                            ->lockForUpdate()
                            ->first();

                        if (!$wallet) {
                            throw new \Exception("钱包不存在");
                        }

                        $beforeBalance = $wallet->money;
                        $amount = (float)$record['amount'];

                        // 同步 Redis 余额到 MySQL（不是减法，是直接覆盖）
                        $wallet->money = (float)$redisBalance;
                        $wallet->save();

                        $this->log->info("同步钱包余额", [
                            'player_id' => $playerId,
                            'before' => $beforeBalance,
                            'after' => $wallet->money,
                            'redis_balance' => $redisBalance,
                            'order_no' => $orderNo,
                        ]);
                    } else {
                        // Redis 余额不存在，可能是缓存过期，跳过钱包同步
                        $wallet = null;
                        $beforeBalance = null;
                        $this->log->warning("Redis 余额不存在，跳过钱包同步", [
                            'player_id' => $playerId,
                            'order_no' => $orderNo,
                        ]);
                    }
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

                // ✅ 如果是已结算状态，触发彩金检查
                if ($settlementStatus == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                    $this->triggerLotteryCheck($gameRecord);
                }

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
     * 触发彩金检查
     * 在结算成功后，发送到彩金队列进行抽奖检查
     *
     * @param PlayGameRecord $record
     */
    private function triggerLotteryCheck(PlayGameRecord $record): void
    {
        try {
            // 过滤条件检查
            if (!$this->shouldTriggerLottery($record)) {
                return;
            }

            // 发送到彩金队列
            Client::send('game-lottery', [
                'player_id' => $record->player_id,
                'bet' => $record->bet,
                'play_game_record_id' => $record->id
            ]);

            $this->log->info('🎰 彩金队列已触发', [
                'order_no' => $record->order_no,
                'player_id' => $record->player_id,
                'bet' => $record->bet,
                'record_id' => $record->id
            ]);

        } catch (\Throwable $e) {
            // 彩金触发失败不应阻塞主流程，只记录警告
            $this->log->warning('⚠️ 彩金队列触发失败', [
                'order_no' => $record->order_no,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 检查是否应该触发彩金
     *
     * @param PlayGameRecord $record
     * @return bool
     */
    private function shouldTriggerLottery(PlayGameRecord $record): bool
    {
        // 1. 下注金额必须大于0（过滤免费游戏）
        if ($record->bet <= 0) {
            return false;
        }

        // 2. 必须是已结算状态
        if ($record->settlement_status != PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            return false;
        }

        // 3. 过滤BTG鱼机游戏
        $originalData = json_decode($record->original_data, true);
        if (is_array($originalData)) {
            // 处理关联数组和索引数组两种情况
            $gameType = null;

            // 索引数组：[{...}, {...}]
            if (isset($originalData[0]) && is_array($originalData[0])) {
                $gameType = $originalData[0]['game_type'] ?? null;
            } // 关联数组：{game_type: "fish", ...}
            elseif (isset($originalData['game_type'])) {
                $gameType = $originalData['game_type'];
            }

            if ($gameType === 'fish') {
                return false; // BTG鱼机游戏不参与彩金
            }
        }

        return true;
    }

}
