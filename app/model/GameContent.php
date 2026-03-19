<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class GameContent
 * @property int id 主键
 * @property int game_id 游戏ID
 * @property int platform_id 平台id
 * @property string name 头像名称
 * @property string lang 语言标识
 * @property string description 游戏说明
 * @property string picture 游戏图片
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property GamePlatform gamePlatform 电子游戏平台
 * @package app\model
 */
class GameContent extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = [
        'id',
        'game_id',
        'platform_id',
        'name',
        'lang',
        'description',
        'picture',
        'created_at',
        'updated_at',
    ];
    protected $table = 'game_content';

    /**
     * 游戏平台
     * @return BelongsTo
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.game_platform_model'), 'platform_id')->withTrashed();
    }

    /**
     * 电子游戏
     * @return BelongsTo
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.game_model'), 'game_id')->withTrashed();
    }
}
