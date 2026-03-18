<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class AdminDepartment
 * @property int id 主键
 * @property string pid 上级部门
 * @property string name 部門名稱
 * @property string leader 負責人
 * @property string phone 手机号
 * @property int status 狀態
 * @property int type 1 部门 2渠道
 * @property int sort 排序
 * @property string path 层级
 * @property string deleted_at 删除时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Channel channel 渠道信息
 * @package app\model
 */
class AdminDepartment extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    const TYPE_DEPARTMENT = 1; // 部门
    const TYPE_CHANNEL = 2; // 渠道
    const TYPE_AGENT = 3; // 代理
    const TYPE_STORE = 4; // 店家

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(plugin()->webman->config('database.department_table'));
    }

    protected static function booted()
    {
        //创建时间倒序
        static::addGlobalScope('sort', function (Builder $builder) {
            $builder->latest();
        });
    }

    protected function getPidAttribute($value)
    {
        return (int)$value;
    }

    /**
     * 渠道信息
     * @return HasOne
     */
    public function channel(): HasOne
    {
        return $this->hasOne(plugin()->webman->config('database.channel_model'), 'department_id');
    }
}
