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
use support\Redis;
use yzh52521\WebmanLock\Locker;

/**
 * 二维码生成与核销服务
 */
class DepositBonusQrcodeService
{
    /**
     * 生成二维码订单
     */
    public function generateQrcodeOrder(
        int $activityId,
        int $tierId,
        int $playerId,
        int $storeId,
        int $createdBy,
        int $agentId = 0
    ): DepositBonusOrder
    {
        try {
            // 验证活动
            $activity = DepositBonusActivity::find($activityId);
            if (!$activity || !$activity->isValid()) {
                throw new BusinessException('活动不存在或已结束');
            }

            // 验证档位
            $tier = DepositBonusTier::find($tierId);
            if (!$tier || $tier->activity_id != $activityId) {
                throw new BusinessException('档位不存在');
            }

            // 检查玩家参与次数限制
            if (!$activity->checkPlayerLimit($playerId)) {
                throw new BusinessException('您已达到参与次数限制');
            }

            // 检查玩家是否存在
            $player = Player::find($playerId);
            if (!$player) {
                throw new BusinessException('玩家不存在');
            }

            Db::beginTransaction();

            // 创建订单
            $order = new DepositBonusOrder();
            $order->order_no = DepositBonusOrder::generateOrderNo();
            $order->activity_id = $activityId;
            $order->tier_id = $tierId;
            $order->store_id = $storeId;
            $order->agent_id = $agentId;
            $order->player_id = $playerId;
            $order->deposit_amount = $tier->deposit_amount;
            $order->bonus_amount = $tier->bonus_amount;
            $order->required_bet_amount = $tier->calculateRequiredBet($activity->bet_multiple);
            $order->current_bet_amount = 0;
            $order->bet_progress = 0;
            $order->status = DepositBonusOrder::STATUS_PENDING;
            $order->created_by = $createdBy;
            $order->created_at = time();

            // 生成二维码令牌
            $order->qrcode_token = $this->generateQrcodeToken($order);
            $order->qrcode_expires_at = time() + 86400; // 24小时有效期
            $order->expires_at = time() + ($activity->valid_days * 86400); // 活动有效期

            $order->save();

            Db::commit();

            // 生成二维码图片（异步或同步）
            // $this->generateQrcodeImage($order);

            return $order;

        } catch (\Exception $e) {
            Db::rollBack();
            Log::error('生成二维码订单失败: ' . $e->getMessage());
            throw new BusinessException($e->getMessage());
        }
    }

    /**
     * 生成二维码令牌
     */
    private function generateQrcodeToken(DepositBonusOrder $order): string
    {
        $data = [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'player_id' => $order->player_id,
            'bonus_amount' => $order->bonus_amount,
            'timestamp' => time(),
        ];

        // 使用AES加密
        $key = config('app.key', 'default_key');
        $jsonData = json_encode($data);
        $encrypted = openssl_encrypt($jsonData, 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16));

        return base64_encode($encrypted);
    }

    /**
     * 解密二维码令牌
     */
    private function decryptQrcodeToken(string $token): array
    {
        try {
            $key = config('app.key', 'default_key');
            $encrypted = base64_decode($token);
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16));

            return json_decode($decrypted, true);
        } catch (\Exception $e) {
            throw new BusinessException('二维码无效');
        }
    }

    /**
     * 核销二维码
     */
    public function verifyQrcode(string $token, int $playerId): DepositBonusOrder
    {
        // 使用分布式锁防止重复核销
        $lockKey = "qrcode_verify_lock:{$token}";
        $lock = Locker::lock($lockKey, 10, true);

        if (!$lock->acquire()) {
            throw new BusinessException('操作太频繁，请稍后重试');
        }

        try {
            // 解密令牌
            $tokenData = $this->decryptQrcodeToken($token);

            // 查询订单
            $order = DepositBonusOrder::where('qrcode_token', $token)->first();
            if (!$order) {
                throw new BusinessException('二维码不存在');
            }

            // 验证玩家
            if ($order->player_id != $playerId) {
                throw new BusinessException('二维码与玩家不匹配');
            }

            // 验证订单状态
            if (!$order->canVerify()) {
                throw new BusinessException('订单状态不允许核销');
            }

            // 验证二维码有效期
            if ($order->qrcode_expires_at < time()) {
                throw new BusinessException('二维码已过期');
            }

            // 查询玩家
            $player = Player::find($playerId);
            if (!$player) {
                throw new BusinessException('玩家不存在');
            }

            // 查询活动配置
            $activity = $order->activity;
            if (!$activity || !$activity->isValid()) {
                throw new BusinessException('活动已结束');
            }

            // 检查是否需要验证无机台使用
            if ($activity->require_no_machine) {
                // TODO: 检查玩家是否有正在使用的机台
                // if ($this->hasActiveMachine($playerId)) {
                //     throw new BusinessException('请先退出正在使用的机台');
                // }
            }

            Db::beginTransaction();

            // 增加玩家余额
            $balanceBefore = $player->money;
            $player->money += $order->bonus_amount;
            $player->save();

            // 检查玩家是否有未完成的打码量任务
            $existingTask = PlayerBonusTask::where('player_id', $playerId)
                ->where('store_id', $order->store_id)
                ->where('status', PlayerBonusTask::STATUS_IN_PROGRESS)
                ->where('expires_at', '>', time())
                ->orderBy('created_at', 'asc')
                ->first();

            if ($existingTask) {
                // 有未完成任务，叠加打码量到现有任务
                $existingTask->required_bet_amount += $order->required_bet_amount;

                // 重新计算进度
                if ($existingTask->required_bet_amount > 0) {
                    $existingTask->bet_progress = round(($existingTask->current_bet_amount / $existingTask->required_bet_amount) * 100, 2);
                }

                // 延长有效期（取最长的有效期）
                if ($order->expires_at > $existingTask->expires_at) {
                    $existingTask->expires_at = $order->expires_at;
                }

                $existingTask->updated_at = time();
                $existingTask->save();

                Log::info('打码量叠加到已有任务', [
                    'task_id' => $existingTask->id,
                    'player_id' => $playerId,
                    'order_id' => $order->id,
                    'added_bet_amount' => $order->required_bet_amount,
                    'total_required' => $existingTask->required_bet_amount,
                ]);

                $task = $existingTask;
            } else {
                // 没有未完成任务，创建新的打码量任务
                $task = new PlayerBonusTask();
                $task->player_id = $playerId;
                $task->store_id = $order->store_id;
                $task->agent_id = $order->agent_id ?? 0;
                $task->order_id = $order->id;
                $task->required_bet_amount = $order->required_bet_amount;
                $task->current_bet_amount = 0;
                $task->bet_progress = 0;
                $task->status = PlayerBonusTask::STATUS_IN_PROGRESS;
                $task->expires_at = $order->expires_at;
                $task->created_at = time();
                $task->updated_at = time();
                $task->save();

                Log::info('创建新的打码量任务', [
                    'task_id' => $task->id,
                    'player_id' => $playerId,
                    'order_id' => $order->id,
                    'required_bet_amount' => $task->required_bet_amount,
                ]);
            }

            // 记录账变（充值满赠专用表）
            PlayerMoneyEditLogBonus::createLog([
                'player_id' => $playerId,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'change_type' => PlayerMoneyEditLogBonus::CHANGE_TYPE_BONUS_GRANT,
                'amount' => $order->bonus_amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $player->money,
                'operator_type' => PlayerMoneyEditLogBonus::OPERATOR_TYPE_SYSTEM,
                'remark' => "充值满赠活动赠送：{$activity->activity_name}",
            ]);

            // 同时记录到原有账变表
            $moneyEditLog = new PlayerMoneyEditLog();
            $moneyEditLog->player_id = $playerId;
            $moneyEditLog->department_id = $player->department_id ?? 0;
            $moneyEditLog->type = PlayerMoneyEditLog::TYPE_INCREASE;
            $moneyEditLog->action = PlayerMoneyEditLog::DEPOSIT_BONUS_GRANT;
            $moneyEditLog->tradeno = $order->order_no;
            $moneyEditLog->currency = 'CNY';
            $moneyEditLog->money = $order->bonus_amount;
            $moneyEditLog->origin_money = $balanceBefore;
            $moneyEditLog->after_money = $player->money;
            $moneyEditLog->inmoney = $order->bonus_amount;
            $moneyEditLog->subsidy_money = 0;
            $moneyEditLog->bet_multiple = $activity->bet_multiple;
            $moneyEditLog->bet_num = $order->required_bet_amount;
            $moneyEditLog->remark = "充值满赠：{$activity->activity_name}（充值{$order->deposit_amount}赠{$order->bonus_amount}）";
            $moneyEditLog->activity = $activity->id;
            $moneyEditLog->user_id = $order->verified_by ?? null;
            $moneyEditLog->user_name = '';
            $moneyEditLog->save();

            // 更新订单状态
            $order->status = DepositBonusOrder::STATUS_VERIFIED;
            $order->verified_at = time();
            $order->updated_at = time();
            $order->save();

            Db::commit();

            // 清除缓存
            $this->clearPlayerCache($playerId);

            return $order;

        } catch (\Exception $e) {
            Db::rollBack();
            Log::error('核销二维码失败: ' . $e->getMessage(), [
                'token' => $token,
                'player_id' => $playerId,
            ]);
            throw new BusinessException($e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * 批量生成二维码
     */
    public function batchGenerateQrcode(
        int   $activityId,
        int   $tierId,
        array $playerIds,
        int   $storeId,
        int   $createdBy
    ): array
    {
        $orders = [];
        $errors = [];

        foreach ($playerIds as $playerId) {
            try {
                $order = $this->generateQrcodeOrder($activityId, $tierId, $playerId, $storeId, $createdBy);
                $orders[] = $order;
            } catch (\Exception $e) {
                $errors[] = [
                    'player_id' => $playerId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => $orders,
            'errors' => $errors,
        ];
    }

    /**
     * 清除玩家缓存
     */
    private function clearPlayerCache(int $playerId): void
    {
        try {
            Redis::del("player_bonus_tasks:{$playerId}");
            Redis::del("player_balance:{$playerId}");
        } catch (\Exception $e) {
            Log::warning('清除玩家缓存失败: ' . $e->getMessage());
        }
    }
}
