<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Cache;
use support\Model;

/**
 * Class StoreSetting
 * 店家/代理配置表
 *
 * @property int id 主键
 * @property int department_id 部门/渠道id
 * @property int admin_user_id 绑定的后台账号ID（代理/店家配置使用，0表示渠道配置）
 * @property string feature 功能名称
 * @property int num 数量
 * @property string content 内容
 * @property string date_start 开始时间
 * @property string date_end 结束时间
 * @property int status 状态
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @package addons\webman\model
 */
class StoreSetting extends Model
{
    use HasDateTimeFormatter, SoftDeletes;

    protected $table = 'store_setting';

    protected $fillable = ['department_id', 'admin_user_id', 'feature', 'num', 'content', 'date_start', 'date_end', 'status'];

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    /**
     * 关联后台账号（代理/店家）
     */
    public function adminUser()
    {
        return $this->belongsTo(\app\model\AdminUser::class, 'admin_user_id', 'id');
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function (StoreSetting $setting) {
            $cacheKey = self::getCacheKey($setting->department_id, $setting->admin_user_id, $setting->feature);
            Cache::delete($cacheKey);
        });

        static::deleted(function (StoreSetting $setting) {
            $cacheKey = self::getCacheKey($setting->department_id, $setting->admin_user_id, $setting->feature);
            Cache::delete($cacheKey);
        });

        static::updated(function (StoreSetting $setting) {
            $cacheKey = self::getCacheKey($setting->department_id, $setting->admin_user_id, $setting->feature);
            Cache::delete($cacheKey);
        });
    }

    /**
     * 获取缓存键名
     */
    protected static function getCacheKey($departmentId, $adminUserId, $feature)
    {
        $identifier = $adminUserId ?? 'all';
        return 'store-setting-' . $departmentId . '-' . $identifier . '-' . $feature;
    }

    /**
     * 获取配置（带优先级）
     * 优先级：具体代理/店家 > 渠道配置 > 总配置
     *
     * @param string $feature 功能名称
     * @param int $departmentId 部门ID
     * @param int|null $playerId 玩家ID（已废弃，保留参数兼容性）
     * @param int|null $adminUserId 后台账号ID（代理/店家）
     * @return StoreSetting|null
     */
    public static function getSetting($feature, $departmentId = 0, $playerId = null, $adminUserId = null)
    {
        $cacheKey = self::getCacheKey($departmentId, $adminUserId, $feature);

        // 尝试从缓存获取
        $setting = Cache::get($cacheKey);

        if ($setting !== null) {
            return $setting;
        }

        // 查询配置（按优先级排序）
        $setting = self::where('feature', $feature)
            ->where('status', 1)
            ->where(function ($query) use ($departmentId, $adminUserId) {
                // 具体代理/店家配置（admin_user_id）
                $query->where(function ($q) use ($departmentId, $adminUserId) {
                    if ($adminUserId) {
                        $q->where('department_id', $departmentId)
                            ->where('admin_user_id', $adminUserId);
                    }
                })
                    // 渠道配置
                    ->orWhere(function ($q) use ($departmentId) {
                        if ($departmentId) {
                            $q->where('department_id', $departmentId)
                                ->where('admin_user_id', 0);
                        }
                    })
                    // 总配置
                    ->orWhere(function ($q) {
                        $q->where('department_id', 0)
                            ->where('admin_user_id', 0);
                    });
            })
            ->orderByRaw("
                CASE
                    WHEN admin_user_id > 0 THEN 1
                    WHEN department_id != 0 THEN 2
                    ELSE 3
                END
            ")
            ->first();

        // 缓存结果（包括 null 值，避免缓存穿透）
        Cache::set($cacheKey, $setting, 3600);

        return $setting;
    }
}