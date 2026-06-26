<?php

namespace app\service;

use app\model\Player;
use support\Cache;

/**
 * 玩家信息缓存服务
 *
 * 用途：减少电子游戏平台对玩家表的频繁查询
 *
 * 缓存策略：
 * - 只缓存静态字段（id, uuid, department_id 等）
 * - 不缓存实时字段（余额由 Redis 管理）
 * - TTL: 10 分钟（玩家信息变更不频繁）
 */
class PlayerCacheService
{
    /**
     * 缓存 KEY 前缀
     */
    private const CACHE_PREFIX_BY_UUID = 'player:cache:uuid:';
    private const CACHE_PREFIX_BY_ID = 'player:cache:id:';

    /**
     * 缓存过期时间（秒）
     */
    private const CACHE_TTL = 600; // 10 分钟

    /**
     * 通过 UUID 获取玩家信息（带缓存）
     *
     * @param string $uuid 玩家 UUID
     * @param bool $withTrashed 是否包含软删除的玩家
     * @return Player|null
     */
    public static function getByUuid(string $uuid, bool $withTrashed = false): ?Player
    {
        $cacheKey = self::CACHE_PREFIX_BY_UUID . $uuid;

        // 1. 尝试从缓存获取玩家 ID
        $cachedPlayerId = Cache::get($cacheKey);

        if ($cachedPlayerId !== null) {
            // 缓存命中，通过 ID 查询（主键查询很快）
            $query = Player::query();
            if ($withTrashed) {
                $query->withTrashed();
            }

            $player = $query->find($cachedPlayerId);

            if ($player) {
                return $player;
            }

            // 玩家已删除，清除缓存
            Cache::delete($cacheKey);
            Cache::delete(self::CACHE_PREFIX_BY_ID . $cachedPlayerId);
        }

        // 2. 缓存未命中，从数据库查询
        $query = Player::query()->where('uuid', $uuid);
        if ($withTrashed) {
            $query->withTrashed();
        }

        $player = $query->first();

        if ($player) {
            // 缓存玩家 ID
            self::cachePlayer($player);
        }

        return $player;
    }

    /**
     * 通过 ID 获取玩家信息（带缓存）
     *
     * @param int $playerId 玩家 ID
     * @param bool $withTrashed 是否包含软删除的玩家
     * @return Player|null
     */
    public static function getById(int $playerId, bool $withTrashed = false): ?Player
    {
        $cacheKey = self::CACHE_PREFIX_BY_ID . $playerId;

        // 1. 尝试从缓存获取
        $cachedData = Cache::get($cacheKey);

        if ($cachedData !== null && isset($cachedData['uuid'])) {
            // 缓存命中，验证玩家是否还存在
            $query = Player::query();
            if ($withTrashed) {
                $query->withTrashed();
            }

            $player = $query->find($playerId);

            if ($player) {
                return $player;
            }

            // 玩家已删除，清除缓存
            Cache::delete($cacheKey);
            Cache::delete(self::CACHE_PREFIX_BY_UUID . $cachedData['uuid']);
        }

        // 2. 缓存未命中，从数据库查询
        $query = Player::query();
        if ($withTrashed) {
            $query->withTrashed();
        }

        $player = $query->find($playerId);

        if ($player) {
            // 缓存玩家信息
            self::cachePlayer($player);
        }

        return $player;
    }

    /**
     * 缓存玩家信息
     *
     * @param Player $player 玩家对象
     */
    private static function cachePlayer(Player $player): void
    {
        // 缓存 uuid → id 映射
        Cache::set(
            self::CACHE_PREFIX_BY_UUID . $player->uuid,
            $player->id,
            self::CACHE_TTL
        );

        // 缓存 id → 基本信息映射（用于验证）
        Cache::set(
            self::CACHE_PREFIX_BY_ID . $player->id,
            ['uuid' => $player->uuid],
            self::CACHE_TTL
        );
    }

    /**
     * 清除玩家缓存
     *
     * 使用场景：
     * - 玩家信息更新时
     * - 玩家被删除时
     *
     * @param int|null $playerId 玩家 ID
     * @param string|null $uuid 玩家 UUID
     */
    public static function clear(?int $playerId = null, ?string $uuid = null): void
    {
        if ($playerId) {
            $cacheKey = self::CACHE_PREFIX_BY_ID . $playerId;
            $cachedData = Cache::get($cacheKey);

            Cache::delete($cacheKey);

            if ($cachedData && isset($cachedData['uuid'])) {
                Cache::delete(self::CACHE_PREFIX_BY_UUID . $cachedData['uuid']);
            }
        }

        if ($uuid) {
            $cacheKey = self::CACHE_PREFIX_BY_UUID . $uuid;
            $cachedPlayerId = Cache::get($cacheKey);

            Cache::delete($cacheKey);

            if ($cachedPlayerId) {
                Cache::delete(self::CACHE_PREFIX_BY_ID . $cachedPlayerId);
            }
        }
    }

    /**
     * 批量清除玩家缓存
     *
     * @param array $playerIds 玩家 ID 数组
     */
    public static function clearBatch(array $playerIds): void
    {
        foreach ($playerIds as $playerId) {
            self::clear($playerId);
        }
    }
}
