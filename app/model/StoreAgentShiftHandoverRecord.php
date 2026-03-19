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
 * @property float total_profit_amount 总营收
 * @property float total_in 总营收
 * @property float total_out 总营收
 * @property string start_time 开始时间
 * @property string end_time 结束时间
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
