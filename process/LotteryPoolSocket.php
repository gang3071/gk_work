<?php

namespace process;

use app\model\Lottery;
use app\model\LotteryPool;
use app\service\GameLotteryServices;
use app\service\LotteryServices;
use support\Log;
use Webman\Push\PushException;
use Workerman\Crontab\Crontab;

class LotteryPoolSocket
{
    /**
     * 上一次发送的数据缓存，用于判断数据是否有变化
     * @var string|null
     */
    private static $lastDataHash = null;

    /**
     * 发送彩金变化消息（兜底机制）
     * 注意：主要推送逻辑已改为实时推送，此定时任务仅作为兜底
     * @return void
     */
    public function onWorkerStart()
    {
        // 每30秒执行一次检查（降低频率，仅作为兜底）
        new Crontab('*/30 * * * * *', function () {
            try {
                $this->sendLotteryPoolData();
            } catch (\Throwable $e) {
                Log::error('LotteryPoolSocket执行失败: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        });
    }

    /**
     * 发送彩金池数据 - 新版：使用独立彩池（兜底机制）
     * 注意：主要推送逻辑已改为实时推送，此方法仅作为兜底检查
     * @return void
     */
    private function sendLotteryPoolData()
    {
        // 获取彩金池数据
        $lotteryServices = (new LotteryServices())->setJackLotteryList()->setSlotLotteryList();
        $gameLotteryPool = GameLotteryServices::getLotteryPool();
        // 构建消息数据
        $messageData = [
            'slot_amount' => $this->formatLotteryList($lotteryServices->slotLotteryList),
            'jack_amount' => $this->formatLotteryList($lotteryServices->jackLotteryList),
            'game_lottery_list' => $this->formatGameLotteryPool($gameLotteryPool),
        ];
        // 数据变化检测，避免发送重复数据
        $currentHash = md5(json_encode($messageData));
        if ($currentHash === self::$lastDataHash) {
            Log::debug('LotteryPoolSocket兜底检查: 数据无变化，跳过推送');
            return;
        }
        // 发送消息
        try {
            sendSocketMessage('group-lottery-pool', $messageData);
            Log::info('LotteryPoolSocket兜底推送成功', [
                'slot_count' => count($messageData['slot_amount']),
                'jack_count' => count($messageData['jack_amount']),
            ]);
        } catch (PushException $e) {
            Log::error($e->getMessage());
            return;
        }
        self::$lastDataHash = $currentHash;
    }

    /**
     * 格式化彩金列表 - 新版：使用独立彩池
     * @param $lotteryList
     * @return array
     */
    private function formatLotteryList($lotteryList)
    {
        $result = [];

        /** @var Lottery $lottery */
        foreach ($lotteryList as $lottery) {
            // 新版：直接使用 lottery.amount（独立彩池金额）
            $amount = floatval($lottery->amount);

            // 从Redis获取实时金额并累加
            try {
                $redis = \support\Redis::connection()->client();
                $redisKey = LotteryServices::REDIS_KEY_LOTTERY_AMOUNT . $lottery->id;
                $redisAmount = $redis->get($redisKey);
                if ($redisAmount !== false && $redisAmount > 0) {
                    $amount = floatval(bcadd($amount, $redisAmount, 2));
                }
            } catch (\Exception) {
                // 降级使用数据库金额
            }

            // 限制不超过最大金额
            if ($lottery->max_amount > 0) {
                $amount = min($amount, floatval($lottery->max_amount));
            }

            $result[] = [
                'id' => $lottery->id,
                'name' => $lottery->name,
                'amount' => number_format($amount, 2, '.', ''),
                'lotteryMultiple' => 1,
            ];
        }

        return $result;
    }

    /**
     * 格式化电子游戏彩金池数据
     * @param array $gameLotteryPool
     * @return array
     */
    private function formatGameLotteryPool($gameLotteryPool)
    {
        $formattedGamePool = [];

        if (empty($gameLotteryPool)) {
            return $formattedGamePool;
        }

        foreach ($gameLotteryPool as $lottery) {
            $formattedGamePool[] = [
                'id' => $lottery['id'],
                'name' => $lottery['name'],
                'amount' => number_format($lottery['amount'], 2, '.', ''),
            ];
        }

        return $formattedGamePool;
    }
}
