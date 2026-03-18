<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Notice
 * @property int id 主键
 * @property int department_id 渠道id
 * @property int player_id 玩家id
 * @property int source_id 来源id
 * @property int type 类型
 * @property string title 标题
 * @property string content 内容
 * @property int status 状态
 * @property int receiver 接受方，1=玩家, 2=总后台, 2=子站
 * @property int is_private 是否私人消息
 * @property int admin_id 管理员id
 * @property string admin_name 管理员名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property AdminUser adminUser 管理员
 * @property Channel channel 渠道
 * @property Player player 玩家
 * @package app\model
 */
class Notice extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const TYPE_SYSTEM = 1; // 系统
    const TYPE_LOTTERY = 2; // 派彩
    const TYPE_EXAMINE_RECHARGE = 3; // 充值审核
    const TYPE_EXAMINE_WITHDRAW = 4; // 提现审核
    const TYPE_EXAMINE_ACTIVITY = 5; // 活动奖励审核
    const TYPE_EXAMINE_LOTTERY = 6; // 彩金奖励审核
    const TYPE_MACHINE = 7; // 机台设备离线
    const TYPE_MACHINE_BET = 8; // slot压分数据异常
    const TYPE_MACHINE_WIN = 9; // slot得分数据异常
    const TYPE_MACHINE_WIN_NUMBER = 10; // 钢珠数据异常
    const TYPE_RECHARGE_PASS = 11; // 充值通过消息
    const TYPE_RECHARGE_REJECT = 12; // 充值拒绝消息
    const TYPE_WITHDRAW_PASS = 13; // 提现审核通过
    const TYPE_WITHDRAW_REJECT = 14; // 提现拒绝
    const TYPE_WITHDRAW_COMPLETE = 15; // 提现打款
    const TYPE_ACTIVITY_PASS = 16; // 活動獎勵稽核通過
    const TYPE_ACTIVITY_REJECT = 17; // 活動獎勵稽核不通過
    const TYPE_ACTIVITY_RECEIVE = 18; // 活動獎勵待领取
    const TYPE_REVERSE_WATER = 19; // 电子游戏反水奖励待领取
    const TYPE_MACHINE_LOCK = 20; // 机台锁定通知

    const RECEIVER_PLAYER = 1; // 玩家
    const RECEIVER_ADMIN = 2; // 总站
    const RECEIVER_DEPARTMENT = 3; // 子站

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.notice_table'));
    }

    /**
     * 游戏类别
     * @return BelongsTo
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.user_model'), 'admin_id');
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
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id', 'department_id')->withTrashed();
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function (Notice $notice) {
            if ($notice->is_private == 1) {
                sendSocketMessage('player-' . $notice->player_id, [
                    'msg_type' => 'player_notice_num',
                    'notice_num' => Notice::query()
                        ->where('player_id', $notice->player_id)
                        ->where('receiver', Notice::RECEIVER_PLAYER)
                        ->where('is_private', 1)
                        ->where('status', 0)
                        ->count('*'),
                ]);
            }
        });
    }
}
