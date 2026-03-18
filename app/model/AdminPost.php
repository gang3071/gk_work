<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class AdminPost
 * @property int id 主键
 * @property string name 权限角色名称
 * @property int status 备注说明
 * @property int sort 排序
 * @property int department_id 渠道id
 * @property int type 1 总后台 2渠道后台
 * @property string deleted_at 删除时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @package app\model
 */
class AdminPost extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(plugin()->webman->config('database.post_table'));
    }

    protected static function booted()
    {
        //创建时间倒序
        static::addGlobalScope('sort', function (Builder $builder) {
            $builder->latest();
        });
    }
}
