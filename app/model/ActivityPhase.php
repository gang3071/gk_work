<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class ActivityPhase
 * @property int id 主键
 * @property string cate_id 机台分类
 * @property string name 活动名称
 * @property int activity_id 活动id
 * @property int condition 达成条件
 * @property int bonus 达成条件
 * @property string notice 提示消息
 * @property int sort 排序
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Activity activity 活动
 * @property MachineCategory machineCategory
 * @package app\model
 */
class ActivityPhase extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $table = 'activity_phase';

    /**
     * 活动
     * @return BelongsTo
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id')->withTrashed();
    }

    /**
     * 游戏类别
     * @return BelongsTo
     */
    public function machineCategory(): BelongsTo
    {
        return $this->belongsTo(MachineCategory::class, 'cate_id')->withTrashed();
    }
}
