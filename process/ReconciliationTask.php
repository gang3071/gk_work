<?php

namespace process;

use app\model\PlayerPlatformCash;
use app\service\WalletService;
use support\Log;
use support\Redis;
use Workerman\Crontab\Crontab;

/**
 * Redis 钱包对账定时任务
 *
 * 作用：监控和兜底，确保 Redis 与 MySQL 数据一致性
 * - 每10分钟对比 Redis 和数据库余额
 * - 智能修正小额差异（< 10元）
 * - 大额差异告警，需人工处理
 */
class ReconciliationTask
{
    public function onWorkerStart()
    {
        // 每10分钟执行一次对账（降低频率，保留监控功能）
        new Crontab('0 */10 * * * *', function () {
            $this->reconcile();
        });

        Log::info('ReconciliationTask: 对账任务已启动（每10分钟）');
    }

    /**
     * 执行对账（智能判断策略）
     */
    private function reconcile()
    {
        $startTime = microtime(true);

        try {
            Log::info('ReconciliationTask: 开始对账');

            // 获取所有钱包记录
            $wallets = PlayerPlatformCash::select(['player_id', 'platform_id', 'money'])
                ->get()
                ->toArray();

            if (empty($wallets)) {
                Log::warning('ReconciliationTask: 没有钱包数据');
                return;
            }

            // 智能对账
            $result = $this->smartReconcile($wallets);

            $elapsed = (microtime(true) - $startTime) * 1000;

            Log::info('ReconciliationTask: 对账完成', [
                'total' => count($wallets),
                'matched' => $result['matched'],
                'rebuilt' => $result['rebuilt'],
                'fixed_small' => $result['fixed_small'],
                'alerted' => $result['alerted'],
                'elapsed_ms' => round($elapsed, 2),
            ]);

            // 如果有大额差异告警
            if ($result['alerted'] > 0) {
                $this->sendAlert($result);
            }

        } catch (\Throwable $e) {
            Log::error('ReconciliationTask: 对账失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 智能对账策略
     *
     * @param array $wallets
     * @return array
     */
    private function smartReconcile(array $wallets): array
    {
        $matched = 0;         // 一致
        $rebuilt = 0;         // 重建 Redis 缓存
        $fixedSmall = 0;      // 修正小额差异
        $alerted = 0;         // 大额差异告警
        $alertDetails = [];

        foreach ($wallets as $wallet) {
            $playerId = $wallet['player_id'];
            $dbBalance = (float)$wallet['money'];

            // ✅ 直接读取 Redis 缓存（用于对账，不触发自动同步）
            $cacheKey = "wallet:balance:{$playerId}";
            $redisBalance = Redis::get($cacheKey);

            if ($redisBalance !== null && $redisBalance !== false) {
                $redisBalance = (float)$redisBalance;
            } else {
                $redisBalance = null;
            }

            // 情况1: Redis 不存在 → 以 MySQL 为准，重建缓存
            if ($redisBalance === null) {
                // ✅ 使用 WalletService 重建缓存（标准流程）
                WalletService::getBalance($playerId, 1, true);  // forceRefresh = true
                $rebuilt++;
                Log::info('重建 Redis 缓存', [
                    'player_id' => $playerId,
                    'balance' => $dbBalance,
                ]);
                continue;
            }

            // 计算差异
            $diff = abs($redisBalance - $dbBalance);

            // 情况2: 差异 < 0.01 → 一致（浮点精度误差）
            if ($diff < 0.01) {
                $matched++;
                continue;
            }

            // 情况3: 小额差异（< 10元）→ 以 Redis 为准
            // 原因：可能是 GameRecordSyncWorker 同步延迟，Redis 包含最新交易
            if ($diff < 10) {
                Log::warning('发现小额差异，以 Redis 为准修正 MySQL', [
                    'player_id' => $playerId,
                    'redis_balance' => $redisBalance,
                    'db_balance' => $dbBalance,
                    'diff' => round($diff, 2),
                ]);

                // 更新 MySQL（会触发模型事件自动同步到 Redis）
                PlayerPlatformCash::query()
                    ->where('player_id', $playerId)
                    ->update(['money' => $redisBalance]);

                $fixedSmall++;
                continue;
            }

            // 情况4: 大额差异（≥ 10元）→ 告警，不自动修正
            // 原因：可能是 Bug、数据错误，需人工介入
            Log::error('发现大额差异，需人工处理', [
                'player_id' => $playerId,
                'redis_balance' => $redisBalance,
                'db_balance' => $dbBalance,
                'diff' => round($diff, 2),
            ]);

            $alertDetails[] = [
                'player_id' => $playerId,
                'redis' => $redisBalance,
                'mysql' => $dbBalance,
                'diff' => round($diff, 2),
            ];

            $alerted++;
        }

        return [
            'matched' => $matched,
            'rebuilt' => $rebuilt,
            'fixed_small' => $fixedSmall,
            'alerted' => $alerted,
            'alert_details' => $alertDetails,
        ];
    }

    /**
     * 发送告警
     *
     * @param array $result 对账结果
     */
    private function sendAlert(array $result)
    {
        try {
            $message = "⚠️ Redis 钱包对账发现大额差异\n\n";
            $message .= "总数: " . ($result['matched'] + $result['rebuilt'] + $result['fixed_small'] + $result['alerted']) . "\n";
            $message .= "一致: {$result['matched']}\n";
            $message .= "重建: {$result['rebuilt']}\n";
            $message .= "小额修正: {$result['fixed_small']}\n";
            $message .= "🚨 大额差异: {$result['alerted']}\n\n";

            // 添加详情（最多显示前5条）
            if (!empty($result['alert_details'])) {
                $message .= "详情（前5条）:\n";
                $details = array_slice($result['alert_details'], 0, 5);
                foreach ($details as $i => $detail) {
                    $message .= ($i + 1) . ". 玩家{$detail['player_id']}: ";
                    $message .= "Redis={$detail['redis']}, MySQL={$detail['mysql']}, ";
                    $message .= "差额={$detail['diff']}\n";
                }
            }

            $message .= "\n时间: " . date('Y-m-d H:i:s');
            $message .= "\n需人工确认正确余额";

            // 发送 Telegram 告警（如果配置了）
            $telegramToken = config('plugin.app.telegram.bot_token');
            $telegramChatId = config('plugin.app.telegram.chat_id');

            if ($telegramToken && $telegramChatId) {
                $url = "https://api.telegram.org/bot{$telegramToken}/sendMessage";
                $data = [
                    'chat_id' => $telegramChatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_exec($ch);
                curl_close($ch);

                Log::info('ReconciliationTask: 已发送 Telegram 告警');
            }

        } catch (\Throwable $e) {
            Log::error('ReconciliationTask: 发送告警失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
