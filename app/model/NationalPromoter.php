<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Class NationalPromoter
 * @property int id 主键
 * @property int uid 玩家id
 * @property int recommend_id 上级ID
 * @property float chip_amount 当前打码量
 * @property int level_sort 用户级别
 * @property int level 用户等级
 * @property int invite_num 邀请玩家数
 * @property float pending_amount 待结算金额
 * @property float settlement_amount 已结算金额
 * @property int status 全民代理状态，0-未激活，1-已激活
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家信息
 * @property LevelList level_list 全民代理等级详情
 * @property NationalProfitRecord national_profit_record 全民代理收益历史
 * @property NationalPromoter sub_players 下级玩家
 * @property NationalProfitRecord last_national_profit_record 上次分润记录
 * @package app\model
 */
class NationalPromoter extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = ['uid', 'level'];
    protected $table = 'national_promoter';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'uid')->withTrashed();
    }

    /**
     * 全民代理等级详情
     * @return HasOne
     */
    public function level_list(): HasOne
    {
        return $this->hasOne(LevelList::class, 'id', 'level');
    }

    /**
     * 全民代理收益历史
     * @return HasMany
     */
    public function national_profit_record(): HasMany
    {
        return $this->HasMany(NationalProfitRecord::class, 'recommend_id', 'uid');
    }

    /**
     * 下级玩家
     * @return HasMany
     */
    public function sub_players(): HasMany
    {
        return $this->HasMany(NationalPromoter::class, 'recommend_id', 'uid');
    }

    /**
     * 上次分润记录
     * @return hasOne
     */
    public function last_national_profit_record(): hasOne
    {
        return $this->hasOne(PlayerDeliveryRecord::class, 'player_id',
            'uid')->where('type', 18)->orderBy('created_at', 'desc');
    }
}
