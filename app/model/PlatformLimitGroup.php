<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 限红组模型
 *
 * @property int $id
 * @property int $department_id
 * @property string $code
 * @property string $name
 * @property string $description
 * @property int $status
 * @property int $sort
 */
class PlatformLimitGroup extends Model
{
    use SoftDeletes;

    protected $table = 'platform_limit_group';

    protected $fillable = [
        'department_id',
        'code',
        'name',
        'description',
        'status',
        'sort',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'status' => 'integer',
        'sort' => 'integer',
    ];

    /**
     * 平台配置
     */
    public function configs(): HasMany
    {
        return $this->hasMany(PlatformLimitGroupConfig::class, 'limit_group_id');
    }

    /**
     * 店家分配
     */
    public function adminUserAssignments(): HasMany
    {
        return $this->hasMany(AdminUserLimitGroup::class, 'limit_group_id');
    }

    /**
     * 获取特定平台的配置
     */
    public function getPlatformConfig(int $platformId): ?PlatformLimitGroupConfig
    {
        return $this->configs()
            ->where('platform_id', $platformId)
            ->where('status', 1)
            ->first();
    }
}
