<?php

namespace app\model;

use support\Model;

/**
 * 活动统计模型
 * @property int $id ID
 * @property int $activity_id 活动ID
 * @property int $store_id 店家ID
 * @property int $agent_id 代理ID
 * @property string $stat_date 统计日期
 * @property int $total_participants 参与人数
 * @property int $new_participants 新增参与人数
 * @property int $total_orders 订单总数
 * @property float $total_deposit_amount 充值总金额
 * @property float $total_bonus_amount 赠送总金额
 * @property float $total_bet_amount 总押码量
 * @property float $total_withdraw_amount 已提现金额
 * @property int $completed_orders 完成押码量订单数
 * @property int $expired_orders 过期订单数
 * @property int $cancelled_orders 取消订单数
 * @property int $updated_at 更新时间
 * @property int $created_at 创建时间
 */
class DepositBonusStatistics extends Model
{
    protected $table = 'deposit_bonus_statistics';
    protected $pk = 'id';

    /**
     * 关联活动
     */
    public function activity()
    {
        return $this->belongsTo(DepositBonusActivity::class, 'activity_id', 'id');
    }

    /**
     * 更新或创建统计记录
     */
    public static function updateOrCreateStat(int $activityId, int $storeId, int $agentId = 0, string $date = null): self
    {
        if (!$date) {
            $date = date('Y-m-d');
        }

        $stat = self::where('activity_id', $activityId)
            ->where('store_id', $storeId)
            ->where('agent_id', $agentId)
            ->where('stat_date', $date)
            ->first();

        if (!$stat) {
            $stat = new self();
            $stat->activity_id = $activityId;
            $stat->store_id = $storeId;
            $stat->agent_id = $agentId;
            $stat->stat_date = $date;
            $stat->created_at = time();
        }

        $stat->updated_at = time();
        return $stat;
    }

    /**
     * 重新计算统计数据
     */
    public function recalculate(): void
    {
        $startTime = strtotime($this->stat_date);
        $endTime = $startTime + 86400; // 一天

        // 订单统计
        $orders = DepositBonusOrder::where('activity_id', $this->activity_id)
            ->where('store_id', $this->store_id)
            ->where('agent_id', $this->agent_id)
            ->where('created_at', '>=', $startTime)
            ->where('created_at', '<', $endTime)
            ->get();

        $this->total_orders = $orders->count();
        $this->total_participants = $orders->unique('player_id')->count();
        $this->total_deposit_amount = $orders->sum('deposit_amount');
        $this->total_bonus_amount = $orders->sum('bonus_amount');
        $this->total_bet_amount = $orders->sum('current_bet_amount');

        $this->completed_orders = $orders->where('status', DepositBonusOrder::STATUS_COMPLETED)->count();
        $this->expired_orders = $orders->where('status', DepositBonusOrder::STATUS_EXPIRED)->count();
        $this->cancelled_orders = $orders->where('status', DepositBonusOrder::STATUS_CANCELLED)->count();

        $this->save();
    }
}