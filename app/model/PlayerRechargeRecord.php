<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webman\Event\Event;

/**
 * Class PlayerRechargeRecord
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int department_id 部门/渠道id
 * @property int talk_user_id 聊聊账号id
 * @property int setting_id 充值账号配置id
 * @property string tradeno 单号
 * @property string talk_tradeno QTalk单号
 * @property int status 状态
 * @property int type 类型
 * @property string player_name 玩家名称
 * @property string player_phone 玩家手机号
 * @property float money 金额
 * @property float inmoney 实际金额
 * @property float point 充值点数
 * @property string currency 币种
 * @property float rate 汇率
 * @property float actual_rate 实际汇率
 * @property string player_tag 忘记标注
 * @property string remark 备注
 * @property string reject_reason 拒绝原因
 * @property int user_id 管理员id
 * @property string user_name 管理员
 * @property string notify_result 回调数据
 * @property string certificate 付款凭证
 * @property string finish_time 完成时间
 * @property string cancel_time 取消时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property float recharge_ratio 全民代理返佣比例
 *
 * @property Player player 玩家
 * @property Channel channel 渠道
 * @property ChannelRechargeSetting channel_recharge_setting 充值账户
 * @package app\model
 */
class PlayerRechargeRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const STATUS_WAIT = 0; // 充值中
    const STATUS_RECHARGING = 1; // 待审核(QTalk充值请求成功)
    const STATUS_RECHARGED_SUCCESS = 2; // 充值成功(管理员通过)
    const STATUS_RECHARGED_FAIL = 3; // 充值失败
    const STATUS_RECHARGED_CANCEL = 4; // 充值取消(玩家取消)
    const STATUS_RECHARGED_REJECT = 5; // 拒绝(管理员拒绝)
    const STATUS_RECHARGED_SYSTEM_CANCEL = 6; // 已关闭(系统取消)

    const TYPE_THIRD = 1; // qtalk充值
    const TYPE_SELF = 2; // 银行卡充值
    const TYPE_BUSINESS = 3; // 币商充值
    const TYPE_ARTIFICIAL = 4; // 人工充值
    const TYPE_GB = 5; // 购宝充值
    const TYPE_MACHINE = 6; // 机器投钞
    const TYPE_EH = 7; // Eh-Pay
    protected $table = 'player_recharge_record';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.channel_model'), 'department_id', 'department_id')->withTrashed();
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel_recharge_setting(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.channel_recharge_setting_model'), 'setting_id')->withTrashed();
    }

    /**
     * 获取器 - 标签id
     * @param $value
     * @return false|string[]
     */
    public function getPlayerTagAttribute($value)
    {
        return array_filter(explode(',', $value));
    }

    /**
     * 修改器 - 标签id
     * @param $value
     * @return string
     */
    public function setPlayerTagAttribute($value): string
    {
        return $this->attributes['player_tag'] = implode(',', $value);
    }

    /**
     * 金额
     *
     * @param $value
     * @return float
     */
    public function getMoneyAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 时间金额
     *
     * @param $value
     * @return float
     */
    public function getInmoneyAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 游戏点数
     *
     * @param $value
     * @return float
     */
    public function getPointAttribute($value): float
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
        if (config('app.profit', 'task') == 'event') {
            static::updated(function (PlayerRechargeRecord $playerRechargeRecord) {
                $oldStatus = $playerRechargeRecord->getOriginal('status'); // 原始值
                $newStatus = $playerRechargeRecord->status;
                // 游戏结束并且产生盈亏后计算分润
                if ($oldStatus != $newStatus && $newStatus == PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS) {
                    Event::emit('promotion.playerRecharge', $playerRechargeRecord);
                }
            });
        }
    }
}
