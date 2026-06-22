<?php

namespace process;

use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\service\GameRecordCacheService;
use app\service\HighScoreBroadcastService;
use app\service\MergeBetPlatformHelper;
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

    /**
     * 真人视讯平台代码列表（这些平台不发送高分广播）
     * 根据平台唯一 code 识别
     */
    private const LIVE_CASINO_CODES = [
        'WM',      // WM真人
        'DG',      // DG真人
        'SA',      // SA真人
        'RSGLIVE', // GClub真人
        'MT',      // MT真人
        'O8',      // EEAI真人
        'TNINE',   // TNINE真人
        'KYS',     // KYSport
        'OB',      // OB
        'SPS',     // SPSport
        'SPS_DY',  // SPSport单一钱包
    ];

    /**
     * 执行锁标志
     */
    private bool $isRunning = false;

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

        // 每秒同步记录（保持实时性，避免积压）
        // 性能优化通过 EVALSHA（减少 70% 网络传输）而不是降低频率
        new Crontab('*/1 * * * * *', function () use ($self) {
            $self->syncRecords();
        });
    }

    /**
     * 同步记录（批量处理）
     */
    private function syncRecords(): void
    {
        // ✅ 执行锁：防止重复执行
        if ($this->isRunning) {
            $this->log->debug("上次同步仍在执行，跳过本次");
            return;
        }

        $this->isRunning = true;
        $startTime = microtime(true);

        try {
            // 1. 获取待同步记录
            $records = GameRecordCacheService::getPendingSyncRecords(self::BATCH_SIZE);

            if (empty($records)) {
                // 每10秒记录一次（避免日志刷屏）
                static $lastLogTime = 0;
                if (time() - $lastLogTime >= 10) {
                    $this->log->debug("队列为空，无待同步记录");
                    $lastLogTime = time();
                }
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
        } finally {
            // ✅ 释放执行锁
            $this->isRunning = false;
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

            // 2. 批次内去重：合并同一Redis Key的多次读取
            // ⚠️ 重要：使用redis_key而不是order_no作为去重标识
            // 原因：不同平台的order_no语义不同（T9Slot的gameOrderNumber vs transactionId）
            // Redis Key才是真正的唯一标识
            //
            // 支持场景：
            // - RSG电子：下注→结算（2条记录，同一key）
            // - DG/SA真人：多次下注→结算（N条记录，同一key累加）
            // - T9Slot/QT：子订单（不同key，不会被合并）← 关键！
            $mergedRecords = [];
            foreach ($records as $record) {
                $redisKey = $record['redis_key'] ?? '';  // ← 使用Redis Key去重
                $orderNo = $record['order_no'];  // 仅用于日志

                if (empty($redisKey)) {
                    // 防御性检查：redis_key缺失（不应该发生）
                    $this->log->error('记录缺少redis_key', ['record' => $record]);
                    continue;
                }

                if (!isset($mergedRecords[$redisKey])) {
                    // 首次出现，直接记录
                    $mergedRecords[$redisKey] = $record;
                } else {
                    // ✅ 批次内重复：智能合并同一Redis Key的记录
                    $existing = $mergedRecords[$redisKey];
                    $existingSettled = ($existing['settlement_status'] ?? 0) == 1;
                    $currentSettled = ($record['settlement_status'] ?? 0) == 1;

                    if ($currentSettled) {
                        // ✅ 当前是结算记录 → 直接替换（结算记录包含最终状态）
                        // 无论之前是下注还是结算，都用最新的结算记录
                        $mergedRecords[$redisKey] = $record;

                        $this->log->info('批次内合并：保留结算记录', [
                            'order_no' => $orderNo,
                            'previous_status' => $existingSettled ? '结算' : '下注',
                            'final_settlement_status' => 1,
                        ]);
                    } else {
                        // ✅ 当前是下注记录
                        if ($existingSettled) {
                            // 已存在结算 → 保留结算（不替换）
                            // 这种情况理论上不应该发生（结算应该在最后）
                            $this->log->warning('批次内异常：结算后又读到下注', [
                                'order_no' => $orderNo,
                                'keeping' => '结算记录',
                            ]);
                            // 保留existing（结算记录），不做任何操作
                        } else {
                            // 都是下注记录 → 保留后者（DG/SA同局多次下注，后者有累加后的amount）
                            // Redis中hMSet只更新字段，不覆盖，所以后读到的记录包含累加后的完整数据
                            $mergedRecords[$redisKey] = $record;

                            $this->log->debug('批次内合并：多次下注累加', [
                                'order_no' => $orderNo,
                                'old_amount' => $existing['amount'] ?? 0,
                                'new_amount' => $record['amount'] ?? 0,
                            ]);
                        }
                    }
                }
            }

            // 3. 分组：需要新增 vs 需要更新
            $toInsert = [];
            $toUpdate = [];

            foreach ($mergedRecords as $record) {
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

            // 4.5. ✅ 统一查询新插入记录的完整信息（避免重复查询）
            // 这个查询结果会被彩金检查、高分广播、标记已同步三个功能共享使用
            if (!empty($toInsert)) {
                $insertedOrderNos = array_column($toInsert, 'order_no');
                $newlyInserted = PlayGameRecord::query()
                    ->whereIn('order_no', $insertedOrderNos)
                    ->select('id', 'order_no', 'platform_id', 'player_id', 'department_id', 'bet', 'win', 'original_data')
                    ->get()
                    ->keyBy('order_no');

                // 合并到 $existingRecords，后续方法可以直接使用
                foreach ($newlyInserted as $orderNo => $record) {
                    $existingRecords[$orderNo] = $record;
                }
            }

            // 5. 批量触发彩金检查（使用已更新的 $existingRecords，不再单独查询）
            $this->batchTriggerLottery($toInsert, $toUpdate, $existingRecords);

            // 5.5. 批量触发高分广播检测（使用已更新的 $existingRecords，不再单独查询）
            $this->batchTriggerHighScoreBroadcast($toInsert, $toUpdate, $existingRecords);

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

        // ✅ 从 Redis 缓存记录中补充 balance_before/balance_after 快照
        $records = MergeBetPlatformHelper::enrichInsertRecords($records);

        // 1. 批量查询玩家信息（一次性查询）
        $playerIds = array_unique(array_column($records, 'player_id'));
        $players = Player::query()
            ->whereIn('id', $playerIds)
            ->get()
            ->keyBy('id');

        // 2. 收集需要同步钱包的玩家ID（下注记录且有余额快照）
        $betPlayerIds = [];
        foreach ($records as $record) {
            $snapshot = MergeBetPlatformHelper::getBalanceSnapshot($record);
            if (($record['settlement_status'] ?? 0) == 0
                && ($record['amount'] ?? 0) > 0
                && $snapshot['after'] !== null) {
                $betPlayerIds[] = $record['player_id'];
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
        $deliveryRecords = [];
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
                'balance_before' => $record['balance_before'] ?? null,
                'balance_after' => $record['balance_after'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // 5. 同步钱包余额（使用 Lua 脚本执行时的余额快照，而非当前 Redis 余额）
            $snapshot = MergeBetPlatformHelper::getBalanceSnapshot($record);

            // 🔍 诊断日志（调试级别）
            $this->log->debug('[batchInsertRecords] 快照状态', [
                'order_no' => $record['order_no'],
                'snapshot_before' => $snapshot['before'],
                'snapshot_after' => $snapshot['after'],
            ]);

            if (($record['settlement_status'] ?? 0) == 0
                && ($record['amount'] ?? 0) > 0
                && $snapshot['after'] !== null
                && isset($wallets[$playerId])) {
                $wallet = $wallets[$playerId];
                $beforeBalance = $wallet->money;
                $wallet->money = $snapshot['after'];
                $wallet->save();

                $this->log->info("批量同步钱包余额（快照）", [
                    'player_id' => $playerId,
                    'before' => $beforeBalance,
                    'after' => $wallet->money,
                    'order_no' => $record['order_no'],
                ]);

                // 收集 DeliveryRecord 数据（插入后需要 record ID）
                $deliveryRecords[$record['order_no']] = [
                    'player_id' => $playerId,
                    'department_id' => $player->department_id ?? 0,
                    'platform_id' => $record['platform_id'],
                    'record' => $record,
                ];
            }
        }

        if (empty($insertData)) {
            return 0;
        }

        // 6. 批量插入（一次性插入所有记录）
        try {
            PlayGameRecord::query()->insert($insertData);
        } catch (\Throwable $e) {
            $this->log->error('[batchInsertRecords] 插入失败', [
                'error' => $e->getMessage(),
                'count' => count($insertData),
            ]);
            throw $e;
        }

        $this->log->info("批量插入记录", [
            'count' => count($insertData),
        ]);

        // 7. 批量创建 DeliveryRecord（需要查询新插入记录的 ID）
        if (!empty($deliveryRecords)) {
            $orderNos = array_keys($deliveryRecords);
            $newRecords = PlayGameRecord::query()
                ->whereIn('order_no', $orderNos)
                ->select('id', 'order_no', 'platform_id', 'player_id', 'department_id')
                ->get()
                ->keyBy('order_no');

            foreach ($deliveryRecords as $orderNo => $deliveryData) {
                $gameRecord = $newRecords[$orderNo] ?? null;
                if ($gameRecord) {
                    MergeBetPlatformHelper::createDeliveryFromSnapshot(
                        $deliveryData['player_id'],
                        $deliveryData['platform_id'],
                        $deliveryData['record'],
                        $gameRecord,
                        $deliveryData['department_id']
                    );
                }
            }
        }

        return count($insertData);
    }

    /**
     * 批量更新已存在记录
     */
    private function batchUpdateRecords(array $records, $existingRecords): int
    {
        $updated = 0;

        // 收集需要同步钱包的玩家（合并平台累加下注）
        $walletSyncNeeded = [];

        foreach ($records as $record) {
            $orderNo = $record['order_no'];
            $settlementStatus = $record['settlement_status'] ?? 0;
            $platform = $record['platform'] ?? '';

            /** @var PlayGameRecord $existing */
            $existing = $existingRecords[$orderNo] ?? null;

            if (!$existing) {
                continue;
            }

            $needUpdate = false;
            $balanceUpdated = false;

            // ✅ 合并下注平台：允许未结算状态下更新bet和balance_after（DG/RSGLIVE同局多笔下注累加）
            if (MergeBetPlatformHelper::isMergePlatform($platform) && $settlementStatus == 0) {
                if (MergeBetPlatformHelper::updateMergedBetBalance($existing, $record)) {
                    $needUpdate = true;
                }
                // 更新 balance_after 为最新余额（balance_before 保持首次下注前的值不变）
                if (isset($record['balance_after']) && $record['balance_after'] !== '' && $record['balance_after'] != $existing->balance_after) {
                    $existing->balance_after = $record['balance_after'];
                    $needUpdate = true;
                    $balanceUpdated = true;

                    // 收集钱包同步信息
                    $walletSyncNeeded[$existing->player_id] = [
                        'balance_after' => (float)$record['balance_after'],
                        'order_no' => $orderNo,
                    ];
                }
            }

            // ✅ 更新结算状态（所有平台）
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

                $needUpdate = true;
            }

            if ($needUpdate) {
                $existing->save();
                $updated++;
            }
        }

        // ✅ 批量同步钱包余额（合并平台累加下注后）
        if (!empty($walletSyncNeeded)) {
            $playerIds = array_keys($walletSyncNeeded);
            $wallets = PlayerPlatformCash::query()
                ->whereIn('player_id', $playerIds)
                ->get()
                ->keyBy('player_id');

            foreach ($walletSyncNeeded as $playerId => $syncInfo) {
                $wallet = $wallets[$playerId] ?? null;
                if ($wallet) {
                    $beforeBalance = $wallet->money;
                    $wallet->money = $syncInfo['balance_after'];
                    $wallet->save();

                    $this->log->info("批量更新：同步钱包余额（合并平台累加）", [
                        'player_id' => $playerId,
                        'before' => $beforeBalance,
                        'after' => $wallet->money,
                        'order_no' => $syncInfo['order_no'],
                    ]);
                }
            }
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
        // ✅ 直接从 $existingRecords 获取完整信息（已在 syncBatchRecords 中统一查询）
        foreach ($insertedRecords as $record) {
            if (($record['settlement_status'] ?? 0) == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                $orderNo = $record['order_no'];
                /** @var PlayGameRecord $dbRecord */
                $dbRecord = $existingRecords[$orderNo] ?? null;

                if ($dbRecord && $dbRecord->bet > 0) {
                    $lotteryTriggers[] = [
                        'order_no' => $dbRecord->order_no,
                        'platform_id' => $dbRecord->platform_id,
                        'player_id' => $dbRecord->player_id,
                        'bet' => $dbRecord->bet,
                        'original_data' => $dbRecord->original_data ?? '{}',
                        'record_id' => $dbRecord->id,
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
     * 批量触发高分广播检测
     */
    private function batchTriggerHighScoreBroadcast(array $insertedRecords, array $updatedRecords, $existingRecords): void
    {
        $broadcastTriggers = [];

        // 1. 收集所有需要检查的渠道ID和平台ID
        // ✅ 直接从 $existingRecords 获取完整信息（已在 syncBatchRecords 中统一查询）
        $departmentIds = [];
        $platformIds = [];

        // 从新插入记录中收集
        foreach ($insertedRecords as $record) {
            if (($record['settlement_status'] ?? 0) == PlayGameRecord::SETTLEMENT_STATUS_SETTLED
                && ($record['win'] ?? 0) > 0) {
                $orderNo = $record['order_no'];
                $dbRecord = $existingRecords[$orderNo] ?? null;

                if ($dbRecord) {
                    $departmentIds[] = $dbRecord->department_id ?? 0;
                    $platformIds[] = $dbRecord->platform_id ?? 0;
                }
            }
        }

        // 从已存在记录中获取更新记录的 department_id
        foreach ($updatedRecords as $record) {
            if (($record['settlement_status'] ?? 0) == PlayGameRecord::SETTLEMENT_STATUS_SETTLED
                && ($record['win'] ?? 0) > 0) {
                $existing = $existingRecords[$record['order_no']] ?? null;
                if ($existing) {
                    $departmentIds[] = $existing->department_id ?? 0;
                    $platformIds[] = $existing->platform_id ?? 0;
                }
            }
        }

        if (empty($departmentIds)) {
            return;
        }

        // 4. 批量查询平台信息（通过 code 识别真人视讯平台）
        $platformIds = array_unique(array_filter($platformIds));
        $livePlatformIds = [];
        if (!empty($platformIds)) {
            $platforms = GamePlatform::query()
                ->whereIn('id', $platformIds)
                ->select('id', 'code')
                ->get();

            foreach ($platforms as $platform) {
                // 通过平台唯一 code 判断是否是真人视讯
                if (in_array($platform->code, self::LIVE_CASINO_CODES)) {
                    $livePlatformIds[] = $platform->id;
                    $this->log->debug('真人视讯平台跳过高分广播', [
                        'platform_id' => $platform->id,
                        'platform_code' => $platform->code,
                    ]);
                }
            }
        }

        // 5. 批量获取阈值配置（一次性查询所有渠道）
        $departmentIds = array_unique($departmentIds);
        $thresholds = HighScoreBroadcastService::batchGetThresholds($departmentIds);

        $this->log->info('🔍 高分广播阈值查询结果', [
            'department_ids' => $departmentIds,
            'thresholds' => $thresholds,
            'live_platform_ids' => $livePlatformIds,
        ]);

        // 6. 检查新插入的已结算记录
        // ✅ 使用 $existingRecords 中的完整信息（已包含新插入记录）
        foreach ($insertedRecords as $record) {
            if (($record['settlement_status'] ?? 0) == PlayGameRecord::SETTLEMENT_STATUS_SETTLED
                && ($record['win'] ?? 0) > 0) {
                $orderNo = $record['order_no'];
                /** @var PlayGameRecord $dbRecord */
                $dbRecord = $existingRecords[$orderNo] ?? null;

                if (!$dbRecord) {
                    continue;
                }

                $platformId = $dbRecord->platform_id;

                // 跳过真人视讯平台
                if (in_array($platformId, $livePlatformIds)) {
                    continue;
                }

                $departmentId = $dbRecord->department_id ?? 0;
                $threshold = $thresholds[$departmentId] ?? null;

                $this->log->info('🔍 高分广播新记录检查', [
                    'order_no' => $orderNo,
                    'win' => $dbRecord->win,
                    'threshold' => $threshold,
                    'department_id' => $departmentId,
                    'platform_id' => $platformId,
                    'passed' => ($threshold !== null && $threshold > 0 && $dbRecord->win >= $threshold),
                ]);

                // 只有达到阈值才加入触发列表
                if ($threshold !== null && $threshold > 0 && $dbRecord->win >= $threshold) {
                    $broadcastTriggers[] = [
                        'record_id' => $dbRecord->id,
                        'player_id' => $dbRecord->player_id,
                        'department_id' => $dbRecord->department_id,
                        'win' => $dbRecord->win,
                    ];
                }
            }
        }

        // 7. 检查更新后的已结算记录
        foreach ($updatedRecords as $record) {
            if (($record['settlement_status'] ?? 0) == PlayGameRecord::SETTLEMENT_STATUS_SETTLED
                && ($record['win'] ?? 0) > 0) {
                /** @var PlayGameRecord $existing */
                $existing = $existingRecords[$record['order_no']] ?? null;

                if ($existing) {
                    // 跳过真人视讯平台
                    if (in_array($existing->platform_id, $livePlatformIds)) {
                        continue;
                    }

                    $threshold = $thresholds[$existing->department_id] ?? null;

                    $this->log->info('🔍 高分广播更新记录检查', [
                        'order_no' => $record['order_no'],
                        'existing_win' => $existing->win,
                        'record_win' => $record['win'] ?? null,
                        'threshold' => $threshold,
                        'department_id' => $existing->department_id,
                        'platform_id' => $existing->platform_id,
                        'passed' => ($threshold !== null && $threshold > 0 && $existing->win >= $threshold),
                    ]);

                    // 只有达到阈值才触发
                    if ($threshold !== null && $threshold > 0 && $existing->win >= $threshold) {
                        $broadcastTriggers[] = [
                            'record_id' => $existing->id,
                            'player_id' => $existing->player_id,
                            'department_id' => $existing->department_id,
                            'win' => $existing->win,
                        ];
                    }
                }
            }
        }

        // 8. 批量发送到高分广播队列
        $sentCount = 0;
        foreach ($broadcastTriggers as $trigger) {
            try {
                Client::send('high-score-broadcast', [
                    'record_id' => $trigger['record_id'],
                    'player_id' => $trigger['player_id'],
                    'department_id' => $trigger['department_id'],
                    'win' => $trigger['win'],
                ]);
                $sentCount++;
                $this->log->info('✅ 高分广播队列发送成功', [
                    'record_id' => $trigger['record_id'],
                    'player_id' => $trigger['player_id'],
                    'win' => $trigger['win'],
                ]);
            } catch (\Throwable $e) {
                $this->log->error('❌ 高分广播队列发送失败', [
                    'record_id' => $trigger['record_id'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        if (count($broadcastTriggers) > 0) {
            $this->log->info('🎉 批量触发高分广播', [
                'total' => count($broadcastTriggers),
                'sent' => $sentCount,
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
                $needUpdate = false;
                $platform = $record['platform'] ?? '';

                // ✅ 合并下注平台：允许未结算状态下更新bet和balance_after（DG/RSGLIVE同局多笔下注累加）
                if (MergeBetPlatformHelper::isMergePlatform($platform) && $settlementStatus == 0) {
                    if (MergeBetPlatformHelper::updateMergedBetBalance($existing, $record)) {
                        $needUpdate = true;

                        $this->log->info("{$platform}合并下注：更新累计金额", [
                            'order_no' => $orderNo,
                            'new_bet' => $existing->bet,
                            'record_id' => $existing->id,
                        ]);
                    }

                    // 更新 balance_after 为最新余额（balance_before 保持首次下注前的值不变）
                    if (isset($record['balance_after']) && $record['balance_after'] !== '' && $record['balance_after'] != $existing->balance_after) {
                        $oldBalanceAfter = $existing->balance_after;
                        $existing->balance_after = $record['balance_after'];
                        $needUpdate = true;

                        // 同步钱包余额
                        $snapshot = MergeBetPlatformHelper::getBalanceSnapshot($record);
                        if ($snapshot['after'] !== null) {
                            $wallet = PlayerPlatformCash::query()->where('player_id', $playerId)
                                ->lockForUpdate()
                                ->first();

                            if ($wallet) {
                                $beforeWalletBalance = $wallet->money;
                                $wallet->money = $snapshot['after'];
                                $wallet->save();

                                $this->log->info("{$platform}合并下注：同步钱包余额", [
                                    'player_id' => $playerId,
                                    'before' => $beforeWalletBalance,
                                    'after' => $wallet->money,
                                    'order_no' => $orderNo,
                                ]);
                            }
                        }
                    }
                }

                // ✅ 更新结算状态（所有平台）
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
                    $needUpdate = true;

                    $this->log->info("更新结算记录", [
                        'order_no' => $orderNo,
                        'record_id' => $existing->id,
                    ]);

                    // ✅ 触发彩金检查
                    $this->triggerLotteryCheck($existing);
                }

                if ($needUpdate) {
                    $existing->save();
                }

                // 标记为已同步
                GameRecordCacheService::markAsSynced($record['redis_key'], $existing->id);

            } else {
                // 不存在，创建新记录
                $player = Player::query()->find($playerId);
                if (!$player) {
                    throw new \Exception("玩家不存在: {$playerId}");
                }

                // 2. 钱包同步（使用 Lua 脚本执行时的余额快照，而非当前 Redis 余额）
                $snapshot = MergeBetPlatformHelper::getBalanceSnapshot($record);

                if ($settlementStatus == 0 && ($record['amount'] ?? 0) > 0 && $snapshot['after'] !== null) {
                    /** @var PlayerPlatformCash $wallet */
                    $wallet = PlayerPlatformCash::query()->where('player_id', $playerId)
                        ->lockForUpdate()
                        ->first();

                    if ($wallet) {
                        $beforeBalance = $wallet->money;
                        $wallet->money = $snapshot['after'];
                        $wallet->save();

                        $this->log->info("同步钱包余额（快照）", [
                            'player_id' => $playerId,
                            'before' => $beforeBalance,
                            'after' => $wallet->money,
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

                // 🎉 高分广播检测（2026-05-14 优化：改为异步队列，避免阻塞同步流程）
                // ✅ 优化：提前检查是否达到阈值，减少不必要的队列消息
                // ⚠️ 2026-05-18：排除真人视讯平台，不发送高分广播
                if ($gameRecord->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED
                    && $gameRecord->win > 0) {
                    try {
                        // 检查是否是真人视讯平台（通过 code 识别）
                        $platform = GamePlatform::query()->find($gameRecord->platform_id);
                        if ($platform && in_array($platform->code, self::LIVE_CASINO_CODES)) {
                            // 真人视讯平台不发送高分广播
                            $this->log->debug('真人视讯平台跳过高分广播', [
                                'record_id' => $gameRecord->id,
                                'platform_code' => $platform->code,
                                'win' => $gameRecord->win,
                            ]);
                        } else {
                            // 获取该渠道的高分广播阈值
                            $threshold = HighScoreBroadcastService::getThreshold($gameRecord->department_id);

                            // 只有达到阈值才发送到队列
                            if ($threshold !== null && $threshold > 0 && $gameRecord->win >= $threshold) {
                                Client::send('high-score-broadcast', [
                                    'record_id' => $gameRecord->id,
                                    'player_id' => $gameRecord->player_id,
                                    'department_id' => $gameRecord->department_id,
                                    'win' => $gameRecord->win,
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->log->error('高分广播队列发送失败', [
                            'record_id' => $gameRecord->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // 4. 创建交易记录（使用余额快照）
                MergeBetPlatformHelper::createDeliveryFromSnapshot(
                    $playerId,
                    $platformId,
                    $record,
                    $gameRecord,
                    $player->department_id ?? 0
                );

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

    /**
     * ❌ 已废弃：批量推送余额变化到客户端
     *
     * 原因：现在通过 Redis Pub/Sub 实时推送
     * 位置：RedisLuaScripts::atomicBet/Settle/Cancel 自动触发 publishBalanceChange()
     * 延迟：< 50ms（相比定时推送降低 90%）
     *
     * ⚠️ 保留此方法仅用于兜底或紧急回退，正常情况下不应调用
     *
     * @param array $records Redis 缓存记录数组
     * @param string $reason 推送原因（bet | settle | cancel）
     * @deprecated 已废弃，使用 Redis Pub/Sub 实时推送
     */
    private function batchPushBalanceChanges(array $records, string $reason): void
    {
        $pushCount = 0;
        $skipCount = 0;

        foreach ($records as $record) {
            try {
                $playerId = $record['player_id'] ?? null;
                $platform = $record['platform'] ?? '';

                // ✅ 统一使用 balance_before 和 balance_after 字段
                $oldBalance = $record['balance_before'] ?? '';
                $newBalance = $record['balance_after'] ?? '';

                // 检查是否有余额信息
                if (!$playerId || $oldBalance === '' || $newBalance === '') {
                    $skipCount++;
                    continue;
                }

                // 调用推送服务
                \app\service\BalancePushService::pushBalanceChange(
                    (int)$playerId,
                    (float)$oldBalance,
                    (float)$newBalance,
                    $reason,
                    [
                        'platform' => $platform,
                        'order_no' => $record['order_no'] ?? '',
                    ]
                );

                $pushCount++;

            } catch (\Throwable $e) {
                // 推送失败不影响同步，仅记录日志
                $this->log->warning('余额推送失败', [
                    'order_no' => $record['order_no'] ?? 'unknown',
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($pushCount > 0) {
            $this->log->info('批量推送余额变化', [
                'reason' => $reason,
                'pushed' => $pushCount,
                'skipped' => $skipCount,
            ]);
        }
    }

}
