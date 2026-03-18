<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Class NationalProfitRecord
 * @property int id 主键
 * @property int uid 玩家id
 * @property int recommend_id 上级id
 * @property float money 金额
 * @property int type 类型
 * @property int status 状态
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @package app\model
 */
class NationalProfitRecord extends Model
{
    use HasDateTimeFormatter;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.national_profit_record_table'));
    }

    /**
     * 玩家信息
     * @return hasOne
     */
    public function player(): hasOne
    {
        return $this->hasOne(plugin()->webman->config('database.player_model'), 'id', 'uid')->withTrashed();
    }

}
