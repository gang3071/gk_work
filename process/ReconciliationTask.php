<?php

namespace process;

use app\model\PlayerPlatformCash;
use support\Log;
use support\Redis;
use Workerman\Crontab\Crontab;

/**
 * Redis 钱包对账定时任务（单向对账：Redis → DB）
 *
 * 核心原则：Redis 是唯一实时标准（Single Source of Truth）
 *
 * 作用：确保数据库与 Redis 保持一致
 * - 每10分钟对比 Redis 和数据库余额
 * - 永远以 Redis 为准，强制同步到数据库
 * - Redis 缺失时跳过（正常的缓存过期）
 * - 大额差异（≥10元）同步后发送统计告警
 *
 * 为什么不从 DB 重建 Redis：
 * - Redis 缓存过期是正常的（玩家不活跃）
 * - 玩家下次操作时会自动加载到 Redis
 * - 对账任务不应该把所有玩家加载到 Redis（浪费内存）
 */
class ReconciliationTask
{
    public function onWorkerStart()
    {
        // 每10分钟执行一次对账
        new Crontab('0 */10 * * * *', function () {
            $this->reconcile();
        });

        Log::info('ReconciliationTask: 对账任务已启动', [
            'interval' => '每10分钟',
            'mode' => '单向对账（Redis → DB）',
            'principle' => 'Redis as Single Source of Truth',
        ]);
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
                'synced' => $result['synced'],
                'redis_missing' => $result['redis_missing'],
                'large_diff_count' => count($result['alert_details']),
                'elapsed_ms' => round($elapsed, 2),
            ]);

            // 如果有大额差异，发送统计告警
            if (!empty($result['alert_details'])) {
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
     * 单向对账策略（Redis 是唯一实时标准）
     *
     * 核心原则：
     * 1. Redis 是"唯一实时标准"（Single Source of Truth）
     * 2. 对账任务只负责：确保数据库与 Redis 保持一致
     * 3. 永远以 Redis 为准，强制同步到数据库
     * 4. Redis 缺失 → 告警（可能是 Redis 故障，不能从 DB 重建）
     *
     * 为什么不从 DB 重建 Redis：
     * - Redis 缓存过期是正常的（玩家不活跃时）
     * - 当玩家下次操作时，会自动从 DB 加载到 Redis
     * - 对账任务不应该提前加载所有玩家到 Redis（浪费内存）
     * - 如果 Redis 数据丢失（故障），从 DB 重建可能用旧数据覆盖
     *
     * @param array $wallets
     * @return array
     */
    private function smartReconcile(array $wallets): array
    {
        $matched = 0;         // 一致
        $synced = 0;          // 已同步（Redis → DB）
        $redisMissing = 0;    // Redis 缺失（只记录，不处理）
        $alertDetails = [];

        foreach ($wallets as $wallet) {
            $playerId = $wallet['player_id'];
            $dbBalance = (float)$wallet['money'];

            // ✅ 直接读取 Redis 缓存
            $cacheKey = "wallet:balance:{$playerId}";
            // 使用 work 连接池读取余额（共享数据）
            $redisBalance = Redis::connection('work')->get($cacheKey);

            if ($redisBalance !== null && $redisBalance !== false) {
                $redisBalance = (float)$redisBalance;
            } else {
                $redisBalance = null;
            }

            // ========================================
            // 情况 1: Redis 不存在 → 跳过（正常的缓存过期）
            // ========================================
            // 说明：
            // - 玩家长时间不活跃，Redis 缓存自然过期
            // - 这是正常现象，不需要处理
            // - 当玩家下次操作时，会通过 getPlayerBalance() 自动加载
            // - 对账任务不应该把所有玩家都加载到 Redis
            if ($redisBalance === null) {
                $redisMissing++;
                continue;  // ✅ 直接跳过，不处理
            }

            // 计算差异
            $diff = abs($redisBalance - $dbBalance);

            // ========================================
            // 情况 2: 差异 < 0.01 → 一致（浮点精度误差）
            // ========================================
            if ($diff < 0.01) {
                $matched++;
                continue;
            }

            // ========================================
            // 情况 3: 存在差异 → 永远以 Redis 为准，强制同步到数据库
            // ========================================
            // 核心原则：Redis 是唯一实时标准
            // - 无论差异大小，都以 Redis 为准
            // - 无论 Redis > DB 还是 Redis < DB，都以 Redis 为准
            // - 如果 Redis 数据有问题，应该修复 Redis，而不是让 DB 影响 Redis

            $logMessage = $diff >= 10 ? '发现大额差异' : '发现小额差异';
            $logData = [
                'player_id' => $playerId,
                'redis_balance' => $redisBalance,
                'db_balance' => $dbBalance,
                'diff' => round($diff, 2),
                'direction' => $redisBalance > $dbBalance ? 'Redis > DB' : 'Redis < DB',
                'action' => 'Redis → DB (强制同步)',
            ];

            if ($diff >= 10) {
                Log::error("ReconciliationTask: {$logMessage}，以 Redis 为准强制同步", $logData);
            } else {
                Log::warning("ReconciliationTask: {$logMessage}，以 Redis 为准强制同步", $logData);
            }

            // ✅ 使用 saveWithoutEvents() 避免循环同步
            $walletModel = PlayerPlatformCash::query()
                ->where('player_id', $playerId)
                ->where('platform_id', 1)
                ->lockForUpdate()
                ->first();

            if ($walletModel) {
                $walletModel->money = $redisBalance;
                $walletModel->saveWithoutEvents();

                Log::info('ReconciliationTask: 已同步 Redis → DB', [
                    'player_id' => $playerId,
                    'old_db_balance' => $dbBalance,
                    'new_db_balance' => $redisBalance,
                    'source' => 'Redis (Single Source of Truth)',
                ]);

                $synced++;

                // 大额差异记录到告警详情（用于统计）
                if ($diff >= 10) {
                    $alertDetails[] = [
                        'player_id' => $playerId,
                        'redis' => $redisBalance,
                        'mysql' => $dbBalance,
                        'diff' => round($diff, 2),
                        'synced' => true,
                    ];
                }
            } else {
                Log::error('ReconciliationTask: 钱包记录不存在', [
                    'player_id' => $playerId,
                ]);
            }
        }

        return [
            'matched' => $matched,
            'synced' => $synced,              // Redis → DB 同步数量
            'redis_missing' => $redisMissing,  // Redis 缺失数量（跳过）
            'alert_details' => $alertDetails,   // 大额差异详情
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
            $largeCount = count($result['alert_details']);

            $message = "⚠️ Redis 钱包对账发现大额差异\n\n";
            $message .= "总数: " . ($result['matched'] + $result['synced'] + $result['redis_missing']) . "\n";
            $message .= "一致: {$result['matched']}\n";
            $message .= "已同步: {$result['synced']} (Redis → DB)\n";
            $message .= "Redis缺失: {$result['redis_missing']}\n";
            $message .= "🚨 大额差异: {$largeCount} (已强制同步)\n\n";

            // 添加详情（最多显示前5条）
            if (!empty($result['alert_details'])) {
                $message .= "大额差异详情（前5条）:\n";
                $details = array_slice($result['alert_details'], 0, 5);
                foreach ($details as $i => $detail) {
                    $message .= ($i + 1) . ". 玩家{$detail['player_id']}: ";
                    $message .= "Redis={$detail['redis']}, MySQL={$detail['mysql']}, ";
                    $message .= "差额={$detail['diff']} (已同步)\n";
                }
            }

            $message .= "\n时间: " . date('Y-m-d H:i:s');
            $message .= "\n注意: 已按 Redis 为准强制同步到数据库";

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
