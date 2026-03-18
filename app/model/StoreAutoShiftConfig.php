<?php

namespace app\model;

use Illuminate\Database\Eloquent\SoftDeletes;
use support\Model;

/**
 * 自动交班配置模型
 */
class StoreAutoShiftConfig extends Model
{
    use SoftDeletes;

    protected $table = 'store_auto_shift_config';
    protected $pk = 'id';
    const DELETED_AT = 'deleted_at';

    // 交班模式常量
    const MODE_DAILY = 1;      // 每日
    const MODE_WEEKLY = 2;     // 每周
    const MODE_CUSTOM = 3;     // 自定义周期

    // 状态常量
    const STATUS_NORMAL = 1;   // 正常
    const STATUS_PAUSED = 2;   // 暂停
    const STATUS_ERROR = 3;    // 异常

    /**
     * 关联店家
     */
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    /**
     * 关联绑定玩家（旧方法，已废弃）
     * @deprecated 使用 bindAdminUser() 替代
     */
    public function bindPlayer()
    {
        return $this->belongsTo(Player::class, 'bind_player_id', 'id');
    }

    /**
     * 关联绑定的代理/店家（新方法）
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
     * 获取交班模式文本
     */
    public function getShiftModeTextAttr($value, $data)
    {
        $modes = [
            self::MODE_DAILY => '每日',
            self::MODE_WEEKLY => '每周',
            self::MODE_CUSTOM => '自定义周期'
        ];
        return $modes[$data['shift_mode']] ?? '未知';
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $statuses = [
            self::STATUS_NORMAL => '正常',
            self::STATUS_PAUSED => '暂停',
            self::STATUS_ERROR => '异常'
        ];
        return $statuses[$data['status']] ?? '未知';
    }

    /**
     * 获取每周交班日期数组
     */
    public function getShiftWeekdaysArrayAttr($value, $data)
    {
        if (empty($data['shift_weekdays'])) {
            return [];
        }
        return array_map('intval', explode(',', $data['shift_weekdays']));
    }

    /**
     * 获取启用状态文本
     */
    public function getIsEnabledTextAttr($value, $data)
    {
        return $data['is_enabled'] ? '已启用' : '未启用';
    }
}
