<?php

namespace app\service;

use app\model\PlayerBonusTask;
use support\Log;
use support\Redis;

/**
 * 提现验证服务
 */
class DepositBonusWithdrawService
{
    /**
     * 检查玩家是否可以提现
     */
    public function checkWithdrawable(int $playerId, float $withdrawAmount = 0): array
    {
        try {
            // 先从缓存查询
            $cacheKey = "player_withdraw_check:{$playerId}";
            $cached = Redis::get($cacheKey);

            if ($cached) {
                $result = json_decode($cached, true);
                // 如果缓存显示可以提现，直接返回
                if ($result['can_withdraw']) {
                    return $result;
                }
            }

            // 查询玩家是否有未完成的押码量任务
            $activeTasks = PlayerBonusTask::where('player_id', $playerId)
                ->where('status', PlayerBonusTask::STATUS_IN_PROGRESS)
                ->where('expires_at', '>', time())
                ->with('order.activity')
                ->get();

            // 没有未完成任务，可以提现
            if ($activeTasks->isEmpty()) {
                $result = [
                    'can_withdraw' => true,
                    'reason' => '',
                    'tasks' => [],
                ];

                // 缓存结果
                Redis::setex($cacheKey, 60, json_encode($result));

                return $result;
            }

            // 有未完成任务，禁止提现
            $taskDetails = [];
            foreach ($activeTasks as $task) {
                $taskDetails[] = [
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

            $result = [
                'can_withdraw' => false,
                'reason' => '您有未完成的押码量任务，完成后方可提现',
                'task_count' => count($taskDetails),
                'tasks' => $taskDetails,
                'tip' => '请先完成所有押码量任务，然后即可提现全部余额',
            ];

            // 缓存结果（较短时间）
            Redis::setex($cacheKey, 30, json_encode($result));

            return $result;

        } catch (\Exception $e) {
            Log::error('检查提现资格失败: ' . $e->getMessage(), ['player_id' => $playerId]);

            // 发生错误时，为安全起见，允许提现
            return [
                'can_withdraw' => true,
                'reason' => '',
                'tasks' => [],
            ];
        }
    }

    /**
     * 提现前验证（在提现流程中调用）
     */
    public function validateWithdraw(int $playerId, float $withdrawAmount): void
    {
        $check = $this->checkWithdrawable($playerId, $withdrawAmount);

        if (!$check['can_withdraw']) {
            throw new \support\exception\BusinessException($check['reason']);
        }
    }

    /**
     * 清除提现检查缓存
     */
    public function clearWithdrawCache(int $playerId): void
    {
        try {
            Redis::del("player_withdraw_check:{$playerId}");
        } catch (\Exception $e) {
            Log::warning('清除提现缓存失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取玩家可提现余额信息
     */
    public function getWithdrawableBalance(int $playerId): array
    {
        try {
            // 获取玩家信息
            $player = \app\model\Player::find($playerId);
            if (!$player) {
                return [
                    'total_balance' => 0,
                    'withdrawable_balance' => 0,
                    'locked_balance' => 0,
                    'can_withdraw' => false,
                ];
            }

            // 检查是否可以提现
            $check = $this->checkWithdrawable($playerId);

            $totalBalance = $player->money ?? 0;

            if ($check['can_withdraw']) {
                // 全部余额可提现
                return [
                    'total_balance' => $totalBalance,
                    'withdrawable_balance' => $totalBalance,
                    'locked_balance' => 0,
                    'can_withdraw' => true,
                    'tasks' => [],
                ];
            } else {
                // 有押码量任务，全部余额锁定
                return [
                    'total_balance' => $totalBalance,
                    'withdrawable_balance' => 0,
                    'locked_balance' => $totalBalance,
                    'can_withdraw' => false,
                    'reason' => $check['reason'],
                    'tasks' => $check['tasks'],
                ];
            }

        } catch (\Exception $e) {
            Log::error('获取可提现余额失败: ' . $e->getMessage(), ['player_id' => $playerId]);

            return [
                'total_balance' => 0,
                'withdrawable_balance' => 0,
                'locked_balance' => 0,
                'can_withdraw' => false,
            ];
        }
    }
}