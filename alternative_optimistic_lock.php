<?php
/**
 * 可选方案：乐观锁避免重复读取
 *
 * 原理：
 * 1. Lua脚本读取时同时获取version字段
 * 2. Worker写MySQL前检查version是否变化
 * 3. 如果变化，才重新读取
 */

// ============ Lua脚本改动 ============

$LUA_GET_PENDING_RECORDS_WITH_VERSION = <<<'LUA'
local queue_key = KEYS[1]
local limit = tonumber(ARGV[1])
local current_time = tonumber(ARGV[2])
local timeout = tonumber(ARGV[3])

local keys = redis.call('ZRANGE', queue_key, 0, limit - 1)
local result = {}

for i, key in ipairs(keys) do
    local exists = redis.call('EXISTS', key)
    if exists == 1 then
        local status = redis.call('HGET', key, 'status') or ''
        local processing_time = tonumber(redis.call('HGET', key, 'processing_time') or 0)

        if status == 'pending' or (status == 'processing' and current_time - processing_time > timeout) then
            -- 标记为处理中
            redis.call('HSET', key, 'status', 'processing')
            redis.call('HSET', key, 'processing_time', current_time)

            -- ✅ 新增：读取version（用于乐观锁）
            local version = redis.call('HGET', key, 'version') or '0'

            -- 返回：key + version
            table.insert(result, key .. '|' .. version)
        end
    end
end

return result
LUA;

// ============ saveSettle改动 ============

function saveSettle_with_version($platform, $data) {
    // ...
    if ($betExists) {
        $currentStatus = self::redis()->hGet($betKey, 'status') ?? 'pending';
        $newStatus = $currentStatus === 'processing' ? 'processing' : 'pending';

        // ✅ 新增：递增version
        $newVersion = self::redis()->hIncrBy($betKey, 'version', 1);

        self::redis()->hMSet($betKey, [
            'settlement_status' => 1,
            'status' => $newStatus,
            // version字段已通过hIncrBy更新，不需要再设置
        ]);
    }
}

// ============ Worker改动 ============

function syncBatchRecords_optimistic_lock($records) {
    // 解析version
    $versions = [];
    foreach ($records as $record) {
        $redisKey = $record['redis_key'];
        $version = $record['version'] ?? 0;  // Lua返回的version
        $versions[$redisKey] = $version;
    }

    // 去重（只检查version是否变化）
    $mergedRecords = [];
    $needRefresh = [];  // 需要重新读取的key

    foreach ($records as $record) {
        $redisKey = $record['redis_key'];
        if (!isset($mergedRecords[$redisKey])) {
            $mergedRecords[$redisKey] = $record;
        } else {
            // 批次内重复，检查version
            if ($record['version'] != $versions[$redisKey]) {
                // version变化了，标记需要重新读取
                $needRefresh[$redisKey] = true;
            }
        }
    }

    // ✅ 只重新读取version变化的记录（大部分情况下是0条）
    if (!empty($needRefresh)) {
        $redis = Redis::connection('work');
        $pipe = $redis->pipeline();
        foreach (array_keys($needRefresh) as $key) {
            $pipe->hGetAll($key);
        }
        $results = $pipe->execute();

        $keysList = array_keys($needRefresh);
        foreach ($results as $index => $latestData) {
            $redisKey = $keysList[$index];
            if (!empty($latestData)) {
                $latestData['redis_key'] = $redisKey;
                $mergedRecords[$redisKey] = $latestData;
            }
        }
    }

    // 后续流程不变...
}

/**
 * 性能对比：
 *
 * 方案1（当前Pipeline）：
 *   - 总是重新读取：200条 × Pipeline = 20-30ms
 *   - 优点：简单可靠
 *   - 缺点：即使没变化也要读
 *
 * 方案2（乐观锁）：
 *   - 只读取变化的：假设5%变化 = 10条 × Pipeline = 2-3ms
 *   - 优点：性能最优（95%情况下0ms）
 *   - 缺点：代码复杂，需要Lua脚本支持
 *
 * 建议：
 *   - 性能要求不高：用方案1（Pipeline）
 *   - 性能要求极高：用方案2（乐观锁）
 */
