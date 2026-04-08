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
        $this->log = Log::channel('default');
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

        try {
            // 使用 queue 连接池（支持阻塞操作，不影响 igaming 核心业务）
            $redisConnection = \support\Redis::connection('queue');
            $this->redis = $redisConnection->client();

            // 设置为阻塞模式（Redis Pub/Sub 需要）
            $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

            $this->log->info("开始订阅 Redis 频道: balance:change");

            // 订阅频道（阻塞模式）
            $this->redis->subscribe(['balance:change'], [$this, 'handleMessage']);

        } catch (\Throwable $e) {
            $this->log->error("实时余额推送进程启动失败", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
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
        $startTime = microtime(true);

        try {
            // 解析消息
            $data = json_decode($message, true);
            if (!$data) {
                $this->log->warning("余额推送消息解析失败", [
                    'message' => substr($message, 0, 200),
                ]);
                return;
            }

            // 验证必要字段
            if (!isset($data['player_id'], $data['new_balance'], $data['reason'])) {
                $this->log->warning("余额推送消息缺少必要字段", ['data' => $data]);
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

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result) {
                $this->log->debug("实时推送成功", [
                    'player_id' => $data['player_id'],
                    'reason' => $data['reason'],
                    'platform' => $data['platform'] ?? '',
                    'duration_ms' => $duration,
                ]);
            } else {
                $this->log->warning("实时推送失败", [
                    'player_id' => $data['player_id'],
                    'reason' => $data['reason'],
                    'duration_ms' => $duration,
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
