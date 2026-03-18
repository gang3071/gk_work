<?php

namespace app\model\mongo;


use Jenssegers\Mongodb\Eloquent\Model;

/**
 * Class LotteryPoolAddLog
 * @property int id 主键
 * @property int player_id 玩家id
 * @property string uuid 玩家uuid
 * @property int department_id 渠道id
 * @property int machine_id 机台id
 * @property string machine_name 机台名
 * @property int machine_type 机台类型
 * @property string machine_code 机台code
 * @property int new_num 机台分数/转数最新
 * @property int last_num 机台分数/转数上次
 * @property int lottery_point 累计单位金额
 * @property int num 点数/转数
 * @property float add_amount 汇入彩金池金额
 * @property float lottery_amount 当前彩金池金额
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @package app\model\mongo
 */
class LotteryPoolAddLog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'lottery_pool_add_log';
    protected $primaryKey = '_id';
    protected $fillable = [
        'uuid',
        'player_id',
        'department_id',
        'machine_id',
        'machine_name',
        'machine_type',
        'machine_code',
        'new_num',
        'last_num',
        'lottery_point',
        'num',
        'add_amount',
        'lottery_amount'
    ];
}