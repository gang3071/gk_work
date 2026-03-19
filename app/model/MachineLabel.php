<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class MachineLabel
 * @property int id 主键
 * @property string name 名称
 * @property int type 类型
 * @property string picture_url 图片
 * @property int status 状态
 * @property int point 游戏点
 * @property int turn 转数
 * @property int score 分数
 * @property string courtyard 天井
 * @property string correct_rate 确率
 * @property int sort 排序
 * @property string created_at 创建时间
 * @property string updated_at 更新时间
 *
 * @property GameType $gameType
 * @property MachineLabelExtend $machineLabelExtend
 * @package app\model
 */
class MachineLabel extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'machine_label';


    /**
     * 分类扩展信息
     * @return hasMany
     */
    public function machineLabelExtend(): hasMany
    {
        return $this->hasMany(MachineLabelExtend::class, 'label_id');
    }
}
