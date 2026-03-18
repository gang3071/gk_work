<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class MachineKickLog
 * @property int id 主键
 * @property int player_id 推荐id
 * @property int machine_id 类型
 * @property int platform_id 状态
 * @property float wash_point 下分分數
 * @property float before_game_amount 遊戲錢包餘額異動前
 * @property float after_game_amount 遊戲錢包餘額異動後
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player
 * @property Machine machine
 * @package app\model
 */
class MachineKickLog extends Model
{
    use HasDateTimeFormatter;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.machine_kick_log_table'));
    }

    /**
     * 玩家信息
     * @return
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 玩家信息
     * @return
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_model'), 'machine_id')->withTrashed();
    }

    public function getCreatedAtAttribute($date)
    {
        return date('Y-m-d H:i:s', strtotime($date));
    }

    public function getUpdatedAtAttribute($date)
    {
        return date('Y-m-d H:i:s', strtotime($date));
    }
}
