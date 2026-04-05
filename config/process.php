<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use process\BurstCleaner;
use process\ChannelSettlement;
use process\GamePoolSocket;
use process\GameRecordCleanWorker;
use process\GameRecordSyncWorker;
use process\LotteryPoolSocket;
use process\LotteryRemind;
use process\NationalPromoterRebate;
use process\ProfitSettlement;
use process\ReconciliationTask;
use process\ReverseWater;

return [
    'ProfitSettlement' => [
        'handler' => ProfitSettlement::class
    ],
    'LotteryPoolSocket' => [
        'handler' => LotteryPoolSocket::class
    ],
    'LotteryRemind' => [
        'handler' => LotteryRemind::class
    ],
    'NationalPromoterRebate' => [
        'handler' => NationalPromoterRebate::class
    ],
    'ReverseWater' => [
        'handler' => ReverseWater::class
    ],
    'ChannelSettlement' => [
        'handler' => ChannelSettlement::class
    ],
    'GamePoolSocket' => [
        'handler' => GamePoolSocket::class
    ],
    'BurstCleaner' => [
        'handler' => BurstCleaner::class
    ],
    // Redis 钱包对账任务（每分钟）
    'ReconciliationTask' => [
        'handler' => ReconciliationTask::class
    ],
    // ✅ 游戏记录同步进程（Redis → MySQL 批量同步）
    'GameRecordSyncWorker' => [
        'handler' => GameRecordSyncWorker::class,
        'count' => 2,  // 2个进程（可根据负载调整）
    ],
    // ✅ 游戏记录清理进程（Redis 过期记录清理）
    'GameRecordCleanWorker' => [
        'handler' => GameRecordCleanWorker::class,
        'count' => 1,  // 1个进程即可（低频任务）
    ],
];

// ============================================================
// 游戏下注记录异步队列说明
// ============================================================
// game-bet-record 队列由 webman-redis-queue 自动管理
// 消费者配置：config/plugin/webman/redis-queue/process.php
// 消费者类：app/queue/redis/GameBetRecord.php
// 进程数：8个（在 config/plugin/webman/redis-queue/process.php 中配置）
//
// ⚠️ 不要在这里添加单独的进程配置，webman-redis-queue 会自动发现并消费所有队列
// ============================================================
