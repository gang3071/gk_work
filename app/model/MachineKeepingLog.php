<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class MachineKeepingLog
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int machine_id 机台id
 * @property int department_id 部门/渠道id
 * @property string machine_name 机台名称
 * @property int keep_seconds 每保留时长
 * @property int status 1 进行中 2 已结束
 * @property int is_system 是否系统
 * @property int user_id 管理员id
 * @property string user_name 管理员名称
 * @property string remark 备注
 * @property string updated_at 最后一次修改时间
 * @property string created_at 创建时间
 *
 * @property Player $player
 * @property Machine $machine
 * @property AdminUser $user
 * @property Channel $channel 部门/渠道
 * @package app\model
 */
class MachineKeepingLog extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const STATUS_STAR = 1; // 进行中
    const STATUS_END = 2; // 已结束
    protected $table = 'machine_keeping_log';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id')->withTrashed();
    }

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
        return $this->belongsTo(Channel::class, 'department_id', 'department_id')->withTrashed();
    }
}
