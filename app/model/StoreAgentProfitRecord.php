<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 线下分润报表
 * Class StoreAgentProfitRecord
 * @property int id 主键
 * @property int player_id 分润玩家id（旧字段，已废弃）
 * @property int admin_user_id 上级代理的AdminUser ID
 * @property int agent_id 代理id（旧字段）
 * @property int agent_admin_user_id 下级代理/店家的AdminUser ID
 * @property int department_id 部门/渠道id
 * @property int type 1 代理 2店家
 * @property float profit_amount 当前分润
 * @property float sub_profit_amount 上缴分润
 * @property float total_win 总赢
 * @property float total_diff 总输赢
 * @property float total_bet 总押注
 * @property float machine_amount 投钞金额(纸币)
 * @property float machine_point 投钞点数
 * @property float total_income 总营收
 * @property float total_in 结算总转入
 * @property float total_out 结算总转出
 * @property float ratio 分润比
 * @property float sub_ratio 上缴比例
 * @property string settlement_tradeno 结算单号
 * @property float adjust_amount 分润调整金额
 * @property float actual_amount 实际到账金额
 * @property string start_time 结算开始时间
 * @property string end_time 结算结束时间
 * @property int user_id 管理员id
 * @property string user_name 管理员名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminUser adminUser 上级代理
 * @property AdminUser agentAdminUser 下级代理/店家
 * @property Channel channel 渠道
 * @package app\model
 */
class StoreAgentProfitRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const TYPE_AGENT = 1; // 代理
    const TYPE_STORE = 2; // 店家
    protected $table = 'store_agent_profit_record';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 代理信息
     * @return BelongsTo
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'agent_id')->withTrashed();
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
     * 推广员信息（旧方法，已废弃）
     * @return belongsTo
     * @deprecated 使用 adminUser() 或 agentAdminUser() 替代
     */
    public function agent_promoter(): belongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_promoter_model'), 'player_id',
            'player_id');
    }

    /**
     * 上级代理（新方法）
     * @return BelongsTo
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    /**
     * 下级代理/店家（新方法）
     * @return BelongsTo
     */
    public function agentAdminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'agent_admin_user_id');
    }
}
