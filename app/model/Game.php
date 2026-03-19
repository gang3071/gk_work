<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class PlayerGamePlatform
 * @property int id 主键
 * @property int platform_id 平台id
 * @property int cate_id 游戏分类
 * @property int game_extend_id 游戏id
 * @property int status 状态
 * @property int sort 排序
 * @property int is_new 是否新游戏(0:否,1:是)
 * @property int is_hot 是否热门游戏(0:否,1:是)
 * @property int is_ios 是否IOS平台展示(0:否,1:是)
 * @property int display_mode 展示模式
 * @property string channel_hidden 渠道隐藏
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property GamePlatform gamePlatform 电子游戏平台
 * @property GameContent gameContent 电子游戏内容
 * @property GameExtend game_extend 平台游戏
 * @package app\model
 */
class Game extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    // 展示模式常量
    const DISPLAY_MODE_LANDSCAPE = 1; // 横版
    const DISPLAY_MODE_PORTRAIT = 2;  // 竖版
    const DISPLAY_MODE_ALL = 3;       // 全部支持
    protected $table = 'game';

    /**
     * 游戏平台
     * @return BelongsTo
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.game_platform_model'),
            'platform_id')->withTrashed();
    }

    /**
     * 游戏内容
     * @return hasMany
     */
    public function gameContent(): hasMany
    {
        return $this->hasMany(plugin()->webman->config('database.game_content_model'), 'game_id');
    }

    /**
     * 游戏
     * @return BelongsTo
     */
    public function game_extend(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.game_extend_model'),
            'game_extend_id');
    }
}
