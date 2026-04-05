<?php

namespace app\Constants;

/**
 * 交易类型常量
 *
 * 统一字段设计：使用 type 替代 bet_type, settle_type, cancel_type
 */
class TransactionType
{
    // ========== 下注类（扣款操作）==========
    /** 普通下注 */
    public const BET = 'bet';

    /** 预扣款（RSG 打鱼机） */
    public const BET_PREPAY = 'bet_prepay';

    /** 奖金回合（QT 免费游戏，不扣款） */
    public const BET_BONUS = 'bet_bonus';

    /** 扣款调整（BTG 负数调整） */
    public const BET_ADJUST = 'bet_adjust';

    /** 打赏（MT 打赏扣款） */
    public const BET_GIFT = 'bet_gift';

    // ========== 结算类（派彩操作）==========
    /** 普通结算/派彩 */
    public const SETTLE = 'settle';

    /** 派彩调整（RSG/BTG 重新结算） */
    public const SETTLE_ADJUST = 'settle_adjust';

    /** Jackpot 彩金 */
    public const SETTLE_JACKPOT = 'settle_jackpot';

    /** 奖励派彩（BTG 活动奖励） */
    public const SETTLE_REWARD = 'settle_reward';

    /** 退款派彩（RSG 退款形式的派彩） */
    public const SETTLE_REFUND = 'settle_refund';

    /** 免费游戏结算（TNineSlot 免费游戏） */
    public const SETTLE_FREEGAME = 'settle_freegame';

    // ========== 取消类（退款/回滚操作）==========
    /** 退款（ATG 退款操作） */
    public const CANCEL_REFUND = 'cancel_refund';

    /** 取消下注 */
    public const CANCEL = 'cancel';

    /** 回滚（QT/BTG 交易回滚） */
    public const CANCEL_ROLLBACK = 'cancel_rollback';

    /**
     * 获取所有类型
     *
     * @return array
     */
    public static function all(): array
    {
        return [
            // 下注类
            self::BET,
            self::BET_PREPAY,
            self::BET_BONUS,
            self::BET_ADJUST,
            self::BET_GIFT,
            // 结算类
            self::SETTLE,
            self::SETTLE_ADJUST,
            self::SETTLE_JACKPOT,
            self::SETTLE_REWARD,
            self::SETTLE_REFUND,
            self::SETTLE_FREEGAME,
            // 取消类
            self::CANCEL_REFUND,
            self::CANCEL,
            self::CANCEL_ROLLBACK,
        ];
    }

    /**
     * 判断是否为下注类型
     *
     * @param string $type
     * @return bool
     */
    public static function isBet(string $type): bool
    {
        return str_starts_with($type, 'bet');
    }

    /**
     * 判断是否为结算类型
     *
     * @param string $type
     * @return bool
     */
    public static function isSettle(string $type): bool
    {
        return str_starts_with($type, 'settle');
    }

    /**
     * 判断是否为取消/退款类型
     *
     * @param string $type
     * @return bool
     */
    public static function isCancel(string $type): bool
    {
        return str_starts_with($type, 'cancel');
    }

    /**
     * 从旧字段映射到新字段
     *
     * @param array $data 包含 bet_type, settle_type, cancel_type 的数据
     * @return string
     */
    public static function mapFromLegacy(array $data): string
    {
        // 优先检查 cancel_type
        if (isset($data['cancel_type'])) {
            return match ($data['cancel_type']) {
                'refund' => self::CANCEL_REFUND,
                'rollback' => self::CANCEL_ROLLBACK,
                'cancel' => self::CANCEL,
                default => self::CANCEL,
            };
        }

        // 检查 settle_type
        if (isset($data['settle_type'])) {
            return match ($data['settle_type']) {
                'adjust' => self::SETTLE_ADJUST,
                'jackpot' => self::SETTLE_JACKPOT,
                'reward' => self::SETTLE_REWARD,
                'refund' => self::SETTLE_REFUND,
                'freegame' => self::SETTLE_FREEGAME,
                'settle' => self::SETTLE,
                default => self::SETTLE,
            };
        }

        // 检查 bet_type
        if (isset($data['bet_type'])) {
            return match ($data['bet_type']) {
                'prepay' => self::BET_PREPAY,
                'bonus' => self::BET_BONUS,
                'adjust' => self::BET_ADJUST,
                'gift' => self::BET_GIFT,
                'bet' => self::BET,
                default => self::BET,
            };
        }

        // 默认为普通下注
        return self::BET;
    }

    /**
     * 获取类型的中文名称
     *
     * @param string $type
     * @return string
     */
    public static function getLabel(string $type): string
    {
        return match ($type) {
            self::BET => '普通下注',
            self::BET_PREPAY => '预扣款',
            self::BET_BONUS => '奖金回合',
            self::BET_ADJUST => '扣款调整',
            self::BET_GIFT => '打赏',
            self::SETTLE => '普通结算',
            self::SETTLE_ADJUST => '派彩调整',
            self::SETTLE_JACKPOT => '彩金派彩',
            self::SETTLE_REWARD => '奖励派彩',
            self::SETTLE_REFUND => '退款派彩',
            self::SETTLE_FREEGAME => '免费游戏',
            self::CANCEL_REFUND => '退款',
            self::CANCEL => '取消下注',
            self::CANCEL_ROLLBACK => '交易回滚',
            default => '未知类型',
        };
    }

    /**
     * 获取金额变化方向
     *
     * @param string $type
     * @return string + 表示加钱，- 表示扣钱
     */
    public static function getAmountDirection(string $type): string
    {
        if (self::isBet($type)) {
            return '-'; // 下注扣钱
        }

        if (self::isSettle($type) || self::isCancel($type)) {
            return '+'; // 结算/取消/退款加钱
        }

        return ''; // 未知
    }
}
