<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 玩家洗分记录
 * Class PlayerWashRecod
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int recommend_id 推荐id
 * @property int department_id 部门/渠道id
 * @property int machine_id 机台id
 * @property int game_record_id 游戏记录id
 * @property int status 状态
 * @property int machine_score 机台得分
 * @property double odds_x 比值x
 * @property double odds_y 比值y
 * @property int control_open_point 工控压分
 * @property string machine_info 机台信息
 * @property string machine_image 识别图片
 * @property int user_id 管理员图片
 * @property string user_name 管理员名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player $player
 * @property Machine $machine
 * @property Channel $channel
 * @property PlayerGameRecord $playerGameRecord
 * @package app\model
 */
class PlayerWashRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.player_wash_record_table'));
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.channel_model'), 'department_id', 'department_id')->withTrashed();
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
     * 机台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'machine_id')->withTrashed();
    }

    /**
     * 机台信息
     * @return BelongsTo
     */
    public function playerGameRecord(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_game_record_model'), 'game_record_id');
    }
}
