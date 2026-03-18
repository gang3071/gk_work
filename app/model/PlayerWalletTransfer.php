<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerWalletTransfer
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int parent_player_id 上级玩家id
 * @property int agent_player_id 代理玩家id
 * @property int platform_id 平台id
 * @property int department_id 渠道id
 * @property int type 类型 1转出 2转入
 * @property int amount 金额
 * @property int game_amount 游戏平台账户余额
 * @property int player_amount 玩家账户余额
 * @property int platform_no 平台单号
 * @property int tradeno 单号
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Channel channel 渠道
 * @property Player player 玩家
 * @property GamePlatform gamePlatform 平台信息
 * @package app\model
 */
class PlayerWalletTransfer extends Model
{
    use HasDateTimeFormatter;

    protected $dataAuth = ['department_id' => 'department_id'];
    const TYPE_OUT = 1; // 转出
    const TYPE_IN = 2; // 转入

    //数据权限字段
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.player_wallet_transfer_table'));
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.channel_model'), 'department_id',
            'department_id')->withTrashed();
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 平台信息
     * @return BelongsTo
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.game_platform_model'), 'platform_id')->withTrashed();
    }
}
