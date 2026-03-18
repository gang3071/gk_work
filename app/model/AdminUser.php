<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Cache;

/**
 * 系统管理员用户模型
 *
 * 管理所有后台系统的管理员账号，支持主站、渠道、代理、店家等多种类型
 *
 * 数据库字段
 * @property int $id 主键ID（自增）
 * @property string $username 用户账号/登录名（唯一）
 * @property string $password 密码（bcrypt加密）
 * @property string $nickname 用户昵称/真实姓名
 * @property string $avatar 用户头像URL
 * @property string $email 邮箱地址
 * @property string $phone 手机号码
 * @property int $status 账号状态：0=禁用，1=启用
 * @property int $type 用户类型：1=主站，2=渠道，3=代理，4=店家
 * @property string $remember_token 记住登录Token
 * @property int $department_id 所属部门ID，关联 admin_departments 表
 * @property int $parent_admin_id 上级管理员ID（店家的上级代理ID）
 * @property bool $is_super 是否渠道超级管理员：0=否，1=是
 * @property array $post 岗位ID数组（JSON格式）
 * @property float $ratio 分润比例（百分比，仅代理/店家使用）
 * @property float $adjust_amount 分润调整金额
 * @property int $last_settlement_timestamp 上次结算时间戳
 * @property float $settlement_amount 已结算金额
 * @property float $total_profit_amount 总分润金额（历史累计）
 * @property float $profit_amount 当前分润金额（待结算）
 * @property string $deleted_at 软删除时间
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *
 * 关联关系
 * @property-read \Illuminate\Database\Eloquent\Collection $roles 拥有的角色列表
 * @property-read AdminDepartment $department 所属部门/渠道
 * @property-read \Illuminate\Database\Eloquent\Collection $permissions 拥有的权限列表（通过角色）
 * @property-read Player $player 关联的玩家账号
 * @property-read PlayerPromoter $promoter 关联的推广员账号
 * @property-read AdminUser $parentAdmin 上级管理员（店家的上级代理）
 *
 * @package app\model
 * @table admin_users
 */
class AdminUser extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    /**
     * 用户类型常量
     */
    const TYPE_ADMIN = 1;       // 主站管理员
    const TYPE_CHANNEL = 2;     // 渠道管理员
    const TYPE_AGENT = 3;       // 代理管理员
    const TYPE_STORE = 4;       // 店家管理员

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0;  // 禁用
    const STATUS_ENABLED = 1;   // 启用

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'username',
        'password',
        'nickname',
        'avatar',
        'email',
        'phone',
        'status',
        'type',
        'department_id',
        'parent_admin_id',
        'is_super',
        'post',
        'ratio',
        'adjust_amount',
        'last_settlement_timestamp',
        'settlement_amount',
        'total_profit_amount',
        'profit_amount',
    ];

    /**
     * 序列化时隐藏的属性
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'type' => 'integer',
        'department_id' => 'integer',
        'parent_admin_id' => 'integer',
        'is_super' => 'boolean',
        'post' => 'array',
        'ratio' => 'float',
        'adjust_amount' => 'float',
        'last_settlement_timestamp' => 'integer',
        'settlement_amount' => 'float',
        'total_profit_amount' => 'float',
        'profit_amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 默认属性值
     *
     * @var array
     */
    protected $attributes = [
        'status' => self::STATUS_ENABLED,
        'type' => self::TYPE_ADMIN,
        'is_super' => false,
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.user_table'));
    }

    // ==================== 关联关系 ====================

    /**
     * 拥有的角色（多对多）
     *
     * 一个用户可以拥有多个角色
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles()
    {
        return $this->belongsToMany(
            plugin()->webman->config('database.role_model'),
            plugin()->webman->config('database.role_user_model'),
            'user_id',
            'role_id'
        );
    }

    /**
     * 所属部门/渠道
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function department()
    {
        return $this->belongsTo(
            plugin()->webman->config('database.department_model'),
            'department_id'
        );
    }

    /**
     * 拥有的权限（通过角色）
     *
     * 使用 HasManyThrough 获取用户通过角色拥有的所有权限
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function permissions()
    {
        return $this->hasManyThrough(
            plugin()->webman->config('database.role_permission_model'),
            plugin()->webman->config('database.role_user_model'),
            'user_id',
            'role_id',
            'id',
            'role_id'
        );
    }

    /**
     * 权限（兼容旧方法名）
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     * @deprecated 请使用 permissions() 方法
     */
    public function permission()
    {
        return $this->permissions();
    }

    /**
     * 上级管理员（用于店家查询上级代理）
     *
     * @return BelongsTo
     */
    public function parentAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'parent_admin_id');
    }

    /**
     * 下级店家列表（代理查询下级店家）
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function childStores()
    {
        return $this->hasMany(AdminUser::class, 'parent_admin_id');
    }

    /**
     * 代理下的所有玩家（通过agent_admin_id关联）
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function agentPlayers()
    {
        return $this->hasMany(
            plugin()->webman->config('database.player_model'),
            'agent_admin_id'
        );
    }

    /**
     * 店家下的所有玩家（通过store_admin_id关联）
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function storePlayers()
    {
        return $this->hasMany(
            plugin()->webman->config('database.player_model'),
            'store_admin_id'
        );
    }

    // ==================== 访问器/修改器 ====================

    /**
     * 设置密码（修改器）
     *
     * 自动使用 bcrypt 加密密码
     *
     * @param string $value 明文密码
     * @return void
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }

    // ==================== 事件监听 ====================

    /**
     * 模型的 "booted" 方法
     *
     * 监听用户更新事件，自动更新部门缓存
     *
     * @return void
     */
    protected static function booted()
    {
        static::updated(function (AdminUser $adminUser) {
            $cacheKey = 'admin_department_' . $adminUser->id;
            Cache::delete($cacheKey);

            // 查询同部门及下级部门的用户
            $data = AdminUser::query()
                ->whereNull('admin_users.deleted_at')
                ->select(['admin_users.department_id'])
                ->join('admin_department', 'admin_department.id', '=', 'admin_users.department_id')
                ->whereRaw("FIND_IN_SET({$adminUser->department_id}, admin_department.path)")
                ->get();

            Cache::set($cacheKey, $data);
        });
    }

    // ==================== 作用域查询 ====================

    /**
     * 查询启用状态的用户
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 根据用户类型查询
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $type 用户类型
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * 查询主站管理员
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAdmins($query)
    {
        return $query->where('type', self::TYPE_ADMIN);
    }

    /**
     * 查询渠道管理员
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeChannels($query)
    {
        return $query->where('type', self::TYPE_CHANNEL);
    }

    /**
     * 查询代理管理员
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAgents($query)
    {
        return $query->where('type', self::TYPE_AGENT);
    }

    /**
     * 查询店家管理员
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStores($query)
    {
        return $query->where('type', self::TYPE_STORE);
    }

    // ==================== 辅助方法 ====================

    /**
     * 检查是否为主站管理员
     *
     * @return bool
     */
    public function isAdmin()
    {
        return $this->type === self::TYPE_ADMIN;
    }

    /**
     * 检查是否为渠道管理员
     *
     * @return bool
     */
    public function isChannel()
    {
        return $this->type === self::TYPE_CHANNEL;
    }

    /**
     * 检查是否为代理管理员
     *
     * @return bool
     */
    public function isAgent()
    {
        return $this->type === self::TYPE_AGENT;
    }

    /**
     * 检查是否为店家管理员
     *
     * @return bool
     */
    public function isStore()
    {
        return $this->type === self::TYPE_STORE;
    }

    /**
     * 检查是否拥有指定角色
     *
     * @param int|string $role 角色ID或角色名称
     * @return bool
     */
    public function hasRole($role)
    {
        if (is_numeric($role)) {
            return $this->roles()->where('id', $role)->exists();
        }

        return $this->roles()->where('name', $role)->exists();
    }

    /**
     * 获取用户类型名称
     *
     * @return string
     */
    public function getTypeName()
    {
        $typeNames = [
            self::TYPE_ADMIN => '主站管理员',
            self::TYPE_CHANNEL => '渠道管理员',
            self::TYPE_AGENT => '代理管理员',
            self::TYPE_STORE => '店家管理员',
        ];

        return $typeNames[$this->type] ?? '未知';
    }

    /**
     * 获取状态名称
     *
     * @return string
     */
    public function getStatusName()
    {
        return $this->status === self::STATUS_ENABLED ? '启用' : '禁用';
    }
}
