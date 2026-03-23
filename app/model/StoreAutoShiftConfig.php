<?php

namespace app\model;

use Illuminate\Database\Eloquent\SoftDeletes;
use support\Model;

/**
 * 自动交班配置模型
 *
 * @property int $id 主键ID
 * @property int $department_id 部门/渠道ID
 * @property int $bind_admin_user_id 绑定的管理员用户ID（代理/店家）
 * @property int $is_enabled 是否启用（0=未启用，1=已启用）
 * @property string $shift_time_1 早班交班时间（08:00）
 * @property string $shift_time_2 中班交班时间（16:00）
 * @property string $shift_time_3 晚班交班时间（00:00）
 * @property int $auto_settlement 是否自动结算（0=否，1=是）
 * @property string|null $last_shift_time 上次交班时间
 * @property string|null $next_shift_time 下次交班时间（系统自动计算）
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string|null $deleted_at 软删除时间
 */
class StoreAutoShiftConfig extends Model
{
    use SoftDeletes;

    protected $table = 'store_auto_shift_config';
    protected $pk = 'id';
    const DELETED_AT = 'deleted_at';

    /**
     * 关联店家
     */
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    /**
     * 关联绑定的代理/店家
     */
    public function bindAdminUser()
    {
        return $this->belongsTo(\app\model\AdminUser::class, 'bind_admin_user_id', 'id');
    }

    /**
     * 关联执行日志
     */
    public function logs()
    {
        return $this->hasMany(StoreAutoShiftLog::class, 'config_id', 'id');
    }

    /**
     * 获取启用状态文本
     */
    public function getIsEnabledTextAttr($value, $data)
    {
        return $data['is_enabled'] ? '已启用' : '未启用';
    }
}
