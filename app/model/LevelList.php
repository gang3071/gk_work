<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class LevelList
 * @property int id 主键
 * @property int sort 等级权重
 * @property int level 子等级
 * @property int level_id 子等级
 * @property string name 等级名称
 * @property float must_chip_amount 打码量
 * @property float damage_rebate_ratio 客损返佣比例
 * @property float recharge_ratio 首冲返佣比例
 * @property float reverse_water 等级反水比例
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property NationalLevel national_level 全民代理等级分类
 * @package app\model
 */
class LevelList extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = [
        'department_id',
        'level',
        'level_id',
        'must_chip_amount'
    ];
    protected $table = 'level_list';

    /**
     * 全民代理等级分类
     * @return BelongsTo
     */
    public function national_level(): BelongsTo
    {
        return $this->belongsTo(NationalLevel::class, 'level_id');
    }
}
