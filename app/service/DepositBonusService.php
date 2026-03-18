<?php

namespace app\service;

use app\model\DepositBonusActivity;
use app\model\DepositBonusOrder;
use app\model\DepositBonusTier;
use app\model\Player;
use app\model\PlayerBonusTask;
use app\model\PlayerMoneyEditLog;
use app\model\PlayerMoneyEditLogBonus;
use support\Db;
use support\exception\BusinessException;
use support\Log;

/**
 * 充值满赠核心服务
 */
class DepositBonusService
{
    /**
     * 创建活动
     */
    public function createActivity(array $data, int $storeId, int $createdBy): DepositBonusActivity
    {
        try {
            Db::beginTransaction();

            // 创建活动
            $activity = new DepositBonusActivity();
            $activity->store_id = $storeId;
            $activity->activity_name = $data['activity_name'];
            $activity->activity_type = DepositBonusActivity::TYPE_DEPOSIT_BONUS;
            $activity->start_time = strtotime($data['start_time']);
            $activity->end_time = strtotime($data['end_time']);
            $activity->unlock_type = $data['unlock_type'] ?? DepositBonusActivity::UNLOCK_TYPE_BET;
            $activity->bet_multiple = $data['bet_multiple'] ?? 5.0;
            $activity->valid_days = $data['valid_days'] ?? 7;
            $activity->allow_physical_machine = $data['allow_physical_machine'] ?? 0;
            $activity->require_no_machine = $data['require_no_machine'] ?? 0;
            $activity->limit_per_player = $data['limit_per_player'] ?? 0;
            $activity->limit_period = $data['limit_period'] ?? 'day';
            $activity->description = $data['description'] ?? '';
            $activity->status = DepositBonusActivity::STATUS_ENABLED;
            $activity->created_by = $createdBy;
            $activity->created_at = time();
            $activity->save();

            // 创建档位
            if (!empty($data['tiers'])) {
                foreach ($data['tiers'] as $tierData) {
                    $tier = new DepositBonusTier();
                    $tier->activity_id = $activity->id;
                    $tier->deposit_amount = $tierData['deposit_amount'];
                    $tier->bonus_amount = $tierData['bonus_amount'];
                    $tier->bonus_ratio = round(($tierData['bonus_amount'] / $tierData['deposit_amount']) * 100, 2);
                    $tier->sort_order = $tierData['sort_order'] ?? 0;
                    $tier->status = DepositBonusTier::STATUS_ENABLED;
                    $tier->created_at = time();
                    $tier->save();
                }
            }

            Db::commit();
            return $activity;

        } catch (\Exception $e) {
            Db::rollBack();
            Log::error('创建充值满赠活动失败: ' . $e->getMessage());
            throw new BusinessException('创建活动失败');
        }
    }

    /**
     * 更新活动
     */
    public function updateActivity(int $activityId, array $data): DepositBonusActivity
    {
        try {
            Db::beginTransaction();

            $activity = DepositBonusActivity::find($activityId);
            if (!$activity) {
                throw new BusinessException('活动不存在');
            }

            // 更新活动基本信息
            if (isset($data['activity_name'])) $activity->activity_name = $data['activity_name'];
            if (isset($data['start_time'])) $activity->start_time = strtotime($data['start_time']);
            if (isset($data['end_time'])) $activity->end_time = strtotime($data['end_time']);
            if (isset($data['bet_multiple'])) $activity->bet_multiple = $data['bet_multiple'];
            if (isset($data['valid_days'])) $activity->valid_days = $data['valid_days'];
            if (isset($data['description'])) $activity->description = $data['description'];
            if (isset($data['status'])) $activity->status = $data['status'];

            $activity->updated_at = time();
            $activity->save();

            // 更新档位
            if (isset($data['tiers'])) {
                // 删除旧档位
                DepositBonusTier::where('activity_id', $activityId)->delete();

                // 创建新档位
                foreach ($data['tiers'] as $tierData) {
                    $tier = new DepositBonusTier();
                    $tier->activity_id = $activity->id;
                    $tier->deposit_amount = $tierData['deposit_amount'];
                    $tier->bonus_amount = $tierData['bonus_amount'];
                    $tier->bonus_ratio = round(($tierData['bonus_amount'] / $tierData['deposit_amount']) * 100, 2);
                    $tier->sort_order = $tierData['sort_order'] ?? 0;
                    $tier->status = DepositBonusTier::STATUS_ENABLED;
                    $tier->created_at = time();
                    $tier->save();
                }
            }

            Db::commit();
            return $activity;

        } catch (\Exception $e) {
            Db::rollBack();
            Log::error('更新充值满赠活动失败: ' . $e->getMessage());
            throw new BusinessException('更新活动失败');
        }
    }

    /**
     * 获取活动列表
     */
    public function getActivityList(int $storeId, array $filters = [])
    {
        $query = DepositBonusActivity::where('store_id', $storeId)
            ->whereNull('deleted_at');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['keyword'])) {
            $query->where('activity_name', 'like', '%' . $filters['keyword'] . '%');
        }

        return $query->with('tiers')
            ->orderBy('created_at', 'desc')
            ->paginate($filters['page_size'] ?? 20);
    }

    /**
     * 获取活动详情
     */
    public function getActivityDetail(int $activityId)
    {
        $activity = DepositBonusActivity::with(['tiers', 'statistics'])
            ->find($activityId);

        if (!$activity) {
            throw new BusinessException('活动不存在');
        }

        return $activity;
    }

    /**
     * 启用/停用活动
     */
    public function toggleActivityStatus(int $activityId, int $status): bool
    {
        $activity = DepositBonusActivity::find($activityId);
        if (!$activity) {
            throw new BusinessException('活动不存在');
        }

        $activity->status = $status;
        $activity->updated_at = time();
        return $activity->save();
    }

    /**
     * 删除活动（软删除）
     */
    public function deleteActivity(int $activityId): bool
    {
        $activity = DepositBonusActivity::find($activityId);
        if (!$activity) {
            throw new BusinessException('活动不存在');
        }

        // 检查是否有未完成的订单
        $activeOrders = DepositBonusOrder::where('activity_id', $activityId)
            ->whereIn('status', [
                DepositBonusOrder::STATUS_PENDING,
                DepositBonusOrder::STATUS_VERIFIED
            ])
            ->count();

        if ($activeOrders > 0) {
            throw new BusinessException('活动下有未完成的订单，无法删除');
        }

        $activity->deleted_at = time();
        return $activity->save();
    }

    /**
     * 获取玩家的活动参与记录
     */
    public function getPlayerOrders(int $playerId, array $filters = [])
    {
        $query = DepositBonusOrder::where('player_id', $playerId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['activity_id'])) {
            $query->where('activity_id', $filters['activity_id']);
        }

        return $query->with(['activity', 'tier', 'task'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['page_size'] ?? 20);
    }

    /**
     * 取消订单（扣除已发放的赠金）
     */
    public function cancelOrder(int $orderId, int $operatorId = null, string $reason = ''): bool
    {
        try {
            Db::beginTransaction();

            $order = DepositBonusOrder::find($orderId);
            if (!$order) {
                throw new BusinessException('订单不存在');
            }

            // 检查订单状态
            if ($order->status == DepositBonusOrder::STATUS_CANCELLED) {
                throw new BusinessException('订单已取消');
            }

            if ($order->status == DepositBonusOrder::STATUS_COMPLETED) {
                throw new BusinessException('订单已完成，无法取消');
            }

            // 如果订单已核销，需要扣除赠金
            if ($order->status == DepositBonusOrder::STATUS_VERIFIED) {
                $player = Player::find($order->player_id);
                if (!$player) {
                    throw new BusinessException('玩家不存在');
                }

                // 检查玩家余额是否足够扣除
                if ($player->money < $order->bonus_amount) {
                    throw new BusinessException('玩家余额不足，无法取消订单');
                }

                $activity = $order->activity;
                $balanceBefore = $player->money;

                // 扣除赠金
                $player->money -= $order->bonus_amount;
                $player->save();

                // 记录账变（充值满赠专用表）
                PlayerMoneyEditLogBonus::createLog([
                    'player_id' => $order->player_id,
                    'store_id' => $order->store_id,
                    'order_id' => $order->id,
                    'change_type' => PlayerMoneyEditLogBonus::CHANGE_TYPE_BONUS_CANCEL,
                    'amount' => -$order->bonus_amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $player->money,
                    'operator_type' => $operatorId ? PlayerMoneyEditLogBonus::OPERATOR_TYPE_ADMIN : PlayerMoneyEditLogBonus::OPERATOR_TYPE_SYSTEM,
                    'operator_id' => $operatorId,
                    'remark' => "取消充值满赠订单：{$activity->activity_name}" . ($reason ? "，原因：{$reason}" : ''),
                ]);

                // 同时记录到原有账变表
                $moneyEditLog = new PlayerMoneyEditLog();
                $moneyEditLog->player_id = $order->player_id;
                $moneyEditLog->department_id = $player->department_id ?? 0;
                $moneyEditLog->type = PlayerMoneyEditLog::TYPE_DEDUCT;
                $moneyEditLog->action = PlayerMoneyEditLog::DEPOSIT_BONUS_CANCEL;
                $moneyEditLog->tradeno = $order->order_no;
                $moneyEditLog->currency = 'CNY';
                $moneyEditLog->money = $order->bonus_amount;
                $moneyEditLog->origin_money = $balanceBefore;
                $moneyEditLog->after_money = $player->money;
                $moneyEditLog->inmoney = -$order->bonus_amount;
                $moneyEditLog->subsidy_money = 0;
                $moneyEditLog->bet_multiple = $activity->bet_multiple ?? 0;
                $moneyEditLog->bet_num = $order->required_bet_amount;
                $moneyEditLog->remark = "取消充值满赠：{$activity->activity_name}（退还赠送{$order->bonus_amount}）" . ($reason ? "，原因：{$reason}" : '');
                $moneyEditLog->activity = $activity->id;
                $moneyEditLog->user_id = $operatorId;
                $moneyEditLog->user_name = '';
                $moneyEditLog->save();

                // 更新或取消押码量任务
                $task = PlayerBonusTask::where('order_id', $orderId)->first();
                if ($task && $task->status == PlayerBonusTask::STATUS_IN_PROGRESS) {
                    $task->status = PlayerBonusTask::STATUS_EXPIRED;
                    $task->updated_at = time();
                    $task->save();
                }
            }

            // 更新订单状态
            $order->status = DepositBonusOrder::STATUS_CANCELLED;
            $order->remark = $reason ?: '订单已取消';
            $order->updated_at = time();
            $order->save();

            Db::commit();

            Log::info('取消充值满赠订单成功', [
                'order_id' => $orderId,
                'player_id' => $order->player_id,
                'operator_id' => $operatorId,
                'reason' => $reason,
            ]);

            return true;

        } catch (\Exception $e) {
            Db::rollBack();
            Log::error('取消充值满赠订单失败: ' . $e->getMessage(), ['order_id' => $orderId]);
            throw new BusinessException($e->getMessage());
        }
    }
}
