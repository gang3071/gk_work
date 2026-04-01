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
     * 点数
     *
     * @param $value
     * @return float
     */
    public function getMoneyAttribute($value): float
    {
        return floatval($value);
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
     * 模型的 "booted" 方法
     * 监听余额变化，自动检查爆机状态并同步 Redis 缓存
     *
     * @return void
     */
    protected static function booted()
    {
        // 监听余额更新事件
        static::updated(function (PlayerPlatformCash $wallet) {
            // 检查 money 字段是否变化
            if (!$wallet->wasChanged('money')) {
                return;
            }

            // ✅ 同步更新 Redis 缓存（先于 try 块，确保缓存同步失败会被捕获）
            $cacheUpdated = false;
            try {
                $cacheUpdated = \app\service\WalletService::updateCache(
                    $wallet->player_id,
                    $wallet->platform_id,
                    (float)$wallet->money
                );

                // 🚨 缓存同步失败告警
                if (!$cacheUpdated) {
                    \support\Log::critical('PlayerPlatformCash: Redis cache sync failed!', [
                        'player_id' => $wallet->player_id,
                        'platform_id' => $wallet->platform_id,
                        'old_balance' => $wallet->getOriginal('money'),
                        'new_balance' => $wallet->money,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (\Throwable $e) {
                // Redis 缓存同步异常
                \support\Log::critical('PlayerPlatformCash: Redis cache sync exception!', [
                    'player_id' => $wallet->player_id,
                    'platform_id' => $wallet->platform_id,
                    'new_balance' => $wallet->money,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            try {

                // 只处理实体机平台的余额变化（爆机检查）
                if ($wallet->platform_id != self::PLATFORM_SELF) {
                    return;
                }

                // 获取玩家信息
                $player = $wallet->player;
                if (!$player) {
                    return;
                }

                // 获取变化前后的余额
                $previousAmount = floatval($wallet->getOriginal('money'));
                $currentAmount = floatval($wallet->money);

                // 获取爆机配置
                $adminUserId = $player->store_admin_id ?? null;
                if (!$adminUserId) {
                    return;
                }

                $crashSetting = \app\model\StoreSetting::getSetting(
                    'machine_crash_amount',
                    $player->department_id,
                    null,
                    $adminUserId
                );

                // 如果没有配置或配置被禁用，不处理
                if (!$crashSetting || $crashSetting->status != 1) {
                    return;
                }

                $crashAmount = $crashSetting->num ?? 0;
                if ($crashAmount <= 0) {
                    return;
                }

                // 检查爆机状态变化
                $wasCrashed = $previousAmount >= $crashAmount;
                $isCrashed = $currentAmount >= $crashAmount;

                // 更新爆机状态字段（如果状态有变化）
                if ($wallet->is_crashed != $isCrashed) {
                    // 使用 withoutEvents 避免递归触发 updated 事件
                    $wallet->withoutEvents(function () use ($wallet, $isCrashed) {
                        $wallet->is_crashed = $isCrashed;
                        $wallet->save();
                    });
                }

                // 从未爆机变为爆机 -> 发送爆机通知
                if (!$wasCrashed && $isCrashed) {
                    $crashInfo = [
                        'crashed' => true,
                        'crash_amount' => $crashAmount,
                        'current_amount' => $currentAmount,
                    ];
                    notifyMachineCrash($player, $crashInfo);
                }

                // 从爆机变为未爆机 -> 发送解锁通知
                if ($wasCrashed && !$isCrashed) {
                    checkAndNotifyCrashUnlock($player, $previousAmount);
                }
            } catch (\Exception $e) {
                \support\Log::error('PlayerPlatformCash: Failed to check machine crash or update cache', [
                    'player_id' => $wallet->player_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
