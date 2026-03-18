<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class ChannelPlatformReverseWaterSetting
 * @property int id 主键
 * @property int water_id 反水表主键id
 * @property float point 押码量
 * @property float ratio 反水比例
 * @property int admin_id 操作人
 * @property string remark 备注
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property ChannelPlatformReverseWater water 反水活动
 * @package app\model
 */
class ChannelPlatformReverseWaterSetting extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(plugin()->webman->config('database.channel_platform_reverse_water_setting_table'));
    }

    /**
     * 反水活动
     * @return BelongsTo
     */
    public function water(): BelongsTo
    {
        return $this->belongsTo(ChannelPlatformReverseWater::class, 'water_id', 'id');
    }
}
