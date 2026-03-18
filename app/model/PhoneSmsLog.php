<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PhoneSmsLog
 * @property int id 主键
 * @property int player_id 玩家id
 * @property string phone 手机
 * @property string code 验证码
 * @property int type 验证码类型
 * @property string expire_time 过期时间
 * @property int status 状态
 * @property int send_times 发送次数
 * @property string uid 编码
 * @property string response 返回消息
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player $player
 * @package app\model
 */
class PhoneSmsLog extends Model
{
    use HasDateTimeFormatter;

    const TYPE_LOGIN = 1; // 登录
    const TYPE_REGISTER = 2; // 注册
    const TYPE_CHANGE_PASSWORD = 3; // 修改密码
    const TYPE_CHANGE_PAY_PASSWORD = 4; // 修改支付密码
    const TYPE_CHANGE_PHONE = 5; // 修改手机号
    const TYPE_BIND_NEW_PHONE = 6; // 绑定新手机号
    const TYPE_TALK_BIND = 7; // QTalk绑定账号
    const TYPE_LINE_BIND = 8; // LINE绑定账号

    const COUNTRY_CODE_JP = 81; // 日本
    const COUNTRY_CODE_TW = 886; // 中国台湾
    const COUNTRY_CODE_CH = 86; // 中国大陆

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.phone_sms_log_table'));
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }
}
