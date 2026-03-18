<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Class PlayerPromoter
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int recommend_id 推荐id
 * @property int department_id 部门/渠道id
 * @property string path 层级
 * @property int status 状态
 * @property int player_num 玩家数量
 * @property int team_num 团队数量
 * @property float team_withdraw_total_amount 总提现(团队)
 * @property float team_recharge_total_amount 总充值(团队)
 * @property float total_machine_amount 投钞(个人)
 * @property float total_machine_point 投钞(个人)
 * @property float total_profit_amount 总分润(个人)
 * @property float total_water_amount 总电子游戏返水(个人)
 * @property float profit_amount 当前分润(个人)
 * @property float adjust_amount 当前分润调整金额
 * @property float player_profit_amount 当期直系玩家提供分润
 * @property float settlement_amount 已结算金额
 * @property float last_profit_amount 上次结算分润(个人)
 * @property float last_settlement_time 上次结算时间
 * @property float last_settlement_timestamp 上次结算时间
 * @property float team_total_profit_amount 总分润(团队)
 * @property float team_profit_amount 当前分润(团队)
 * @property float team_settlement_amount 团队已结算金额
 * @property float total_commission 总充值手续费
 * @property float total_amount 线下店总营收
 * @property float children_total_amount 线下店子集总营收
 * @property float ratio 分润比例
 * @property string name 姓名
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player
 * @property Channel channel
 * @property PlayerPromoter parent_promoter
 * @package app\model
 */
class PlayerPromoter extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = ['name', 'adjust_amount', 'status', 'ratio'];

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

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
        $this->setTable(plugin()->webman->config('database.player_promoter_table'));
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
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 上级推广员
     * @return hasOne
     */
    public function parent_promoter(): hasOne
    {
        return $this->hasOne(plugin()->webman->config('database.player_promoter_model'), 'player_id', 'recommend_id');
    }
}
