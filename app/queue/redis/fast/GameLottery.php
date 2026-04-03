<?php

namespace app\queue\redis\fast;

use app\model\Player;
use app\service\GameLotteryServices;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

class GameLottery implements Consumer
{
    // 要消费的队列名
    public $queue = 'game-lottery';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    // 消费
    public function consume($data)
    {
        $log = Log::channel('game_lottery');
        $log->info('🎲 开始处理游戏抽奖', [
            'play_game_record_id' => $data['play_game_record_id'] ?? 0,
            'player_id' => $data['player_id'],
            'bet' => $data['bet'],
        ]);

        try {
            /** @var Player $player */
            $player = Player::query()->find($data['player_id']);

            if (empty($player)) {
                $log->warning('玩家不存在', [
                    'player_id' => $data['player_id'],
                    'play_game_record_id' => $data['play_game_record_id'] ?? 0,
                ]);
                return;
            }

            if ($player->channel->lottery_status == 0) {
                $log->info('渠道抽奖功能未开启', [
                    'player_id' => $data['player_id'],
                    'play_game_record_id' => $data['play_game_record_id'] ?? 0,
                    'channel_id' => $player->channel->id
                ]);
                return;
            }

            // 防止重复处理：检查该下注记录是否已经参与过彩金检查
            $playGameRecordId = $data['play_game_record_id'] ?? 0;
            if ($playGameRecordId > 0) {
                $cacheKey = 'game_lottery_processed_' . $playGameRecordId;
                $redis = \support\Redis::connection()->client();

                // 使用 SET NX（只在key不存在时设置）来防止重复处理
                $lockResult = $redis->set($cacheKey, time(), ['NX', 'EX' => 3600]); // 1小时过期

                if (!$lockResult) {
                    $log->warning('该下注记录已处理过，跳过重复检查', [
                        'play_game_record_id' => $playGameRecordId,
                        'player_id' => $data['player_id']
                    ]);
                    return;
                }
            }

            $gameLotteryServices = new GameLotteryServices();
            $gameLotteryServices->setPlayer($player)->setLog()->setLotteryList()->addLotteryPool($data['bet'])->checkLottery($data['bet'], $data['play_game_record_id']);

            $log->info('游戏抽奖处理完成', [
                'player_id' => $data['player_id'],
                'bet' => $data['bet'],
                'play_game_record_id' => $data['play_game_record_id']
            ]);
        } catch (Exception $e) {
            $log->error('游戏抽奖处理失败', [
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
     * 在每次消费失败时触发（max_attempts 内的每次重试失败都会触发）
     *
     * @param \Throwable $exception 异常对象
     * @param array $package 消息包
     */
    public function onConsumeFailure(\Throwable $exception, $package)
    {
        $log = Log::channel('game_lottery');

        $playerId = $package['data']['player_id'] ?? 'unknown';
        $bet = $package['data']['bet'] ?? 0;
        $playGameRecordId = $package['data']['play_game_record_id'] ?? 0;
        $attempts = $package['attempts'] ?? 0;
        $maxAttempts = $package['max_attempts'] ?? 3;
        $isFinalAttempt = $attempts >= $maxAttempts;

        // 记录失败日志
        $log->error('🔴 彩金检查失败' . ($isFinalAttempt ? '（已达最大重试次数）' : ''), [
            'message_id' => $package['id'] ?? null,
            'queue' => $package['queue'] ?? 'unknown',
            'player_id' => $playerId,
            'bet' => $bet,
            'play_game_record_id' => $playGameRecordId,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_file' => $exception->getFile() . ':' . $exception->getLine(),
        ]);

        // 达到最大重试次数时的特殊处理
        if ($isFinalAttempt) {
            // 1. 发送严重告警（Telegram）
            $this->sendCriticalAlert($exception, $package);

            // 2. 记录到失败队列（可选：用于人工处理）
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

            $playerId = $package['data']['player_id'] ?? 'unknown';
            $bet = $package['data']['bet'] ?? 0;
            $recordId = $package['data']['play_game_record_id'] ?? 'unknown';

            // 组装消息
            $date = date('Y-m-d H:i:s');
            $level = 'CRITICAL';
            $message = '彩金检查失败告警';

            $text = "🚨 *Webman 错误告警*\n";
            $text .= "📅 时间: `{$date}`\n";
            $text .= "🔴 级别: `{$level}`\n";
            $text .= "🖥️ 节点: `" . gethostname() . "`\n";
            $text .= "📝 消息: {$message}\n";
            $text .= "📋 队列: `{$package['queue']}`\n";
            $text .= "👤 玩家: `{$playerId}`\n";
            $text .= "💰 下注: `{$bet}`\n";
            $text .= "🎰 记录: `{$recordId}`\n";
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
     * 记录到失败队列（用于人工处理）
     */
    private function logToFailureQueue(array $package)
    {
        try {
            $failureKey = 'queue:game-lottery:failed';
            $failureData = [
                'failed_at' => date('Y-m-d H:i:s'),
                'package' => $package,
            ];

            \support\Redis::lPush($failureKey, json_encode($failureData));

            // 设置过期时间（30天）
            \support\Redis::expire($failureKey, 86400 * 30);
        } catch (\Throwable $e) {
            Log::error('记录失败队列失败', ['error' => $e->getMessage()]);
        }
    }
}
