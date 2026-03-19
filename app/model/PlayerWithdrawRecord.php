<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webman\Event\Event;

/**
 * Class PlayerWithdrawRecord
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int talk_user_id 聊聊账号id
 * @property int department_id 部门/渠道id
 * @property string player_tag 玩家标注
 * @property string tradeno 单号
 * @property string talk_tradeno QTalk单号
 * @property int status 状态
 * @property int type 类型
 * @property int bank_type 类型 1 USDT充值 2支付宝 3微信 4银行
 * @property string player_name 玩家名称
 * @property string player_phone 玩家手机号
 * @property float money 金额
 * @property float inmoney 实际金额
 * @property float point 提出游戏点
 * @property float fee 手续费
 * @property string bank_name 银行名
 * @property string account_name 银行账号所属人
 * @property string account 银行账号
 * @property string qr_code 钱包二维码
 * @property float rate 汇率
 * @property float actual_rate 实际汇率
 * @property string wallet_address 钱包地址
 * @property string currency 币种
 * @property string remark 备注
 * @property string certificate 打款凭证
 * @property string reject_reason 拒绝原因
 * @property string talk_result QTalk通知
 * @property int user_id 管理员id
 * @property string user_name 管理员
 * @property string finish_time 完成时间
 * @property string cancel_time 取消时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家
 * @property Channel channel 渠道
 * @property PlayerBank playerBank 玩家提现账户
 * @package app\model
 */
class PlayerWithdrawRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    const STATUS_WAIT = 1; // 提现中(待审核)
    const STATUS_SUCCESS = 2; // 成功
    const STATUS_FAIL = 3; // 提现失败
    const STATUS_PENDING_PAYMENT = 4; // 待打款(审核通过)
    const STATUS_PENDING_REJECT = 5; // 审核拒绝
    const STATUS_CANCEL = 6; // 玩家取消
    const STATUS_SYSTEM_CANCEL = 7; // 系统取消
    const TYPE_THIRD = 1; // QTalk
    const TYPE_SELF = 2; // 渠道提现
    const TYPE_ARTIFICIAL = 3; // 人工提现
    const TYPE_GB = 4; // 购宝提现
    const TYPE_COIN = 5; // 币商提现
    protected $dataAuth = ['department_id' => 'department_id'];
    protected $table = 'player_withdraw_record';

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::updated(function (PlayerWithdrawRecord $playerWithdrawRecord) {
            $oldStatus = $playerWithdrawRecord->getOriginal('status'); // 原始值
            $newStatus = $playerWithdrawRecord->status;
            if (config('app.profit', 'task') == 'event') {
                if ($oldStatus != $newStatus && $newStatus == PlayerWithdrawRecord::STATUS_SUCCESS) {
                    Event::emit('promotion.playerWithdraw', $playerWithdrawRecord);
                }
            }
            if ($oldStatus != $newStatus && $newStatus == PlayerWithdrawRecord::STATUS_SUCCESS) {
                PlayerDeliveryRecord::query()
                    ->where('type', PlayerDeliveryRecord::TYPE_WITHDRAWAL)
                    ->where('target_id', $playerWithdrawRecord->id)
                    ->update([
                        'withdraw_status' => $playerWithdrawRecord->status
                    ]);
            }
        });
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
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
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
     * 实际金额
     *
     * @param $value
     * @return float
     */
    public function getInmoneyAttribute($value): float
    {
        return floatval($value);
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
     * 手续费
     *
     * @param $value
     * @return float
     */
    public function getFeeAttribute($value): float
    {
        return floatval($value);
    }
}
