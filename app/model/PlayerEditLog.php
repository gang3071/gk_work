<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 玩家信息编辑日志
 * Class PlayerEditLog
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int department_id 部门/渠道id
 * @property string origin_data 原数据
 * @property string new_data 新数据
 * @property int user_id 操作管理员
 * @property string user_name 管理员名
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminUser $user 管理员
 * @property Player $player 玩家
 * @property Channel $channel 部门/渠道
 * @package app\model
 */
class PlayerEditLog extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.player_edit_log_table'));
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->BelongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.channel_model'), 'department_id', 'department_id')->withTrashed();
    }

    /**
     * 管理员用户
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.user_model'), 'user_id')->withTrashed();
    }
}
