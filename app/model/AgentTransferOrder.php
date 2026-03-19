<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class AgentTransferOrder
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int department_id 部门/渠道id
 * @property string tradeno 单号
 * @property string agent_tradeno 单号
 * @property int status 状态
 * @property int type 类型
 * @property string player_account 玩家名称
 * @property string player_phone 玩家手机号
 * @property float point 提出游戏点
 * @property float money 金额
 * @property float fee 手续费
 * @property string currency 币种
 * @property string remark 备注
 * @property string reject_reason 拒绝原因
 * @property string finish_time 完成时间
 * @property string cancel_time 取消时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家
 * @property Channel channel 渠道
 * @package app\model
 */
class AgentTransferOrder extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    const STATUS_WAIT = 1; // 提现中(待审核)
    const STATUS_SUCCESS = 2; // 成功
    const STATUS_FAIL = 3; // 提现失败

    const TYPE_IN = 1; // 转入
    const TYPE_OUT = 2; // 转出
    protected $table = 'agent_transfer_order';

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
}
