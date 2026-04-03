<?php

namespace app\queue\redis\fast;

use app\model\Player;
use app\model\PlayGameRecord;
use app\queue\redis\fast\platform\PlatformHandlerFactory;
use Exception;
use support\Db;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

/**
 * 游戏操作队列消费者（重构版 - 平台分离架构）
 *
 * 架构说明：
 * - 使用 Strategy Pattern + Factory Pattern
 * - 每个平台的业务逻辑独立在各自的 Handler 中
 * - GameOperation 只负责调度和事务管理
 *
 * 优点：
 * - 易于维护：每个平台的逻辑独立，互不干扰
 * - 易于扩展：新增平台只需添加新的 Handler，无需修改主文件
 * - 易于测试：可以单独测试每个平台的 Handler
 * - 代码清晰：主文件从1060行减少到~150行
 */
class GameOperation implements Consumer
{
    public string $queue = 'game-operation';
    public string $connection = 'default';

    private $log;

    public function __construct()
    {
        $this->log = Log::channel('game-operation');
    }

    /**
     * 消费队列消息
     *
     * @param array $data 队列数据
     * @return void
     * @throws Throwable
     */
    public function consume($data): void
    {
        $platform = $data['platform'] ?? 'unknown';
        $operation = $data['operation'] ?? 'unknown';
        $orderNo = $data['order_no'] ?? '';
        $playerId = $data['player_id'] ?? 0;

        $startTime = microtime(true);

        $this->log->info("GameOperation: 开始处理", [
            'platform' => $platform,
            'operation' => $operation,
            'order_no' => $orderNo,
        ]);

        // 开启数据库事务
        Db::beginTransaction();

        try {
            // 1. 幂等性检查（数据库）
            $exists = PlayGameRecord::where('order_no', $orderNo)->exists();
            if ($exists && $operation === 'bet') {
                // 下注操作：订单已存在，检查是否支持累计下注
                $handler = PlatformHandlerFactory::create($platform, $this->log);
                if (!$handler->supportsAccumulatedBet()) {
                    $this->log->warning("GameOperation: 订单已存在且不支持累计下注，跳过", [
                        'order_no' => $orderNo,
                        'platform' => $platform,
                    ]);
                    Db::rollBack();
                    return;
                }
                // 支持累计下注，继续处理（Handler内部会处理）
            }

            // 2. 查询玩家
            $player = Player::find($playerId);
            if (!$player) {
                throw new Exception("玩家不存在: {$playerId}");
            }

            // 3. 创建平台处理器并执行操作
            $this->dispatchToHandler($platform, $operation, $data, $player);

            // 4. 提交事务
            Db::commit();

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->log->info("GameOperation: 处理完成", [
                'platform' => $platform,
                'operation' => $operation,
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

        } catch (Throwable $e) {
            // 回滚事务
            Db::rollBack();

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->log->error("GameOperation: 处理失败", [
                'platform' => $platform,
                'operation' => $operation,
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 重新抛出异常，触发队列重试
            throw $e;
        }
    }

    /**
     * 分发到平台处理器
     *
     * @param string $platform 平台代码
     * @param string $operation 操作类型
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @return void
     * @throws Exception
     */
    private function dispatchToHandler(string $platform, string $operation, array $data, Player $player): void
    {
        // 创建平台处理器
        $handler = PlatformHandlerFactory::create($platform, $this->log);

        // 根据操作类型调用对应方法
        match ($operation) {
            'bet' => $handler->processBet($data, $player),
            'settle' => $handler->processSettle($data, $player),
            'cancel' => $handler->processCancel($data, $player),
            'refund' => $handler->processRefund($data, $player),
            default => throw new Exception("未知操作类型: {$operation}"),
        };
    }
}
