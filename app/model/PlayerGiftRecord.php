<?php

namespace app\model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;


/**
 * Class PlayersGiftRecord
 * @property int id 主键
 * @property int player_game_log_id player_game_log表主键id
 * @property int machine_category_give_rule_id machine_category_give_rule表主键id
 * @property int machine_id machine表id
 * @property int player_id 玩家id
 * @property string player_name 玩家名字
 * @property string machine_name 机台名称
 * @property int machine_type 机台类别 1=斯洛 2 钢珠
 * @property int open_num 开分点数
 * @property int give_num 赠送分数
 * @property float condition 满足完成条件
 * @property string created_at 删除时间
 * @property string updated_at 创建时间
 * @package app\model
 */
class PlayerGiftRecord extends Model
{

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.player_gift_record_table'));
    }
}