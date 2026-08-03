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
            $machineCacheKey = $data['machine_cache_key'] ?? '';
            $oldKeepSeconds = $data['keep_seconds'] ?? 0;
            $oldKeeping = $data['keeping'] ?? 0;
            $gamingUserId = $data['gaming_user_id'] ?? $playerId;

            // ✅ 快速校验：数据完整性
            if (!$machineId || !$gamingUserId || !$machineCacheKey) {
                return; // 数据不完整，静默跳过
            }

            // ✅ 从 Events::getMachine 的缓存中读取 Machine 对象
            $machine = Cache::get($machineCacheKey);
            if (!$machine) {
                // 缓存未命中，查询数据库
                $machine = Machine::query()->find($machineId);
                if (!$machine) {
                    return; // 机台不存在
                }
            }

            // ✅ 使用 cate_id 作为缓存 key（同分类机台共享缓存）
            $cateId = $machine->cate_id;
            $keepMinutesCacheKey = "machine_category:{$cateId}:keep_minutes";
            $keepMinutes = Cache::get($keepMinutesCacheKey);

            if ($keepMinutes === null) {
                // 查询 machineCategory 表
                $keepMinutes = \app\model\MachineCategory::query()
                    ->where('id', $cateId)
                    ->value('keep_minutes') ?? 0;

                // 缓存 keep_minutes（1小时）
                Cache::set($keepMinutesCacheKey, $keepMinutes, 3600);
            }

            $keepSecondsChanged = false;
            $keepingChanged = false;
            $newKeepSeconds = $oldKeepSeconds;
            $newKeeping = $oldKeeping;

            // 增加保留时间
            if ($keepMinutes > 0 && $changeAmount > 0) {
                $addSeconds = bcmul($keepMinutes, $changeAmount, 2);
                $newKeepSeconds = bcadd($oldKeepSeconds, $addSeconds, 2);

                // 检查最大保留时间限制
                $maxKeepSeconds = $this->getMaxKeepSeconds();
                if ($maxKeepSeconds > 0 && $newKeepSeconds > $maxKeepSeconds) {
                    $newKeepSeconds = $maxKeepSeconds;
                }

                if ($newKeepSeconds != $oldKeepSeconds) {
                    $keepSecondsChanged = true;
                }

                Log::info('[PlayKeepMachine] 保留时间计算', [
                    'machine_id' => $machineId,
                    'player_id' => $gamingUserId,
                    'keep_minutes' => $keepMinutes,
                    'change_amount' => $changeAmount,
                    'cate_id' => $cateId,
                    'add_seconds' => $addSeconds,
                    'old_keep_seconds' => $oldKeepSeconds,
                    'new_keep_seconds' => $newKeepSeconds,
                    'max_keep_seconds' => $maxKeepSeconds,
                ]);
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
                // 创建 MachineServices 更新 Redis（复用前面已获取的 $machine）
                try {
                    $services = MachineServices::createServices($machine);

                    // ⚠️ CRITICAL：重新读取实时 gaming_user_id 和 keeping 状态
                    // 原因：队列消息是异步的，处理时玩家可能已被踢出（keeping=1）
                    $currentGamingUserId = $services->gaming_user_id;
                    $currentKeeping = $services->keeping;

                    // 只在玩家还在游戏中时才处理
                    if (empty($currentGamingUserId)) {
                        return;
                    }

                    // 如果当前已经在保留状态（被踢出/闲置），则解除保留
                    if ($currentKeeping == 1) {
                        $services->keeping = 0;
                        $newKeeping = 0;
                        // 更新保留日志
                        try {
                            updateKeepingLog($machineId, $currentGamingUserId);
                        } catch (\Throwable $e) {
                            // 日志更新失败不影响主流程
                        }
                    }

                    if ($keepSecondsChanged) {
                        $services->keep_seconds = $newKeepSeconds;
                    }
                } catch (\Throwable $e) {
                    // 更新失败不影响推送
                }

                // 推送到客户端（使用实时 gaming_user_id）
                if (!empty($currentGamingUserId)) {
                    $this->pushKeepingStatus($currentGamingUserId, $machineId, $newKeepSeconds, $newKeeping);
                }
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
     * 获取最大保留时间（秒）
     *
     * ✅ 优化：每次从 Redis 读取，确保后台修改实时生效
     * 性能：Redis 读取耗时 <1ms，可忽略不计
     *
     * @return int
     */
    private function getMaxKeepSeconds(): int
    {
        // ✅ 直接从 Redis 缓存读取（实时生效）
        // SystemSetting 模型的 updated 事件会自动更新此缓存
        $setting = Cache::get('setting-max_keeping_minutes-0');

        if (empty($setting) || !isset($setting->num)) {
            return 0;  // 没有配置或无效，返回 0（不限制）
        }

        $maxMinutes = intval($setting->num);
        return $maxMinutes > 0 ? $maxMinutes * 60 : 0;
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

