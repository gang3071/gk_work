<?php

namespace process;

use app\model\PlayerPlatformCash;
use app\service\RedisWalletService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * Redis 钱包对账定时任务
 * 每分钟对比 Redis 和数据库余额，确保数据一致性
 */
class ReconciliationTask
{
    public function onWorkerStart()
    {
        // 每分钟执行一次对账
        new Crontab('*/1 * * * *', function () {
            $this->reconcile();
        });

        Log::info('ReconciliationTask: 对账任务已启动');
    }

    /**
     * 执行对账
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

            // 批量对账
            $result = RedisWalletService::batchSync($wallets);

            $elapsed = (microtime(true) - $startTime) * 1000;

            Log::info('ReconciliationTask: 对账完成', [
                'total' => count($wallets),
                'synced' => $result['synced'],
                'inconsistent' => $result['inconsistent'],
                'elapsed_ms' => round($elapsed, 2),
            ]);

            // 如果不一致数量过多，发送告警
            if ($result['inconsistent'] > 10) {
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
     * 发送告警
     *
     * @param array $result 对账结果
     */
    private function sendAlert(array $result)
    {
        try {
            $message = "⚠️ Redis 钱包对账异常\n\n";
            $message .= "总数: {$result['synced']}\n";
            $message .= "不一致: {$result['inconsistent']}\n";
            $message .= "时间: " . date('Y-m-d H:i:s');

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
