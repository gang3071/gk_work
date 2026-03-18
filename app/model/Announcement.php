<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Announcement
 * @property int id 主键
 * @property string title 标题
 * @property string content 内容
 * @property string valid_time 有效时间
 * @property string push_time 发布时间
 * @property int sort 排序
 * @property int status 状态
 * @property int priority 优先级
 * @property int admin_id 管理员id
 * @property string admin_name 管理员名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property AdminUser adminUser 管理员
 * @property Channel channel 渠道
 * @package app\model
 */
class Announcement extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const PRIORITY_ORDINARY = 1; // 普通
    const PRIORITY_SENIOR = 2; // 高级
    const PRIORITY_EMERGENT = 3; // 紧急

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.announcement_table'));
    }

    /**
     * 游戏类别
     * @return BelongsTo
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.user_model'), 'admin_id');
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.channel_model'), 'department_id', 'department_id')->withTrashed();
    }
}
