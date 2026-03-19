<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerLoginRecord
 * @property int id 主键
 * @property int player_id 推荐id
 * @property int department_id 部门/渠道id
 * @property string login_domain 登錄域名
 * @property string ip ip
 * @property string country_name 國家名稱
 * @property string city_name 地區名稱
 * @property string remark 备注
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @package app\model
 */
class PlayerLoginRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    protected $fillable = [
        'player_id',
        'login_domain',
        'ip',
        'country_name',
        'city_name',
        'remark',
        'department_id',
    ];
    protected $table = 'player_login_record';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }
}
