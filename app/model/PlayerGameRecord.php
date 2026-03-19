<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webman\Event\Event;

/**
 * Class PlayerGameRecord
 * @property int id 主键
 * @property int game_id 游戏id
 * @property int machine_id 机台id
 * @property int player_id 玩家id
 * @property int agent_player_id 代理玩家id
 * @property int parent_player_id 上级玩家id
 * @property int type 类型
 * @property float open_point 游戏上点
 * @property float wash_point 游戏下点
 * @property float open_amount 机台上分
 * @property float wash_amount 机台下分
 * @property float after_game_amount 余点数
 * @property float give_amount 开分赠点：赠送点数
 * @property string code 機台編號
 * @property string odds 比值
 * @property int status 状态
 * @property int national_damage_ratio 全民代理返佣比例
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Machine machine 机台
 * @property Player player 玩家
 * @property PlayerGameLog last_player_game_log 最新游戏记录
 * @package app\model
 */
class PlayerGameRecord extends Model
{
    use HasDateTimeFormatter;

    const STATUS_START = 1; // 进行中
    const STATUS_END = 2; // 结束
    protected $table = 'player_game_record';

    /**
     * 上分
     *
     * @param $value
     * @return float
     */
    public function getOpenPointAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 下分
     *
     * @param $value
     * @return float
     */
    public function getWashPointAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 余点数
     *
     * @param $value
     * @return float
     */
    public function getAfterGameAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 机台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id')->withTrashed();
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
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        if (config('app.profit', 'task') == 'event') {
            static::updated(function (PlayerGameRecord $playerGameRecord) {
                $oldStatus = $playerGameRecord->getOriginal('status'); // 原始值
                $newStatus = $playerGameRecord->status;
                // 游戏结束并且产生盈亏后计算分润
                if ($oldStatus != $newStatus && $newStatus == PlayerGameRecord::STATUS_END && $playerGameRecord->open_point != $playerGameRecord->wash_point) {
                    Event::emit('promotion.playerGame', $playerGameRecord);
                }
            });
        }
    }

    public function last_player_game_log(): HasOne
    {
        return $this->hasOne(PlayerGameLog::class, 'game_record_id')->latest();
    }
}
