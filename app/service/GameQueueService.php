<?php

namespace app\service;

use app\model\Player;
use support\Log;
use Webman\RedisQueue\Client;

/**
 * 游戏队列服务（简化版）
 * 用于将游戏操作发送到异步队列
 */
class GameQueueService
{
    /**
     * 发送下注操作到队列
     *
     * @param string $platform 平台代码（MT, RSG, BTG等）
     * @param Player $player 玩家对象
     * @param array $params 请求参数
     * @return bool
     */
    public static function sendBet(string $platform, Player $player, array $params): bool
    {
        try {
            Client::send('game-operation', [
                'platform' => $platform,
                'operation' => 'bet',
                'player_id' => $player->id,
                'order_no' => $params['order_no'] ?? '',
                'params' => $params,
                'created_at' => time(),
            ]);

            Log::info("GameQueue: 下注已入队", [
                'platform' => $platform,
                'order_no' => $params['order_no'] ?? '',
                'player_id' => $player->id,
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error("GameQueue: 下注入队失败", [
                'platform' => $platform,
                'order_no' => $params['order_no'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 发送结算操作到队列
     *
     * @param string $platform 平台代码
     * @param Player $player 玩家对象
     * @param array $params 请求参数
     * @return bool
     */
    public static function sendSettle(string $platform, Player $player, array $params): bool
    {
        try {
            Client::send('game-operation', [
                'platform' => $platform,
                'operation' => 'settle',
                'player_id' => $player->id,
                'order_no' => $params['order_no'] ?? '',
                'params' => $params,
                'created_at' => time(),
            ]);

            Log::info("GameQueue: 结算已入队", [
                'platform' => $platform,
                'order_no' => $params['order_no'] ?? '',
                'player_id' => $player->id,
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error("GameQueue: 结算入队失败", [
                'platform' => $platform,
                'order_no' => $params['order_no'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 发送取消操作到队列
     *
     * @param string $platform 平台代码
     * @param Player $player 玩家对象
     * @param array $params 请求参数
     * @return bool
     */
    public static function sendCancel(string $platform, Player $player, array $params): bool
    {
        try {
            Client::send('game-operation', [
                'platform' => $platform,
                'operation' => 'cancel',
                'player_id' => $player->id,
                'order_no' => $params['order_no'] ?? '',
                'params' => $params,
                'created_at' => time(),
            ]);

            Log::info("GameQueue: 取消已入队", [
                'platform' => $platform,
                'order_no' => $params['order_no'] ?? '',
                'player_id' => $player->id,
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error("GameQueue: 取消入队失败", [
                'platform' => $platform,
                'order_no' => $params['order_no'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 发送退款操作到队列
     *
     * @param string $platform 平台代码
     * @param Player $player 玩家对象
     * @param array $params 请求参数
     * @return bool
     */
    public static function sendRefund(string $platform, Player $player, array $params): bool
    {
        try {
            Client::send('game-operation', [
                'platform' => $platform,
                'operation' => 'refund',
                'player_id' => $player->id,
                'order_no' => $params['order_no'] ?? '',
                'params' => $params,
                'created_at' => time(),
            ]);

            Log::info("GameQueue: 退款已入队", [
                'platform' => $platform,
                'order_no' => $params['order_no'] ?? '',
                'player_id' => $player->id,
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error("GameQueue: 退款入队失败", [
                'platform' => $platform,
                'order_no' => $params['order_no'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
