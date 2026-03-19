<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use support\Cache;

/**
 * 系统角色模型
 *
 * 管理系统中的所有角色及其权限配置，支持多种后台类型和数据权限控制
 *
 * 数据库字段
 * @property int $id 主键ID（自增）
 * @property string $name 角色名称
 * @property string $desc 角色描述/备注说明
 * @property int $sort 排序权重，数值越大越靠前
 * @property int $data_type 数据权限类型：0=全部数据，1=自定义数据，2=本部门及以下，3=本部门，4=仅本人
 * @property int $type 后台类型：1=总后台，2=渠道后台，3=代理后台，4=店家后台
 * @property bool $check_strictly 是否严格检查权限（父子级联）
 * @property int $is_protected 是否受保护（1=不可删除，0=可删除）
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *
 *
 * @package app\model
 * @table admin_roles
 */
class AdminRole extends Model
{
    /**
     * 后台类型常量
     */
    const TYPE_ADMIN = 1;       // 总后台
    const TYPE_CHANNEL = 2;     // 渠道后台
    const TYPE_AGENT = 3;       // 代理后台
    const TYPE_STORE = 4;       // 店家后台

    /**
     * 特殊角色常量
     */
    const ROLE_CHANNEL = 3;     // 渠道管理员角色ID
    const ROLE_AGENT = 18;      // 线下渠道代理角色ID（从 config/app.php 中定义）
    const ROLE_STORE = 19;      // 线下渠道店家角色ID（从 config/app.php 中定义）

    /**
     * 数据权限类型常量
     */
    const DATA_TYPE_ALL = 0;                // 全部数据权限
    const DATA_TYPE_CUSTOM = 1;             // 自定义数据权限
    const DATA_TYPE_DEPARTMENT_BELOW = 2;   // 本部门及以下数据权限
    const DATA_TYPE_DEPARTMENT = 3;         // 本部门数据权限
    const DATA_TYPE_SELF = 4;               // 本人数据权限

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'desc',
        'sort',
        'data_type',
        'type',
        'check_strictly',
        'is_protected',
    ];

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'sort' => 'integer',
        'data_type' => 'integer',
        'type' => 'integer',
        'check_strictly' => 'boolean',
        'is_protected' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 默认属性值
     *
     * @var array
     */
    protected $attributes = [
        'sort' => 0,
        'data_type' => self::DATA_TYPE_ALL,
        'type' => self::TYPE_ADMIN,
        'check_strictly' => false,
    ];
    protected $table = 'admin_roles';

    /**
     * 模型的 "booted" 方法
     *
     * 监听角色更新事件，自动清除相关用户的缓存
     * 监听删除事件，阻止删除受保护的角色
     * 监听更新前事件，阻止修改受保护角色的关键字段
     *
     * @return void
     */
    protected static function booted()
    {
        // 更新前检查：阻止修改受保护角色的关键字段
        static::updating(function (AdminRole $adminRole) {
            $isProtected = ($adminRole->is_protected ?? 0) == 1 ||
                in_array($adminRole->id, [self::ROLE_CHANNEL, self::ROLE_AGENT, self::ROLE_STORE]);

            if ($isProtected) {
                // 获取原始数据
                $original = $adminRole->getOriginal();

                // 不允许修改角色名称
                if ($adminRole->isDirty('name') && $adminRole->name != $original['name']) {
                    throw new \Exception('系统内置角色不允许修改名称');
                }

                // 不允许修改角色类型
                if ($adminRole->isDirty('type') && $adminRole->type != $original['type']) {
                    throw new \Exception('系统内置角色不允许修改类型');
                }

                // 不允许取消保护标记
                if ($adminRole->isDirty('is_protected') && $adminRole->is_protected != 1) {
                    throw new \Exception('系统内置角色不允许取消保护');
                }
            }
        });

        static::updated(function (AdminRole $adminRole) {
            // 清除拥有该角色的所有用户的缓存
            $roleUsers = AdminRoleUsers::query()->where('role_id', $adminRole->id)->get();

            /** @var AdminRoleUsers $roleUser */
            foreach ($roleUsers as $roleUser) {
                $cacheKey = 'role_user_' . $roleUser->user_id;
                Cache::delete($cacheKey);
            }
        });

        // 阻止删除受保护的角色
        static::deleting(function (AdminRole $adminRole) {
            // 检查是否为受保护的角色
            if (($adminRole->is_protected ?? 0) == 1) {
                throw new \Exception('该角色为系统内置角色，不允许删除');
            }

            // 检查特定角色ID（渠道、代理、店家超管）
            if (in_array($adminRole->id, [self::ROLE_CHANNEL, self::ROLE_AGENT, self::ROLE_STORE])) {
                throw new \Exception('该角色为系统内置角色，不允许删除');
            }
        });
    }

    // ==================== 关联关系 ====================

    /**
     * 关联部门（多对多）
     *
     * 一个角色可以关联多个部门
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function departments()
    {
        return $this->belongsToMany(
            plugin()->webman->config('database.department_model'),
            plugin()->webman->config('database.role_department_model'),
            'role_id',
            'department_id'
        );
    }

    /**
     * 关联部门（兼容旧方法名）
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     * @deprecated 请使用 departments() 方法
     */
    public function department()
    {
        return $this->departments();
    }

    /**
     * 拥有该角色的用户（多对多）
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(
            plugin()->webman->config('database.user_model'),
            plugin()->webman->config('database.role_user_table'),
            'role_id',
            'user_id'
        );
    }

    /**
     * 拥有的菜单权限（多对多）
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function menus()
    {
        return $this->belongsToMany(
            plugin()->webman->config('database.menu_model'),
            plugin()->webman->config('database.role_menu_table'),
            'role_id',
            'menu_id'
        );
    }

    /**
     * 拥有的功能权限（多对多）
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function permissions()
    {
        return $this->belongsToMany(
            plugin()->webman->config('database.permission_model'),
            plugin()->webman->config('database.role_permission_table'),
            'role_id',
            'permission_id'
        );
    }

    // ==================== 访问器/修改器 ====================

    /**
     * 设置严格检查属性（修改器）
     *
     * @param mixed $value
     * @return void
     */
    protected function setCheckStrictlyAttribute($value)
    {
        $this->attributes['check_strictly'] = (int)$value;
    }

    /**
     * 获取严格检查属性（访问器）
     *
     * @param mixed $value
     * @return bool
     */
    protected function getCheckStrictlyAttribute($value)
    {
        return (bool)$value;
    }

    // ==================== 辅助方法 ====================

    /**
     * 检查是否为代理角色
     *
     * @return bool
     */
    public function isAgentRole()
    {
        return $this->id == config('app.agent_role', self::ROLE_AGENT);
    }

    /**
     * 检查是否为店家角色
     *
     * @return bool
     */
    public function isStoreRole()
    {
        return $this->id == config('app.store_role', self::ROLE_STORE);
    }

    /**
     * 获取后台类型名称
     *
     * @return string
     */
    public function getTypeName()
    {
        $typeNames = [
            self::TYPE_ADMIN => '总后台',
            self::TYPE_CHANNEL => '渠道后台',
            self::TYPE_AGENT => '代理后台',
            self::TYPE_STORE => '店家后台',
        ];

        return $typeNames[$this->type] ?? '未知';
    }

    /**
     * 获取数据权限类型名称
     *
     * @return string
     */
    public function getDataTypeName()
    {
        $dataTypeNames = [
            self::DATA_TYPE_ALL => '全部数据',
            self::DATA_TYPE_CUSTOM => '自定义数据',
            self::DATA_TYPE_DEPARTMENT_BELOW => '本部门及以下',
            self::DATA_TYPE_DEPARTMENT => '本部门数据',
            self::DATA_TYPE_SELF => '仅本人数据',
        ];

        return $dataTypeNames[$this->data_type] ?? '未知';
    }
}
