<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class MachineReport
 * @property int id 主键
 * @property int machine_id 机台id
 * @property int department_id 部门/渠道id
 * @property int type 类型
 * @property string odds 比值
 * @property float open_point 机台总上分数
 * @property float wash_point 机台总下分数
 * @property float open_amount 玩家总上点数
 * @property float wash_amount 玩家总下点数
 * @property float total_amount 总金额
 * @property float total_point 机台总分
 * @property float give_amount 开分赠点：赠送
 * @property float lottery_amount 彩金点数
 * @property float activity_amount 彩金点数
 * @property float pressure 壓分
 * @property float score 得分
 * @property int turn_point 转数
 * @property string date 日期
 * @property string created_at 创建时间
 * @property string deleted_at 删除时间
 * @property string updated_at 更新时间
 * @property int is_test 是否为测试数据 0否 1是
 *
 * @property Machine $machine 机台信息
 * @package app\model
 */
class MachineReport extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    protected $fillable = [
        'machine_id',
        'department_id',
        'open_point',
        'wash_point',
        'total_amount',
        'odds',
        'date',
    ];

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
        $this->setTable(plugin()->webman->config('database.machine_report_table'));
    }

    /**
     * 机台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_model'), 'machine_id')->withTrashed();
    }

    public function playerGameLogs()
    {
        return $this->hasMany(PlayerGameLog::class, 'machine_id', 'machine_id');
    }
}
