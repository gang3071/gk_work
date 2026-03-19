<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class MachineProducer
 * @property int id 主键
 * @property string name 名称
 * @property int status 状态
 * @property int sort 排序
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 * @package app\model
 */
class MachineProducer extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    //数据权限字段
    protected $table = 'machine_producer';
}
