<?php

namespace app\traits;

use app\model\PlayGameRecord;
use Carbon\Carbon;
use support\Log;
use Webman\RedisQueue\Client;

/**
 * 游戏记录异步处理 Trait
 * 用于单一钱包API异步创建游戏记录，提升响应速度
 */
trait AsyncGameRecordTrait
{
    /**
     * 异步创建下注记录
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param string $gameCode 游戏代码
     * @param string $orderNo 订单号
     * @param float $bet 下注金额
     * @param array $originalData 原始数据
     * @param string|null $orderTime 下注时间
     * @param bool $updateStats 是否更新玩家统计
     * @return void
     */
    protected function asyncCreateBetRecord(
        int     $playerId,
        int     $platformId,
        string  $gameCode,
        string  $orderNo,
        float   $bet,
        array   $originalData = [],
        ?string $orderTime = null,
        bool    $updateStats = false
    ): void
    {
        $startTime = microtime(true);

        $data = [
            'player_id' => $playerId,
            'platform_id' => $platformId,
            'game_code' => $gameCode,
            'order_no' => $orderNo,
            'bet' => $bet,
            'win' => 0,
            'diff' => 0,
            'original_data' => $originalData,
            'order_time' => $orderTime ?? Carbon::now()->toDateTimeString(),
            'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED,
            'record_type' => 'bet',
            'update_stats' => $updateStats,
        ];

        try {
            // 发送到队列
            Client::send('game-bet-record', $data, 0);

            $duration = (microtime(true) - $startTime) * 1000;

            // 记录入队日志
            Log::channel('async_game_record')->info('🚀 异步创建下注记录入队', [
                'order_no' => $orderNo,
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'bet' => $bet,
                'game_code' => $gameCode,
                'queue_time' => round($duration, 2) . 'ms',
            ]);

        } catch (\Throwable $e) {
            // 队列入队失败，记录错误
            Log::channel('async_game_record')->error('❌ 异步创建下注记录入队失败', [
                'order_no' => $orderNo,
                'player_id' => $playerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 重新抛出异常，让上层处理
            throw $e;
        }
    }

    /**
     * 异步更新结算记录
     *
     * @param string $orderNo 订单号
     * @param float $win 赢取金额
     * @param float $diff 输赢差值
     * @return void
     */
    protected function asyncUpdateSettleRecord(
        string $orderNo,
        float  $win,
        float  $diff
    ): void
    {
        $startTime = microtime(true);

        $data = [
            'order_no' => $orderNo,
            'win' => $win,
            'diff' => $diff,
            'record_type' => 'settle',
        ];

        try {
            Client::send('game-bet-record', $data, 0);

            $duration = (microtime(true) - $startTime) * 1000;

            // 记录入队日志
            Log::channel('async_game_record')->info('💰 异步更新结算记录入队', [
                'order_no' => $orderNo,
                'win' => $win,
                'diff' => $diff,
                'queue_time' => round($duration, 2) . 'ms',
            ]);

        } catch (\Throwable $e) {
            Log::channel('async_game_record')->error('❌ 异步更新结算记录入队失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * 异步取消下注记录
     *
     * @param string $orderNo 订单号
     * @return void
     */
    protected function asyncCancelBetRecord(string $orderNo): void
    {
        $startTime = microtime(true);

        $data = [
            'order_no' => $orderNo,
            'record_type' => 'cancel',
        ];

        try {
            Client::send('game-bet-record', $data, 0);

            $duration = (microtime(true) - $startTime) * 1000;

            // 记录入队日志
            Log::channel('async_game_record')->info('🔄 异步取消下注记录入队', [
                'order_no' => $orderNo,
                'queue_time' => round($duration, 2) . 'ms',
            ]);

        } catch (\Throwable $e) {
            Log::channel('async_game_record')->error('❌ 异步取消下注记录入队失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * 批量异步创建下注记录
     *
     * @param array $records 记录数组
     * @return void
     */
    protected function asyncBatchCreateBetRecords(array $records): void
    {
        foreach ($records as $record) {
            $this->asyncCreateBetRecord(
                $record['player_id'],
                $record['platform_id'],
                $record['game_code'],
                $record['order_no'],
                $record['bet'],
                $record['original_data'] ?? [],
                $record['order_time'] ?? null,
                $record['update_stats'] ?? false
            );
        }
    }
}
