<?php

namespace app\queue\redis\fast;

use app\model\GameType;
use app\model\Machine;
use app\model\Player;
use app\model\PlayerGameLog;
use app\model\PlayerGameRecord;
use app\service\LotteryServices;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

class LotteryMachine implements Consumer
{
    // 要消费的队列名
    public $queue = 'lottery-machine';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    // 消费
    public function consume($data)
    {
        $log = Log::channel('lottery_machine');
        $log->info('开始处理机台抽奖', ['data' => $data]);

        try {
            /** @var Machine $machine */
            $machine = Machine::query()->find($data['machine_id']);
            /** @var Player $player */
            $player = Player::query()->find($data['player_id']);

            if (empty($machine)) {
                $log->warning('机台不存在', ['machine_id' => $data['machine_id']]);
                return;
            }

            if (empty($player)) {
                $log->warning('玩家不存在', ['player_id' => $data['player_id']]);
                return;
            }

            if ($player->channel->lottery_status == 0) {
                $log->info('渠道抽奖功能未开启', [
                    'player_id' => $data['player_id'],
                    'channel_id' => $player->channel->id
                ]);
                return;
            }

            // 防止重复处理：使用机台ID+玩家ID+押注金额+时间戳作为唯一标识
            $machineId = $data['machine_id'] ?? 0;
            $playerId = $data['player_id'] ?? 0;
            $num = $data['num'] ?? 0;
            $lastNum = $data['last_num'] ?? 0;

            if ($machineId > 0 && $playerId > 0) {
                // 使用组合键生成唯一标识
                $uniqueKey = md5($machineId . '_' . $playerId . '_' . $num . '_' . $lastNum);
                $cacheKey = 'machine_lottery_processed_' . $uniqueKey;
                $redis = \support\Redis::connection()->client();

                // 使用 SET NX（只在key不存在时设置）来防止重复处理
                $lockResult = $redis->set($cacheKey, time(), ['NX', 'EX' => 3600]); // 1小时过期

                if (!$lockResult) {
                    $log->warning('该机台押注已处理过，跳过重复检查', [
                        'machine_id' => $machineId,
                        'player_id' => $playerId,
                        'num' => $num,
                        'last_num' => $lastNum
                    ]);
                    return;
                }
            }

            // 通知后台管理系统玩家正在游戏
            $this->notifyPlayerBetting($player, $machine, $data);

            $lotteryServices = new LotteryServices();
            switch ($machine->type) {
                case GameType::TYPE_STEEL_BALL:
                    $lotteryServices = $lotteryServices->setJackLotteryList();
                    $log->info('设置钢珠机抽奖列表', ['machine_id' => $data['machine_id']]);
                    break;
                case GameType::TYPE_SLOT:
                    $lotteryServices = $lotteryServices->setSlotLotteryList();
                    $log->info('设置斯洛机抽奖列表', ['machine_id' => $data['machine_id']]);
                    break;
            }

            $lotteryServices->setMachine($machine)->setPlayer($player)->addLotteryPool($data['num'], $data['last_num'])->checkLottery();

            $log->info('机台抽奖处理完成', [
                'machine_id' => $data['machine_id'],
                'player_id' => $data['player_id'],
                'num' => $data['num'],
                'last_num' => $data['last_num']
            ]);
        } catch (Exception $e) {
            $log->error('机台抽奖处理失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // ✅ 重新抛出异常，让队列重试
            throw $e;
        }
    }

    /**
     * 消费失败回调
     *
     * @param \Throwable $exception 异常对象
     * @param array $package 消息包
     */
    public function onConsumeFailure(\Throwable $exception, $package)
    {
        $log = Log::channel('game_lottery');

        $machineId = $package['data']['machine_id'] ?? 'unknown';
        $playerId = $package['data']['player_id'] ?? 'unknown';
        $num = $package['data']['num'] ?? 0;
        $attempts = $package['attempts'] ?? 0;
        $maxAttempts = $package['max_attempts'] ?? 3;
        $isFinalAttempt = $attempts >= $maxAttempts;

        // 记录失败日志
        $log->error('🔴 机台彩金检查失败' . ($isFinalAttempt ? '（已达最大重试次数）' : ''), [
            'message_id' => $package['id'] ?? null,
            'queue' => $package['queue'] ?? 'unknown',
            'machine_id' => $machineId,
            'player_id' => $playerId,
            'num' => $num,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_file' => $exception->getFile() . ':' . $exception->getLine(),
        ]);

        // 达到最大重试次数时的特殊处理
        if ($isFinalAttempt) {
            $this->sendCriticalAlert($exception, $package);
            $this->logToFailureQueue($package);
        }
    }

    /**
     * 发送严重告警（Telegram）
     */
    private function sendCriticalAlert(\Throwable $exception, array $package)
    {
        try {
            $token = config('plugin.webman.push.app.telegram_bot_token')
                ?? config('app.telegram_bot_token');
            $chatId = config('plugin.webman.push.app.telegram_chat_id')
                ?? config('app.telegram_chat_id');

            // 如果未配置Telegram，跳过
            if (empty($token) || empty($chatId)) {
                return;
            }

            $machineId = $package['data']['machine_id'] ?? 'unknown';
            $playerId = $package['data']['player_id'] ?? 'unknown';
            $num = $package['data']['num'] ?? 0;

            // 组装消息
            $date = date('Y-m-d H:i:s');
            $level = 'CRITICAL';
            $message = '机台彩金检查失败告警';

            $text = "🚨 *Webman 错误告警*\n";
            $text .= "📅 时间: `{$date}`\n";
            $text .= "🔴 级别: `{$level}`\n";
            $text .= "🖥️ 节点: `" . gethostname() . "`\n";
            $text .= "📝 消息: {$message}\n";
            $text .= "📋 队列: `{$package['queue']}`\n";
            $text .= "🎰 机台: `{$machineId}`\n";
            $text .= "👤 玩家: `{$playerId}`\n";
            $text .= "💰 押注: `{$num}`\n";
            $text .= "🔁 重试: `{$package['attempts']}/{$package['max_attempts']}`\n";
            $text .= "❌ 错误: " . mb_substr($exception->getMessage(), 0, 200) . "\n";
            $text .= "📍 位置: `{$exception->getFile()}:{$exception->getLine()}`\n";
            $text .= "\n⚠️ *警告：玩家可能错过彩金中奖机会！*";

            // 发送到 Telegram API
            $this->sendToTelegram($token, $chatId, $text);

        } catch (\Throwable $e) {
            Log::error('发送Telegram告警失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 发送消息到Telegram
     */
    private function sendToTelegram(string $token, string $chatId, string $text)
    {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        // 确保UTF-8编码
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

        // 使用 curl 发送请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * 记录到失败队列
     */
    private function logToFailureQueue(array $package)
    {
        try {
            $failureKey = 'queue:lottery-machine:failed';
            $failureData = [
                'failed_at' => date('Y-m-d H:i:s'),
                'package' => $package,
            ];

            \support\Redis::lPush($failureKey, json_encode($failureData));
            \support\Redis::expire($failureKey, 86400 * 30);
        } catch (\Throwable $e) {
            Log::error('记录失败队列失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 通知后台管理系统玩家正在游戏
     * @param Player $player
     * @param Machine $machine
     * @param array $data
     * @return void
     */
    private function notifyPlayerBetting($player, $machine, $data)
    {
        try {
            // 获取当前游戏记录
            $record = PlayerGameRecord::query()
                ->where('player_id', $player->id)
                ->where('status', PlayerGameRecord::STATUS_START)
                ->orderBy('id', 'desc')
                ->first();

            // 获取累计押注（最近5分钟）
            $fiveMinutesAgo = date('Y-m-d H:i:s', time() - 300);
            $totalPressure = PlayerGameLog::query()
                ->where('player_id', $player->id)
                ->where('created_at', '>=', $fiveMinutesAgo)
                ->sum('pressure');

            sendSocketMessage('group-online-players-machine', [
                'msg_type' => 'player_betting',
                'type' => 'machine',
                'player' => [
                    'id' => $player->id,
                    'uuid' => $player->uuid,
                    'name' => $player->name ?: $player->uuid,
                    'phone' => $player->phone,
                    'avatar' => $this->getAvatarUrl($player->avatar),
                    'is_test' => $player->is_test,
                    'is_coin' => $player->is_coin,
                    'is_promoter' => $player->is_promoter,
                    'machine_id' => $machine->id,
                    'machine_name' => $machine->name,
                    'machine_code' => $machine->code,
                    'last_bet_time' => date('Y-m-d H:i:s'),
                    'bet_seconds_ago' => 0,
                    'total_pressure' => number_format($totalPressure, 2),
                    'last_pressure' => number_format($data['num'] ?? 0, 2),
                ],
                'timestamp' => time(),
            ]);
        } catch (Exception $e) {
            Log::channel('lottery_machine')->error('通知后台玩家押注失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取头像URL
     * @param $avatar
     * @return string
     */
    private function getAvatarUrl($avatar): string
    {
        if (!$avatar) {
            return '';
        }

        if (is_numeric($avatar)) {
            return config('def_avatar.' . $avatar, '');
        }

        return $avatar;
    }
}
