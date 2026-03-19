<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Class playerGameLog
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int agent_player_id 代理玩家id
 * @property int parent_player_id 上级玩家id
 * @property int department_id 部门/渠道id
 * @property int game_id 游戏id
 * @property int machine_id 机台id
 * @property int game_record_id 游戏记录id
 * @property int type 类型
 * @property string odds 比值
 * @property string action 操作
 * @property float open_point 上分分數
 * @property float wash_point 下分分數
 * @property float gift_point 外贈分數
 * @property float before_game_amount 异动前余额
 * @property float game_amount 異動餘額
 * @property float after_game_amount 遊戲錢包餘額異動後
 * @property float machine_amount 機台分數
 * @property int control_open_point 工控壓分
 * @property float open 開分
 * @property float wash 洗分
 * @property float pressure 壓分
 * @property float score 得分
 * @property float chip_amount 打码量
 * @property int turn_point 转数
 * @property float turn_used_point 每转消耗游戏点数
 * @property float winlose 輸贏
 * @property int user_id 管理员id
 * @property string user_name 管理员名称
 * @property int is_system 系統踢除 0=否 1=是
 * @property string deleted_at 删除时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property int is_test 是否为测试数据 0否 1是
 *
 * @property Player player 玩家信息
 * @property MachineRecording machine_recording 录制记录
 * @property Machine machine 机台信息
 * @package app\model
 */
class PlayerGameLog extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const ACTION_OPEN = 1; // 开分
    const ACTION_LEAVE = 2; // 弃台
    const ACTION_DOWN = 3; // 下分

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    protected $table = 'player_game_log';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 录制记录
     * @return hasOne
     */
    public function machine_recording(): hasOne
    {
        return $this->hasOne(plugin()->webman->config('database.machine_recording_model'),
            'player_game_log_id')->withTrashed();
    }

    /**
     * 机台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_model'), 'machine_id')->withTrashed();
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function (PlayerGameLog $playerGameLog) {
            $date = date('Y-m-d');
            /** @var MachineReport $machineReport */
            $machineReport = MachineReport::where('machine_id', $playerGameLog->machine_id)
                ->where('date', $date)
                ->where('department_id', $playerGameLog->department_id)
                ->where('odds', $playerGameLog->odds)
                ->first();
            $openAmount = $playerGameLog->game_amount < 0 ? abs($playerGameLog->game_amount) : 0;
            $washAmount = $playerGameLog->game_amount > 0 ? abs($playerGameLog->game_amount) : 0;
            $giveAmount = $playerGameLog->gift_point > 0 ? abs($playerGameLog->gift_point) : 0;
            if (!empty($machineReport)) {
                $machineReport->open_amount = bcadd($machineReport->open_amount, $openAmount, 2);
                $machineReport->wash_amount = bcadd($machineReport->wash_amount, $washAmount, 2);
                $machineReport->open_point = bcadd($machineReport->open_point, $playerGameLog->open_point, 2);
                $machineReport->wash_point = bcadd($machineReport->wash_point, $playerGameLog->wash_point, 2);
                $machineReport->total_amount = bcadd($machineReport->total_amount, bcsub($washAmount, $openAmount, 2), 2);
                $machineReport->total_point = bcadd($machineReport->total_point, bcsub($playerGameLog->open_point, $playerGameLog->wash_point, 2), 2);
                $machineReport->give_amount = bcadd($machineReport->give_amount, $giveAmount, 2);
                $machineReport->pressure = bcadd($machineReport->pressure, $playerGameLog->pressure ?? 0, 2);
                $machineReport->score = bcadd($machineReport->score, $playerGameLog->score ?? 0, 2);
                $machineReport->turn_point = bcadd($machineReport->turn_point, $playerGameLog->turn_point ?? 0);
                $machineReport->is_test = $playerGameLog->is_test ?? 0; //标记测试数据
            } else {
                $machineReport = new MachineReport();
                $machineReport->machine_id = $playerGameLog->machine_id;
                $machineReport->department_id = $playerGameLog->department_id;
                $machineReport->open_amount = $openAmount;
                $machineReport->wash_amount = $washAmount;
                $machineReport->open_point = $playerGameLog->open_point;
                $machineReport->wash_point = $playerGameLog->wash_point;
                $machineReport->total_amount = bcsub($washAmount, $openAmount, 2); //玩家盈亏 = 玩家下分 - 玩家上分
                $machineReport->total_point = bcsub($playerGameLog->open_point, $playerGameLog->wash_point, 2); //机台盈亏 = 机台上分 - 玩家下分
                $machineReport->give_amount = $giveAmount;
                $machineReport->pressure = $playerGameLog->pressure ?? 0;
                $machineReport->score = $playerGameLog->score ?? 0;
                $machineReport->turn_point = $playerGameLog->turn_point ?? 0;
                $machineReport->is_test = $playerGameLog->is_test ?? 0; //标记测试数据
                $machineReport->date = $date;
                $machineReport->odds = $playerGameLog->odds;
            }
            $machineReport->save();
        });
    }

    /**
     * 上分分數
     *
     * @param $value
     * @return float
     */
    public function getOpenPointAttribute($value): float
    {
        return $value ? floatval($value) : 0;
    }

    /**
     * 下分分數
     *
     * @param $value
     * @return float
     */
    public function getWashPointAttribute($value): float
    {
        return $value ? floatval($value) : 0;
    }

    /**
     * 遊戲錢包餘額異動前
     *
     * @param $value
     * @return float
     */
    public function getBeforeGameAmountAttribute($value): float
    {
        return $value ? floatval($value) : 0;
    }

    /**
     * 金额
     *
     * @param $value
     * @return float
     */
    public function getGameAmountAttribute($value): float
    {
        return $value ? floatval($value) : 0;
    }

    /**
     * 遊戲錢包餘額異動後
     *
     * @param $value
     * @return float
     */
    public function getAfterGameAmountAttribute($value): float
    {
        return $value ? floatval($value) : 0;
    }

    /**
     * 開分
     *
     * @param $value
     * @return float
     */
    public function getOpenAttribute($value): float
    {
        return $value ? floatval($value) : 0;
    }

    /**
     * 洗分
     *
     * @param $value
     * @return float
     */
    public function getWashAttribute($value)
    {
        return $value ? floatval($value) : 0;
    }

    /**
     * 得分
     *
     * @param $value
     * @return float
     */
    public function getScoreAttribute($value)
    {
        return $value ? floatval($value) : 0;
    }
}
