<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * Class MachineGamingLog
 * @property int id 主键
 * @property int machine_id 机台id
 * @property int type 类型
 * @property string date 日期
 * @property int turn_point 转数
 * @property int seventh_turn_point 7天转数
 * @property int thirty_turn_point 30天转数
 * @property int pressure 压分
 * @property int seventh_pressure 7天压分
 * @property int thirty_pressure 30天压分
 * @property int score 得分
 * @property int seventh_score 7天得分
 * @property int thirty_score 30天压分
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @package app\model
 */
class MachineGamingLog extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = ['machine_id', 'type', 'date', 'turn_point', 'pressure', 'score', 'seventh_turn_point', 'seventh_pressure', 'seventh_score', 'thirty_turn_point', 'thirty_pressure', 'thirty_score'];
    protected $table = 'machine_gaming_log';
}