<?php

namespace app\model;

use support\Model;

/**
 * 充值赠送订单模型
 * @property int $id 订单ID
 * @property string $order_no 订单编号
 * @property int $activity_id 活动ID
 * @property int $tier_id 档位ID
 * @property int $store_id 店家ID
 * @property int $agent_id 代理ID
 * @property int $player_id 玩家ID
 * @property float $deposit_amount 充值金额
 * @property float $bonus_amount 赠送金额
 * @property float $required_bet_amount 需要完成的押码量
 * @property float $current_bet_amount 当前已完成押码量
 * @property float $bet_progress 押码进度（%）
 * @property string $qrcode_token 二维码令牌
 * @property string $qrcode_url 二维码图片URL
 * @property int $qrcode_expires_at 二维码过期时间
 * @property int $status 订单状态：0=待核销,1=已核销,2=已完成,3=已过期,4=已取消
 * @property int $verified_at 核销时间
 * @property int $verified_by 核销人ID
 * @property int $completed_at 完成时间（押码量达标）
 * @property int $expires_at 有效期截止时间
 * @property int $created_by 创建人ID
 * @property int $created_at 创建时间
 * @property int $updated_at 更新时间
 * @property string $remark 备注
 */
class DepositBonusOrder extends Model
{
    protected $table = 'deposit_bonus_order';
    protected $pk = 'id';

    // 订单状态常量
    const STATUS_PENDING = 0;    // 待核销
    const STATUS_VERIFIED = 1;   // 已核销（押码中）
    const STATUS_COMPLETED = 2;  // 已完成（押码量达标）
    const STATUS_EXPIRED = 3;    // 已过期
    const STATUS_CANCELLED = 4;  // 已取消

    /**
     * 关联活动
     */
    public function activity()
    {
        return $this->belongsTo(DepositBonusActivity::class, 'activity_id', 'id');
    }

    /**
     * 关联档位
     */
    public function tier()
    {
        return $this->belongsTo(DepositBonusTier::class, 'tier_id', 'id');
    }

    /**
     * 关联玩家（使用addons中的Player模型）
     */
    public function player()
    {
        return $this->belongsTo(\app\model\Player::class, 'player_id', 'id');
    }

    /**
     * 关联押码量任务
     */
    public function task()
    {
        return $this->hasOne(PlayerBonusTask::class, 'order_id', 'id');
    }

    /**
     * 关联押码量明细
     */
    public function betDetails()
    {
        return $this->hasMany(DepositBonusBetDetail::class, 'order_id', 'id');
    }

    /**
     * 关联账变记录
     */
    public function moneyLogs()
    {
        return $this->hasMany(PlayerMoneyEditLogBonus::class, 'order_id', 'id');
    }

    /**
     * 生成订单编号
     */
    public static function generateOrderNo(): string
    {
        return 'DB' . date('YmdHis') . rand(1000, 9999);
    }

    /**
     * 检查订单是否可以核销
     */
    public function canVerify(): bool
    {
        if ($this->status != self::STATUS_PENDING) {
            return false;
        }

        if ($this->qrcode_expires_at && $this->qrcode_expires_at < time()) {
            return false;
        }

        return true;
    }

    /**
     * 检查订单是否已过期
     */
    public function isExpired(): bool
    {
        if ($this->expires_at && $this->expires_at < time()) {
            return true;
        }

        return false;
    }

    /**
     * 更新押码进度
     */
    public function updateBetProgress(float $betAmount): void
    {
        $this->current_bet_amount += $betAmount;

        if ($this->required_bet_amount > 0) {
            $this->bet_progress = round(($this->current_bet_amount / $this->required_bet_amount) * 100, 2);
        }

        $this->save();
    }

    /**
     * 检查是否完成押码量
     */
    public function isBetCompleted(): bool
    {
        return $this->current_bet_amount >= $this->required_bet_amount;
    }

    /**
     * 获取状态文本
     */
    public function getStatusText(): string
    {
        $statusMap = [
            self::STATUS_PENDING => '待核销',
            self::STATUS_VERIFIED => '已核销',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_EXPIRED => '已过期',
            self::STATUS_CANCELLED => '已取消',
        ];

        return $statusMap[$this->status] ?? '未知';
    }
}
