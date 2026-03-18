<?php

namespace app\model;

use support\Model;

/**
 * 玩家押码量任务模型
 * @property int $id ID
 * @property int $player_id 玩家ID
 * @property int $store_id 店家ID
 * @property int $agent_id 代理ID
 * @property int $order_id 关联订单ID
 * @property float $required_bet_amount 需要完成的押码量
 * @property float $current_bet_amount 当前已完成押码量
 * @property float $bet_progress 完成进度（%）
 * @property int $status 状态：0=进行中,1=已完成,2=已过期
 * @property int $expires_at 有效期截止时间
 * @property int $completed_at 完成时间
 * @property int $created_at 创建时间
 * @property int $updated_at 更新时间
 */
class PlayerBonusTask extends Model
{
    protected $table = 'player_bonus_task';
    protected $pk = 'id';

    // 状态常量
    const STATUS_IN_PROGRESS = 0; // 进行中
    const STATUS_COMPLETED = 1;   // 已完成
    const STATUS_EXPIRED = 2;     // 已过期

    /**
     * 关联玩家
     */
    public function player()
    {
        return $this->belongsTo(\app\model\Player::class, 'player_id', 'id');
    }

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(DepositBonusOrder::class, 'order_id', 'id');
    }

    /**
     * 检查是否已过期
     */
    public function isExpired(): bool
    {
        return $this->expires_at < time();
    }

    /**
     * 检查是否已完成
     */
    public function isCompleted(): bool
    {
        return $this->current_bet_amount >= $this->required_bet_amount;
    }

    /**
     * 更新押码进度
     */
    public function updateProgress(float $betAmount): void
    {
        $this->current_bet_amount += $betAmount;

        if ($this->required_bet_amount > 0) {
            $this->bet_progress = round(($this->current_bet_amount / $this->required_bet_amount) * 100, 2);
        }

        $this->updated_at = time();
        $this->save();
    }

    /**
     * 完成任务
     */
    public function complete(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = time();
        $this->updated_at = time();
        $this->save();
    }

    /**
     * 设置为过期
     */
    public function expire(): void
    {
        $this->status = self::STATUS_EXPIRED;
        $this->updated_at = time();
        $this->save();
    }

    /**
     * 获取剩余押码量
     */
    public function getRemainingBetAmount(): float
    {
        $remaining = $this->required_bet_amount - $this->current_bet_amount;
        return max(0, $remaining);
    }

    /**
     * 获取剩余天数
     */
    public function getRemainingDays(): int
    {
        $remaining = $this->expires_at - time();
        return max(0, ceil($remaining / 86400));
    }
}
