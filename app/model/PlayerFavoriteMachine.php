<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class Slider
 * @property int id 主键
 * @property int machine_id 機台id
 * @property int player_id 玩家id
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家信息
 * @property Machine machine 機台信息
 * @package app\model
 */
class PlayerFavoriteMachine extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'player_favorite_machine';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 機台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_model'), 'machine_id');
    }

    /**
     * 模型的 "booted" 方法
     * @return void
     */
    protected static function booted()
    {
        static::created(function (PlayerFavoriteMachine $playerFavoriteMachine) {
            $playerFavoriteMachine->machine->increment('favorite_num');
        });
        static::deleted(function (PlayerFavoriteMachine $playerFavoriteMachine) {
            $playerFavoriteMachine->machine->decrement('favorite_num');
        });
    }
}
