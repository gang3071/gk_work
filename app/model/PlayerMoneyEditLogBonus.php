<?php

namespace app\model;

use support\Model;

/**
 * 充值满赠账变记录模型
 * @property int $id 记录ID
 * @property int $player_id 玩家ID
 * @property int $store_id 店家ID
 * @property int $agent_id 代理ID
 * @property int $order_id 关联订单ID
 * @property string $change_type 变动类型：bonus_grant=赠送发放,bonus_cancel=赠送取消
 * @property float $amount 变动金额
 * @property float $balance_before 变动前余额
 * @property float $balance_after 变动后余额
 * @property int $related_id 关联业务ID
 * @property string $related_type 关联业务类型
 * @property int $operator_id 操作人ID
 * @property string $operator_type 操作人类型：admin,player,system
 * @property string $remark 备注
 * @property int $created_at 创建时间
 */
class PlayerMoneyEditLogBonus extends Model
{
    protected $table = 'player_money_edit_log_bonus';
    protected $pk = 'id';

    // 变动类型常量
    const CHANGE_TYPE_BONUS_GRANT = 'bonus_grant';    // 赠送发放
    const CHANGE_TYPE_BONUS_CANCEL = 'bonus_cancel';  // 赠送取消

    // 操作人类型常量
    const OPERATOR_TYPE_ADMIN = 'admin';
    const OPERATOR_TYPE_PLAYER = 'player';
    const OPERATOR_TYPE_SYSTEM = 'system';

    /**
     * 关联玩家
     */
    public function player()
    {
        return $this->belongsTo(\app\model\Player::class, 'player_id', 'id');
    }

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(DepositBonusOrder::class, 'order_id', 'id');
    }

    /**
     * 创建账变记录
     */
    public static function createLog(array $data): self
    {
        $log = new self();
        $log->player_id = $data['player_id'];
        $log->store_id = $data['store_id'];
        $log->agent_id = $data['agent_id'] ?? 0;
        $log->order_id = $data['order_id'] ?? null;
        $log->change_type = $data['change_type'];
        $log->amount = $data['amount'];
        $log->balance_before = $data['balance_before'];
        $log->balance_after = $data['balance_after'];
        $log->operator_id = $data['operator_id'] ?? null;
        $log->operator_type = $data['operator_type'] ?? self::OPERATOR_TYPE_SYSTEM;
        $log->remark = $data['remark'] ?? '';
        $log->created_at = time();
        $log->save();

        return $log;
    }
}
