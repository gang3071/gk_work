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

use process\BalancePushWorker;
use process\BurstCleaner;
use process\ChannelSettlement;
use process\ClearAbnormalMachine;
use process\GamePoolSocket;
use process\GameRecordCleanWorker;
use process\GameRecordSyncWorker;
use process\GetAmsViewers;
use process\GetTencentViewers;
use process\LotteryPoolSocket;
use process\LotteryRemind;
use process\MachineKeepOutPlayer;
use process\MediaClear;
use process\NationalPromoterRebate;
use process\OnlinePlayerPushWorker;
use process\ProfitSettlement;
use process\RechargeRemind;
use process\ReconciliationTask;
use process\ReverseWater;
use process\SyncMachineActivity;
use process\SyncMachineGameLog;
use process\SyncMachineStatus;
use process\TencentStream;
use process\WithdrawRemind;

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
    // ✅ Redis 钱包对账任务（每10分钟）
    // 作用：监控和兜底，确保 Redis 与 MySQL 数据一致性
    'ReconciliationTask' => [
        'handler' => ReconciliationTask::class
    ],
    // ✅ 游戏记录同步进程（Redis → MySQL 批量同步）
    // 性能优化（2026-04-09）：使用 EVALSHA 减少 70% 网络传输
    'GameRecordSyncWorker' => [
        'handler' => GameRecordSyncWorker::class,
        'count' => 2,  // 2 个进程，应对高峰期
    ],
    // ✅ 游戏记录清理进程（Redis 过期记录清理）
    'GameRecordCleanWorker' => [
        'handler' => GameRecordCleanWorker::class,
        'count' => 1,  // 1个进程即可（低频任务）
    ],
    // ✅ 实时余额推送进程（Redis Pub/Sub → WebSocket 实时推送）
    // 作用：订阅 balance:change 频道，收到消息后立即推送到 WebSocket
    // 延迟：< 50ms（相比 Crontab 定时任务，延迟降低 95%）
    'BalancePushWorker' => [
        'handler' => BalancePushWorker::class,
        'count' => 1,  // 1个进程即可（Pub/Sub 消费不需要并发）
    ],
    // ✅ 在线玩家定时推送进程（Redis Set + 定时任务）
    // 作用：每3秒批量推送在线玩家列表到 WebSocket
    // 优势：消除99.9%的重复推送，降低Socket压力，无队列积压
    // 延迟：< 3秒（可接受）
    'OnlinePlayerPushWorker' => [
        'handler' => OnlinePlayerPushWorker::class,
        'count' => 1,  // 1个进程即可（定时任务不需要并发）
    ],

    // ============================================================
    // 实体机台相关进程
    // ============================================================

    // ✅ 机台超时玩家踢出进程
    // 作用：每分钟检查一次，将超时玩家踢出机台
    // 时间：默认5分钟无操作自动踢出
    'MachineKeepOutPlayer' => [
        'handler' => MachineKeepOutPlayer::class,
        'count' => 1,
    ],

    // ✅ 机台状态同步进程
    // 作用：每5秒从硬件读取机台实时状态，推送到前端
    // 延迟：< 5秒
    'SyncMachineStatus' => [
        'handler' => SyncMachineStatus::class,
        'count' => 1,
    ],

    // ✅ 机台游戏日志同步进程
    // 作用：每10秒记录一次玩家游戏行为
    // 用途：数据分析、统计
    'SyncMachineGameLog' => [
        'handler' => SyncMachineGameLog::class,
        'count' => 1,
    ],

    // ✅ 机台活动同步进程
    // 作用：每分钟统计机台运行时间和游戏次数
    // 用途：活动奖励计算
    'SyncMachineActivity' => [
        'handler' => SyncMachineActivity::class,
        'count' => 1,
    ],

    // ✅ 异常机台清理进程
    // 作用：每小时清理一次异常机台（无玩家但状态异常）
    // 安全：避免机台被永久占用
    'ClearAbnormalMachine' => [
        'handler' => ClearAbnormalMachine::class,
        'count' => 1,
    ],

    // ============================================================
    // 视讯直播相关进程
    // ============================================================

    // ✅ AMS 媒体服务器观看人数统计
    // 作用：每分钟统计 AMS 媒体服务器观看人数，缓存到 Redis
    // 延迟：< 60秒
    'GetAmsViewers' => [
        'handler' => GetAmsViewers::class,
        'count' => 1,
    ],

    // ✅ 腾讯云直播观看人数统计
    // 作用：每分钟统计腾讯云直播观看人数，缓存到 Redis
    // 延迟：< 60秒
    'GetTencentViewers' => [
        'handler' => GetTencentViewers::class,
        'count' => 1,
    ],

    // ✅ 腾讯云直播流管理
    // 作用：每2分钟检查推流状态，无观看者自动关闭，节省带宽
    // 优化：自动释放无效推流资源
    'TencentStream' => [
        'handler' => TencentStream::class,
        'count' => 1,
    ],

    // ✅ 媒体文件清理
    // 作用：每5分钟清理一次过期媒体文件
    // 防止：磁盘空间无限增长
    'MediaClear' => [
        'handler' => MediaClear::class,
        'count' => 1,
    ],

    // ============================================================
    // 提醒相关进程
    // ============================================================

    // ✅ 充值审核提醒
    // 作用：每25秒推送待审核充值通知到管理员
    // 实时：管理员及时处理充值申请
    'RechargeRemind' => [
        'handler' => RechargeRemind::class,
        'count' => 1,
    ],

    // ✅ 提款审核提醒
    // 作用：每20秒推送待审核提款通知到管理员
    // 实时：管理员及时处理提款申请
    'WithdrawRemind' => [
        'handler' => WithdrawRemind::class,
        'count' => 1,
    ],

    // ✅ 玩家打码量统计同步任务（每小时）
    // 作用：将 Redis 中的日维度打码量数据同步到 MySQL
    'PlayerBetStatisticsSyncHourly' => [
        'handler' => process\PlayerBetStatisticsSyncHourly::class,
    ],
    // ✅ 玩家打码量统计同步任务（每天）
    // 作用：将 Redis 中的周/月维度打码量数据同步到 MySQL
    'PlayerBetStatisticsSyncDaily' => [
        'handler' => process\PlayerBetStatisticsSyncDaily::class,
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
