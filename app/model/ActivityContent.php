<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class ActivityContent
 * @property int id 主键
 * @property string name 活动名称
 * @property int activity_id 活动id
 * @property string lang 语言标识
 * @property string description 活动说明
 * @property string get_way 领取方式
 * @property string join_condition 参与条件
 * @property string picture 活动主图
 * @property int user_id 管理员id
 * @property string user_name 管理员名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property Activity activity 活动
 * @package app\model
 */
class ActivityContent extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $table = 'activity_content';

    /**
     * 活动
     * @return BelongsTo
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id')->withTrashed();
    }
}
