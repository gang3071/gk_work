<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerDisabledGame
 * 玩家禁用游戏表（记录被禁用的电子游戏）
 *
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int game_id 游戏id（被禁用的游戏）
 * @property int platform_id 平台id
 * @property int status 状态(0:取消禁用,1:禁用生效)
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家
 * @property Game game 游戏
 * @property GamePlatform gamePlatform 游戏平台
 * @package app\model
 */
class PlayerDisabledGame extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'player_disabled_game';

    protected $fillable = ['player_id', 'game_id', 'platform_id', 'status'];

    /**
     * 玩家
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /**
     * 游戏
     * @return BelongsTo
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /**
     * 游戏平台
     * @return BelongsTo
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(GamePlatform::class, 'platform_id');
    }
}
