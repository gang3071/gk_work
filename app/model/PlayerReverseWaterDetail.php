<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerReverseWaterDetail
 * @property int id 主键
 * @property int admin_id 操作人 0-默认系统
 * @property int player_id 用户id
 * @property int platform_id 平台id
 * @property float point 打码量
 * @property float reverse_water 反水额
 * @property float all_diff 总输赢
 * @property float platform_ratio 平台比例
 * @property float level_ratio 等级加成
 * @property string date 反水日期
 * @property string receive_time 领取时间
 * @property string remark 备注
 * @property string status 领取状态 0-未领取 1-已领取
 * @property string switch 能否结算开关，0-关，1-开
 * @property string is_settled 是否结算，0-未结算，1-已结算
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 用户
 * @property GamePlatform platform 游戏平台
 * @package app\model
 */
class PlayerReverseWaterDetail extends Model
{
    use HasDateTimeFormatter;

    const STATUS_UNRECEIVED = 0; // 未领取
    const STATUS_RECEIVED = 1; // 已领取
    protected $table = 'player_reverse_water_detail';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(GamePlatform::class, 'platform_id')->withTrashed();
    }
}
