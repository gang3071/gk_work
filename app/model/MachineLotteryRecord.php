<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class MachineLotteryRecord
 * @property int id 主键
 * @property int machine_id 机器id
 * @property int department_id 渠道id
 * @property int player_id 玩家id
 * @property int draw_bet 中奖分数
 * @property int use_turn 使用转数
 * @property int lottery_win 中奖得分（钢珠=中奖珠数；Slot=中奖期间净得分）
 * @property int lottery_bet Slot开奖期间总压分变化量（结束时 bet - start_bet）
 * @property int odds_x 机台比值分子
 * @property int odds_y 机台比值分母
 * @property int has_rush 是否rush
 * @property string created_at 创建时间
 * @property string updated_at 更新时间
 *
 * @property Machine machine
 * @property Player player
 * @package app\model
 */
class MachineLotteryRecord extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'machine_lottery_record';
    protected $guarded = [];

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'department_id',
            'department_id')->withTrashed();
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
     * 机台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id')->withTrashed();
    }
}
