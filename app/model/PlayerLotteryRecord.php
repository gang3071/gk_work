<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerLotteryRecord
 * @property int id 主键
 * @property int player_id 玩家id
 * @property string uuid 玩家uuid
 * @property string player_phone 玩家手机号
 * @property string player_name 玩家昵称
 * @property int department_id 渠道id
 * @property int machine_id 机台id
 * @property string machine_name 机台名
 * @property string machine_code 机台code
 * @property int game_type 机台类型
 * @property int source 来源
 * @property int play_game_record_id 电子游戏记录id
 * @property int player_game_record_id 机台游戏记录id
 * @property string odds 比值
 * @property float amount 派彩
 * @property float bet 押注
 * @property int is_max 是否最高
 * @property int lottery_id 彩金id
 * @property string lottery_name 彩金名
 * @property float lottery_pool_amount 彩金池金额
 * @property float lottery_rate 金额比例
 * @property int lottery_type 彩金类型
 * @property int lottery_multiple 彩金倍数
 * @property int lottery_sort 排序
 * @property float cate_rate 派彩系数
 * @property int user_id 管理员id
 * @property int user_name 管理员名称
 * @property string reject_reason 拒绝原因
 * @property int status 状态
 * @property int is_promoter 是否推广员
 * @property int is_test 是否测试账户
 * @property int is_coin 是否币商
 * @property string audit_at 审核时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property Player player 玩家信息
 * @property Machine machine 机台信息
 * @property Lottery lottery 彩金信息
 * @property Channel channel 渠道信息
 * @property PlayGameRecord play_game_record 电子游戏记录id
 * @package app\model
 */
class PlayerLotteryRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    const STATUS_UNREVIEWED = 0;
    const STATUS_REJECT = 1; // 未审核
    const STATUS_PASS = 2; // 未通过
    const STATUS_COMPLETE = 3; // 通过

    const SOURCE_MACHINE = 1;// 实体机台
    const SOURCE_GAME = 2;// 电子游戏
    const SOURCE_MANUAL = 3;// 手动发放
    protected $dataAuth = ['department_id' => 'department_id']; // 已完成
    protected $table = 'player_lottery_record';

    /**
     * @return void
     */
    protected static function booted()
    {
        static::updated(function (PlayerLotteryRecord $data) {
            if ($data->wasChanged(['status']) && $data->status == PlayerLotteryRecord::STATUS_COMPLETE && $data->source == self::SOURCE_MACHINE) {
                $date = date('Y-m-d');
                /** @var MachineReport $machineReport */
                $machineReport = MachineReport::query()
                    ->where('machine_id', $data->machine_id)
                    ->where('date', $date)
                    ->where('department_id', $data->department_id)
                    ->where('odds', $data->odds)
                    ->first();
                if (!empty($machineReport)) {
                    $machineReport->lottery_amount = bcadd($machineReport->lottery_amount, $data->amount ?? 0);
                } else {
                    $machineReport = new MachineReport();
                    $machineReport->machine_id = $data->machine_id;
                    $machineReport->department_id = $data->department_id;
                    $machineReport->lottery_amount = $data->amount;
                    $machineReport->date = $date;
                    $machineReport->odds = $data->odds;
                }
                $machineReport->save();
            }
        });
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
     * 电子游戏记录
     * @return BelongsTo
     */
    public function play_game_record(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.play_game_record_model'), 'play_game_record_id');
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
     * 彩金信息
     * @return BelongsTo
     */
    public function lottery(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.lottery_model'), 'lottery_id')->withTrashed();
    }

    /**
     * 派彩金额
     *
     * @param $value
     * @return float
     */
    public function getAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 金额比例
     *
     * @param $value
     * @return float
     */
    public function getLotteryRateAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 派彩系数
     *
     * @param $value
     * @return float
     */
    public function getCateRateAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 派彩系数
     *
     * @param $value
     * @return float
     */
    public function getLotteryPoolAmountAttribute($value): float
    {
        return floatval($value);
    }
}