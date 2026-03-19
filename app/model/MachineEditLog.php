<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 机台异动日志
 * Class MachineEditLog
 * @property int id 主键
 * @property int machine_id 机台id
 * @property int department_id 部门/渠道id
 * @property int source 来源
 * @property string origin_data 原数据
 * @property string new_data 新数据
 * @property int user_id 操作管理员
 * @property string user_name 管理员名
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminUser $user 管理员
 * @property Machine $machine 机台
 * @property Channel $channel 部门/渠道
 * @package app\model
 */
class MachineEditLog extends Model
{
    use HasDateTimeFormatter;

    const SOURCE_MACHINE = 1; // 来源机台表
    const SOURCE_MEDIA = 2; // 来源媒体表
    protected $table = 'machine_edit_log';

    /**
     * 机台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->BelongsTo(Machine::class, 'machine_id')->withTrashed();
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'department_id', 'department_id')->withTrashed();
    }

    /**
     * 管理员用户
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'user_id')->withTrashed();
    }
}
