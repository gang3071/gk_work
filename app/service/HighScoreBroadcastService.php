<?php

namespace app\service;

use app\model\Channel;
use app\model\Player;
use app\model\PlayGameRecord;
use app\model\SystemSetting;
use support\Cache;
use support\Log;
use support\Redis;

/**
 * 高分广播服务
 *
 * 功能：当玩家在第三方游戏中得分超过配置阈值时，全频道广播该消息
 * 配置：在后管系统设置中配置（feature = 'high_score_broadcast_threshold'）
 */
class HighScoreBroadcastService
{
    /**
     * 防抖键前缀（防止同一玩家短时间内重复广播）
     */
    const DEBOUNCE_KEY_PREFIX = 'high_score_broadcast:debounce:';

    /**
     * 防抖时间（秒）- 同一玩家在此时间内只广播一次
     */
    const DEBOUNCE_SECONDS = 60;

    /**
     * 未配置阈值的缓存标记
     */
    const NOT_CONFIGURED_MARKER = '__NOT_CONFIGURED__';

    /**
     * 检查并广播高分消息
     *
     * @param PlayGameRecord $record 游戏记录
     * @return bool 是否触发了广播
     */
    public static function checkAndBroadcast(PlayGameRecord $record): bool
    {
        try {
            // 1. 只处理已结算且有赢分的记录
            if ($record->settlement_status != PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                Log::info('高分广播跳过：非已结算状态', [
                    'record_id' => $record->id,
                    'settlement_status' => $record->settlement_status,
                ]);
                return false;
            }

            if ($record->win <= 0) {
                Log::info('高分广播跳过：win<=0', [
                    'record_id' => $record->id,
                    'win' => $record->win,
                ]);
                return false;
            }

            // 2. 获取该渠道的高分广播配置
            $threshold = self::getThreshold($record->department_id);

            if ($threshold === null || $threshold <= 0) {
                Log::info('高分广播跳过：未配置阈值或阈值<=0', [
                    'record_id' => $record->id,
                    'department_id' => $record->department_id,
                    'threshold' => $threshold,
                ]);
                return false; // 未配置或禁用
            }

            // 3. 检查是否达到阈值
            if ($record->win < $threshold) {
                Log::info('高分广播跳过：未达阈值', [
                    'record_id' => $record->id,
                    'win' => $record->win,
                    'threshold' => $threshold,
                ]);
                return false;
            }

            // 4. 防抖检查（防止同一玩家短时间内重复广播）
            if (!self::checkDebounce($record->player_id, $record->department_id)) {
                Log::info('高分广播防抖跳过', [
                    'player_id' => $record->player_id,
                    'department_id' => $record->department_id,
                    'win' => $record->win,
                ]);
                return false;
            }

            // 5. 加载关联数据
            $player = $record->player;
            $channel = $record->channel;

            if (!$player || !$channel) {
                Log::warning('高分广播缺少必要数据', [
                    'record_id' => $record->id,
                    'has_player' => !is_null($player),
                    'has_channel' => !is_null($channel),
                ]);
                return false;
            }

            // 6. 构建广播消息
            $messageData = self::buildMessage($player, $channel, $record);

            // 7. 执行广播
            self::broadcast($channel->department_id, $messageData['message'], $messageData['lang']);

            Log::info('高分广播成功', [
                'player_id' => $record->player_id,
                'player_nickname' => $player->nickname,
                'game_name' => $record->game_name ?? 'Unknown',
                'win' => $record->win,
                'threshold' => $threshold,
                'department_id' => $record->department_id,
                'message' => $messageData['message'],
                'lang' => $messageData['lang'],
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error('高分广播异常', [
                'record_id' => $record->id ?? 0,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * 批量获取多个渠道的高分广播阈值
     *
     * @param array $departmentIds 渠道ID数组
     * @return array 渠道ID => 阈值的映射，未配置则为null
     */
    public static function batchGetThresholds(array $departmentIds): array
    {
        if (empty($departmentIds)) {
            return [];
        }

        $thresholds = [];
        $uncached = [];

        // 1. 先从缓存读取
        foreach ($departmentIds as $id) {
            $cacheKey = 'setting-high_score_broadcast_threshold-' . $id;
            $setting = Cache::get($cacheKey);

            if ($setting) {
                if ($setting === self::NOT_CONFIGURED_MARKER) {
                    $thresholds[$id] = null;
                } else {
                    $thresholds[$id] = self::extractThreshold($setting);
                }
            } else {
                $uncached[] = $id;
            }
        }

        // 2. 批量查询未缓存的
        if (!empty($uncached)) {
            try {
                $settings = SystemSetting::whereIn('department_id', $uncached)
                    ->where('feature', 'high_score_broadcast_threshold')
                    ->get()
                    ->keyBy('department_id');

                foreach ($uncached as $id) {
                    $setting = $settings[$id] ?? null;
                    $cacheKey = 'setting-high_score_broadcast_threshold-' . $id;

                    if ($setting) {
                        Cache::set($cacheKey, $setting, 300);
                        $thresholds[$id] = self::extractThreshold($setting);
                    } else {
                        Cache::set($cacheKey, self::NOT_CONFIGURED_MARKER, 300);
                        $thresholds[$id] = null;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('批量获取高分广播阈值失败', [
                    'department_ids' => $uncached,
                    'error' => $e->getMessage(),
                ]);

                // 失败时填充 null
                foreach ($uncached as $id) {
                    $thresholds[$id] = null;
                }
            }
        }

        return $thresholds;
    }

    /**
     * 从 setting 对象提取阈值
     *
     * @param mixed $setting SystemSetting 对象
     * @return float|null 阈值，null表示未启用或无效
     */
    private static function extractThreshold($setting): ?float
    {
        if (empty($setting->status)) {
            return null;
        }

        $threshold = (float)($setting->num ?? 0);
        return $threshold > 0 ? $threshold : null;
    }

    /**
     * 获取指定渠道的高分广播阈值
     *
     * @param int $departmentId 渠道ID
     * @return float|null 阈值金额，null表示未配置
     */
    public static function getThreshold(int $departmentId): ?float
    {
        try {
            // 从缓存获取配置（SystemSetting 模型已实现自动缓存）
            $cacheKey = 'setting-high_score_broadcast_threshold-' . $departmentId;
            $setting = Cache::get($cacheKey);

            if (!$setting) {
                // 缓存未命中，从数据库查询
                $setting = SystemSetting::where('department_id', $departmentId)
                    ->where('feature', 'high_score_broadcast_threshold')
                    ->first();

                if (!$setting) {
                    // ✅ 优化：缓存"未配置"状态，避免重复查询数据库
                    Cache::set($cacheKey, self::NOT_CONFIGURED_MARKER, 300);
                    return null;
                }

                // 缓存结果（设置5分钟过期，避免跨服务器缓存不同步）
                Cache::set($cacheKey, $setting, 300);
            }

            // ✅ 优化：检查是否为"未配置"标记
            if ($setting === self::NOT_CONFIGURED_MARKER) {
                return null;
            }

            // 检查是否启用
            if (empty($setting->status)) {
                return null;
            }

            $threshold = (float)($setting->num ?? 0);

            return $threshold > 0 ? $threshold : null;

        } catch (\Throwable $e) {
            Log::error('获取高分广播阈值失败', [
                'department_id' => $departmentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 防抖检查
     *
     * @param int $playerId 玩家ID
     * @param int $departmentId 渠道ID
     * @return bool true=可以广播，false=需要跳过
     */
    private static function checkDebounce(int $playerId, int $departmentId): bool
    {
        $redis = Redis::connection()->client();
        $key = self::DEBOUNCE_KEY_PREFIX . $departmentId . ':' . $playerId;

        // 使用 SET NX EX 实现原子性防抖
        $result = $redis->set($key, time(), ['NX', 'EX' => self::DEBOUNCE_SECONDS]);

        return $result !== false;
    }

    /**
     * 构建广播消息（多语言）
     *
     * @param Player $player 玩家
     * @param Channel $channel 渠道
     * @param PlayGameRecord $record 游戏记录
     * @return array ['message' => string, 'lang' => string] 消息和语言代码
     */
    private static function buildMessage(Player $player, Channel $channel, PlayGameRecord $record): array
    {
        $deviceName = $player->nickname ?? 'Unknown';
        $gameName = $record->game_name ?? 'Unknown Game';
        $score = number_format($record->win, 0);

        // 根据渠道语言返回不同的文本
        $lang = $channel->lang ?? 'zh-TW';

        // ✅ 优化：使用翻译文件，支持运营自行修改文案
        try {
            $message = trans('high_score_broadcast.message', [
                'device_name' => $deviceName,
                'game_name' => $gameName,
                'score' => $score,
            ], $lang);
        } catch (\Throwable $e) {
            // 降级：如果翻译失败，使用默认繁体中文
            $message = "高分報喜：恭喜（{$deviceName}）於（{$gameName}）贏得{$score}分";
            Log::warning('高分广播翻译失败，使用默认文案', [
                'lang' => $lang,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'message' => $message,
            'lang' => $lang,
        ];
    }

    /**
     * 执行全频道广播
     *
     * @param int $departmentId 渠道ID
     * @param string $message 广播消息
     * @param string $lang 语言代码
     * @return void
     */
    private static function broadcast(int $departmentId, string $message, string $lang = 'zh-TW'): void
    {
        try {
            // ✅ 优化：title 支持多语言
            try {
                $title = trans('high_score_broadcast.title', [], $lang);
            } catch (\Throwable $e) {
                $title = '🎉 高分報喜'; // 降级默认值
            }

            // 构建推送数据（与彩金通知格式统一）
            $data = [
                'msg_type' => 'high_score_broadcast',
                'title' => $title,
                'content' => $message,
                'timestamp' => time(),
                'department_id' => $departmentId,
            ];

            // 使用全局广播频道（与机台彩金、电子游戏彩金保持一致）
            sendSocketMessage('group-lottery-pool', $data);

            Log::info('推送高分广播', [
                'channel' => 'group-lottery-pool',
                'message' => $message,
                'department_id' => $departmentId,
                'lang' => $lang,
            ]);

        } catch (\Throwable $e) {
            Log::error('推送高分广播失败', [
                'department_id' => $departmentId,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
    }

}
