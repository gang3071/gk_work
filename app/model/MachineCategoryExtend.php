<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class MachineCategoryExtend
 * @property int id 主键
 * @property string name 名称
 * @property string lang 语言标识
 * @property int cate_id 分类id
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property MachineCategory $machineCategory
 * @package app\model
 */
class MachineCategoryExtend extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = [
        'id',
        'cate_id',
        'name',
        'lang',
        'created_at',
        'updated_at',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.machine_category_extend_table'));
    }

    /**
     * 游戏类别
     * @return BelongsTo
     */
    public function machineCategory(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_category_model'), 'cate_id')->withTrashed();
    }
}
