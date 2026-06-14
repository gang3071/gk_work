<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 店家交班记录
 * Class StoreAgentShiftHandoverRecord
 * @property int id 主键
 * @property int department_id 部门/渠道id
 * @property float machine_amount 投钞金额(纸币)
 * @property float machine_point 投钞点数
 * @property float total_in 总收入（送分金额）
 * @property float total_out 总支出（取分金额）
 * @property float lottery_amount 彩金发放金额（TYPE_LOTTERY=13）
 * @property float activity_bonus_amount 活动奖励金额（TYPE_ACTIVITY_BONUS=10）
 * @property float lottery_ticket_reward_amount 摸奖券中奖奖励金额（TYPE_LOTTERY_TICKET_REWARD=33）
 * @property float total_profit_amount 总利润（总收入 - 总支出）
 * @property string start_time 开始时间
 * @property string end_time 结束时间
 * @property int is_auto_shift 是否自动交班（0=手动交班，1=自动交班）
 * @property int auto_shift_log_id 自动交班日志ID（关联 store_auto_shift_log.id，仅自动交班时有值）
 * @property int user_id 審核人員ID
 * @property int bind_player_id 绑定玩家id（旧字段，已废弃）
 * @property int bind_admin_user_id 绑定的AdminUser ID（代理/店家）
 * @property string user_name 審核人員名稱
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminUser $user 管理员
 * @property AdminUser $bindAdminUser 绑定的代理/店家
 * @property Channel $channel 部门/渠道
 * @package app\model
 */
class StoreAgentShiftHandoverRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];
    protected $table = 'store_agent_shift_handover_record';

    /**
     * 管理员用户
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'user_id')->withTrashed();
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'department_id',
            'department_id')->withTrashed();
    }

    /**
     * 绑定的代理/店家
     * @return BelongsTo
     */
    public function bindAdminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'bind_admin_user_id');
    }
}
