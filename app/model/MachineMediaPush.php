<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class MachineMedia
 * @property int id 主键
 * @property int machine_id 机台id
 * @property int media_id 機台影像ip
 * @property string endpoint_service_id endpointServiceId
 * @property string machine_tencent_play_id 腾讯配置id
 * @property string expiration_date 到期时间
 * @property string machine_code 机器编号
 * @property int status 狀態，0=停用, 1=啟用
 * @property string rtmp_url rtmp地址
 * @property string created_at 创建时间
 * @property string updated_at 更新时间
 *
 * @property Machine machine
 * @property MachineMedia media
 * @property machineTencentPlay machineTencentPlay
 * @package app\model
 */
class MachineMediaPush extends Model
{
    use HasDateTimeFormatter;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.machine_media_push_table'));
    }

    /**
     * 关联机台
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_model'), 'machine_id')->withTrashed();
    }

    /**
     * 关联机台媒体流
     * @return BelongsTo
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_media_model'), 'media_id')->withTrashed();
    }

    /**
     * 关联腾讯云配置
     * @return BelongsTo
     */
    public function machineTencentPlay(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_tencent_play_model'),
            'machine_tencent_play_id');
    }
}
