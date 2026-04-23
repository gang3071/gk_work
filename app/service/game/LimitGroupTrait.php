<?php

namespace app\service\game;

use app\model\AdminUserLimitGroup;
use app\model\PlatformLimitGroupConfig;
use support\Log;

/**
 * 限红组配置复用 Trait
 * 用于 RSG、DG、ATG 等平台的限红组配置查询
 */
trait LimitGroupTrait
{
    /**
     * 获取限红组配置
     *
     * @param string $logChannel 日志通道名称（如：'rsg_server', 'dg_server', 'atg_server'）
     * @return PlatformLimitGroupConfig|null 返回限红组配置对象，如果没有配置则返回null
     */
    protected function getLimitGroupConfig(string $logChannel): ?PlatformLimitGroupConfig
    {
        // ✅ 缓存优化：限红组配置缓存30分钟
        $cacheKey = sprintf(
            'limit_group_config:%d:%d:%s',
            $this->platform->id,
            $this->player->id,
            $this->player->store_admin_id ?? '0'
        );

        $limitGroupConfig = \support\Cache::get($cacheKey);
        if ($limitGroupConfig !== null) {
            return $limitGroupConfig;
        }

        /** @var PlatformLimitGroupConfig|null $limitGroupConfig */
        $limitGroupConfig = null;

        // 如果玩家有店家ID，优先查询店家绑定的限红组配置
        if (!empty($this->player->store_admin_id)) {
            // 查询店家绑定的平台限红组配置
            /** @var AdminUserLimitGroup|null $adminUserLimitGroup */
            $adminUserLimitGroup = AdminUserLimitGroup::query()
                ->where('admin_user_id', $this->player->store_admin_id)
                ->where('platform_id', $this->platform->id)
                ->where('status', 1)
                ->first();

            // 如果店家有绑定限红组，获取该限红组的平台配置
            if ($adminUserLimitGroup) {
                /** @var PlatformLimitGroupConfig|null $limitGroupConfig */
                $limitGroupConfig = PlatformLimitGroupConfig::query()
                    ->where('limit_group_id', $adminUserLimitGroup->limit_group_id)
                    ->where('platform_id', $this->platform->id)
                    ->where('status', 1)
                    ->first();
            }
        }

        // 如果没有找到店家限红组配置，则使用平台的默认限红组配置
        if (!$limitGroupConfig && !empty($this->platform->default_limit_group_id)) {
            // 从游戏平台表的 default_limit_group_id 字段获取默认限红组配置
            /** @var PlatformLimitGroupConfig|null $limitGroupConfig */
            $limitGroupConfig = PlatformLimitGroupConfig::query()
                ->where('limit_group_id', $this->platform->default_limit_group_id)
                ->where('platform_id', $this->platform->id)
                ->where('status', 1)
                ->first();

            // 记录使用了默认限红组
            if ($limitGroupConfig instanceof PlatformLimitGroupConfig) {
                Log::channel($logChannel)->info($this->platform->code . '使用平台默认限红组', [
                    'player_id' => $this->player->id,
                    'store_admin_id' => $this->player->store_admin_id ?? 'null',
                    'default_limit_group_id' => $this->platform->default_limit_group_id,
                ]);
            }
        }

        // 缓存结果（包括null值，避免缓存穿透）
        \support\Cache::set($cacheKey, $limitGroupConfig, 1800);

        return $limitGroupConfig;
    }

    /**
     * 验证限红组配置数据是否存在
     *
     * @param PlatformLimitGroupConfig|null $limitGroupConfig 限红组配置对象
     * @return bool 如果配置存在且有config_data则返回true
     */
    protected function hasLimitGroupConfigData(?PlatformLimitGroupConfig $limitGroupConfig): bool
    {
        return $limitGroupConfig instanceof PlatformLimitGroupConfig
            && !empty($limitGroupConfig->config_data);
    }
}
