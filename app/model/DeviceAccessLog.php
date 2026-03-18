<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 设备访问日志模型
 *
 * @property int $id 主键ID
 * @property int $device_id 设备ID
 * @property string $device_no 设备号
 * @property string $ip_address 访问IP地址
 * @property int $is_allowed 是否允许(0:拒绝,1:允许)
 * @property string $reject_reason 拒绝原因
 * @property string $request_url 请求URL
 * @property string $user_agent User Agent
 * @property string $created_at 创建时间
 *
 * @property Device $device 所属设备
 */
class DeviceAccessLog extends Model
{
    use HasDateTimeFormatter;

    /**
     * 表名
     * @var string
     */
    protected $table = 'device_access_log';

    /**
     * 表示模型是否应该使用时间戳
     * @var bool
     */
    public $timestamps = false;

    /**
     * 可批量赋值的属性
     * @var array
     */
    protected $fillable = [
        'device_id',
        'device_no',
        'ip_address',
        'is_allowed',
        'reject_reason',
        'request_url',
        'user_agent',
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'device_id' => 'integer',
        'is_allowed' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * 是否允许常量
     */
    const IS_ALLOWED_NO = 0;  // 拒绝
    const IS_ALLOWED_YES = 1; // 允许

    /**
     * 所属设备
     * @return BelongsTo
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'id');
    }

    /**
     * 获取是否允许文本
     * @return string
     */
    public function getIsAllowedTextAttribute(): string
    {
        return $this->is_allowed === self::IS_ALLOWED_YES
            ? admin_trans('device.access_log.allowed')
            : admin_trans('device.access_log.rejected');
    }
}
