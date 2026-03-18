<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class BankContent
 * @property int id 主键
 * @property int bank_id 银行ID
 * @property string name 头像名称
 * @property string lang 语言标识
 * @property string pic 游戏图片
 * @property string created_at 创建时间
 * @property string updated_at 修改时间
 *
 * @property bank bank 银行
 * @package app\model
 */
class BankContent extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = [
        'id',
        'bank_id',
        'name',
        'lang',
        'pic',
        'created_at',
        'updated_at',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.bank_content_table'));
    }

    /**
     * 银行
     * @return BelongsTo
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.bank_model'), 'bank_id', 'id');
    }
}
