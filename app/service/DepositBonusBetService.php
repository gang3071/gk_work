<?php

namespace app\service;

use app\model\DepositBonusBetDetail;
use app\model\DepositBonusOrder;
use app\model\PlayerBonusTask;
use support\Db;
use support\Log;
use support\Redis;

/**
 * 押码量统计服务
 */
class DepositBonusBetService
{
    /**
     * 记录押注并更新押码量
     */
    public function recordBet(array $betData): void
    {
        try {
            $playerId = $betData['player_id'];
            $betAmount = $betData['bet_amount'];
            $validBetAmount = $betData['valid_bet_amount'] ?? $betAmount;
            $gameType = $betData['game_type'];

            // 检查游戏类型是否允许
            if (!DepositBonusBetDetail::isGameTypeAllowed($gameType)) {
                Log::info('游戏类型不参与押码量统计', ['game_type' => $gameType]);
                return;
            }

            // 查询玩家是否有进行中的押码量任务
            $tasks = PlayerBonusTask::where('player_id', $playerId)
                ->where('status', PlayerBonusTask::STATUS_IN_PROGRESS)
                ->where('expires_at', '>', time())
                ->get();

            if ($tasks->isEmpty()) {
                return; // 没有押码量任务
            }

            Db::beginTransaction();

            foreach ($tasks as $task) {
                // 记录押码量明细
                $detail = new DepositBonusBetDetail();
                $detail->order_id = $task->order_id;
                $detail->player_id = $playerId;
                $detail->store_id = $task->store_id;
                $detail->game_type = $gameType;
                $detail->game_platform = $betData['game_platform'] ?? '';
                $detail->game_id = $betData['game_id'] ?? '';
                $detail->game_name = $betData['game_name'] ?? '';
                $detail->bet_amount = $betAmount;
                $detail->win_amount = $betData['win_amount'] ?? 0;
                $detail->valid_bet_amount = $validBetAmount;
                $detail->balance_before = $betData['balance_before'] ?? 0;
                $detail->balance_after = $betData['balance_after'] ?? 0;
                $detail->accumulated_bet = $task->current_bet_amount;
                $detail->new_accumulated_bet = $task->current_bet_amount + $validBetAmount;
                $detail->bet_time = $betData['bet_time'] ?? time();
                $detail->settle_time = $betData['settle_time'] ?? time();
                $detail->created_at = time();
                $detail->save();

                // 更新任务押码量
                $task->updateProgress($validBetAmount);

                // 同步更新订单押码量
                $order = $task->order;
                if ($order) {
                    $order->updateBetProgress($validBetAmount);
                }

                // 检查是否完成押码量
                if ($task->isCompleted()) {
                    $this->completeTask($task);
                }

                // 更新Redis缓存
                $this->updateRedisCache($task);
            }

            Db::commit();

        } catch (\Exception $e) {
            Db::rollBack();
            Log::error('记录押码量失败: ' . $e->getMessage(), $betData);
        }
    }

    /**
     * 完成押码量任务
     */
    private function completeTask(PlayerBonusTask $task): void
    {
        try {
            Db::beginTransaction();

            // 更新任务状态
            $task->complete();

            // 更新订单状态
            $order = DepositBonusOrder::find($task->order_id);
            if ($order) {
                $order->status = DepositBonusOrder::STATUS_COMPLETED;
                $order->completed_at = time();
                $order->updated_at = time();
                $order->save();
            }

            Db::commit();

            // 发送通知（可选）
            // $this->sendCompletionNotice($task);

            Log::info('押码量任务完成', [
                'task_id' => $task->id,
                'player_id' => $task->player_id,
                'order_id' => $task->order_id,
            ]);

        } catch (\Exception $e) {
            Db::rollBack();
            Log::error('完成押码量任务失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新Redis缓存
     */
    private function updateRedisCache(PlayerBonusTask $task): void
    {
        try {
            $key = "bet_amount_task:{$task->id}";
            Redis::setex($key, 86400, json_encode([
                'task_id' => $task->id,
                'player_id' => $task->player_id,
                'required' => $task->required_bet_amount,
                'current' => $task->current_bet_amount,
                'progress' => $task->bet_progress,
                'status' => $task->status,
                'updated_at' => time(),
            ]));

            // 玩家任务列表缓存
            $playerTasksKey = "player_bonus_tasks:{$task->player_id}";
            Redis::del($playerTasksKey); // 删除缓存，让下次查询重新生成

        } catch (\Exception $e) {
            Log::warning('更新Redis缓存失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取玩家押码量进度
     */
    public function getPlayerBetProgress(int $playerId): array
    {
        // 先尝试从缓存获取
        $cacheKey = "player_bonus_tasks:{$playerId}";
        $cached = Redis::get($cacheKey);

        if ($cached) {
            return json_decode($cached, true);
        }

        // 从数据库查询
        $tasks = PlayerBonusTask::where('player_id', $playerId)
            ->where('status', PlayerBonusTask::STATUS_IN_PROGRESS)
            ->where('expires_at', '>', time())
            ->with('order.activity')
            ->get();

        $result = [];
        foreach ($tasks as $task) {
            $result[] = [
                'task_id' => $task->id,
                'order_id' => $task->order_id,
                'activity_name' => $task->order->activity->activity_name ?? '',
                'bonus_amount' => $task->order->bonus_amount ?? 0,
                'required_bet_amount' => $task->required_bet_amount,
                'current_bet_amount' => $task->current_bet_amount,
                'remaining_bet_amount' => $task->getRemainingBetAmount(),
                'bet_progress' => $task->bet_progress,
                'expires_at' => $task->expires_at,
                'remaining_days' => $task->getRemainingDays(),
            ];
        }

        // 缓存结果
        Redis::setex($cacheKey, 60, json_encode($result)); // 缓存1分钟

        return $result;
    }

    /**
     * 获取押码量明细
     */
    public function getBetDetails(int $orderId, array $filters = []): array
    {
        $query = DepositBonusBetDetail::where('order_id', $orderId);

        if (isset($filters['start_time'])) {
            $query->where('bet_time', '>=', $filters['start_time']);
        }

        if (isset($filters['end_time'])) {
            $query->where('bet_time', '<=', $filters['end_time']);
        }

        return $query->orderBy('bet_time', 'desc')
            ->paginate($filters['page_size'] ?? 20)
            ->toArray();
    }

    /**
     * 检查并过期任务
     */
    public function checkExpiredTasks(): int
    {
        $expiredCount = 0;

        try {
            $tasks = PlayerBonusTask::where('status', PlayerBonusTask::STATUS_IN_PROGRESS)
                ->where('expires_at', '<=', time())
                ->get();

            foreach ($tasks as $task) {
                Db::beginTransaction();

                try {
                    // 更新任务状态
                    $task->expire();

                    // 更新订单状态
                    $order = DepositBonusOrder::find($task->order_id);
                    if ($order) {
                        $order->status = DepositBonusOrder::STATUS_EXPIRED;
                        $order->updated_at = time();
                        $order->save();
                    }

                    Db::commit();
                    $expiredCount++;

                } catch (\Exception $e) {
                    Db::rollBack();
                    Log::error('过期任务处理失败: ' . $e->getMessage(), ['task_id' => $task->id]);
                }
            }

        } catch (\Exception $e) {
            Log::error('检查过期任务失败: ' . $e->getMessage());
        }

        return $expiredCount;
    }
}