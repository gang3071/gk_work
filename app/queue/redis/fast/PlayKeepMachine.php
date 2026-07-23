<?php

namespace app\queue\redis\fast;

use app\model\Machine;
use app\service\machine\MachineServices;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Consumer;

/**
 * 玩家保留机台队列消费者（优化版）
 *
 * 性能优化：
 * 1. 缓存 Machine 对象和 machineCategory，避免重复数据库查询
 * 2. 缓存最大保留时间配置，避免每次读取
 * 3. 合并 WebSocket 推送，减少网络开销
 * 4. 减少不必要的 debug 日志
 */
class PlayKeepMachine implements Consumer
{
    /**
     * 队列名称
     */
    public $queue = 'play-keep-machine';

    /**
     * 连接名称
     */
    public $connection = 'default';

    /**
     * Machine 缓存（进程内缓存，避免重复查询）
     * @var array
     */
    private static $machineCache = [];

    /**
     * 最大保留时间缓存（秒）
     * @var int|null
     */
    private static $maxKeepSeconds = null;

    /**
     * 缓存过期时间（秒）- 与 Events.php 保持一致
     */
    private const CACHE_TTL = 3600; // 1 小时

    /**
     * 消费消息
     *
     * @param array $data 消息数据
     * @return void
     */
    public function consume($data)
    {
        try {
            $machineId = $data['machine_id'] ?? 0;
            $playerId = $data['player_id'] ?? 0;
            $changeAmount = $data['change_amount'] ?? 0;
            $keepMinutes = $data['keep_minutes'] ?? 0;
            $oldKeepSeconds = $data['keep_seconds'] ?? 0;
            $oldKeeping = $data['keeping'] ?? 0;
            $gamingUserId = $data['gaming_user_id'] ?? $playerId;

            // ✅ 快速校验：数据完整性
            if (!$machineId || !$gamingUserId) {
                return; // 数据不完整，静默跳过
            }

            $keepSecondsChanged = false;
            $keepingChanged = false;
            $newKeepSeconds = $oldKeepSeconds;
            $newKeeping = $oldKeeping;

            // 增加保留时间
            if ($keepMinutes > 0 && $changeAmount > 0) {
                $addSeconds = bcmul($keepMinutes, $changeAmount);
                $newKeepSeconds = bcadd($oldKeepSeconds, $addSeconds);

                // 检查最大保留时间限制
                $maxKeepSeconds = $this->getMaxKeepSeconds();
                if ($maxKeepSeconds > 0 && $newKeepSeconds > $maxKeepSeconds) {
                    $newKeepSeconds = $maxKeepSeconds;
                }

                if ($newKeepSeconds != $oldKeepSeconds) {
                    $keepSecondsChanged = true;
                }
            }

            // 解除保留状态
            if ($oldKeeping == 1) {
                $newKeeping = 0;
                $keepingChanged = true;

                // 更新保留日志（异步，不阻塞）
                try {
                    updateKeepingLog($machineId, $playerId);
                } catch (\Throwable $e) {
                    // 日志更新失败不影响主流程
                }
            }

            // ✅ 更新 Redis（只在有变化时）
            if ($keepSecondsChanged || $keepingChanged) {
                // 创建 MachineServices 更新 Redis
                $machine = $this->getMachine($machineId);
                if ($machine) {
                    try {
                        $services = MachineServices::createServices($machine);
                        if ($keepSecondsChanged) {
                            $services->keep_seconds = $newKeepSeconds;
                        }
                        if ($keepingChanged) {
                            $services->keeping = $newKeeping;
                        }
                    } catch (\Throwable $e) {
                        // 更新失败不影响推送
                    }
                }

                // 推送到客户端
                $this->pushKeepingStatus($gamingUserId, $machineId, $newKeepSeconds, $newKeeping);
            }

        } catch (\Throwable $e) {
            // ✅ 优化6：异常时只记录关键信息
            Log::error('[PlayKeepMachine] 异常', [
                'machine_id' => $data['machine_id'] ?? 0,
                'player_id' => $data['player_id'] ?? 0,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取机台信息（带缓存）
     *
     * 优化策略：
     * 1. 进程内缓存（最快，1 小时 TTL）
     * 2. Redis 缓存（与 Events.php 共享缓存 key，1 小时 TTL）
     * 3. 数据库查询（最后手段）
     *
     * 缓存 key 格式：machine:id:{machine_id}
     *
     * @param int $machineId
     * @return Machine|null
     */
    private function getMachine(int $machineId): ?Machine
    {
        // 1️⃣ 进程内缓存检查
        if (isset(self::$machineCache[$machineId])) {
            $cached = self::$machineCache[$machineId];
            if ($cached['expire'] > time()) {
                return $cached['machine'];
            }
            unset(self::$machineCache[$machineId]);
        }

        // 2️⃣ Redis 缓存检查（与 Events.php 保持一致的 TTL）
        $cacheKey = "machine:id:{$machineId}";
        $machine = Cache::get($cacheKey);

        if (!$machine) {
            // 3️⃣ 数据库查询（预加载 machineCategory 避免 N+1）
            $machine = Machine::query()
                ->with('machineCategory:id,keep_minutes')
                ->find($machineId);

            if ($machine) {
                // 缓存到 Redis（1 小时，与 Events.php 一致）
                Cache::set($cacheKey, $machine, self::CACHE_TTL);
            }
        }

        // 4️⃣ 缓存到进程内存
        if ($machine) {
            self::$machineCache[$machineId] = [
                'machine' => $machine,
                'expire' => time() + self::CACHE_TTL,
            ];
        }

        return $machine;
    }

    /**
     * 清除机台缓存
     *
     * @param int $machineId
     * @return void
     */
    private function clearMachineCache(int $machineId): void
    {
        // 清除进程内缓存
        unset(self::$machineCache[$machineId]);

        // 清除 Redis 缓存（machine:id 格式）
        $cacheKey = "machine:id:{$machineId}";
        Cache::delete($cacheKey);

        // 清除 keep_minutes 缓存
        $keepMinutesCacheKey = "play_keep_machine:keep_minutes:{$machineId}";
        Cache::delete($keepMinutesCacheKey);
    }

    /**
     * 获取机台的保留时间增量（分钟）
     *
     * @param int $machineId
     * @param Machine $machine
     * @return int
     */
    private function getKeepMinutes(int $machineId, Machine $machine): int
    {
        static $cache = [];

        if (isset($cache[$machineId])) {
            return $cache[$machineId];
        }

        // 从 Redis 缓存读取
        $cacheKey = "play_keep_machine:keep_minutes:{$machineId}";
        $keepMinutes = Cache::get($cacheKey);

        if ($keepMinutes === null) {
            // 查询数据库
            $keepMinutes = $machine->machineCategory->keep_minutes ?? 0;
            // 缓存 30 分钟
            Cache::set($cacheKey, $keepMinutes, 1800);
        }

        $cache[$machineId] = $keepMinutes;
        return $keepMinutes;
    }

    /**
     * 获取最大保留时间（秒）
     *
     * @return int
     */
    private function getMaxKeepSeconds(): int
    {
        // 进程内缓存
        if (self::$maxKeepSeconds !== null) {
            return self::$maxKeepSeconds;
        }

        // Redis 缓存读取
        $setting = Cache::get('setting-max_keeping_minutes-0');
        $maxMinutes = (!empty($setting) && $setting->num > 0) ? $setting->num : 0;

        self::$maxKeepSeconds = $maxMinutes * 60;
        return self::$maxKeepSeconds;
    }

    /**
     * 推送保留状态到客户端
     *
     * @param int $playerId
     * @param int $machineId
     * @param int $keepSeconds
     * @param int $keeping
     * @return void
     */
    private function pushKeepingStatus(int $playerId, int $machineId, int $keepSeconds, int $keeping): void
    {
        $message = [
            'msg_type' => 'player_machine_keeping',
            'player_id' => $playerId,
            'machine_id' => $machineId,
            'keep_seconds' => $keepSeconds,
            'keeping' => $keeping
        ];

        // ✅ 推送到两个频道（客户端需要）
        sendSocketMessage('player-' . $playerId . '-' . $machineId, $message);
        sendSocketMessage('player-' . $playerId, $message);
    }
}

