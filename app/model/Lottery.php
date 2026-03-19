<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Lottery
 * @property int id 主键
 * @property int game_type 類型，1=斯洛 2 钢珠
 * @property float rate 金额比例
 * @property int double_status 双倍状态
 * @property float double_amount 双倍开启金额
 * @property string name 彩金名
 * @property int lottery_type 彩金类型
 * @property int condition 条件
 * @property int last_player_id 最近获奖玩家id
 * @property int last_player_name 最近获奖玩家名
 * @property int last_award_amount 最近获奖金额
 * @property int max_amount 最大金额
 * @property int max_status 最大派彩状态
 * @property int lottery_times 发放次数
 * @property int status 状态
 * @property int sort 排序
 * @property float amount 彩金池金额
 * @property float pool_ratio 入池比值
 * @property float win_ratio 中奖概率
 * @property float bet_amount 最低下注金额
 * @property float max_pool_amount 最大彩池金额
 * @property int burst_status 爆彩状态：1启用 0禁用
 * @property int burst_duration 爆彩持续时长（分钟）
 * @property string|array burst_multiplier_config 爆彩倍数配置JSON
 * @property string|array burst_trigger_config 爆彩触发概率配置JSON
 * @property int auto_refill_status 自动补充状态：1启用 0禁用
 * @property float auto_refill_amount 自动补充目标金额(彩池不足时补充到此金额)
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property Player player 玩家信息
 * @package app\model
 */
class Lottery extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    const LOTTERY_TYPE_FIXED = 1; // 固定
    const LOTTERY_TYPE_RANDOM = 2; // 随机

    /**
     * 模型启动方法
     * 注册模型事件监听器，自动清除缓存
     */
    protected static function boot()
    {
        parent::boot();

        // 监听保存事件（包括创建和更新）
        static::saved(function ($lottery) {
            self::clearLotteryCache();
        });

        // 监听删除事件
        static::deleted(function ($lottery) {
            self::clearLotteryCache();
        });

        // 监听恢复事件（软删除恢复）
        static::restored(function ($lottery) {
            self::clearLotteryCache();
        });
    }

    /**
     * 清除彩金相关缓存
     */
    private static function clearLotteryCache(): void
    {
        try {
            // 清除斯洛彩金缓存
            \support\Cache::delete('machine_lottery_pool_1');
            \support\Cache::delete('machine_lottery_list_1');

            // 清除钢珠彩金缓存
            \support\Cache::delete('machine_lottery_pool_2');
            \support\Cache::delete('machine_lottery_list_2');
        } catch (\Exception $e) {
            // 日志记录但不抛出异常，避免影响主流程
            \support\Log::warning('清除机台彩金缓存失败', [
                'error' => $e->getMessage()
            ]);
        }
    }

    protected $table = 'lottery';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'last_player_id')->withTrashed();
    }

    /**
     * 获取爆彩倍数配置（带默认值）
     * @return array
     */
    public function getBurstMultiplierConfig(): array
    {
        $default = [
            'final' => 50,
            'stage_4' => 25,
            'stage_3' => 15,
            'stage_2' => 10,
            'initial' => 5,
        ];

        if (empty($this->burst_multiplier_config)) {
            return $default;
        }

        // 手动处理 JSON 解析
        $config = $this->burst_multiplier_config;

        // 如果是字符串，尝试解析为数组
        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        // 如果解析失败或不是数组，返回默认值
        if (!is_array($config)) {
            return $default;
        }

        // 使用 + 运算符而不是 array_merge，保留字符串键
        return $config + $default;
    }

    /**
     * 获取爆彩触发概率配置（带默认值）
     * @return array
     */
    public function getBurstTriggerConfig(): array
    {
        $default = [
            '95' => 10,
            '90' => 6,
            '85' => 4,
            '80' => 2.5,
            '75' => 1.5,
            '70' => 0.8,
            '65' => 0.4,
            '60' => 0.2,
            '50' => 0.1,
            '40' => 0.05,
            '30' => 0.02,
            '20' => 0.01,
        ];

        if (empty($this->burst_trigger_config)) {
            return $default;
        }

        // 手动处理 JSON 解析
        $config = $this->burst_trigger_config;

        // 如果是字符串，尝试解析为数组
        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        // 如果解析失败或不是数组，返回默认值
        if (!is_array($config)) {
            return $default;
        }

        // 使用 + 运算符而不是 array_merge，保留字符串键
        return $config + $default;
    }

    /**
     * 根据剩余时间百分比获取爆彩倍数
     * @param float $remainingPercent 剩余时间百分比 (0-100)
     * @return float
     */
    public function getBurstMultiplier(float $remainingPercent): float
    {
        $config = $this->getBurstMultiplierConfig();

        if ($remainingPercent <= 10) {
            return $config['final'];
        } elseif ($remainingPercent <= 30) {
            return $config['stage_4'];
        } elseif ($remainingPercent <= 50) {
            return $config['stage_3'];
        } elseif ($remainingPercent <= 70) {
            return $config['stage_2'];
        } else {
            return $config['initial'];
        }
    }

    /**
     * 根据彩池占比获取爆彩触发概率
     * @param float $poolPercent 彩池占比 (0-100)
     * @return float
     */
    public function getBurstTriggerProbability(float $poolPercent): float
    {
        $config = $this->getBurstTriggerConfig();

        if ($poolPercent >= 95) {
            return $config['95'];
        } elseif ($poolPercent >= 90) {
            return $config['90'];
        } elseif ($poolPercent >= 85) {
            return $config['85'];
        } elseif ($poolPercent >= 80) {
            return $config['80'];
        } elseif ($poolPercent >= 75) {
            return $config['75'];
        } elseif ($poolPercent >= 70) {
            return $config['70'];
        } elseif ($poolPercent >= 65) {
            return $config['65'];
        } elseif ($poolPercent >= 60) {
            return $config['60'];
        } elseif ($poolPercent >= 50) {
            return $config['50'];
        } elseif ($poolPercent >= 40) {
            return $config['40'];
        } elseif ($poolPercent >= 30) {
            return $config['30'];
        } elseif ($poolPercent >= 20) {
            return $config['20'];
        } else {
            return 0;
        }
    }

    /**
     * 计算当前彩池占比
     * @return float
     */
    public function getPoolPercentage(): float
    {
        if (empty($this->max_pool_amount) || $this->max_pool_amount <= 0) {
            return 0;
        }

        return ($this->amount / $this->max_pool_amount) * 100;
    }
}
