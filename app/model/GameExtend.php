<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class GameExtend
 * @property int id 主键
 * @property int platform_id 游戏平台id
 * @property string logo 游戏编号
 * @property int cate_id 分类
 * @property string code 游戏编号
 * @property string game_id 游戏id
 * @property string table_name 名称
 * @property string name 名称
 * @property string org_data 原始数据
 * @property int status 状态
 * @property int is_new 是否新游戏(0:否,1:是)
 * @property int is_hot 是否热门游戏(0:否,1:是)
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminUser adminUser 管理员
 * @property Channel channel 渠道
 * @property Player player 玩家
 * @package app\model
 */
class GameExtend extends Model
{
    use HasDateTimeFormatter;

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    protected $table = 'game_extend';

    /**
     * 游戏平台
     * @return BelongsTo
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(GamePlatform::class,
            'platform_id')->withTrashed();
    }
}
