<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 分润报表
 * Class PromoterProfitRecord
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int department_id 部门/渠道id
 * @property int promoter_player_id 推广玩家id
 * @property int source_player_id 来源推广玩家id
 * @property int status 状态
 * @property float withdraw_amount 提现金额
 * @property float recharge_amount 充值金额
 * @property float bonus_amount 活动奖励金额
 * @property float admin_deduct_amount 管理员扣点
 * @property float admin_add_amount 管理员加点
 * @property float present_amount 赠送金额
 * @property float machine_up_amount 玩家上点
 * @property float machine_down_amount 玩家下点
 * @property float game_amount 游戏平台
 * @property float lottery_amount 派彩金额
 * @property float profit_amount 分润金额
 * @property float machine_amount 投钞金额(纸币)
 * @property float water_amount 电子游戏返水金额
 * @property float machine_point 投钞点数
 * @property float player_profit_amount 直系玩家提供分润
 * @property string settlement_tradeno 结算单号
 * @property int settlement_id 结算id
 * @property float ratio 分润比
 * @property float actual_ratio 实际分润
 * @property int model 类型 1 任务模式 2 事件模式
 * @property string date 分润日期
 * @property string settlement_time 结算时间
 * @property float commission_ratio 充值手续费比例
 * @property float commission 充值手续费
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家信息
 * @property Player player_promoter 推广员玩家信息
 * @property PlayerPromoter promoter 推广员信息
 * @property PlayerPromoter source_promoter 来员推广员
 * @property PromoterProfitSettlementRecord settlement 结算信息
 * @package app\model
 */
class PromoterProfitRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const STATUS_UNCOMPLETED = 0; // 未结算
    const STATUS_COMPLETED = 1; // 已结算

    const MODEL_TASK = 1; // 任务模式
    const MODEL_EVENT = 2; // 事件模式
    protected $table = 'promoter_profit_record';

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
        return $this->belongsTo(PlayerPromoter::class, 'promoter_player_id', 'player_id');
    }

    /**
     * 来源推广员
     * @return BelongsTo
     */
    public function source_promoter(): BelongsTo
    {
        return $this->belongsTo(PlayerPromoter::class, 'source_player_id', 'player_id');
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
     * 结算信息
     * @return BelongsTo
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PromoterProfitSettlementRecord::class, 'settlement_id');
    }
}
