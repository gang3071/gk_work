<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 设备IP绑定模型
 *
 * @property int $id 主键ID
 * @property int $device_id 设备ID
 * @property string $ip_address IP地址
 * @property int $ip_type IP类型(1:IPv4,2:IPv6)
 * @property int $status 状态(0:禁用,1:启用)
 * @property string $remark 备注
 * @property string $last_used_at 最后使用时间
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *
 * @property Device $device 所属设备
 */
class DeviceIp extends Model
{
    use HasDateTimeFormatter;

    /**
     * 表名
     * @var string
     */
    protected $table = 'device_ip';

    /**
     * 可批量赋值的属性
     * @var array
     */
    protected $fillable = [
        'device_id',
        'ip_address',
        'ip_type',
        'status',
        'remark',
        'last_used_at',
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'device_id' => 'integer',
        'ip_type' => 'integer',
        'status' => 'integer',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * IP类型常量
     */
    const IP_TYPE_IPV4 = 1; // IPv4
    const IP_TYPE_IPV6 = 2; // IPv6

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0; // 禁用
    const STATUS_ENABLED = 1;  // 启用

    /**
     * IP类型列表
     * @return array
     */
    public static function getIpTypeList(): array
    {
        return [
            self::IP_TYPE_IPV4 => 'IPv4',
            self::IP_TYPE_IPV6 => 'IPv6',
        ];
    }

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
     * 获取IP类型文本
     * @return string
     */
    public function getIpTypeTextAttribute(): string
    {
        $typeList = self::getIpTypeList();
        return $typeList[$this->ip_type] ?? '';
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
     * 所属设备
     * @return BelongsTo
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'id');
    }

    /**
     * 验证IP格式
     * @param string $ipAddress IP地址
     * @return bool
     */
    public static function validateIpAddress(string $ipAddress): bool
    {
        // 验证IPv4
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        // 验证IPv6
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }

        return false;
    }

    /**
     * 检测IP类型
     * @param string $ipAddress IP地址
     * @return int
     */
    public static function detectIpType(string $ipAddress): int
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::IP_TYPE_IPV4;
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::IP_TYPE_IPV6;
        }

        return self::IP_TYPE_IPV4; // 默认IPv4
    }

    /**
     * 更新最后使用时间
     * @return bool
     */
    public function updateLastUsedAt(): bool
    {
        $this->last_used_at = now();
        return $this->save();
    }

    /**
     * 模型事件
     */
    protected static function boot()
    {
        parent::boot();

        // 创建前自动检测IP类型
        static::creating(function ($model) {
            if (empty($model->ip_type)) {
                $model->ip_type = self::detectIpType($model->ip_address);
            }
        });
    }
}
