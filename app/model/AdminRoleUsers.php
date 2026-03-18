<?php

namespace app\model;


use Illuminate\Database\Eloquent\Model;

/**
 * 系统角色用户关联表模型
 *
 * 用于管理管理员用户与角色的多对多关系
 *
 * @property int $id 主键ID（自增）
 * @property int $role_id 角色ID，关联 admin_roles 表
 * @property int $user_id 用户ID，关联 admin_users 表
 *
 * @property-read AdminRole $role 关联的角色
 * @property-read AdminUser $user 关联的用户
 *
 * @package app\model
 * @table admin_role_users
 */
class AdminRoleUsers extends Model
{
    /**
     * 不使用时间戳
     * @var bool
     */
    public $timestamps = false;

    /**
     * 可批量赋值的属性
     * @var array
     */
    protected $fillable = [
        'role_id',
        'user_id',
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'role_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.role_user_table'));
    }

    /**
     * 关联角色
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function role()
    {
        return $this->belongsTo(
            plugin()->webman->config('database.role_model'),
            'role_id',
            'id'
        );
    }

    /**
     * 关联用户
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(
            plugin()->webman->config('database.user_model'),
            'user_id',
            'id'
        );
    }
}
