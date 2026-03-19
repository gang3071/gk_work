<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class ChannelFinancialRecord
 * @property int id 主键
 * @property int department_id 类型
 * @property int player_id 玩家id
 * @property string target 资料表
 * @property int target_id 资料表记录id
 * @property int action 操作
 * @property string tradeno 单号
 * @property int user_id 管理员id
 * @property string user_name 管理员名
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property Player player 玩家信息
 * @package app\model
 */
class ChannelFinancialRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const ACTION_RECHARGE_PASS = 1; // 充值审核通过
    const ACTION_RECHARGE_REJECT = 2; // 充值审核拒绝
    const ACTION_WITHDRAW_PASS = 3; // 提现审核通过
    const ACTION_WITHDRAW_REJECT = 4; // 提现审核拒绝
    const ACTION_WITHDRAW_PAYMENT = 5; // 提现打款
    const ACTION_RECHARGE_SETTING_ADD = 6; // 添加充值配置
    const ACTION_RECHARGE_SETTING_STOP = 7; // 停用充值配置
    const ACTION_RECHARGE_SETTING_ENABLE = 8; // 启用充值配置
    const ACTION_RECHARGE_SETTING_EDIT = 9; // 修改充值配置
    const ACTION_WITHDRAW_GB_ERROR = 10; // 购宝处理失败
    const ACTION_WITHDRAW_EH_ERROR = 11; // EH支付处理失败
    protected $table = 'channel_financial_record';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }
}
