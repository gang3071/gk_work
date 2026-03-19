<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class MachineRecording
 * @property int id 主键
 * @property int media_id 媒体id
 * @property int department_id 渠道id
 * @property int machine_id 机器id
 * @property string machine_code 机器编号
 * @property string machine_name 机器名称
 * @property int status 状态
 * @property int player_game_record_id 游戏记录id
 * @property int player_game_log_id 上下分记录id
 * @property int type 类型
 * @property int data_id 记录id
 * @property string org_data 媒体服务数据
 * @property string vod_name 录像文件名
 * @property string start_time 开始录制时间
 * @property string end_time 停止录制时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property Channel channel 渠道
 * @property MachineMedia media 视讯流
 * @property Machine machine 机台
 * @property PlayerGameLog player_game_log 上下分记录
 * @property PlayerGameRecord player_game_record 游戏记录
 * @package app\model
 */
class MachineRecording extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    const TYPE_TEST = 1; // 测试
    const TYPE_OPEN = 2; // 开分
    const TYPE_WASH = 3; // 洗分
    const TYPE_REWARD = 4; //开奖

    const STATUS_STARTING = 1; // 录制中
    const STATUS_COMPLETE = 2; // 录制完成
    const STATUS_FAIL = 3; //  录制失败

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];
    protected $table = 'machine_recording';

    /**
     * 机台
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_model'), 'machine_id')->withTrashed();
    }

    /**
     * 视讯流
     * @return BelongsTo
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_media_model'), 'media_id')->withTrashed();
    }

    /**
     * 上下分记录
     * @return BelongsTo
     */
    public function playerGameLog(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_game_log_model'), 'player_game_log_id');
    }

    /**
     * 上下分记录
     * @return BelongsTo
     */
    public function playerGameRecord(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_game_record_model'), 'player_game_record_id');
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
}
