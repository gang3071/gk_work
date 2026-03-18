<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ChannelGameWeb
 * @property int id 主键
 * @property int platform_id 游戏平台id
 * @property int channel_id 渠道id
 * @property string web_id 站点id
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property LotteryPool $lotteryPool
 * @package app\model
 */
class ChannelGameWeb extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'channel_game_web';
}
