<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * 后台菜单模型
 *
 * @property int $id 主键ID
 * @property string $name 菜单名称（语言包key）
 * @property string $icon 图标
 * @property string $url 链接地址
 * @property string $plugin 插件名称
 * @property int $pid 父级菜单ID
 * @property int $sort 排序
 * @property int $status 状态
 * @property int $open 是否展开
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *
 * @package app\model
 * @table admin_menus
 */
class AdminMenu extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = ['name', 'icon', 'url', 'plugin', 'pid', 'sort', 'status', 'open'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.menu_table'));
    }

    protected function getNameAttribute($value)
    {
        return admin_trans('menu.titles.' . $value, $value);
    }
}
