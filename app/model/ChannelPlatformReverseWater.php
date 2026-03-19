<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class ChannelPlatformReverseWater
 * @property int id 主键
 * @property int department_id 部门id
 * @property int platform_id 平台id
 * @property string checkout_time 结算时间
 * @property string status 是否开启结算0-否 1-是
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property ChannelPlatformReverseWaterSetting setting 反水配置
 * @package app\model
 */
class ChannelPlatformReverseWater extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    protected $fillable = [
        'platform_id',
        'department_id',
        'checkout_time',
        'status'
    ];
    protected $table = 'channel_platform_reverse_water';

    /**
     * 反水活动配置
     * @return HasMany
     */
    public function setting(): HasMany
    {
        return $this->hasMany(ChannelPlatformReverseWaterSetting::class, 'water_id', 'id');
    }

    /**
     * 电子游戏平台
     * @return BelongsTo
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.game_platform_model'), 'platform_id')->withTrashed();
    }
}
