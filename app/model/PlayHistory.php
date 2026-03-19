<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class PlayerBank
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int machine_id 玩家id
 * @property string ip ip地址
 * @property int department_id 玩家id
 * @property string created_at 创建时间
 * @property Player player 玩家
 * @property Machine machine 机台
 * @package app\model
 */
class PlayHistory extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    protected $table = 'play_history';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 机台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_model'), 'machine_id')->withTrashed();
    }
}
