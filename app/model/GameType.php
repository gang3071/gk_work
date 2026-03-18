<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class GameType
 * @property int id 主键
 * @property int type 实体机类型
 * @property string name 名称
 * @property string cate 游戏分类
 * @property string picture_url 图片地址
 * @property int status 状态
 * @property int sort 排序
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property LotteryPool $lotteryPool
 * @package app\model
 */
class GameType extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    const TYPE_SLOT = 1; // 斯洛
    const TYPE_STEEL_BALL = 2; // 钢珠
    const TYPE_FISH = 3; // 鱼机
    const TYPE_GAME = 9; // 电子游戏

    const CATE_PHYSICAL_MACHINE = 1; // 实体机台
    const CATE_COMPUTER_GAME = 2; // 电子游戏
    const CATE_LIVE_VIDEO = 3; // 真人视讯
    const CATE_TABLE = 5; // 牌桌
    const CATE_P2P = 6; // 棋牌
    const CATE_FISH = 4; // 捕鱼
    const CATE_SLO = 7; // 老虎機
    const CATE_ARCADE = 8; // 街機
    const CATE_SPORT = 9; // 体育
    const CATE_LOTTERY = 10; // 彩票

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.game_type_table'));
    }

    /**
     * 玩家信息
     * @return HasOne
     */
    public function lotteryPool(): HasOne
    {
        return $this->HasOne(plugin()->webman->config('database.lottery_pool_model'), 'type', 'type');
    }
}
