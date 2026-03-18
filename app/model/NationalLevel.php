<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class NationalLevel
 * @property int id 主键
 * @property int sort 等级权重
 * @property int level 等级
 * @property string name 等级名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @package app\model
 *
 * @property LevelList $level_list
 */
class NationalLevel extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = [
        'department_id',
        'level',
        'status',
        'sort',
        'name',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.national_level_table'));
    }

    /**
     * 等级信息
     * @return HasMany
     */
    public function level_list(): HasMany
    {
        return $this->HasMany(LevelList::class, 'level_id');
    }
}
