<?php

namespace app\service;

use app\model\PlayerDeliveryRecord;
use support\Log;

/**
 * 玩家余额推送服务
 *
 * 用于在玩家余额变化时推送实时余额到客户端
 * 使用与 PlayerDeliveryRecord 相同的推送格式，保持系统一致性
 */
class BalancePushService
{
    // 游戏操作类型映射到 PlayerDeliveryRecord 类型
    private const REASON_TYPE_MAP = [
        'bet' => PlayerDeliveryRecord::TYPE_BET,                    // 下注
        'settle' => PlayerDeliveryRecord::TYPE_SETTLEMENT,           // 结算
        'cancel' => PlayerDeliveryRecord::TYPE_CANCEL_BET,          // 取消下注
        'refund' => PlayerDeliveryRecord::TYPE_REFUND,              // 退款
        'prepay' => PlayerDeliveryRecord::TYPE_PREPAY,              // 预扣金额
        'jackpot' => PlayerDeliveryRecord::TYPE_SETTLEMENT,         // Jackpot（视为结算）
        'reward' => PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS,      // 奖励
        'adjust' => PlayerDeliveryRecord::TYPE_RE_SETTLEMENT,       // 调整
    ];

    /**
     * 推送玩家余额变化（使用系统统一格式）
     *
     * @param int $playerId 玩家ID
     * @param float $oldBalance 变化前余额
     * @param float $newBalance 变化后余额
     * @param string $reason 变化原因（bet/settle/cancel/refund等）
     * @param array $extra 额外数据（platform、order_no等）
     * @return bool
     */
    public static function pushBalanceChange(
        int    $playerId,
        float  $oldBalance,
        float  $newBalance,
        string $reason,
        array  $extra = []
    ): bool
    {
        try {
            // 映射操作原因到交易类型
            $type = self::REASON_TYPE_MAP[$reason] ?? PlayerDeliveryRecord::TYPE_SETTLEMENT;

            // ✅ 构建推送数据（与 PlayerDeliveryRecord 模型事件格式完全一致）
            $pushData = [
                'msg_type' => 'player_info',
                'player_id' => $playerId,
                'type' => $type,
                'amount' => bcsub($newBalance, $oldBalance, 2),  // 变化金额
                'amount_before' => $oldBalance,
                'amount_after' => $newBalance,
                'machine_name' => $extra['platform'] ?? '',
                'machine_type' => 0,  // 游戏平台固定为 0
            ];

            // 使用系统统一的推送函数
            $result = sendSocketMessage('player-' . $playerId, $pushData);

            if ($result) {
                Log::debug('游戏余额推送成功', [
                    'player_id' => $playerId,
                    'reason' => $reason,
                    'platform' => $extra['platform'] ?? '',
                    'amount_before' => $oldBalance,
                    'amount_after' => $newBalance,
                ]);
                return true;
            } else {
                Log::warning('游戏余额推送失败', [
                    'player_id' => $playerId,
                    'reason' => $reason,
                ]);
                return false;
            }

        } catch (\Throwable $e) {
            Log::error('游戏余额推送异常', [
                'player_id' => $playerId,
                'reason' => $reason,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * 推送简化余额（仅推送新余额）
     *
     * @param int $playerId
     * @param float $balance
     * @return bool
     */
    public static function pushBalance(int $playerId, float $balance): bool
    {
        try {
            $pushData = [
                'msg_type' => 'player_info',
                'player_id' => $playerId,
                'type' => PlayerDeliveryRecord::TYPE_SETTLEMENT,
                'amount' => 0,
                'amount_before' => $balance,
                'amount_after' => $balance,
                'machine_name' => '',
                'machine_type' => 0,
                'timestamp' => time(),
            ];

            $result = sendSocketMessage('player-' . $playerId, $pushData);

            return (bool)$result;

        } catch (\Throwable $e) {
            Log::error('余额推送异常', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}