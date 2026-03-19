<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerActivityRecord
 * @property int id 主键
 * @property int activity_id 活动id
 * @property int cate_id 机台分类
 * @property int machine_id 机台id
 * @property int player_id 玩家id
 * @property int department_id 渠道id
 * @property int type 机台类型
 * @property string code 机台code
 * @property int score 达成分数
 * @property int bonus 奖励
 * @property int status 1进行中 2已结束
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string finish_at 完成时间
 *
 * @property Activity activity 活动
 * @property Machine machine 机台
 * @property Player player 玩家
 * @package app\model
 */
class PlayerActivityRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const STATUS_BEGIN = 1; // 进行中
    const STATUS_FINISH = 2; // 已结束
    protected $table = 'player_activity_record';

    /**
     * 活动内容
     * @return belongsTo
     */
    public function activity(): belongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id')->withTrashed();
    }

    /**
     * 机台
     * @return belongsTo
     */
    public function machine(): belongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id')->withTrashed();
    }

    /**
     * 玩家
     * @return belongsTo
     */
    public function player(): belongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }
}
