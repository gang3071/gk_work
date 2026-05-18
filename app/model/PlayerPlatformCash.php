<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerPlatformCash
 * @property int id 主键
 * @property int player_id 玩家id
 * @property string player_account 玩家账户
 * @property int platform_id 平台id
 * @property string platform_name 平台名称
 * @property float money 点数
 * @property int status 遊戲平台狀態 0=鎖定 1=正常
 * @property bool is_crashed 是否爆机 0=正常 1=已爆机
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家
 * @package app\model
 */
class PlayerPlatformCash extends Model
{
    use HasDateTimeFormatter;

    const PLATFORM_SELF = 1; // 实体机平台

    protected $fillable = ['player_id', 'platform_id', 'platform_name', 'money'];
    protected $table = 'player_platform_cash';

    /**
     * 点数（余额访问器）
     *
     * ✅ 整数化改造：自动从 Redis 读取余额，数据库仅作备份
     *
     * 数据流向：
     * - 读取：优先从 Redis 读取（实时标准）
     * - 写入：通过 WalletService::add/deduct 操作 Redis
     * - 同步：WalletService 自动同步 Redis → 数据库
     *
     * @param $value
     * @return float 余额（元）
     */
    public function getMoneyAttribute($value): float
    {
        // ✅ 如果 money 字段有脏数据（刚修改未保存），直接返回当前值
        // 这种情况发生在：代码中先设置 $model->money = xxx，然后访问 $model->money
        if ($this->isDirty('money')) {
            return (float)$this->attributes['money'];
        }

        // ✅ 从 Redis 读取余额（唯一实时标准）
        try {
            return \app\service\WalletService::getBalance($this->player_id, $this->platform_id ?? 1);
        } catch (\Throwable $e) {
            // ✅ Redis 异常时降级到数据库
            \support\Log::warning('PlayerPlatformCash::getMoneyAttribute: Redis 读取失败，降级到数据库', [
                'player_id' => $this->player_id,
                'platform_id' => $this->platform_id,
                'error' => $e->getMessage(),
            ]);

            // 降级：直接查询 player_platform_cash.money（使用原生查询避免访问器循环）
            $balance = \support\Db::table($this->getTable())
                ->where('player_id', $this->player_id)
                ->where('platform_id', $this->platform_id ?? 1)
                ->value('money');

            return $balance !== null ? (float)$balance : 0.0;
        }
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }

    /**
     * 保存模型但不触发事件（用于从 Redis 同步到数据库时避免循环）
     *
     * @param array $options
     * @return bool
     */
    public function saveWithoutEvents(array $options = []): bool
    {
        return static::withoutEvents(function () use ($options) {
            return $this->save($options);
        });
    }
}
