<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class PlayerGamePlatform
 * @property int id 主键
 * @property int platform_id 平台id
 * @property int player_id 游戏编号
 * @property string player_name 平台游戏类型
 * @property string player_code 游戏类型
 * @property string player_password 玩家密码
 * @property int status 状态
 * @property int web_id 关联站点id
 * @property int has_out 是否转出 1是 0否
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property Player player 玩家信息
 * @property GamePlatform gamePlatform 电子游戏平台
 * @package app\model
 */
class PlayerGamePlatform extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    protected $table = 'player_game_platform';

    /**
     * 游戏平台
     * @return BelongsTo
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(GamePlatform::class,
            'platform_id')->withTrashed();
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }
}
