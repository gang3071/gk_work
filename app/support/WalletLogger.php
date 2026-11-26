<?php

namespace app\support;

use support\Log;

/**
 * 玩家钱包操作统一日志记录器
 *
 * 输出标准 JSON Lines 到 wallet_ops 通道，供 Vector 采集写入 ClickHouse。
 *
 * 操作类型（operation_type）：
 *   deduct | add | atomic_wash | atomic_increment | atomic_decrement
 *
 * 原因（reason）：
 *   machine_open | machine_wash | machine_refund
 *   lottery | game_win | game_bet | game_transfer | game_refund
 *   deposit_bonus | settle | refund | unknown
 */
class WalletLogger
{
    // operation_type
    const OP_DEDUCT = 'deduct';
    const OP_ADD = 'add';
    const OP_ATOMIC_INCREMENT = 'atomic_increment';
    const OP_ATOMIC_DECREMENT = 'atomic_decrement';
    const OP_ATOMIC_WASH = 'atomic_wash';

    // reason
    const REASON_MACHINE_OPEN = 'machine_open';
    const REASON_MACHINE_WASH = 'machine_wash';
    const REASON_MACHINE_REFUND = 'machine_refund';
    const REASON_LOTTERY = 'lottery';
    const REASON_GAME_WIN = 'game_win';
    const REASON_GAME_BET = 'game_bet';
    const REASON_GAME_TRANSFER = 'game_transfer';
    const REASON_GAME_REFUND = 'game_refund';
    const REASON_DEPOSIT_BONUS = 'deposit_bonus';
    const REASON_SETTLE = 'settle';
    const REASON_REFUND = 'refund';
    const REASON_UNKNOWN = 'unknown';

    private function __construct() {}

    /**
     * 记录钱包操作日志
     *
     * @param int    $playerId      玩家ID
     * @param string $operationType 操作类型（OP_* 常量）
     * @param string $reason        原因（REASON_* 常量）
     * @param float  $amount        操作金额（元）
     * @param float  $oldBalance    操作前余额（元）
     * @param float  $newBalance    操作后余额（元）
     * @param bool   $success       是否成功
     * @param array  $extra         扩展字段：
     *                               error_code    — 错误码
     *                               machine_id    — 机台ID（机台操作时传入）
     *                               platform      — 平台标识
     *                               [其他自定义键值]
     */
    public static function log(
        int    $playerId,
        string $operationType,
        string $reason,
        float  $amount,
        float  $oldBalance,
        float  $newBalance,
        bool   $success,
        array  $extra = []
    ): void {
        $context = [
            'player_id'      => $playerId,
            'operation_type' => $operationType,
            'reason'         => $reason ?: self::REASON_UNKNOWN,
            'amount'         => round($amount, 2),
            'old_balance'    => round($oldBalance, 2),
            'new_balance'    => round($newBalance, 2),
            'success'        => $success ? 1 : 0,
            'error_code'     => (string)($extra['error_code'] ?? ''),
            'machine_id'     => (int)($extra['machine_id'] ?? 0),
            'platform'       => (string)($extra['platform'] ?? ''),
        ];

        // 其余自定义字段序列化到 extra_data
        static $knownKeys = ['error_code', 'machine_id', 'platform'];
        $custom = array_diff_key($extra, array_flip($knownKeys));
        $context['extra_data'] = empty($custom) ? '' : (json_encode($custom, JSON_UNESCAPED_UNICODE) ?: '');

        if (!$success) {
            Log::channel('wallet_ops')->warning($operationType, $context);
        } else {
            Log::channel('wallet_ops')->info($operationType, $context);
        }
    }
}
