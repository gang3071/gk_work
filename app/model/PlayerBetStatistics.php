<?php

namespace app\model;

use support\Model;

/**
 * 玩家打码量统计模型
 *
 * @property int $id ID
 * @property int $player_id 玩家ID
 * @property string $stat_type 统计类型：machine=实体机台, game=电子游戏
 * @property string $dimension 维度：daily=日, weekly=周, monthly=月
 * @property string $stat_date 统计日期：2026-07-31, 2026-W31, 2026-07
 * @property float $bet_amount 打码量（元）
 * @property int $bet_count 投注次数
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class PlayerBetStatistics extends Model
{
    /**
     * 表名
     * @var string
     */
    protected $table = 'player_bet_statistics';

    /**
     * 可批量赋值字段
     * @var array
     */
    protected $fillable = [
        'player_id',
        'stat_type',
        'dimension',
        'stat_date',
        'bet_amount',
        'bet_count',
    ];

    /**
     * 字段类型转换
     * @var array
     */
    protected $casts = [
        'player_id' => 'int',
        'bet_amount' => 'float',
        'bet_count' => 'int',
    ];

    /**
     * 启用时间戳
     * @var bool
     */
    public $timestamps = true;

    /**
     * 关联玩家
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id', 'id');
    }
}
