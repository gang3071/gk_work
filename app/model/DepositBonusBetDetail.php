<?php

namespace app\model;

use support\Model;

/**
 * 押码量明细模型
 * @property int $id 明细ID
 * @property int $order_id 赠送订单ID
 * @property int $player_id 玩家ID
 * @property int $store_id 店家ID
 * @property int $agent_id 代理ID
 * @property string $game_type 游戏类型：slot,electron,baccarat
 * @property string $game_platform 游戏平台
 * @property string $game_id 游戏ID
 * @property string $game_name 游戏名称
 * @property float $bet_amount 押注金额
 * @property float $win_amount 赢取金额
 * @property float $valid_bet_amount 有效押注金额
 * @property float $balance_before 押注前余额
 * @property float $balance_after 押注后余额
 * @property float $accumulated_bet 累计押码量（本次之前）
 * @property float $new_accumulated_bet 累计押码量（本次之后）
 * @property int $bet_time 押注时间
 * @property int $settle_time 结算时间
 * @property int $created_at 创建时间
 */
class DepositBonusBetDetail extends Model
{
    protected $table = 'deposit_bonus_bet_detail';
    protected $pk = 'id';

    // 游戏类型常量
    const GAME_TYPE_SLOT = 'slot';       // 老虎机（实体机台）
    const GAME_TYPE_ELECTRON = 'electron'; // 电子游戏
    const GAME_TYPE_BACCARAT = 'baccarat'; // 真人百家
    const GAME_TYPE_LOTTERY = 'lottery';   // 彩票

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(DepositBonusOrder::class, 'order_id', 'id');
    }

    /**
     * 关联玩家
     */
    public function player()
    {
        return $this->belongsTo(\app\model\Player::class, 'player_id', 'id');
    }

    /**
     * 检查游戏类型是否允许
     */
    public static function isGameTypeAllowed(string $gameType): bool
    {
        // 根据活动配置，实体机台可能不允许使用赠送余额
        $allowedTypes = [
            self::GAME_TYPE_ELECTRON,
            self::GAME_TYPE_BACCARAT,
            self::GAME_TYPE_LOTTERY,
        ];

        return in_array($gameType, $allowedTypes);
    }
}
