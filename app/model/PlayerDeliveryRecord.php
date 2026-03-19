<?php

namespace app\model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webman\Event\Event;

/**
 * Class PlayerDeliveryRecord
 * @property int id 主键
 * @property int player_id 玩家id
 * @property string target 质料表
 * @property int target_id 质料id
 * @property int department_id 部门/渠道id
 * @property int machine_id 机台id
 * @property int platform_id 电子游戏平台id
 * @property string machine_name 机台名称
 * @property int machine_type 机台类型
 * @property string code 机台编号
 * @property int user_id 管理员id
 * @property int user_name 管理员名称
 * @property int type 类型
 * @property int withdraw_status 提现状态 1=提现中(待审核), 2=成功, 3=失败 , 4=待打款, 5=不通过, 6=玩家取消, 7=系统取消
 * @property string source 来源
 * @property float amount 点数
 * @property float amount_before 異動前金額
 * @property float amount_after 異動后金額
 * @property string tradeno 单号
 * @property string remark 备注
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家
 * @property Machine machine 机台
 * @property GamePlatform gamePlatform 平台信息
 * @package app\model
 */
class PlayerDeliveryRecord extends Model
{
    use HasFactory;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const TYPE_MODIFIED_AMOUNT_ADD = 1; // (管理后台)加点
    const TYPE_PRESENT_IN = 2; // 玩家转入
    const TYPE_PRESENT_OUT = 3; // 币商转出
    const TYPE_MACHINE_UP = 4; // 机台上分
    const TYPE_MACHINE_DOWN = 5; // 机台下分
    const TYPE_RECHARGE = 6; // 充值
    const TYPE_WITHDRAWAL = 7; // 提现
    const TYPE_MODIFIED_AMOUNT_DEDUCT = 8; // (管理后台)扣点
    const TYPE_WITHDRAWAL_BACK = 9; // 提现失败返还
    const TYPE_ACTIVITY_BONUS = 10; // 活动奖金
    const TYPE_REGISTER_PRESENT = 11; // 注册赠送
    const TYPE_PROFIT = 12; // 推广员分润
    const TYPE_LOTTERY = 13; // 彩金中奖
    const TYPE_GAME_PLATFORM_OUT = 14; // 转出到电子游戏
    const TYPE_GAME_PLATFORM_IN = 15; // 电子游戏转入
    const TYPE_NATIONAL_INVITE = 16; // 全民代理邀请奖励
    const TYPE_RECHARGE_REWARD = 17; // 全民代理首充奖励
    const TYPE_DAMAGE_REBATE = 18; // 全民代理客损返佣
    const TYPE_REVERSE_WATER = 19; // 电子游戏反水
    const COIN_ADD = 20; // 币商加点
    const COIN_DEDUCT = 21; // 币商扣点
    const TYPE_SPECIAL = 22; // 特殊类型
    const TYPE_MACHINE = 23; // 投钞类型
    const TYPE_AGENT_OUT = 24; // 代理玩家转出
    const TYPE_AGENT_IN = 25; // 代理玩家转入

    const TYPE_BET = 26; //用户下注
    const TYPE_CANCEL_BET = 27; //用户取消下注
    const TYPE_GIFT = 28; //用户打赏

    const TYPE_SETTLEMENT = 29; //注单结算

    const TYPE_RE_SETTLEMENT = 30; //重新结算

    const TYPE_PREPAY = 31; //预扣金额
    const TYPE_REFUND = 32; //退款

    protected $fillable = [
        'player_id',
        'target',
        'target_id',
        'department_id',
        'type',
        'source',
        'amount',
        'amount_after',
        'amount_before',
        'amount_platform_before',
        'amount_platform_after',
        'tradeno',
        'remark',
        'operator_audit',
        'operator_withdraw',
        'created_at',
    ];

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    protected $table = 'player_delivery_record';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 机台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_model'), 'machine_id')->withTrashed();
    }

    /**
     * 金额
     *
     * @param $value
     * @return float
     */
    public function getAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 異動前金額
     *
     * @param $value
     * @return float
     */
    public function getAmountBeforeAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 異動后金額
     *
     * @param $value
     * @return float
     */
    public function getAmountAfterAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function (PlayerDeliveryRecord $deliveryRecord) {
            // 发送玩家信息消息(更新用户钱包)
            sendSocketMessage('player-' . $deliveryRecord->player_id, [
                'msg_type' => 'player_info',
                'player_id' => $deliveryRecord->player_id,
                'type' => $deliveryRecord->type,
                'amount' => $deliveryRecord->amount,
                'amount_before' => $deliveryRecord->amount_before,
                'amount_after' => $deliveryRecord->amount_after,
                'machine_name' => $deliveryRecord->machine_name,
                'machine_type' => $deliveryRecord->machine_type,
            ]);
            if (config('app.profit', 'task') == 'event') {
                // 发布分润事件
                switch ($deliveryRecord->type) {
                    case PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD: // 管理员加点
                        Event::emit('promotion.adminAdd', $deliveryRecord);
                        break;
                    case PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT: // 管理员扣点
                        Event::emit('promotion.adminDeduct', $deliveryRecord);
                        break;
                    case PlayerDeliveryRecord::TYPE_REGISTER_PRESENT: // 注册赠送
                        Event::emit('promotion.registerPresent', $deliveryRecord);
                        break;
                    case PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS: // 活动奖励
                        Event::emit('promotion.activityBonus', $deliveryRecord);
                        break;
                    default:
                        break;
                }
            }
        });
    }

    /**
     * 平台信息
     * @return BelongsTo
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.game_platform_model'), 'platform_id')->withTrashed();
    }


    /**
     * 充值记录
     * @return mixed
     */
    public function recharge()
    {
        return $this->belongsTo(plugin()->webman->config('database.player_recharge_record_model'), 'target_id');
    }
}
