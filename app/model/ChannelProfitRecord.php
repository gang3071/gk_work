<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 渠道分润报表
 * Class ChannelProfitRecord
 * @property int id 主键
 * @property int department_id 部门/渠道id
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
 * @property float profit_amount 渠道当前分润
 * @property float self_profit_amount 平台当前分润
 * @property float machine_amount 投钞金额(纸币)
 * @property float water_amount 电子游戏返水金额
 * @property float machine_point 投钞点数
 * @property float player_profit_amount 直系玩家提供分润
 * @property string settlement_tradeno 结算单号
 * @property int settlement_id 结算id
 * @property float ratio 分润比
 * @property string date 分润日期
 * @property string settlement_time 结算时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Channel channel 渠道
 * @package app\model
 */
class ChannelProfitRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const STATUS_UNCOMPLETED = 0; // 未结算
    const STATUS_COMPLETED = 1; // 已结算
    protected $table = 'channel_profit_record';

    /**
     * 结算信息
     * @return BelongsTo
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PromoterProfitSettlementRecord::class,
            'settlement_id');
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'department_id',
            'department_id')->withTrashed();
    }
}
