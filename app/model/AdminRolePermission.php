<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 角色权限关联表模型
 *
 * 管理角色与功能权限的多对多关系，用于配置角色的功能访问权限
 *
 * @property int $id 主键ID（自增）
 * @property int $role_id 角色ID，关联 admin_roles 表
 * @property int $permission_id 权限ID，关联 admin_permissions 表
 *
 * @package app\model
 * @table admin_role_permissions
 */
class AdminRolePermission extends Model
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
        'permission_id',
    ];

    /**
     * 属性类型转换（仅包含安全的转换）
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'role_id' => 'integer',
        'permission_id' => 'integer',
    ];
    protected $table = 'admin_role_permissions';
}
