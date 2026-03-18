<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerPresentRecord
 * @property int id 主键
 * @property int type 类型
 * @property int user_id 贈點方
 * @property int player_id 受贈方
 * @property int department_id 部门/渠道id
 * @property string tradeno 单号
 * @property int amount 點數
 * @property string remark 备注
 * @property float user_origin_amount 发起人轉前餘額
 * @property float user_after_amount 发起人轉後餘額
 * @property float player_origin_amount 交易对象轉前餘額
 * @property float player_after_amount 交易对象轉後餘額
 * @property string updated_at 最后一次修改时间
 * @property string created_at 创建时间
 *
 * @property Player user 转出玩家
 * @property Player player 转入玩家
 * @property Channel channel 渠道
 * @package app\model
 */
class PlayerPresentRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const TYPE_IN = 1; // 转入
    const TYPE_OUT = 2; // 转出

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.player_present_record_table'));
    }


    public function user(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'user_id')->withTrashed();
    }

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
     * 交易金额
     *
     * @param $value
     * @return float
     */
    public function getAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 发起人轉前餘額
     *
     * @param $value
     * @return float
     */
    public function getUserOriginAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 发起人轉後餘額
     *
     * @param $value
     * @return float
     */
    public function getUserAfterAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 交易对象轉前餘額
     *
     * @param $value
     * @return float
     */
    public function getPlayerOriginAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 交易对象轉後餘額
     *
     * @param $value
     * @return float
     */
    public function getPlayerAfterAmountAttribute($value): float
    {
        return floatval($value);
    }
}
