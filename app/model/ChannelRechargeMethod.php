<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class ChannelRechargeMethod
 * @property int id 主键
 * @property string name 充值方式
 * @property int department_id 所属部门
 * @property int user_id 管理员id
 * @property int user_name 管理员名称
 * @property int status 状态(0:禁用,1:启用)
 * @property int amount_limit 是否限制充值金额1 限制 0不限制
 * @property float max 最大金额
 * @property float min 最小金额
 * @property int type 类型
 * @property string deleted_at 删除时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminDepartment department
 * @property AdminUser user
 * @package app\model
 */
class ChannelRechargeMethod extends Model
{
    use HasDateTimeFormatter, SoftDeletes;

    const TYPE_USDT = 1; // USDT充值
    const TYPE_ALI = 2; // 支付宝
    const TYPE_WECHAT = 3; // 微信
    const TYPE_BANK = 4; // 银行
    const TYPE_GB = 5; // 购宝
    const TYPE_COIN = 6; // 币商

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];
    protected $table = 'channel_recharge_method';

    /**
     * 部门
     * @return BelongsTo
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.department_model'), 'department_id')->withTrashed();
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
