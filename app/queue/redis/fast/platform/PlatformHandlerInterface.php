<?php

namespace app\queue\redis\fast\platform;

use app\model\Player;

/**
 * 平台处理器接口
 * 定义所有平台必须实现的方法
 */
interface PlatformHandlerInterface
{
    /**
     * 处理下注
     *
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @return void
     * @throws \Exception
     */
    public function processBet(array $data, Player $player): void;

    /**
     * 处理结算
     *
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @return void
     * @throws \Exception
     */
    public function processSettle(array $data, Player $player): void;

    /**
     * 处理取消
     *
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @return void
     * @throws \Exception
     */
    public function processCancel(array $data, Player $player): void;

    /**
     * 处理退款
     *
     * @param array $data 队列数据
     * @param Player $player 玩家对象
     * @return void
     * @throws \Exception
     */
    public function processRefund(array $data, Player $player): void;

    /**
     * 获取平台代码
     *
     * @return string
     */
    public function getPlatformCode(): string;

    /**
     * 是否支持累计下注
     *
     * @return bool
     */
    public function supportsAccumulatedBet(): bool;

    /**
     * 是否需要爆机检查
     *
     * @return bool
     */
    public function needsMachineCrashCheck(): bool;

    /**
     * 是否需要发送彩金队列
     *
     * @return bool
     */
    public function needsLotteryQueue(): bool;
}
