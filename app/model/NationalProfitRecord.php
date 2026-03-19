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

    protected $table = 'national_profit_record';

    /**
     * 玩家信息
     * @return hasOne
     */
    public function player(): hasOne
    {
        return $this->hasOne(Player::class, 'id', 'uid')->withTrashed();
    }

}
