<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class OpenScoreSetting
 * @property int id 主键
 * @property int player_id 店家ID（旧字段，已废弃）
 * @property int admin_user_id 店家AdminUser ID
 * @property int score_1 开分选项1
 * @property int score_2 开分选项2
 * @property int score_3 开分选项3
 * @property int score_4 开分选项4
 * @property int score_5 开分选项5
 * @property int score_6 开分选项6
 * @property int default_scores 默认开分数
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminUser adminUser 店家账号
 * @package app\model
 */
class OpenScoreSetting extends Model
{
    use HasDateTimeFormatter;

    protected $fillable = [
        'player_id',        // 旧字段，保留兼容
        'admin_user_id',    // 新字段
        'score_1',
        'score_2',
        'score_3',
        'score_4',
        'score_5',
        'score_6',
        'default_scores',
    ];

    protected $casts = [
        'player_id' => 'integer',
        'admin_user_id' => 'integer',
        'score_1' => 'integer',
        'score_2' => 'integer',
        'score_3' => 'integer',
        'score_4' => 'integer',
        'score_5' => 'integer',
        'score_6' => 'integer',
        'default_scores' => 'integer',
    ];
    protected $table = 'open_score_setting';

    /**
     * 店家玩家（旧方法，已废弃）
     * @return BelongsTo
     * @deprecated 使用 adminUser() 替代
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id');
    }

    /**
     * 店家账号（新方法）
     * @return BelongsTo
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    /**
     * 获取开分配置数组
     * @return array
     */
    public function getScoresAttribute(): array
    {
        $scores = [];
        for ($i = 1; $i <= 6; $i++) {
            $key = 'score_' . $i;
            if ($this->$key > 0) {
                $scores[] = $this->$key;
            }
        }
        return $scores;
    }
}
