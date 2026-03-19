<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 分润报表
 * Class PromoterProfitRecord
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int department_id 部门/渠道id
 * @property int promoter_player_id 推广玩家id
 * @property int platform_id 平台id
 * @property int profit_id 分润记录id
 * @property float total_bet 总押注
 * @property float total_win 总输赢
 * @property float total_diff 总玩家輸贏額度
 * @property float total_reward 总奖金(不计入输赢)
 * @property float game_amount 游戏金额
 * @property float game_platform_ratio 游戏平台分润比
 * @property string date 分润日期
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家信息
 * @property Player player_promoter 推广员玩家信息
 * @property PlayerPromoter promoter 推广员信息
 * @property PromoterProfitSettlementRecord settlement 结算信息
 * @property GamePlatform gamePlatform 游戏平台
 * @package app\model
 */
class PromoterProfitGameRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const STATUS_UNCOMPLETED = 0; // 未结算
    const STATUS_COMPLETED = 1; // 已结算
    protected $table = 'promoter_profit_game_record';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }

    /**
     * 推广员信息
     * @return BelongsTo
     */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(PlayerPromoter::class, 'promoter_player_id',
            'player_id');
    }

    /**
     * 推广员玩家信息
     * @return BelongsTo
     */
    public function player_promoter(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'promoter_player_id');
    }

    /**
     * 开分赠送规则
     * @return hasMany
     */
    public function promoterProfitGameRecord(): hasMany
    {
        return $this->hasMany(PromoterProfitGameRecord::class,
            'machine_category_id');
    }

    /**
     * 游戏平台信息
     * @return BelongsTo
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(GamePlatform::class, 'platform_id');
    }
}