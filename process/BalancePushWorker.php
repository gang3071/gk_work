<?php

namespace process;

use app\service\BalancePushService;
use support\Log;
use Workerman\Worker;

/**
 * 实时余额推送进程
 *
 * 职责：
 * - 订阅 Redis Pub/Sub 频道 (balance:change)
 * - 收到消息后立即推送到 WebSocket
 * - 延迟 < 50ms，实现真正的实时推送
 *
 * 优势：
 * - 不阻塞 iGaming API（Redis PUBLISH 延迟 < 2ms）
 * - 推送失败不影响核心业务
 * - 比 Crontab 定时任务更实时
 */
class BalancePushWorker
{
    /**
     * @var Worker
     */
    private Worker $worker;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $log;

    /**
     * Redis 订阅连接
     */
    private $redis;

    public function __construct()
    {
        // 使用独立的日志通道，便于问题排查
        $this->log = Log::channel('balance_push');
    }

    /**
     * Worker 启动时回调
     */
    public function onWorkerStart(Worker $worker): void
    {
        $this->worker = $worker;

        $this->log->info("实时余额推送进程启动", [
            'worker_id' => $worker->id,
        ]);

        // 延迟启动订阅，确保 Redis 服务已就绪（重启场景）
        \Workerman\Timer::add(1, function () {
            $this->subscribeWithRetry();
        }, [], false);  // 只执行一次
    }

    /**
     * 带重试的订阅逻辑
     */
    private function subscribeWithRetry(int $attempt = 1): void
    {
        $maxRetries = 10;

        try {
            // 使用 queue 连接池（支持阻塞操作，不影响 igaming 核心业务）
            $redisConnection = \support\Redis::connection('queue');
            $this->redis = $redisConnection->client();

            // 设置为阻塞模式（Redis Pub/Sub 需要）
            $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

            $this->log->info("开始订阅 Redis 频道: balance:change", [
                'attempt' => $attempt,
            ]);

            // 订阅频道（阻塞模式）
            // ⚠️ 此调用永不返回，除非连接断开
            $this->redis->subscribe(['balance:change'], [$this, 'handleMessage']);

            // 如果执行到这里，说明订阅中断了
            $this->log->warning("Redis 订阅意外中断，尝试重连", [
                'attempt' => $attempt,
            ]);

            // 订阅中断后立即重连
            $this->scheduleReconnect($attempt + 1);

        } catch (\Throwable $e) {
            $this->log->error("Redis 订阅失败", [
                'attempt' => $attempt,
                'max_retries' => $maxRetries,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // 重试逻辑
            if ($attempt < $maxRetries) {
                $this->scheduleReconnect($attempt + 1);
            } else {
                $this->log->critical("Redis 订阅失败次数过多，放弃重试", [
                    'max_retries' => $maxRetries,
                ]);
                // 触发 Worker 退出，让 Workerman 主进程重启此进程
                $this->worker->stop();
            }
        }
    }

    /**
     * 安排下次重连
     */
    private function scheduleReconnect(int $nextAttempt): void
    {
        // 指数退避：2^attempt 秒，最大 60 秒
        $delay = min(pow(2, $nextAttempt - 1), 60);

        $this->log->info("将在 {$delay} 秒后尝试重新订阅", [
            'next_attempt' => $nextAttempt,
        ]);

        \Workerman\Timer::add($delay, function () use ($nextAttempt) {
            $this->subscribeWithRetry($nextAttempt);
        }, [], false);  // 只执行一次
    }

    /**
     * 处理订阅消息
     *
     * @param \Redis $redis
     * @param string $channel
     * @param string $message
     */
    public function handleMessage($redis, string $channel, string $message): void
    {
        try {
            // 解析消息
            $data = json_decode($message, true);
            if (!$data) {
                $this->log->warning("余额推送消息解析失败", [
                    'message' => substr($message, 0, 200),
                    'json_error' => json_last_error_msg(),
                ]);
                return;
            }

            // 验证必要字段
            if (!isset($data['player_id'], $data['new_balance'], $data['reason'])) {
                $this->log->warning("余额推送消息缺少必要字段", [
                    'data' => $data,
                ]);
                return;
            }

            // 推送余额变化
            $result = BalancePushService::pushBalanceChange(
                (int)$data['player_id'],
                (float)($data['old_balance'] ?? 0),
                (float)$data['new_balance'],
                $data['reason'],
                [
                    'platform' => $data['platform'] ?? '',
                    'order_no' => $data['order_no'] ?? '',
                ]
            );

            // 只记录失败日志，减少 I/O
            if (!$result) {
                $this->log->warning("实时推送失败", [
                    'player_id' => $data['player_id'],
                    'reason' => $data['reason'],
                    'platform' => $data['platform'] ?? '',
                ]);
            }

        } catch (\Throwable $e) {
            $this->log->error("处理推送消息异常", [
                'error' => $e->getMessage(),
                'message' => substr($message, 0, 200),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Worker 停止时回调
     */
    public function onWorkerStop(Worker $worker): void
    {
        $this->log->info("实时余额推送进程停止", [
            'worker_id' => $worker->id,
        ]);

        if ($this->redis) {
            try {
                $this->redis->close();
            } catch (\Throwable $e) {
                // 忽略关闭错误
            }
        }
    }
}
