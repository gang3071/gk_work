<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 渠道分润结算记录
 * Class ChannelProfitSettlementRecord
 * @property int id 主键
 * @property int department_id 部门/渠道id
 * @property float total_withdraw_amount 总提现金额
 * @property float total_recharge_amount 总充值金额
 * @property float total_bonus_amount 活动奖励金额
 * @property float total_admin_deduct_amount 管理员扣点金额
 * @property float total_admin_add_amount 管理员加点金额
 * @property float total_present_amount 赠送金额
 * @property float total_machine_up_amount 机台上点
 * @property float total_machine_down_amount 机台下点
 * @property float total_game_amount 游戏平台
 * @property float total_lottery_amount 派彩总金额
 * @property float total_profit_amount 结算分润
 * @property float total_commission_amount 充值手续费
 * @property float total_machine_point 投钞金额(纸币)
 * @property float total_machine_amount 机器投钞
 * @property float last_profit_amount 上次结算分润
 * @property float adjust_amount 分润调整金额
 * @property float actual_amount 实际到账金额
 * @property int type 类型
 * @property float tradeno 结算单号
 * @property int user_id 管理员id
 * @property string user_name 管理员名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminUser user
 * @property Channel channel
 * @package app\model
 */
class ChannelProfitSettlementRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const TYPE_SETTLEMENT = 1; // 结算
    const TYPE_CLEAR = 2; // 清算

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(plugin()->webman->config('database.channel_profit_settlement_record_table'));
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.channel_model'), 'department_id',
            'department_id')->withTrashed();
    }

    /**
     * 管理员用户
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.user_model'), 'user_id')->withTrashed();
    }
}