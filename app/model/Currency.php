<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Currency
 * @property int id 主键
 * @property string name 货币名称
 * @property string identifying 货币标识
 * @property float ratio 1点数-货币
 * @property int status 状态
 * @property int admin_id 管理员id
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @package app\model
 */
class Currency extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    protected $table = 'currency';

    /**
     * 游戏类别
     * @return BelongsTo
     */
    public function admin_user(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.user_model'), 'admin_id');
    }

    /**
     * 比值
     *
     * @param $value
     * @return float
     */
    public function getRatioAttribute($value): float
    {
        return floatval($value);
    }
}
