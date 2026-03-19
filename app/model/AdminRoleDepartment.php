<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 角色部门关联表模型
 *
 * 管理角色与部门的多对多关系，用于配置角色的数据权限范围
 *
 * @property int $id 主键ID（自增）
 * @property int $role_id 角色ID，关联 admin_roles 表
 * @property int $department_id 部门ID，关联 admin_departments 表
 *
 * @package app\model
 * @table admin_role_departments
 */
class AdminRoleDepartment extends Model
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
        'department_id',
    ];

    /**
     * 属性类型转换（仅包含安全的转换）
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'role_id' => 'integer',
        'department_id' => 'integer',
    ];
    protected $table = 'admin_role_department';
}
