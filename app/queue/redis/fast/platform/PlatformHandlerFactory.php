<?php

namespace app\queue\redis\fast\platform;

use Psr\Log\LoggerInterface;

/**
 * 平台处理器工厂
 * 根据平台代码创建对应的处理器实例
 */
class PlatformHandlerFactory
{
    /**
     * 创建平台处理器
     *
     * @param string $platformCode 平台代码（RSG/DG/MT/BTG/O8/QT等）
     * @param LoggerInterface $log 日志对象
     * @return PlatformHandlerInterface
     */
    public static function create(string $platformCode, LoggerInterface $log): PlatformHandlerInterface
    {
        return match (strtoupper($platformCode)) {
            'RSG' => new RsgPlatformHandler($log),
            'DG' => new DgPlatformHandler($log),
            'MT' => new MtPlatformHandler($log),
            'BTG' => new BtgPlatformHandler($log),
            'O8' => new O8PlatformHandler($log),
            'QT' => new QtPlatformHandler($log),
            'SP' => new SpPlatformHandler($log),
            'T9SLOT' => new T9SlotPlatformHandler($log),
            // 其他平台使用默认处理器
            default => new DefaultPlatformHandler($log, strtoupper($platformCode)),
        };
    }

    /**
     * 获取所有支持的平台代码
     *
     * @return array
     */
    public static function getSupportedPlatforms(): array
    {
        return [
            // 专用处理器
            'RSG',
            'DG',
            'MT',
            'BTG',
            'O8',
            'QT',
            'SP',
            'T9SLOT',
            // 使用默认处理器
            'SA',
            'ATG',
            'KT',
            'RSGLIVE',
            'SPSDY',
            'JDB',
            'KY',
        ];
    }

    /**
     * 检查平台是否支持累计下注
     *
     * @param string $platformCode
     * @return bool
     */
    public static function supportsAccumulatedBet(string $platformCode): bool
    {
        return in_array(strtoupper($platformCode), ['DG', 'KT', 'RSGLIVE']);
    }

    /**
     * 检查平台是否需要爆机检查
     *
     * @param string $platformCode
     * @return bool
     */
    public static function needsMachineCrashCheck(string $platformCode): bool
    {
        $platformsNeedCheck = [
            'MT', 'RSG', 'BTG', 'QT', 'DG', 'O8',
            'SA', 'SP', 'ATG', 'KT', 'T9SLOT', 'RSGLIVE', 'SPSDY'
        ];
        return in_array(strtoupper($platformCode), $platformsNeedCheck);
    }

    /**
     * 检查平台是否需要发送彩金队列
     *
     * @param string $platformCode
     * @return bool
     */
    public static function needsLotteryQueue(string $platformCode): bool
    {
        $platformsNeedLottery = [
            'RSG', 'BTG', 'QT', 'DG', 'O8',
            'SA', 'SP', 'ATG', 'KT', 'T9SLOT', 'RSGLIVE', 'SPSDY'
        ];
        return in_array(strtoupper($platformCode), $platformsNeedLottery);
    }
}
