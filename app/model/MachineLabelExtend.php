<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class MachineLabelExtend
 * @property int id 主键
 * @property string name 名称
 * @property string lang 语言标识
 * @property int label_id 分类id
 * @property string picture_url 图片
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property MachineLabel $machineLabel
 * @package app\model
 */
class MachineLabelExtend extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = [
        'id',
        'label_id',
        'name',
        'picture_url',
        'lang',
        'created_at',
        'updated_at',
    ];
    protected $table = 'machine_label_extend';

    /**
     * 游戏类别
     * @return BelongsTo
     */
    public function machineLabel(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_label_model'), 'label_id')->withTrashed();
    }
}
