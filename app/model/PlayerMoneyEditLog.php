<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class PlayerMoneyEditLog
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int department_id 部门/渠道id
 * @property int type 类型
 * @property string action 操作
 * @property string tradeno 单号
 * @property string currency 币种
 * @property float money 金额
 * @property float origin_money 原始金额
 * @property float after_money 異動後金額
 * @property float inmoney 实际金额
 * @property float subsidy_money 辅助金额
 * @property float bet_multiple 流水倍数
 * @property float bet_num 流水
 * @property string remark 备注
 * @property int activity 关联活动id
 * @property int user_id 審核人員ID
 * @property string user_name 審核人員名稱
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 最后一次修改时间
 *
 * @property AdminUser $user 管理员
 * @property Player $player 玩家
 * @property Channel $channel 部门/渠道
 * @package app\model
 */
class PlayerMoneyEditLog extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const TYPE_DEDUCT = 0; // 扣点
    const TYPE_INCREASE = 1; // 加点

    const RECHARGE = 0; // 充值
    const VIP_RECHARGE = 1; // vip充值
    const TESTING_MACHINE = 2; // 机台测试
    const OTHER = 3; // 其他
    const ACTIVITY_GIVE = 4; // 活動外贈
    const TRIPLE_SEVEN_GIVE = 5; // 三七鋼珠外贈
    const COMPOSITE_MACHINE_GIVE = 6; // 複合機外贈
    const REAL_PERSON_GIVE = 7; // 真人外贈
    const ELECTRONIC_GIVE = 8; // 電子外贈
    const ADMIN_DEDUCT = 9; // 管理员扣点
    const ADMIN_DEDUCT_OTHER = 10; // 管理员其他扣点

    const ACTIVITY = 11; // 活动
    const COIN_DEDUCT = 12; // 币商扣点
    const COIN_INCREASE = 13; // 币商加点
    const SPECIAL = 14; // 特殊类型
    const COIN_RECHARGE = 15; // 币商充值
    const COIN_WITHDRAWAL = 16; // 币商提现
    const DEPOSIT_BONUS_GRANT = 17; // 充值满赠发放
    const DEPOSIT_BONUS_CANCEL = 18; // 充值满赠取消
    protected $table = 'player_money_edit_log';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->BelongsTo(Player::class, 'player_id')->withTrashed();
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'department_id', 'department_id')->withTrashed();
    }

    /**
     * 管理员用户
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'user_id')->withTrashed();
    }
}
