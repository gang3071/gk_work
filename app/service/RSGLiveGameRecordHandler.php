<?php

namespace app\service;

use support\Log;
use support\Redis;

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

        // ✅ 诊断日志：记录传入的 balance 值
        Log::channel('rsglive_server')->info('[saveBet] 传入数据', [
            'referenceId' => $referenceId,
            'transactionId' => $transactionId,
            'exists' => $exists,
            'balance_before' => $data['balance_before'] ?? 'null',
            'balance_after' => $data['balance_after'] ?? 'null',
            'amount' => $data['amount'],
        ]);

        if (!$exists) {
            // 首次下注：创建聚合记录
            // 🎯 单位转换：Controller传入的是"元"，Redis必须存"分"（整数）
            $amountInCents = (int)round($data['amount'] * 100);
            $balanceBeforeInCents = isset($data['balance_before']) && $data['balance_before'] !== ''
                ? (int)round($data['balance_before'] * 100) : '';
            $balanceAfterInCents = isset($data['balance_after']) && $data['balance_after'] !== ''
                ? (int)round($data['balance_after'] * 100) : '';

            $record = [
                'platform' => $platform,
                'order_no' => $referenceId,
                'player_id' => $data['player_id'],
                'platform_id' => $data['platform_id'],
                'amount' => $amountInCents,
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
                'balance_before' => $balanceBeforeInCents,
                'balance_after' => $balanceAfterInCents,
            ];

            $redis->hMSet($aggKey, $record);
            $redis->expire($aggKey, self::TTL_RECORD);
            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $aggKey);

            Log::channel('rsglive_server')->info('RSGLive聚合记录创建', [
                'referenceId' => $referenceId,
                'amount_yuan' => $data['amount'],
                'amount_cents' => $amountInCents,
                'balance_before_cents' => $balanceBeforeInCents,
                'balance_after_cents' => $balanceAfterInCents,
            ]);
        } else {
            // 后续下注：累加金额 + 更新 balance_after（记录最新余额）
            // 🎯 单位转换：Controller传入的是"元"，需要转换为"分"再累加
            $addAmountInCents = (int)round($data['amount'] * 100);
            $balanceAfterInCents = isset($data['balance_after']) && $data['balance_after'] !== ''
                ? (int)round($data['balance_after'] * 100) : '';

            $oldBalanceAfter = $redis->hGet($aggKey, 'balance_after');
            $redis->hIncrBy($aggKey, 'amount', $addAmountInCents);  // 使用整数累加
            $redis->hSet($aggKey, 'status', 'pending');
            $redis->hSet($aggKey, 'original_data', json_encode($data['original_data'] ?? [], JSON_UNESCAPED_UNICODE));
            // ✅ 更新 balance_after 为最新余额（balance_before 保持首次下注前的值不变）
            if ($balanceAfterInCents !== '') {
                $redis->hSet($aggKey, 'balance_after', $balanceAfterInCents);
            }
            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $aggKey);

            $newAmount = $redis->hGet($aggKey, 'amount');
            $newBalanceAfter = $redis->hGet($aggKey, 'balance_after');
            Log::channel('rsglive_server')->info('RSGLive聚合记录累加', [
                'referenceId' => $referenceId,
                'added_yuan' => $data['amount'],
                'added_cents' => $addAmountInCents,
                'total_cents' => $newAmount,
                'input_balance_after_yuan' => $data['balance_after'] ?? 'null',
                'balance_after_cents' => $balanceAfterInCents,
                'old_balance_after_cents' => $oldBalanceAfter,
                'new_balance_after_cents' => $newBalanceAfter,
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
            // 🎯 单位转换：Controller传入的是"元"，Redis必须存"分"（整数）
            $winInCents = (int)round($data['amount'] * 100);

            // 优先使用 Controller 传入的 diff（已正确计算）
            // 只有在未提供时才从 Redis 读取 amount 计算
            if (isset($data['diff'])) {
                $diffInCents = (int)round($data['diff'] * 100);
            } else {
                // 从 Redis 读取 bet（"分"），计算 diff
                $betAmountInCents = (int)$redis->hGet($aggKey, 'amount');
                $diffInCents = $winInCents - $betAmountInCents;
            }

            $redis->hMSet($aggKey, [
                'win' => $winInCents,
                'diff' => $diffInCents,
                'settlement_status' => 1,
                'settle_type' => $data['settle_type'] ?? 'settle',
                'settle_time' => time(),
                'platform_action_at' => date('Y-m-d H:i:s'),
                'action_data' => json_encode($data['original_data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                // ✅ 不覆盖 balance_before/after — 保持下注时的余额快照
            ]);

            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $aggKey);

            Log::channel('rsglive_server')->info('RSGLive聚合记录结算', [
                'referenceId' => $referenceId,
                'win_yuan' => $data['amount'],
                'win_cents' => $winInCents,
                'diff_yuan' => isset($data['diff']) ? $data['diff'] : round($diffInCents / 100, 2),
                'diff_cents' => $diffInCents,
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
            // 🎯 单位转换：Redis存储的是"分"，Controller传入的是"元"
            // 需要将两者统一为"分"进行计算
            $currentAmountInCents = (int)$redis->hGet($aggKey, 'amount');
            $refundAmountInYuan = (float)($data['refund_amount'] ?? 0);
            $refundAmountInCents = (int)round($refundAmountInYuan * 100);
            $newAmountInCents = max(0, $currentAmountInCents - $refundAmountInCents);

            $redis->hMSet($aggKey, [
                'amount' => $newAmountInCents,
                'cancel_type' => $data['cancel_type'] ?? 'cancel',
                'cancel_time' => time(),
                'action_data' => json_encode($data['original_data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                // ✅ 不覆盖 balance_before/after — 保持下注时的余额快照
            ]);

            $redis->zAdd(self::PREFIX_SYNC_QUEUE, time(), $aggKey);

            Log::channel('rsglive_server')->info('RSGLive聚合记录取消', [
                'referenceId' => $referenceId,
                'refund_yuan' => $refundAmountInYuan,
                'refund_cents' => $refundAmountInCents,
                'amount_before_cents' => $currentAmountInCents,
                'amount_after_cents' => $newAmountInCents,
            ]);
        } else {
            Log::channel('rsglive_server')->warning('RSGLive聚合记录不存在（取消）', [
                'referenceId' => $referenceId,
            ]);
        }
    }
}
