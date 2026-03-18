<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 设备管理模型
 *
 * @property int $id 主键ID
 * @property int $channel_id 所属渠道ID
 * @property int $department_id 所属部门ID
 * @property int $agent_admin_id 所属代理ID
 * @property int $store_admin_id 所属店家ID
 * @property string $device_name 设备名称
 * @property string $device_no 设备号（安卓设备唯一标识）
 * @property string $device_model 设备型号
 * @property int $status 状态(0:禁用,1:启用)
 * @property string $remark 备注
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string $deleted_at 删除时间
 *
 * @property Channel $channel 所属渠道
 * @property AdminDepartment $department 所属部门
 * @property AdminUser $agent 所属代理
 * @property AdminUser $store 所属店家
 * @property DeviceIp[] $deviceIps 绑定的IP列表
 */
class Device extends Model
{
    use HasDateTimeFormatter, SoftDeletes;

    /**
     * 表名
     * @var string
     */
    protected $table = 'device';

    /**
     * 可批量赋值的属性
     * @var array
     */
    protected $fillable = [
        'channel_id',
        'department_id',
        'agent_admin_id',
        'store_admin_id',
        'device_name',
        'device_no',
        'device_model',
        'status',
        'remark',
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'channel_id' => 'integer',
        'department_id' => 'integer',
        'agent_admin_id' => 'integer',
        'store_admin_id' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0; // 禁用
    const STATUS_ENABLED = 1;  // 启用

    /**
     * 状态列表
     * @return array
     */
    public static function getStatusList(): array
    {
        return [
            self::STATUS_DISABLED => admin_trans('device.status.disabled'),
            self::STATUS_ENABLED => admin_trans('device.status.enabled'),
        ];
    }

    /**
     * 获取状态文本
     * @return string
     */
    public function getStatusTextAttribute(): string
    {
        $statusList = self::getStatusList();
        return $statusList[$this->status] ?? '';
    }

    /**
     * 所属渠道
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'id');
    }

    /**
     * 所属部门
     * @return BelongsTo
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(AdminDepartment::class, 'department_id', 'id');
    }

    /**
     * 所属代理
     * @return BelongsTo
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'agent_admin_id', 'id');
    }

    /**
     * 所属店家
     * @return BelongsTo
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'store_admin_id', 'id');
    }

    /**
     * 绑定的IP列表
     * @return HasMany
     */
    public function deviceIps(): HasMany
    {
        return $this->hasMany(DeviceIp::class, 'device_id', 'id');
    }

    /**
     * 启用状态的IP列表
     * @return HasMany
     */
    public function activeDeviceIps(): HasMany
    {
        return $this->hasMany(DeviceIp::class, 'device_id', 'id')
            ->where('status', DeviceIp::STATUS_ENABLED);
    }

    /**
     * 验证设备号和IP是否合法
     * @param string $deviceNo 设备号
     * @param string $ipAddress IP地址
     * @return bool
     */
    public static function verifyDeviceAndIp(string $deviceNo, string $ipAddress): bool
    {
        $device = self::where('device_no', $deviceNo)
            ->where('status', self::STATUS_ENABLED)
            ->first();

        if (!$device) {
            return false;
        }

        // 检查IP是否绑定且启用
        return $device->activeDeviceIps()
            ->where('ip_address', $ipAddress)
            ->exists();
    }

    /**
     * 获取设备绑定的所有启用IP
     * @param string $deviceNo 设备号
     * @return array
     */
    public static function getDeviceAllowedIps(string $deviceNo): array
    {
        $device = self::where('device_no', $deviceNo)
            ->where('status', self::STATUS_ENABLED)
            ->first();

        if (!$device) {
            return [];
        }

        return $device->activeDeviceIps()
            ->pluck('ip_address')
            ->toArray();
    }

    /**
     * 记录设备访问日志
     * @param string $deviceNo 设备号
     * @param string $ipAddress IP地址
     * @param bool $isAllowed 是否允许
     * @param string $rejectReason 拒绝原因
     * @param string $requestUrl 请求URL
     * @param string $userAgent User Agent
     * @return void
     */
    public static function logAccess(
        string $deviceNo,
        string $ipAddress,
        bool   $isAllowed = true,
        string $rejectReason = '',
        string $requestUrl = '',
        string $userAgent = ''
    ): void
    {
        $device = self::where('device_no', $deviceNo)->first();

        DeviceAccessLog::create([
            'device_id' => $device->id ?? 0,
            'device_no' => $deviceNo,
            'ip_address' => $ipAddress,
            'is_allowed' => $isAllowed ? 1 : 0,
            'reject_reason' => $rejectReason,
            'request_url' => $requestUrl,
            'user_agent' => $userAgent,
        ]);
    }
}
