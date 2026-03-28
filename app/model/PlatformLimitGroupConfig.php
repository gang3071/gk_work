<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 限红组平台配置模型
 *
 * @property int $id
 * @property int $limit_group_id
 * @property int $platform_id
 * @property string $platform_code
 * @property array $config_data
 * @property int $status
 */
class PlatformLimitGroupConfig extends Model
{
    use SoftDeletes;

    protected $table = 'platform_limit_group_config';

    protected $fillable = [
        'limit_group_id',
        'platform_id',
        'platform_code',
        'config_data',
        'status',
    ];

    protected $casts = [
        'limit_group_id' => 'integer',
        'platform_id' => 'integer',
        'config_data' => 'array',
        'status' => 'integer',
    ];

    /**
     * 所属限红组
     */
    public function limitGroup(): BelongsTo
    {
        return $this->belongsTo(PlatformLimitGroup::class, 'limit_group_id');
    }

    /**
     * 游戏平台
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(GamePlatform::class, 'platform_id');
    }
}
