<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int id 主键
 * @property int department_id 渠道id
 * @property int player_id 玩家id
 * @property int type 类型1转入2转出
 * @property float amount_change 变化金额
 * @property int oder_no 订单号
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Channel channel 渠道
 * @property Player player 玩家
 * @package app\model
 */
class ChannelTransferRecord extends Model
{
    use HasDateTimeFormatter;

    const TYPE_IN = 1; //转入
    const TYPE_OUT = 2; //转出
    protected $table = 'channel_transfer_record';

    /**
     * 渠道
     * @return hasMany
     */
    public function Channel(): hasMany
    {
        return $this->hasMany(plugin()->webman->config('database.channel_model'), 'department_id', 'department_id');
    }

    /**
     * 玩家
     * @return hasMany
     */
    public function Player(): hasMany
    {
        return $this->hasMany(plugin()->webman->config('database.player_model'), 'department_id', 'department_id');
    }
}
