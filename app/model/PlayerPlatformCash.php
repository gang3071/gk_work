<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerPlatformCash
 * @property int id 主键
 * @property int player_id 玩家id
 * @property string player_account 玩家账户
 * @property int platform_id 平台id
 * @property string platform_name 平台名称
 * @property float money 点数
 * @property int status 遊戲平台狀態 0=鎖定 1=正常
 * @property bool is_crashed 是否爆机 0=正常 1=已爆机
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家
 * @package app\model
 */
class PlayerPlatformCash extends Model
{
    use HasDateTimeFormatter;

    const PLATFORM_SELF = 1; // 实体机平台

    protected $fillable = ['player_id', 'platform_id', 'platform_name', 'money'];
    protected $table = 'player_platform_cash';

    /**
     * 点数
     *
     * @param $value
     * @return float
     */
    public function getMoneyAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }

    /**
     * 保存模型但不触发事件（用于从 Redis 同步到数据库时避免循环）
     *
     * @param array $options
     * @return bool
     */
    public function saveWithoutEvents(array $options = []): bool
    {
        return static::withoutEvents(function () use ($options) {
            return $this->save($options);
        });
    }
}
