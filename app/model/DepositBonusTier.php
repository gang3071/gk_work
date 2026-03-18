<?php

namespace app\model;

use support\Model;

/**
 * 充值满赠档位模型
 * @property int $id 档位ID
 * @property int $activity_id 活动ID
 * @property float $deposit_amount 充值金额
 * @property float $bonus_amount 赠送金额
 * @property float $bonus_ratio 赠送比例（%）
 * @property int $sort_order 排序
 * @property int $status 状态：0=停用,1=启用
 * @property int $created_at 创建时间
 */
class DepositBonusTier extends Model
{
    protected $table = 'deposit_bonus_tier';
    protected $pk = 'id';

    // 状态常量
    const STATUS_DISABLED = 0; // 停用
    const STATUS_ENABLED = 1;  // 启用

    /**
     * 关联活动
     */
    public function activity()
    {
        return $this->belongsTo(DepositBonusActivity::class, 'activity_id', 'id');
    }

    /**
     * 关联订单
     */
    public function orders()
    {
        return $this->hasMany(DepositBonusOrder::class, 'tier_id', 'id');
    }

    /**
     * 计算赠送比例
     */
    public function calculateBonusRatio(): float
    {
        if ($this->deposit_amount <= 0) {
            return 0;
        }

        return round(($this->bonus_amount / $this->deposit_amount) * 100, 2);
    }

    /**
     * 计算需要的押码量
     */
    public function calculateRequiredBet(float $betMultiple): float
    {
        // 押码量 = (充值金额 + 赠送金额) * 押码倍数
        return ($this->deposit_amount + $this->bonus_amount) * $betMultiple;
    }
}
