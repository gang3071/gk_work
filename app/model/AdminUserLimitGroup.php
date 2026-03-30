<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 店家限红分配模型
 *
 * @property int $id
 * @property int $admin_user_id
 * @property int $limit_group_id
 * @property int $platform_id
 * @property string $platform_code
 * @property int $assigned_by
 * @property string $assigned_at
 * @property string $remark
 * @property int $status
 */
class AdminUserLimitGroup extends Model
{
    use SoftDeletes;

    protected $table = 'admin_user_limit_group';

    protected $fillable = [
        'admin_user_id',
        'limit_group_id',
        'platform_id',
        'platform_code',
        'assigned_by',
        'assigned_at',
        'remark',
        'status',
    ];

    protected $casts = [
        'admin_user_id' => 'integer',
        'limit_group_id' => 'integer',
        'platform_id' => 'integer',
        'assigned_by' => 'integer',
        'status' => 'integer',
        'assigned_at' => 'datetime',
    ];

    /**
     * 所属店家
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

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

    /**
     * 分配人
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_by');
    }
}
