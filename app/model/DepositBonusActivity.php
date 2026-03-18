<?php

namespace app\model;

use Illuminate\Database\Eloquent\SoftDeletes;
use support\Model;

/**
 * 充值满赠活动配置模型
 * @property int $id 活动ID
 * @property int $store_id 店家ID
 * @property int $agent_id 代理ID
 * @property string $activity_name 活动名称
 * @property int $activity_type 活动类型：1=充值满赠
 * @property int $start_time 开始时间
 * @property int $end_time 结束时间
 * @property int $status 状态：0=停用,1=启用
 * @property int $unlock_type 解锁类型：1=押码量,2=无机台使用
 * @property float $bet_multiple 押码倍数（针对押码量条件）
 * @property int $valid_days 有效天数
 * @property int $allow_physical_machine 是否允许实体机台：0=否,1=是
 * @property int $require_no_machine 是否要求无使用中机台：0=否,1=是
 * @property int $limit_per_player 每人限制次数：0=不限制
 * @property string $limit_period 限制周期：day=每天,week=每周,month=每月
 * @property string $description 活动说明
 * @property int $created_by 创建人ID
 * @property int $created_at 创建时间
 * @property int $updated_at 更新时间
 * @property int $deleted_at 删除时间
 */
class DepositBonusActivity extends Model
{
    use SoftDeletes;

    protected $table = 'deposit_bonus_activity';
    protected $pk = 'id';
    const DELETED_AT = 'deleted_at';

    // 活动类型常量
    const TYPE_DEPOSIT_BONUS = 1; // 充值满赠

    // 状态常量
    const STATUS_DISABLED = 0; // 停用
    const STATUS_ENABLED = 1;  // 启用

    // 解锁类型常量
    const UNLOCK_TYPE_BET = 1;        // 押码量
    const UNLOCK_TYPE_NO_MACHINE = 2; // 无机台使用

    // 限制周期常量
    const LIMIT_PERIOD_DAY = 'day';
    const LIMIT_PERIOD_WEEK = 'week';
    const LIMIT_PERIOD_MONTH = 'month';

    /**
     * 关联档位
     */
    public function tiers()
    {
        return $this->hasMany(DepositBonusTier::class, 'activity_id', 'id')
            ->where('status', DepositBonusTier::STATUS_ENABLED)
            ->orderBy('sort_order', 'asc');
    }

    /**
     * 关联订单
     */
    public function orders()
    {
        return $this->hasMany(DepositBonusOrder::class, 'activity_id', 'id');
    }

    /**
     * 关联统计
     */
    public function statistics()
    {
        return $this->hasMany(DepositBonusStatistics::class, 'activity_id', 'id');
    }

    /**
     * 检查活动是否有效
     */
    public function isValid(): bool
    {
        if ($this->status != self::STATUS_ENABLED) {
            return false;
        }

        $now = time();
        if ($now < $this->start_time || $now > $this->end_time) {
            return false;
        }

        return true;
    }

    /**
     * 检查玩家是否达到参与次数限制
     */
    public function checkPlayerLimit(int $playerId): bool
    {
        if ($this->limit_per_player == 0) {
            return true; // 不限制
        }

        // 计算时间范围
        $startTime = $this->getStartTimeByPeriod();
        $endTime = time();

        // 查询玩家在周期内的参与次数
        $count = DepositBonusOrder::where('activity_id', $this->id)
            ->where('player_id', $playerId)
            ->where('status', '>', DepositBonusOrder::STATUS_PENDING)
            ->where('created_at', '>=', $startTime)
            ->where('created_at', '<=', $endTime)
            ->count();

        return $count < $this->limit_per_player;
    }

    /**
     * 根据限制周期获取开始时间
     */
    private function getStartTimeByPeriod(): int
    {
        switch ($this->limit_period) {
            case self::LIMIT_PERIOD_WEEK:
                return strtotime('this week');
            case self::LIMIT_PERIOD_MONTH:
                return strtotime('first day of this month');
            case self::LIMIT_PERIOD_DAY:
            default:
                return strtotime('today');
        }
    }
}
