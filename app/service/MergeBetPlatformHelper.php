<?php

namespace app\service;

use app\model\PlayGameRecord;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerPlatformCash;
use support\Log;
use support\Redis;

/**
 * 合并模式平台同步辅助类
 *
 * 处理 DG/RSGLIVE 等合并模式平台在 GameRecordSyncWorker 中的特殊逻辑：
 * - 从 Redis 缓存记录中读取 balance_before/balance_after 快照
 * - 合并下注平台的 bet + balance_after 更新
 * - 使用快照创建 PlayerDeliveryRecord
 */
class MergeBetPlatformHelper
{
    /**
     * 合并模式平台列表（同局多笔下注共享 referenceId，金额累加）
     */
    private const MERGE_PLATFORMS = ['DG', 'RSGLIVE'];

    /**
     * 判断是否为合并模式平台
     */
    public static function isMergePlatform(string $platform): bool
    {
        return in_array($platform, self::MERGE_PLATFORMS);
    }

    /**
     * 为待插入记录补充 balance_before/balance_after 快照
     *
     * 所有平台的 Redis 缓存记录都可能包含 balance_before/balance_after，
     * 此方法确保这些快照数据被正确传递到插入逻辑中。
     *
     * @param array $records 待插入的 Redis 缓存记录
     * @return array 补充了 balance 快照的记录
     */
    public static function enrichInsertRecords(array $records): array
    {
        // 收集需要兜底查询的玩家ID（无快照数据的下注记录）
        $fallbackPlayerIds = [];

        foreach ($records as $record) {
            $hasSnapshot = isset($record['balance_before']) && $record['balance_before'] !== ''
                && isset($record['balance_after']) && $record['balance_after'] !== '';

            // 🔍 诊断日志：记录每条记录的快照状态（调试级别）
            Log::channel('game_bet_record')->debug('[enrichInsertRecords] 记录快照状态', [
                'order_no' => $record['order_no'] ?? 'unknown',
                'platform' => $record['platform'] ?? 'unknown',
                'has_snapshot' => $hasSnapshot,
            ]);

            if (!$hasSnapshot
                && ($record['settlement_status'] ?? 0) == PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
                && ($record['amount'] ?? 0) > 0) {
                $fallbackPlayerIds[] = $record['player_id'];
            }
        }

        // 批量读取 Redis 当前余额（仅用于无快照的兜底）
        $redisBalances = [];
        if (!empty($fallbackPlayerIds)) {
            $balanceKeys = array_map(fn($id) => "wallet:balance:{$id}", array_unique($fallbackPlayerIds));
            try {
                $balanceValues = Redis::connection('work')->mGet($balanceKeys);
                foreach (array_unique($fallbackPlayerIds) as $index => $playerId) {
                    if (isset($balanceValues[$index]) && $balanceValues[$index] !== false) {
                        // ✅ 整数化改造：Redis 存储"分"，转换为"元"
                        $balanceInCents = (int)$balanceValues[$index];
                        $redisBalances[$playerId] = round($balanceInCents / 100, 2);
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('game_bet_record')->warning('批量读取 Redis 余额失败', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 补充快照数据
        foreach ($records as &$record) {
            $hasSnapshot = isset($record['balance_before']) && $record['balance_before'] !== ''
                && isset($record['balance_after']) && $record['balance_after'] !== '';

            if ($hasSnapshot) {
                // 已有快照，确保为浮点数
                $record['balance_before'] = (float)$record['balance_before'];
                $record['balance_after'] = (float)$record['balance_after'];

                Log::channel('game_bet_record')->debug('[enrichInsertRecords] 使用已有快照', [
                    'order_no' => $record['order_no'] ?? 'unknown',
                    'balance_before' => $record['balance_before'],
                    'balance_after' => $record['balance_after'],
                ]);
            } elseif (($record['settlement_status'] ?? 0) == PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
                && ($record['amount'] ?? 0) > 0) {
                // 下注记录无快照，通过当前 Redis 余额反推（兜底，可能不准确）
                $playerId = $record['player_id'];
                if (isset($redisBalances[$playerId])) {
                    $record['balance_after'] = $redisBalances[$playerId];
                    $record['balance_before'] = $record['balance_after'] + ($record['amount'] ?? 0);

                    Log::channel('game_bet_record')->warning('[enrichInsertRecords] 兜底：使用当前 Redis 余额反推', [
                        'order_no' => $record['order_no'] ?? 'unknown',
                        'player_id' => $playerId,
                        'redis_balance' => $redisBalances[$playerId],
                        'balance_before' => $record['balance_before'],
                        'balance_after' => $record['balance_after'],
                    ]);
                } else {
                    Log::channel('game_bet_record')->error('[enrichInsertRecords] 无快照且无 Redis 余额', [
                        'order_no' => $record['order_no'] ?? 'unknown',
                        'player_id' => $playerId,
                    ]);
                }
            }
        }
        unset($record); // ✅ 解除引用，避免后续代码意外修改数组

        return $records;
    }

    /**
     * 合并模式平台：更新 bet 累计金额
     *
     * DG/RSGLIVE 同局多笔下注累加，需要更新 bet 为最新累计金额。
     *
     * @param PlayGameRecord $existing 已存在的数据库记录
     * @param array $record Redis 缓存记录（含 amount）
     * @return bool 是否有更新
     */
    public static function updateMergedBetBalance(PlayGameRecord $existing, array $record): bool
    {
        if (isset($record['amount']) && $record['amount'] != $existing->bet) {
            $existing->bet = $record['amount'];
            return true;
        }

        return false;
    }

    /**
     * 使用余额快照创建 PlayerDeliveryRecord
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param array $record Redis 缓存记录（含 balance_before, balance_after, amount, order_no）
     * @param PlayGameRecord $gameRecord 游戏记录
     * @param int $departmentId 渠道ID
     */
    public static function createDeliveryFromSnapshot(
        int $playerId,
        int $platformId,
        array $record,
        PlayGameRecord $gameRecord,
        int $departmentId
    ): void {
        $beforeBalance = $record['balance_before'] ?? null;
        $afterBalance = $record['balance_after'] ?? null;

        if ($beforeBalance === null || $afterBalance === null || $beforeBalance === '' || $afterBalance === '') {
            return;
        }

        $beforeBalance = (float)$beforeBalance;
        $afterBalance = (float)$afterBalance;

        $delivery = new PlayerDeliveryRecord();
        $delivery->player_id = $playerId;
        $delivery->department_id = $departmentId;
        $delivery->target = $gameRecord->getTable();
        $delivery->target_id = $gameRecord->id;
        $delivery->platform_id = $platformId;
        $delivery->type = PlayerDeliveryRecord::TYPE_BET;
        $delivery->source = 'player_bet';
        $delivery->remark = '游戏下注';
        $delivery->amount = (float)($record['amount'] ?? 0);
        $delivery->amount_before = $beforeBalance;
        $delivery->amount_after = $afterBalance;
        $delivery->tradeno = $record['order_no'] ?? '';
        $delivery->user_id = 0;
        $delivery->user_name = '';
        $delivery->save();
    }

    /**
     * 从 Redis 缓存记录读取余额快照，用于钱包同步
     *
     * @param array $record Redis 缓存记录
     * @return array{before: float|null, after: float|null} 余额快照
     */
    public static function getBalanceSnapshot(array $record): array
    {
        $before = $record['balance_before'] ?? null;
        $after = $record['balance_after'] ?? null;

        if ($before !== null && $before !== '' && $after !== null && $after !== '') {
            return ['before' => (float)$before, 'after' => (float)$after];
        }

        return ['before' => null, 'after' => null];
    }
}
