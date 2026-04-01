<?php

namespace app\queue\redis\fast;

use app\model\Player;
use app\model\PlayGameRecord;
use support\Log;
use Webman\RedisQueue\Consumer;

/**
 * 游戏下注记录异步处理
 * 用于异步创建下注记录，提升钱包API响应速度
 */
class GameBetRecord implements Consumer
{
    // 要消费的队列名
    public $queue = 'game-bet-record';

    // 连接名
    public $connection = 'default';

    /**
     * 消费消息
     *
     * @param array $data 队列数据
     * 格式：
     * [
     *     'player_id' => int,
     *     'platform_id' => int,
     *     'game_code' => string,
     *     'order_no' => string,
     *     'bet' => float,
     *     'win' => float,
     *     'original_data' => array,
     *     'order_time' => string,
     *     'settlement_status' => int,
     *     'record_type' => 'bet|settle|cancel', // 记录类型
     * ]
     */
    public function consume($data)
    {
        $startTime = microtime(true);
        $log = Log::channel('game_bet_record');

        $recordType = $data['record_type'] ?? 'bet';
        $orderNo = $data['order_no'] ?? 'UNKNOWN';

        $log->info('📥 开始处理游戏记录', [
            'type' => $recordType,
            'order_no' => $orderNo,
            'player_id' => $data['player_id'] ?? null,
        ]);

        try {
            switch ($recordType) {
                case 'bet':
                    $this->handleBetRecord($data, $log);
                    break;

                case 'settle':
                    $this->handleSettleRecord($data, $log);
                    break;

                case 'cancel':
                    $this->handleCancelRecord($data, $log);
                    break;

                default:
                    $log->warning('⚠️ 未知的记录类型', ['type' => $recordType, 'data' => $data]);
            }

            $duration = (microtime(true) - $startTime) * 1000;

            $log->info('✅ 游戏记录处理完成', [
                'type' => $recordType,
                'order_no' => $orderNo,
                'process_time' => round($duration, 2) . 'ms',
            ]);

        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $log->error('❌ 游戏记录处理失败', [
                'type' => $recordType,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'process_time' => round($duration, 2) . 'ms',
                'trace' => $e->getTraceAsString()
            ]);

            // 重要：抛出异常让队列重试
            throw $e;
        }
    }

    /**
     * 处理下注记录（支持累计下注场景：DG/KT/RSGLive）
     */
    private function handleBetRecord(array $data, $log)
    {
        $startTime = microtime(true);

        // ✅ 检查订单是否已存在（支持累计下注）
        $existingRecord = PlayGameRecord::query()
            ->where('order_no', $data['order_no'])
            ->lockForUpdate()
            ->first();

        if ($existingRecord) {
            // ✅ 幂等性检查：防止队列重试导致重复累加相同的数据
            $originalData = json_decode($existingRecord->original_data, true) ?? [];

            // 生成当前数据的唯一标识（MD5哈希）
            $currentDataHash = md5(json_encode($data['original_data']));

            // 检查是否已经处理过这笔数据
            foreach ($originalData as $existingData) {
                $existingHash = md5(json_encode($existingData));
                if ($existingHash === $currentDataHash) {
                    // 🔒 幂等性保护：相同的数据已存在，跳过累加（可能是队列重试）
                    $duration = (microtime(true) - $startTime) * 1000;

                    $log->warning('🔒 下注数据已存在，跳过累加（幂等性保护）', [
                        'order_no' => $data['order_no'],
                        'bet' => $data['bet'],
                        'current_bet' => $existingRecord->bet,
                        'data_hash' => substr($currentDataHash, 0, 8),
                        'db_time' => round($duration, 2) . 'ms',
                        'reason' => 'Duplicate data detected, possibly from queue retry',
                    ]);
                    return;
                }
            }

            // ✅ 确认是新的下注数据，执行累加
            $originalData[] = $data['original_data'];

            $oldBet = $existingRecord->bet;
            $existingRecord->bet += $data['bet'];
            $existingRecord->original_data = json_encode($originalData);
            $existingRecord->save();

            $duration = (microtime(true) - $startTime) * 1000;

            $log->info('➕ 下注记录累加成功（累计下注）', [
                'order_no' => $data['order_no'],
                'old_bet' => $oldBet,
                'add_bet' => $data['bet'],
                'new_bet' => $existingRecord->bet,
                'data_count' => count($originalData),
                'db_time' => round($duration, 2) . 'ms',
            ]);
            return;
        }

        // 获取玩家信息（补充推荐人等信息）
        $player = Player::query()->find($data['player_id']);
        if (!$player) {
            $log->warning('玩家不存在', ['player_id' => $data['player_id']]);
            return;
        }

        // 创建新下注记录
        $insert = [
            'player_id' => $data['player_id'],
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $data['platform_id'],
            'game_code' => $data['game_code'],
            'department_id' => $player->department_id,
            'bet' => $data['bet'],
            'win' => $data['win'] ?? 0,
            'diff' => $data['diff'] ?? 0,
            'order_no' => $data['order_no'],
            'original_data' => json_encode([$data['original_data']] ?? []),
            'order_time' => $data['order_time'],
            'settlement_status' => $data['settlement_status'] ?? PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED,
        ];

        PlayGameRecord::query()->create($insert);

        $duration = (microtime(true) - $startTime) * 1000;

        $log->info('✅ 下注记录创建成功', [
            'order_no' => $data['order_no'],
            'player_id' => $data['player_id'],
            'bet' => $data['bet'],
            'platform_id' => $data['platform_id'],
            'game_code' => $data['game_code'],
            'db_time' => round($duration, 2) . 'ms',
        ]);

        // 异步更新玩家统计（如果需要）
        if (!empty($data['update_stats'])) {
            $this->updatePlayerStats($player, $data, $log);
        }
    }

    /**
     * 处理结算记录
     */
    private function handleSettleRecord(array $data, $log)
    {
        $startTime = microtime(true);

        $record = PlayGameRecord::query()
            ->where('order_no', $data['order_no'])
            ->lockForUpdate()
            ->first();

        if (!$record) {
            // ⚠️ 正常情况下不应该出现（应该先下注再结算）
            // 但某些平台可能结算先到达，记录警告但允许继续
            $log->warning('⚠️ 下注记录不存在（可能结算请求先到达）', [
                'order_no' => $data['order_no'],
                'win' => $data['win'],
                'diff' => $data['diff']
            ]);
            return;
        }

        // ✅ 检查是否已结算（幂等性）
        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            $log->info('⏭️ 记录已结算，跳过（幂等性保护）', [
                'order_no' => $data['order_no'],
                'previous_win' => $record->win,
            ]);
            return;
        }

        // 更新结算信息
        $record->win = $data['win'] ?? 0;
        $record->diff = $data['diff'] ?? 0;
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->action_at = now();
        $record->save();

        $duration = (microtime(true) - $startTime) * 1000;

        $log->info('💰 结算记录更新成功', [
            'order_no' => $data['order_no'],
            'bet' => $record->bet,
            'win' => $data['win'],
            'diff' => $data['diff'],
            'db_time' => round($duration, 2) . 'ms',
        ]);

        // ✨ 触发彩金检查（在确认结算成功后）
        $this->triggerLotteryCheck($record, $log);
    }

    /**
     * 处理取消记录
     */
    private function handleCancelRecord(array $data, $log)
    {
        // ✅ 加锁查询，防止并发重复处理
        $record = PlayGameRecord::query()
            ->where('order_no', $data['order_no'])
            ->lockForUpdate()
            ->first();

        if (!$record) {
            $log->warning('要取消的记录不存在', ['order_no' => $data['order_no']]);
            return;
        }

        // ✅ 幂等性检查：如果已经取消，跳过
        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
            $log->info('记录已取消，跳过', ['order_no' => $data['order_no']]);
            return;
        }

        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_CANCELLED;
        $record->save();

        $log->info('取消记录成功', ['order_no' => $data['order_no']]);
    }

    /**
     * 更新玩家统计数据
     */
    private function updatePlayerStats(Player $player, array $data, $log)
    {
        try {
            // 这里可以更新玩家的累计统计数据
            // 例如：总投注额、总输赢等
            // 根据实际需求实现

            $log->info('玩家统计更新成功', ['player_id' => $player->id]);
        } catch (\Throwable $e) {
            $log->error('玩家统计更新失败', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 消费失败回调
     * 在每次消费失败时触发（max_attempts 内的每次重试失败都会触发）
     *
     * @param \Throwable $exception 异常对象
     * @param array $package 消息包
     * 格式：
     * [
     *     'id' => 1357277951,           // 消息ID
     *     'time' => 1709170510,         // 消息时间
     *     'delay' => 0,                 // 延迟时间
     *     'attempts' => 2,              // 当前消费次数（第几次重试）
     *     'queue' => 'game-bet-record', // 队列名
     *     'data' => [...],              // 消息内容
     *     'max_attempts' => 5,          // 最大重试次数
     *     'error' => '错误信息'          // 错误信息
     * ]
     */
    public function onConsumeFailure(\Throwable $exception, $package)
    {
        $log = Log::channel('game_bet_record');

        $recordType = $package['data']['record_type'] ?? 'unknown';
        $orderNo = $package['data']['order_no'] ?? 'UNKNOWN';
        $attempts = $package['attempts'] ?? 0;
        $maxAttempts = $package['max_attempts'] ?? 5;

        // 是否达到最大重试次数
        $isFinalAttempt = $attempts >= $maxAttempts;

        // 记录失败日志
        $log->error('🔴 队列消费失败' . ($isFinalAttempt ? '（已达最大重试次数）' : ''), [
            'message_id' => $package['id'] ?? null,
            'queue' => $package['queue'] ?? 'unknown',
            'record_type' => $recordType,
            'order_no' => $orderNo,
            'player_id' => $package['data']['player_id'] ?? null,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_file' => $exception->getFile() . ':' . $exception->getLine(),
            'data' => $package['data'],
        ]);

        // 达到最大重试次数时的特殊处理
        if ($isFinalAttempt) {
            // 1. 发送严重告警（Telegram）
            $this->sendCriticalAlert($exception, $package);

            // 2. 记录到失败队列（可选：用于人工处理）
            $this->logToFailureQueue($package);

            // 3. 记录到数据库（可选：用于统计和分析）
            $this->logToDatabase($exception, $package);
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

            $recordType = $package['data']['record_type'] ?? 'unknown';
            $orderNo = $package['data']['order_no'] ?? 'UNKNOWN';
            $playerId = $package['data']['player_id'] ?? null;

            // 组装消息
            $date = date('Y-m-d H:i:s');
            $level = 'CRITICAL';
            $message = '队列消费失败告警';
            $context = [
                'queue' => $package['queue'] ?? 'unknown',
                'record_type' => $recordType,
                'order_no' => $orderNo,
                'player_id' => $playerId,
                'attempts' => "{$package['attempts']}/{$package['max_attempts']}",
                'error' => mb_substr($exception->getMessage(), 0, 200),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];

            $text = "🚨 *Webman 错误告警*\n";
            $text .= "📅 时间: `{$date}`\n";
            $text .= "🔴 级别: `{$level}`\n";
            $text .= "🖥️ 节点: `" . gethostname() . "`\n";
            $text .= "📝 消息: {$message}\n";
            $text .= "📋 队列: `{$context['queue']}`\n";
            $text .= "📝 类型: `{$recordType}`\n";
            $text .= "🎫 订单: `{$orderNo}`\n";
            if ($playerId) {
                $text .= "👤 玩家: `{$playerId}`\n";
            }
            $text .= "🔁 重试: `{$context['attempts']}`\n";
            $text .= "❌ 错误: {$context['error']}\n";
            $text .= "📍 位置: `{$context['file']}:{$context['line']}`\n";

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
            $failureKey = 'queue:game-bet-record:failed';
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

    /**
     * 记录到数据库（用于统计和分析）
     */
    private function logToDatabase(\Throwable $exception, array $package)
    {
        try {
            // 可选：创建 queue_failures 表记录失败详情
            // 用于后续分析失败原因和趋势

            /*
            DB::table('queue_failures')->insert([
                'queue' => $package['queue'],
                'message_id' => $package['id'],
                'payload' => json_encode($package['data']),
                'exception' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'failed_at' => now(),
            ]);
            */
        } catch (\Throwable $e) {
            Log::error('记录失败数据库失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 触发彩金检查
     * 在结算成功后，发送到彩金队列进行抽奖检查
     */
    private function triggerLotteryCheck(PlayGameRecord $record, $log)
    {
        try {
            // 过滤条件检查
            if (!$this->shouldTriggerLottery($record)) {
                return;
            }

            // 发送到彩金队列
            \Webman\RedisQueue\Client::send('game-lottery', [
                'player_id' => $record->player_id,
                'bet' => $record->bet,
                'play_game_record_id' => $record->id
            ]);

            $log->info('🎰 彩金队列已触发', [
                'order_no' => $record->order_no,
                'player_id' => $record->player_id,
                'bet' => $record->bet,
                'record_id' => $record->id
            ]);

        } catch (\Throwable $e) {
            // 彩金触发失败不应阻塞主流程，只记录警告
            $log->warning('⚠️ 彩金队列触发失败', [
                'order_no' => $record->order_no,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 检查是否应该触发彩金
     *
     * @param PlayGameRecord $record
     * @return bool
     */
    private function shouldTriggerLottery(PlayGameRecord $record): bool
    {
        // 1. 下注金额必须大于0（过滤免费游戏）
        if ($record->bet <= 0) {
            return false;
        }

        // 2. 必须是已结算状态
        if ($record->settlement_status != PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            return false;
        }

        // 3. 过滤BTG鱼机游戏
        $originalData = json_decode($record->original_data, true);
        if (is_array($originalData) && count($originalData) > 0) {
            $firstData = $originalData[0];
            $gameType = $firstData['game_type'] ?? '';
            if ($gameType === 'fish') {
                return false; // BTG鱼机游戏不参与彩金
            }
        }

        return true;
    }
}
