<?php

namespace process;

use app\model\GameLottery;
use app\model\Lottery;
use app\service\GameLotteryServices;
use app\service\LotteryServices;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 爆彩清理定时任务
 * 定期检查并清理过期的爆彩状态
 */
class BurstCleaner
{
    /**
     * 启动定时任务
     * @return void
     */
    public function onWorkerStart()
    {
        // 每2分钟执行一次爆彩清理检查
        new Crontab('*/2 * * * *', function () {
            try {
                $this->cleanExpiredBursts();
            } catch (\Exception $e) {
                Log::error('爆彩清理任务异常', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        });
    }

    /**
     * 清理所有过期的爆彩状态
     * @return void
     */
    private function cleanExpiredBursts(): void
    {
        $cleanedCount = 0;
        $redis = \support\Redis::connection();

        // 1. 清理电子游戏彩金的过期爆彩
        $gameLotteries = GameLottery::query()
            ->select(['id', 'name', 'burst_status', 'burst_duration'])
            ->where('status', 1)
            ->where('burst_status', 1)
            ->whereNull('deleted_at')
            ->get();

        foreach ($gameLotteries as $lottery) {
            if ($this->cleanGameLotteryBurst($lottery, $redis)) {
                $cleanedCount++;
            }
        }

        // 2. 清理机台彩金的过期爆彩（老虎机和钢珠机）
        $machineLotteries = Lottery::query()
            ->select(['id', 'name', 'game_type', 'burst_status', 'burst_duration'])
            ->where('status', 1)
            ->where('burst_status', 1)
            ->whereNull('deleted_at')
            ->get();

        foreach ($machineLotteries as $lottery) {
            if ($this->cleanMachineLotteryBurst($lottery, $redis)) {
                $cleanedCount++;
            }
        }

        // 记录清理结果
        if ($cleanedCount > 0) {
            Log::info('爆彩清理任务完成', [
                'cleaned_count' => $cleanedCount,
                'game_lottery_count' => $gameLotteries->count(),
                'machine_lottery_count' => $machineLotteries->count(),
            ]);
        }
    }

    /**
     * 清理电子游戏彩金的过期爆彩
     * @param GameLottery $lottery
     * @param \Redis $redis
     * @return bool 是否清理了过期爆彩
     */
    private function cleanGameLotteryBurst(GameLottery $lottery, $redis): bool
    {
        $burstKey = GameLotteryServices::CACHE_KEY_BURST . $lottery->id;
        $startTime = $redis->get($burstKey);

        if (!$startTime) {
            return false;
        }

        // 计算是否过期
        $startTime = intval($startTime);
        $currentTime = time();
        $elapsedSeconds = $currentTime - $startTime;
        $totalSeconds = $lottery->burst_duration * 60;

        if ($elapsedSeconds >= $totalSeconds) {
            // 爆彩已过期，执行清理
            $redis->del($burstKey);

            Log::info('【定时清理】清理电子游戏过期爆彩', [
                'lottery_id' => $lottery->id,
                'lottery_name' => $lottery->name,
                'burst_duration' => $lottery->burst_duration . '分钟',
                'elapsed_seconds' => $elapsedSeconds,
                'start_time' => date('Y-m-d H:i:s', $startTime),
                'end_time' => date('Y-m-d H:i:s', $currentTime),
            ]);

            // 发送爆彩结束全局通知
            $this->sendBurstEndNotice($lottery->id, $lottery->name, 'game');

            return true;
        }

        return false;
    }

    /**
     * 清理机台彩金的过期爆彩
     * @param Lottery $lottery
     * @param \Redis $redis
     * @return bool 是否清理了过期爆彩
     */
    private function cleanMachineLotteryBurst(Lottery $lottery, $redis): bool
    {
        $burstKey = LotteryServices::CACHE_KEY_BURST . $lottery->id;
        $startTime = $redis->get($burstKey);

        if (!$startTime) {
            return false;
        }

        // 计算是否过期
        $startTime = intval($startTime);
        $currentTime = time();
        $elapsedSeconds = $currentTime - $startTime;
        $totalSeconds = $lottery->burst_duration * 60;

        if ($elapsedSeconds >= $totalSeconds) {
            // 爆彩已过期，执行清理
            $redis->del($burstKey);

            $gameTypeName = $lottery->game_type == 1 ? '老虎机' : '钢珠机';

            Log::info('【定时清理】清理机台过期爆彩', [
                'lottery_id' => $lottery->id,
                'lottery_name' => $lottery->name,
                'game_type' => $gameTypeName,
                'burst_duration' => $lottery->burst_duration . '分钟',
                'elapsed_seconds' => $elapsedSeconds,
                'start_time' => date('Y-m-d H:i:s', $startTime),
                'end_time' => date('Y-m-d H:i:s', $currentTime),
            ]);

            // 发送爆彩结束全局通知
            $this->sendBurstEndNotice($lottery->id, $lottery->name, 'machine');

            return true;
        }

        return false;
    }

    /**
     * 发送爆彩结束全局通知
     * @param int $lotteryId
     * @param string $lotteryName
     * @param string $type game|machine
     * @return void
     */
    private function sendBurstEndNotice(int $lotteryId, string $lotteryName, string $type): void
    {
        try {
            $message = [
                'msg_type' => $type === 'game' ? 'game_lottery_burst_notice' : 'machine_lottery_burst_notice',
                'lottery_id' => $lotteryId,
                'lottery_name' => $lotteryName,
                'burst_type' => 'end',
                'title' => '⏰ 爆彩活动结束',
                'content' => sprintf('%s 爆彩活动已结束，感谢参与！', $lotteryName),
            ];

            // 发送到全局广播频道
            sendSocketMessage('broadcast', $message);

            Log::info('发送爆彩结束通知成功', [
                'lottery_id' => $lotteryId,
                'lottery_name' => $lotteryName,
                'type' => $type,
            ]);
        } catch (\Exception $e) {
            Log::error('发送爆彩结束通知失败', [
                'lottery_id' => $lotteryId,
                'lottery_name' => $lotteryName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
