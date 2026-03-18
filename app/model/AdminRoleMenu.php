<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 角色菜单关联表模型
 *
 * 管理角色与菜单的多对多关系，用于配置角色的菜单访问权限
 *
 * @property int $id 主键ID（自增）
 * @property int $role_id 角色ID，关联 admin_roles 表
 * @property int $menu_id 菜单ID，关联 admin_menus 表
 *
 * @package app\model
 * @table admin_role_menus
 */
class AdminRoleMenu extends Model
{
    /**
     * 不使用时间戳
     * @var bool
     */
    public $timestamps = false;

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'role_id',
        'menu_id',
    ];

    /**
     * 属性类型转换（仅包含安全的转换）
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'role_id' => 'integer',
        'menu_id' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.role_menu_table'));
    }
}
