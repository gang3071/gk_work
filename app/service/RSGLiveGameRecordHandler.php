<?php

namespace app\service;

use support\Redis;
use support\Log;

/**
 * RSGLIVE 游戏记录聚合处理器
 *
 * 背景：
 * - RSGLIVE 同一局游戏（同一 referenceId = deskId-shoe-run）会有多次下注请求
 * - 每次请求有唯一的 transaction.id
 * - RSG Live API 不传递下注类型信息，同局所有下注累加到同一聚合记录
 *
 * 数据流：
 * - Lua 脚本：负责余额扣减/增加 + transaction.id 幂等性（不关心游戏记录）
 * - 本处理器：负责游戏记录的聚合存储（referenceId 聚合）
 * - SyncWorker：从 Redis 同步到 MySQL（无感知）
 */
class RSGLiveGameRecordHandler
{
    private const PREFIX_BET = 'game:record:bet:';
    private const PREFIX_SYNC_QUEUE = 'game:sync:queue';
    private const TTL_RECORD = 604800; // 7天

    /**
     * 保存下注记录（聚合版）
     *
     * - 首次下注：创建聚合记录
     * - 后续下注：累加金额
     * - 清理 Lua 创建的单条记录和队列条目
     *
     * @param array $data 下注数据
     *   - referenceId: 聚合键（deskId-shoe-run）
     *   - transactionId: 交易唯一ID（Lua 幂等性用）
     *   - player_id, platform_id, amount, game_code 等
     */
    public static function saveBet(array $data): void
    {
        $referenceId = $data['referenceId'];
        $transactionId = $data['transactionId'];
        $platform = 'RSGLIVE';

        // 聚合记录 key（按 referenceId 聚合）
        $aggKey = self::PREFIX_BET . "{$platform}:{$referenceId}";
        // Lua 创建的单条记录 key
        $individualKey = self::PREFIX_BET . "{$platform}:{$transactionId}";

        $redis = Redis::connection();
        $exists = $redis->exists($aggKey);

        if (!$exists) {
            // 首次下注：创建聚合记录
            $record = [
                'platform' => $platform,
                'order_no' => $referenceId,
                'player_id' => $data['player_id'],
                'platform_id' => $data['platform_id'],
                'amount' => $data['amount'],
                'game_code' => $data['game_code'] ?? '',
                'game_type' => '',
                'game_name' => $data['game_name'] ?? '',
                'transaction_type' => $data['transaction_type'] ?? 'bet',
                'bet_time' => time(),
                'original_data' => json_encode($data['original_data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'settlement_status' => 0,
                'win' => 0,
                'diff' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'balance_before' => $data['balance_before'] ?? '',
                'balance_after' => $data['balance_after'] ?? '',
            ];

            $redis->hMSet($aggKey, $record);
            $redis->expire($aggKey, self::TTL_RECORD);
            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $aggKey);

            Log::channel('rsglive_server')->info('RSGLive聚合记录创建', [
                'referenceId' => $referenceId,
                'amount' => $data['amount'],
            ]);
        } else {
            // 后续下注：累加金额
            $redis->hIncrByFloat($aggKey, 'amount', $data['amount']);
            $redis->hSet($aggKey, 'status', 'pending');
            $redis->hSet($aggKey, 'original_data', json_encode($data['original_data'] ?? [], JSON_UNESCAPED_UNICODE));
            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $aggKey);

            $newAmount = $redis->hGet($aggKey, 'amount');
            Log::channel('rsglive_server')->info('RSGLive聚合记录累加', [
                'referenceId' => $referenceId,
                'added' => $data['amount'],
                'total' => $newAmount,
            ]);
        }

        // 从同步队列移除 Lua 创建的单条记录（保留 Redis 数据供 settle/cancel 的 Lua 脚本使用）
        $redis->zRem(self::PREFIX_SYNC_QUEUE, $individualKey);
    }

    /**
     * 保存结算记录（更新聚合记录）
     *
     * @param array $data 结算数据
     *   - referenceId: 聚合键
     *   - player_id, platform_id, amount (win), diff
     */
    public static function saveSettle(array $data): void
    {
        $referenceId = $data['referenceId'];
        $platform = 'RSGLIVE';
        $aggKey = self::PREFIX_BET . "{$platform}:{$referenceId}";

        $redis = Redis::connection();
        $exists = $redis->exists($aggKey);

        if ($exists) {
            $betAmount = (float)$redis->hGet($aggKey, 'amount');

            $redis->hMSet($aggKey, [
                'win' => $data['amount'],
                'diff' => $data['diff'] ?? bcsub((string)$data['amount'], (string)$betAmount, 2),
                'settlement_status' => 1,
                'settle_type' => $data['settle_type'] ?? 'settle',
                'settle_time' => time(),
                'platform_action_at' => date('Y-m-d H:i:s'),
                'action_data' => json_encode($data['original_data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'balance_before' => $data['balance_before'] ?? '',
                'balance_after' => $data['balance_after'] ?? '',
            ]);

            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $aggKey);

            Log::channel('rsglive_server')->info('RSGLive聚合记录结算', [
                'referenceId' => $referenceId,
                'bet' => $betAmount,
                'win' => $data['amount'],
                'diff' => $data['diff'] ?? bcsub((string)$data['amount'], (string)$betAmount, 2),
            ]);
        } else {
            Log::channel('rsglive_server')->warning('RSGLive聚合记录不存在（结算）', [
                'referenceId' => $referenceId,
            ]);
        }
    }

    /**
     * 保存取消记录（减少聚合记录金额）
     *
     * @param array $data 取消数据
     *   - referenceId: 聚合键
     *   - refund_amount: 退款金额
     *   - player_id, platform_id
     */
    public static function saveCancel(array $data): void
    {
        $referenceId = $data['referenceId'];
        $platform = 'RSGLIVE';
        $aggKey = self::PREFIX_BET . "{$platform}:{$referenceId}";

        $redis = Redis::connection();
        $exists = $redis->exists($aggKey);

        if ($exists) {
            // 减少聚合金额
            $currentAmount = (float)$redis->hGet($aggKey, 'amount');
            $refundAmount = (float)($data['refund_amount'] ?? 0);
            $newAmount = max(0, bcsub((string)$currentAmount, (string)$refundAmount, 2));

            $redis->hMSet($aggKey, [
                'amount' => $newAmount,
                'cancel_type' => $data['cancel_type'] ?? 'cancel',
                'cancel_time' => time(),
                'action_data' => json_encode($data['original_data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'balance_before' => $data['balance_before'] ?? '',
                'balance_after' => $data['balance_after'] ?? '',
            ]);

            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $aggKey);

            Log::channel('rsglive_server')->info('RSGLive聚合记录取消', [
                'referenceId' => $referenceId,
                'refund' => $refundAmount,
                'amount_before' => $currentAmount,
                'amount_after' => $newAmount,
            ]);
        } else {
            Log::channel('rsglive_server')->warning('RSGLive聚合记录不存在（取消）', [
                'referenceId' => $referenceId,
            ]);
        }
    }
}
