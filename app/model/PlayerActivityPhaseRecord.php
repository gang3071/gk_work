<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerActivityPhaseRecord
 * @property int id 主键
 * @property int activity_id 活动id
 * @property int cate_id 机台分类
 * @property int activity_phase_id 活动阶段id
 * @property int player_activity_record_id 玩家活动参与记录id
 * @property int player_id 玩家id
 * @property int department_id 渠道id
 * @property int machine_id 机台id
 * @property int condition 条件
 * @property int bonus 奖励
 * @property string notice 提示语
 * @property int player_score 玩家分数
 * @property string remark 备注
 * @property string reject_reason 拒绝原因
 * @property int user_id 管理员id
 * @property string user_name 管理员
 * @property int status 1未领取 2已领取(待审核) 3已发放(审核通过) 4已拒绝
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Activity activity 活动
 * @property Player player 玩家
 * @property Machine machine 机台
 * @property ActivityPhase activity_phase 活动阶段
 * @property PlayerActivityRecord player_activity_record 活动参与记录
 * @package app\model
 */
class PlayerActivityPhaseRecord extends Model
{
    use HasDateTimeFormatter;

    const STATUS_UNRECEIVED = 1; // 未领取
    const STATUS_RECEIVED = 2; // 已领取(待审核)
    const STATUS_COMPLETE = 3; // 已发放(审核通过)
    const STATUS_REJECT = 4; // 已拒绝
    protected $dataAuth = ['department_id' => 'department_id'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.player_activity_phase_record_table'));
    }

    /**
     * @return void
     */
    protected static function booted()
    {
        static::updated(function (PlayerActivityPhaseRecord $data) {
            if ($data->wasChanged(['status']) && $data->status == PlayerActivityPhaseRecord::STATUS_COMPLETE) {
                $odds = $data->machine->odds_x . ':' . $data->machine->odds_y;
                if ($data->machine->type == GameType::TYPE_STEEL_BALL) {
                    $odds = $data->machine->machineCategory->name;
                }
                $date = date('Y-m-d');
                /** @var MachineReport $machineReport */
                $machineReport = MachineReport::query()
                    ->where('machine_id', $data->machine_id)
                    ->where('date', $date)
                    ->where('odds', $odds)
                    ->where('department_id', $data->department_id)
                    ->first();
                if (!empty($machineReport)) {
                    $machineReport->activity_amount = bcadd($machineReport->activity_amount, $data->bonus ?? 0);
                } else {
                    $machineReport = new MachineReport();
                    $machineReport->machine_id = $data->machine_id;
                    $machineReport->department_id = $data->department_id;
                    $machineReport->lottery_amount = $data->bonus;
                    $machineReport->date = $date;
                    $machineReport->odds = $odds;
                }
                $machineReport->save();
            }
        });
    }

    /**
     * 活动内容
     * @return belongsTo
     */
    public function activity(): belongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.activity_model'), 'activity_id')->withTrashed();
    }

    /**
     * 玩家
     * @return belongsTo
     */
    public function player(): belongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 活动阶段
     * @return belongsTo
     */
    public function activity_phase(): belongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.activity_phase_model'), 'activity_phase_id');
    }

    /**
     * 玩家活动参与记录
     * @return belongsTo
     */
    public function player_activity_record(): belongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_activity_record_model'),
            'player_activity_record_id');
    }

    /**
     * 机台
     * @return belongsTo
     */
    public function machine(): belongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_model'), 'machine_id');
    }
}
