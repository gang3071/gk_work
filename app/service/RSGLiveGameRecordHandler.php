<?php

namespace app\service;

use support\Redis;
use support\Log;

/**
 * RSGLIVE 游戏记录处理器
 *
 * 背景：
 * - RSGLIVE 同一局游戏（同一 referenceId = deskId-shoe-run）会有多次下注请求
 * - 每次请求有唯一的 transaction.id
 * - RSG Live API 不传递下注类型信息，无法正确聚合同局不同下注
 * - 因此每个 transaction.id 作为独立订单处理
 *
 * 数据流：
 * - Lua 脚本：负责余额扣减/增加 + transaction.id 幂等性
 * - 本处理器：负责游戏记录的独立存储
 * - SyncWorker：从 Redis 同步到 MySQL
 */
class RSGLiveGameRecordHandler
{
    private const PREFIX_BET = 'game:record:bet:';
    private const PREFIX_SYNC_QUEUE = 'game:sync:queue';
    private const TTL_RECORD = 604800; // 7天

    /**
     * 保存下注记录（独立版）
     *
     * 每个 transaction.id 作为独立订单，不再聚合
     *
     * @param array $data 下注数据
     *   - referenceId: 聚合键（现在等于 transactionId）
     *   - transactionId: 交易唯一ID
     *   - player_id, platform_id, amount, game_code 等
     */
    public static function saveBet(array $data): void
    {
        $transactionId = $data['transactionId'];
        $platform = 'RSGLIVE';

        // 每个交易独立的 key
        $recordKey = self::PREFIX_BET . "{$platform}:{$transactionId}";

        $redis = Redis::connection();

        // 保存独立记录
        $record = [
            'platform' => $platform,
            'order_no' => $transactionId,
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

        $redis->hMSet($recordKey, $record);
        $redis->expire($recordKey, self::TTL_RECORD);
        $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $recordKey);

        Log::channel('rsglive_server')->info('RSGLive下注记录创建', [
            'transactionId' => $transactionId,
            'amount' => $data['amount'],
        ]);
    }

    /**
     * 保存结算记录（更新独立记录）
     *
     * @param array $data 结算数据
     *   - referenceId: 现在等于 transactionId
     *   - player_id, platform_id, amount (win), diff
     */
    public static function saveSettle(array $data): void
    {
        $transactionId = $data['referenceId'];  // 现在 referenceId 就是 transactionId
        $platform = 'RSGLIVE';
        $recordKey = self::PREFIX_BET . "{$platform}:{$transactionId}";

        $redis = Redis::connection();
        $exists = $redis->exists($recordKey);

        if ($exists) {
            $betAmount = (float)$redis->hGet($recordKey, 'amount');

            $redis->hMSet($recordKey, [
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

            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $recordKey);

            Log::channel('rsglive_server')->info('RSGLive记录结算', [
                'transactionId' => $transactionId,
                'bet' => $betAmount,
                'win' => $data['amount'],
                'diff' => $data['diff'] ?? bcsub((string)$data['amount'], (string)$betAmount, 2),
            ]);
        } else {
            Log::channel('rsglive_server')->warning('RSGLive记录不存在（结算）', [
                'transactionId' => $transactionId,
            ]);
        }
    }

    /**
     * 保存取消记录（标记独立记录为已取消）
     *
     * @param array $data 取消数据
     *   - referenceId: 现在等于 transactionId
     *   - refund_amount: 退款金额
     *   - player_id, platform_id
     */
    public static function saveCancel(array $data): void
    {
        $transactionId = $data['referenceId'];  // 现在 referenceId 就是 transactionId
        $platform = 'RSGLIVE';
        $recordKey = self::PREFIX_BET . "{$platform}:{$transactionId}";

        $redis = Redis::connection();
        $exists = $redis->exists($recordKey);

        if ($exists) {
            $redis->hMSet($recordKey, [
                'cancel_type' => $data['cancel_type'] ?? 'cancel',
                'cancel_time' => time(),
                'action_data' => json_encode($data['original_data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'balance_before' => $data['balance_before'] ?? '',
                'balance_after' => $data['balance_after'] ?? '',
            ]);

            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $recordKey);

            Log::channel('rsglive_server')->info('RSGLive记录取消', [
                'transactionId' => $transactionId,
                'refund' => $data['refund_amount'] ?? 0,
            ]);
        } else {
            Log::channel('rsglive_server')->warning('RSGLive记录不存在（取消）', [
                'transactionId' => $transactionId,
            ]);
        }
    }
}
