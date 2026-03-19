<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerEnterGameRecord
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int department_id 部门/渠道id
 * @property int game_id 游戏id
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player
 * @property Game game
 * @package app\model
 */
class PlayerEnterGameRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    protected $table = 'player_enter_game_record';

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.channel_model'), 'department_id',
            'department_id')->withTrashed();
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 游戏
     * @return BelongsTo
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.game_model'), 'game_id');
    }
}
