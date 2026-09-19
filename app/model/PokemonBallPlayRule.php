<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * 精灵球玩法规则
 *
 * @property int id 主键
 * @property int machine_id 机台ID
 * @property int play_type 玩法类型 1=一球多灯 2=三球 3=三关
 * @property int light_count 灯数
 * @property float multiplier 倍率
 * @property int sort 排序
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @package app\model
 */
class PokemonBallPlayRule extends Model
{
    use HasDateTimeFormatter;

    const PLAY_TYPE_ONE_BALL_MULTI_LIGHT = 1; // 一球多灯
    const PLAY_TYPE_THREE_BALL = 2;           // 三球
    const PLAY_TYPE_THREE_LEVEL = 3;          // 三关

    protected $table = 'pokemon_ball_play_rule';

    /**
     * 根据机台ID获取玩法规则列表
     *
     * @param int $machineId
     * @return Collection
     */
    public static function getByMachineId(int $machineId): Collection
    {
        return static::query()
            ->where('machine_id', $machineId)
            ->orderBy('sort')
            ->get();
    }

    /**
     * 根据游戏结果匹配规则，返回倍率
     *
     * @param int $machineId 机台ID
     * @param array $gameResult 游戏结果 [is_winner, level, hole9_full, light3_full]
     * @return float 匹配的倍率，未匹配返回 0
     */
    public static function matchMultiplier(int $machineId, array $gameResult): float
    {
        $rules = static::getByMachineId($machineId);
        return static::matchMultiplierFromRules($rules, $gameResult);
    }

    /**
     * 根据游戏结果和规则集合匹配倍率（纯函数，不依赖 DB）
     *
     * @param \Illuminate\Support\Collection $rules 规则集合
     * @param array $gameResult 游戏结果 [is_winner, level, hole9_full(预留), light3_full]
     * @return float 匹配的倍率，未匹配返回 0
     */
    public static function matchMultiplierFromRules(SupportCollection $rules, array $gameResult): float
    {
        if (empty($gameResult['is_winner'])) {
            return 0;
        }

        if ($rules->isEmpty()) {
            return 0;
        }

        // 优先匹配：三关 > 三球 > 一球多灯（play_type 降序）
        // 同类型内按 light_count 降序匹配（灯数越多倍率越高）

        // 三关：light3_full = true → play_type = 3
        if (!empty($gameResult['light3_full'])) {
            $threeLevelRule = $rules->where('play_type', self::PLAY_TYPE_THREE_LEVEL)
                ->sortByDesc('light_count')
                ->first();
            if ($threeLevelRule) {
                return (float)$threeLevelRule->multiplier;
            }
        }

        // 三球：level >= 3（打到第三关） → play_type = 2
        if (($gameResult['level'] ?? 0) >= 3) {
            $threeBallRule = $rules->where('play_type', self::PLAY_TYPE_THREE_BALL)
                ->sortByDesc('light_count')
                ->first();
            if ($threeBallRule) {
                return (float)$threeBallRule->multiplier;
            }
        }

        // 一球多灯：is_winner = true → play_type = 1
        $oneBallRule = $rules->where('play_type', self::PLAY_TYPE_ONE_BALL_MULTI_LIGHT)
            ->sortByDesc('light_count')
            ->first();
        if ($oneBallRule) {
            return (float)$oneBallRule->multiplier;
        }

        return 0;
    }
}
