<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class MachineCategory
 * @property int id 主键
 * @property int game_id 类型
 * @property string name 名称
 * @property string picture_url 图片地址
 * @property float keep_minutes 每轉/壓保留幾秒
 * @property float lottery_point 每转/压转彩金池点数
 * @property float lottery_rate 固定门槛派彩系数
 * @property float turn_used_point 每转消耗游戏点数
 * @property int lottery_add_status 彩金累加状态，0=停用, 1=啟用
 * @property int lottery_assign_status 彩金分配状态，0=停用, 1=啟用
 * @property int status 状态
 * @property int sort 排序
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property MachineCategoryGiveRule $machineCategoryGiveRule
 * @property GameType $gameType
 * @property MachineCategoryExtend $machineCategoryExtend
 * @package app\model
 */
class MachineCategory extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    protected $table = 'machine_category';

    /**
     * 游戏类别
     * @return BelongsTo
     */
    public function gameType(): BelongsTo
    {
        return $this->belongsTo(GameType::class, 'game_id')->withTrashed();
    }

    /**
     * 开分赠送规则
     * @return hasMany
     */
    public function machineCategoryGiveRule(): hasMany
    {
        return $this->hasMany(MachineCategoryGiveRule::class, 'machine_category_id');
    }

    /**
     * 分类扩展信息
     * @return hasMany
     */
    public function machineCategoryExtend(): hasMany
    {
        return $this->hasMany(MachineCategoryExtend::class, 'cate_id');
    }
}
