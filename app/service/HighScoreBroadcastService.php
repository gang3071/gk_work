<?php

namespace app\service;

use app\model\Channel;
use app\model\PlayGameRecord;
use app\model\Player;
use support\Cache;
use support\Log;
use support\Redis;
use Webman\Push\Api;

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
                return false;
            }

            if ($record->win <= 0) {
                return false;
            }

            // 2. 获取该渠道的高分广播配置
            $threshold = self::getThreshold($record->department_id);

            if ($threshold === null || $threshold <= 0) {
                return false; // 未配置或禁用
            }

            // 3. 检查是否达到阈值
            if ($record->win < $threshold) {
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
            $message = self::buildMessage($player, $channel, $record);

            // 7. 执行广播
            self::broadcast($channel->department_id, $message);

            Log::info('高分广播成功', [
                'player_id' => $record->player_id,
                'player_nickname' => $player->nickname,
                'game_name' => $record->game_name ?? 'Unknown',
                'win' => $record->win,
                'threshold' => $threshold,
                'department_id' => $record->department_id,
                'message' => $message,
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
     * 获取指定渠道的高分广播阈值
     *
     * @param int $departmentId 渠道ID
     * @return float|null 阈值金额，null表示未配置
     */
    private static function getThreshold(int $departmentId): ?float
    {
        try {
            // 从缓存获取配置（SystemSetting 模型已实现自动缓存）
            $cacheKey = 'setting-high_score_broadcast_threshold-' . $departmentId;
            $setting = Cache::get($cacheKey);

            if (!$setting) {
                // 缓存未命中，从数据库查询
                // 需要使用后管的 SystemSetting 模型
                $settingModel = plugin()->webman->config('database.system_setting_model');
                if (!$settingModel || !class_exists($settingModel)) {
                    return null;
                }

                $setting = $settingModel::where('department_id', $departmentId)
                    ->where('feature', 'high_score_broadcast_threshold')
                    ->first();

                if (!$setting) {
                    return null;
                }

                // 缓存结果
                Cache::set($cacheKey, $setting);
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
     * @return string 广播消息
     */
    private static function buildMessage(Player $player, Channel $channel, PlayGameRecord $record): string
    {
        $deviceName = $player->nickname ?? 'Unknown';
        $gameName = $record->game_name ?? 'Unknown Game';
        $score = number_format($record->win, 0);

        // 根据渠道语言返回不同的文本
        $lang = $channel->lang ?? 'zh-TW';

        return match($lang) {
            'zh-CN' => "高分报喜：恭喜（{$deviceName}）于（{$gameName}）赢得{$score}分",
            'zh-TW' => "高分報喜：恭喜（{$deviceName}）於（{$gameName}）贏得{$score}分",
            'en' => "Big Win: Congratulations to ({$deviceName}) for winning {$score} points in ({$gameName})",
            'jp' => "高得点おめでとう：（{$deviceName}）が（{$gameName}）で{$score}ポイント獲得",
            default => "高分報喜：恭喜（{$deviceName}）於（{$gameName}）贏得{$score}分", // 默认繁体
        };
    }

    /**
     * 执行全频道广播
     *
     * @param int $departmentId 渠道ID
     * @param string $message 广播消息
     * @return void
     */
    private static function broadcast(int $departmentId, string $message): void
    {
        try {
            // 构建推送数据
            $data = [
                'type' => 'high_score_broadcast',
                'message' => $message,
                'timestamp' => time(),
            ];

            // 使用推送服务向该渠道所有在线用户广播
            // 频道格式：department_{渠道ID}
            $channel = "department_{$departmentId}";

            Api::trigger($channel, 'high_score', $data);

            Log::info('推送高分广播', [
                'channel' => $channel,
                'message' => $message,
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
