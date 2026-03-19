<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * 系统配置模型
 *
 * @property int $id 主键ID
 * @property string $name 配置名称
 * @property string $value 配置值
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *
 * @package app\model
 * @table admin_configs
 */
class AdminConfig extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = ['name', 'value'];
    protected $table = 'admin_configs';
}
