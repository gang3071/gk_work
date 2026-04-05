<?php

namespace process;

use app\service\GameRecordCacheService;
use support\Log;
use Workerman\Crontab\Crontab;
use Workerman\Worker;

/**
 * 游戏记录清理进程
 *
 * 职责：
 * - 定期清理 Redis 中的过期游戏记录
 * - 清理同步队列中的过期条目
 *
 * 执行频率：
 * - 每小时执行一次（凌晨整点）
 * - Redis 记录 TTL 是 7 天，每小时清理足够
 */
class GameRecordCleanWorker
{
    /**
     * @var Worker
     */
    private Worker $worker;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $log;

    public function __construct()
    {
        $this->log = Log::channel('game_bet_record');
    }

    /**
     * Worker 启动时回调
     */
    public function onWorkerStart(Worker $worker): void
    {
        $this->worker = $worker;

        $this->log->info("游戏记录清理进程启动", [
            'worker_id' => $worker->id,
            'schedule' => '每小时执行一次',
        ]);

        // ✅ 显式绑定 $this，避免闭包作用域问题
        $self = $this;

        // 每小时清理过期记录（每小时的第 0 分 0 秒）
        new Crontab('0 0 * * * *', function () use ($self) {
            $self->cleanExpired();
        });
    }

    /**
     * 清理过期记录
     */
    private function cleanExpired(): void
    {
        $startTime = microtime(true);

        try {
            $this->log->info("开始清理过期记录");

            // 清理过期的 Redis 记录
            $count = GameRecordCacheService::cleanExpiredRecords();

            $elapsed = (microtime(true) - $startTime) * 1000;

            $this->log->info("清理过期记录完成", [
                'cleaned_count' => $count,
                'elapsed_ms' => round($elapsed, 2),
            ]);

        } catch (\Throwable $e) {
            $this->log->error("清理过期记录失败", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
