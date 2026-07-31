<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayGameRecord
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int parent_player_id 上级玩家id
 * @property int agent_player_id 代理玩家id
 * @property string player_uuid 玩家UUID
 * @property int platform_id 平台id
 * @property string game_code 游戏编号
 * @property int department_id 渠道id
 * @property int status 状态
 * @property int settlement_status 结算状态 0 未结算 1已结算 2取消
 * @property float bet 押注
 * @property float win 输赢
 * @property float diff 玩家輸贏額度
 * @property float balance_before 下注前余额
 * @property float balance_after 下注后余额
 * @property float reward 奖金(不计入输赢)
 * @property string order_no 单号
 * @property string order_time 订单时间
 * @property string original_data 原始数据
 * @property string action_data 取消/结算原始数据
 * @property string action_at 结算时间
 * @property string platform_action_at 结算时间(游戏平台)
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property int is_reverse 是否计算反水 0-未计算 1-计算
 * @property int is_rebet 重结算订单 0-否 1-是
 * @property int type 订单类型 1-下注 2-打赏 3-预扣 4-退款
 * @property int national_promoter_action 全民代理反润结算,0-未结算，1-已结算
 * @property float national_damage_ratio 全民代理返佣比例
 *
 * @property Channel channel 渠道
 * @property Player player 玩家
 * @property GamePlatform gamePlatform 平台信息
 * @property GameExtend gameExtend 游戏信息
 * @package app\model
 */
class PlayGameRecord extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    const STATUS_UNSETTLED = 0; // 未分佣
    const STATUS_SETTLED = 1; // 已分佣
    const SETTLEMENT_STATUS_UNSETTLED = 0; // 未结算
    const SETTLEMENT_STATUS_SETTLED = 1; // 已结算
    const SETTLEMENT_STATUS_CANCELLED = 2; // 已取消
    const SETTLEMENT_STATUS_CONFIRM = 3; // 确认

    const TYPE_BET = 1; // 下注
    const TYPE_GIFT = 2; // 打赏
    const TYPE_PREPAY = 3; // 预扣款
    const TYPE_REFUND = 4; // 退款

    protected $fillable = [
        'player_id',
        'parent_player_id',
        'player_uuid',
        'platform_id',
        'game_code',
        'department_id',
        'bet',
        'win',
        'diff',
        'balance_before',
        'balance_after',
        'reward',
        'order_no',
        'original_data',
        'national_damage_ratio',
        'agent_player_id',
        'platform_action_at',
        'order_time',
        'settlement_status',
        'action_data',
        'type',
        'balance_before',
        'balance_after'
    ];
    protected $table = 'play_game_record';

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'department_id',
            'department_id')->withTrashed();
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
     * 平台信息
     * @return BelongsTo
     */
    public function gamePlatform(): BelongsTo
    {
        return $this->belongsTo(GamePlatform::class, 'platform_id')->withTrashed();
    }

    /**
     * 游戏信息
     * @return BelongsTo
     */
    public function gameExtend(): BelongsTo
    {
        return $this->belongsTo(GameExtend::class, 'game_code', 'code')
            ->where('platform_id', $this->platform_id);
    }

    /**
     * 模型事件
     *
     * ⚠️ 注意：模型事件仅在单条创建（create/save）时触发
     * 批量插入（insert）不会触发此事件
     * 批量插入的打码量统计由 GameRecordSyncWorker 负责
     */
    protected static function booted()
    {
        // 创建记录后投递打码量统计队列
        static::created(function (PlayGameRecord $record) {
            self::sendBetStatistics($record);
        });
    }

    /**
     * 发送打码量统计到队列
     *
     * ⚠️ 此方法会被以下地方调用：
     * 1. 模型 created 事件（单条创建时）
     * 2. GameRecordSyncWorker（批量插入后）
     *
     * @param PlayGameRecord $record
     * @return void
     */
    public static function sendBetStatistics(PlayGameRecord $record): void
    {
        // ✅ 只统计已结算的下注记录
        // 条件：
        // 1. settlement_status == SETTLED（已结算）
        // 2. type == TYPE_BET（下注类型，排除打赏、预扣、退款）
        // 3. bet > 0（有下注金额）
        if ($record->settlement_status == self::SETTLEMENT_STATUS_SETTLED
            && ($record->type ?? self::TYPE_BET) == self::TYPE_BET
            && $record->bet > 0) {
            try {
                // 投递到快速队列
                \Webman\RedisQueue\Client::send('bet-statistics', [
                    'player_id' => $record->player_id,
                    'stat_type' => 'game',
                    'bet_amount' => $record->bet,
                    'source' => $record->game_code ?? 'unknown',
                    'play_game_record_id' => $record->id,
                    'created_at' => $record->created_at,
                ], 'fast');

                \support\Log::debug('[BetStats] 电子游戏打码量已投递队列', [
                    'player_id' => $record->player_id,
                    'bet' => $record->bet,
                    'game_code' => $record->game_code,
                    'record_id' => $record->id,
                    'type' => $record->type ?? self::TYPE_BET,
                ]);
            } catch (\Exception $e) {
                // 队列投递失败不影响主业务
                \support\Log::error('[BetStats] 投递队列失败', [
                    'player_id' => $record->player_id,
                    'record_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
