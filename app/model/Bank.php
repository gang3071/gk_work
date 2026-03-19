<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int id 主键
 * @property int department_id 渠道id
 * @property int status 状态
 * @property int creator_id 创建人ID
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property BankContent bankContent 银行内容
 * @package app\model
 */
class Bank extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'bank_list';

    /**
     * 银行内容
     * @return hasMany
     */
    public function BankContent(): hasMany
    {
        return $this->hasMany(plugin()->webman->config('database.bank_content_model'), 'bank_id', 'id');
    }
}
