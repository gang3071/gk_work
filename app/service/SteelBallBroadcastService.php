<?php

namespace app\service;

use app\model\Channel;
use app\model\Machine;
use app\model\Player;
use app\model\SystemSetting;
use support\Cache;
use support\Log;
use support\Redis;

/**
 * 钢珠下珠数报喜服务
 *
 * 功能：当玩家在钢珠机台开奖结束后，珠数超过配置阈值时，全频道广播该消息
 * 配置：在后管系统设置中配置（feature = 'steel_ball_broadcast_threshold'）
 */
class SteelBallBroadcastService
{
    /**
     * 防抖键前缀（防止同一玩家短时间内重复广播）
     */
    const DEBOUNCE_KEY_PREFIX = 'steel_ball_broadcast:debounce:';

    /**
     * 防抖时间（秒）- 同一玩家在此时间内只广播一次
     */
    const DEBOUNCE_SECONDS = 60;

    /**
     * 未配置阈值的缓存标记
     */
    const NOT_CONFIGURED_MARKER = '__NOT_CONFIGURED__';

    /**
     * 检查并广播钢珠报喜消息
     *
     * @param Machine $machine 机台对象
     * @param int $ballCount 下珠数（$this->score）
     * @return bool 是否触发了广播
     */
    public static function checkAndBroadcast(Machine $machine, int $ballCount): bool
    {
        try {
            // 1. 检查珠数是否有效
            if ($ballCount <= 0) {
                return false;
            }

            // 2. 检查是否有玩家在游戏
            if (empty($machine->gaming_user_id)) {
                return false;
            }

            // 3. 获取该渠道的钢珠报喜配置
            $threshold = self::getThreshold($machine->department_id);

            if ($threshold === null || $threshold <= 0) {
                return false; // 未配置或禁用
            }

            // 4. 检查是否达到阈值
            if ($ballCount < $threshold) {
                return false;
            }

            // 5. 防抖检查（防止同一玩家短时间内重复广播）
            if (!self::checkDebounce($machine->gaming_user_id, $machine->department_id)) {
                Log::info('钢珠报喜防抖跳过', [
                    'player_id' => $machine->gaming_user_id,
                    'department_id' => $machine->department_id,
                    'ball_count' => $ballCount,
                ]);
                return false;
            }

            // 6. 加载关联数据
            $player = Player::with(['storeAdmin', 'vipLevel'])->find($machine->gaming_user_id);
            $channel = Channel::where('department_id', $machine->department_id)->first();

            if (!$player || !$channel) {
                Log::warning('钢珠报喜缺少必要数据', [
                    'machine_id' => $machine->id,
                    'player_id' => $machine->gaming_user_id,
                    'has_player' => !is_null($player),
                    'has_channel' => !is_null($channel),
                ]);
                return false;
            }

            // 加载机台标签
            if (!$machine->relationLoaded('machineLabel')) {
                $machine->load('machineLabel');
            }

            // 7. 构建广播消息
            $messageData = self::buildMessage($player, $channel, $machine, $ballCount);

            // 8. 执行广播
            self::broadcast($channel->department_id, $messageData['message'], $messageData['lang']);

            Log::info('钢珠报喜成功', [
                'player_id' => $machine->gaming_user_id,
                'player_name' => $player->name ?? '',
                'vip_level' => $player->vipLevel->name ?? '',
                'store_name' => $player->storeAdmin->nickname ?? '',
                'machine_code' => $machine->code,
                'machine_name' => $machine->name ?? '',
                'machine_label' => $machine->machineLabel->name ?? '',
                'ball_count' => $ballCount,
                'threshold' => $threshold,
                'department_id' => $machine->department_id,
                'message' => $messageData['message'],
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error('钢珠报喜异常', [
                'machine_id' => $machine->id ?? 0,
                'ball_count' => $ballCount ?? 0,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * 获取指定渠道的钢珠报喜阈值
     *
     * @param int $departmentId 渠道ID
     * @return float|null 阈值珠数，null表示未配置
     */
    public static function getThreshold(int $departmentId): ?float
    {
        try {
            // 从缓存获取配置
            $cacheKey = 'setting-steel_ball_broadcast_threshold-' . $departmentId;
            $setting = Cache::get($cacheKey);

            if (!$setting) {
                // 缓存未命中，从数据库查询
                $setting = SystemSetting::where('department_id', $departmentId)
                    ->where('feature', 'steel_ball_broadcast_threshold')
                    ->first();

                if (!$setting) {
                    // 缓存"未配置"状态，避免重复查询数据库
                    Cache::set($cacheKey, self::NOT_CONFIGURED_MARKER, 300);
                    return null;
                }

                // 缓存结果（设置5分钟过期）
                Cache::set($cacheKey, $setting, 300);
            }

            // 检查是否为"未配置"标记
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
            Log::error('获取钢珠报喜阈值失败', [
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
     * @param Machine $machine 机台
     * @param int $ballCount 下珠数
     * @return array ['message' => string, 'lang' => string] 消息和语言代码
     */
    private static function buildMessage(Player $player, Channel $channel, Machine $machine, int $ballCount): array
    {
        // 获取玩家VIP等级名称
        $vipLevel = '';
        if ($player->vipLevel && !empty($player->vipLevel->name)) {
            $vipLevel = $player->vipLevel->name;
        }

        // 获取玩家名称
        $playerName = $player->name ?? 'Unknown';

        // 获取机台名称
        $machineName = $machine->name ?? $machine->code;

        // 获取机台标签
        $machineLabel = $machine->machineLabel->name ?? '';

        // 根据渠道语言返回不同的文本
        $channelLang = $channel->lang ?? 'zh-TW';

        // 转换语言格式：zh-TW -> zh_TW（翻译目录使用下划线）
        $lang = str_replace('-', '_', $channelLang);

        // 格式化珠数
        $formattedBallCount = number_format($ballCount, 0);

        // 构建消息（按照需求文案：恭喜{vip_level}会员{player_name} 游戏{machine_name}{machine_label}高中{ball_count}珠）
        $vipPrefix = $vipLevel ? "{$vipLevel}會員" : '會員';
        $message = "恭喜{$vipPrefix}{$playerName} 遊戲{$machineName}{$machineLabel}高中{$formattedBallCount}珠";

        return [
            'message' => $message,
            'lang' => $channelLang,
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
            // 构建推送数据
            $data = [
                'msg_type' => 'steel_ball_broadcast',
                'title' => '🎊 鋼珠報喜',
                'content' => $message,
                'timestamp' => time(),
                'department_id' => $departmentId,
            ];

            // 使用全局广播频道（与彩金通知一致）
            sendSocketMessage('group-lottery-pool', $data);

            Log::info('推送钢珠报喜', [
                'channel' => 'group-lottery-pool',
                'message' => $message,
                'department_id' => $departmentId,
                'lang' => $lang,
            ]);

        } catch (\Throwable $e) {
            Log::error('推送钢珠报喜失败', [
                'department_id' => $departmentId,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
