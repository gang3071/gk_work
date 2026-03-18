<?php

namespace app\model\mongo;

use app\model\AdminUser;
use app\model\Channel;
use app\model\Machine;
use app\model\Player;
use app\traits\HasDateTimeFormatter;
use Jenssegers\Mongodb\Eloquent\Model;

/**
 * Class MachineOperationLog
 * @property int id 主键
 * @property int department_id 部门/渠道id
 * @property int machine_id 机台id
 * @property string machine_name 机台名
 * @property int machine_type 机台类型
 * @property string machine_code 机台编号
 * @property string uuid 玩家uuid
 * @property int player_id 玩家id
 * @property string player_phone 玩家手机
 * @property string player_name 玩家名
 * @property int status 状态
 * @property int is_system 是否系统任务
 * @property string content 操作內容
 * @property string action 功能
 * @property int user_id 管理员id
 * @property string remark 备注
 * @property string user_name 管理员名称
 * @property int point 点数
 * @property int machine_cate 机器类别
 * @property int producer_id 机器厂商
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player $player
 * @property Machine $machine
 * @property AdminUser $user
 * @property Channel $channel 部门/渠道
 * @package app\model\mongo
 */
class MachineOperationLog extends Model
{
    use HasDateTimeFormatter;

    protected $connection = 'mongodb';
    protected $collection = 'machine_operation_log';
    protected $primaryKey = '_id';
    protected $fillable = [
        'id',
        'department_id',
        'producer_id',
        'machine_id',
        'machine_name',
        'machine_type',
        'machine_cate',
        'machine_code',
        'uuid',
        'player_id',
        'player_phone',
        'player_name',
        'status',
        'is_system',
        'content',
        'action',
        'user_id',
        'user_name',
        'point',
        'remark'
    ];
}
