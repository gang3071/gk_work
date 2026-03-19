<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerRegisterRecord
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int department_id 部门/渠道id
 * @property string register_domain 登錄域名
 * @property string ip ip
 * @property string country_name 国家
 * @property string city_name 地区
 * @property int type 类型
 * @property string remark 备注
 * @property string device 使用設備
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @package app\model
 */
class PlayerRegisterRecord extends Model
{
    use HasDateTimeFormatter;

    const TYPE_ADMIN = 1; // 管理后台
    const TYPE_CLIENT = 2; // 客户端
    const TYPE_TALK = 3; // QTalk

    protected $fillable = [
        'player_id',
        'register_domain',
        'ip',
        'country_name',
        'city_name',
        'type',
        'device',
        'department_id',
    ];
    protected $table = 'player_register_record';

    /**
     * 玩家信息
     * @return belongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }
}
