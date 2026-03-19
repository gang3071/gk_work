<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Activity
 * @property int id 主键
 * @property int status 状态
 * @property int sort 排序
 * @property int type 类型
 * @property string cate_id 分类id
 * @property string department_id 渠道id
 * @property string start_time 开始时间
 * @property string end_time 结束时间
 * @property int user_id 管理员id
 * @property string user_name 管理员名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property ActivityContent activity_content 活动内容
 * @property ActivityPhase activity_phase 活动阶段
 * @package app\model
 */
class Activity extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    //数据权限字段
    protected $table = 'activity';

    /**
     * 活动内容
     * @return hasMany
     */
    public function activity_content(): hasMany
    {
        return $this->hasMany(plugin()->webman->config('database.activity_content_model'), 'activity_id');
    }

    /**
     * 活动内容
     * @return hasMany
     */
    public function activity_phase(): hasMany
    {
        return $this->hasMany(plugin()->webman->config('database.activity_phase_model'), 'activity_id');
    }
}
