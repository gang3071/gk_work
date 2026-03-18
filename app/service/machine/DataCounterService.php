<?php

namespace app\service\machine;

use support\Cache;
use support\Db;
use support\Log;

/**
 * 虚拟打赏灯数据服务
 */
class DataCounterService
{
    /**
     * 更新开奖记录（BB/RB触发时调用）
     *
     * @param int $machineId 机台ID
     * @param string $type 开奖类型：BB/RB
     * @param float $turn 使用转数
     * @param float $bet 开奖时压分
     * @param int|null $playerId 玩家ID
     * @param int|null $departmentId 部门ID
     * @return void
     */
    public static function updateLottery(
        int    $machineId,
        string $type,
        float  $turn,
        float  $bet,
        ?int   $playerId = null,
        ?int   $departmentId = null
    ): void
    {
        try {
            $now = time();
            $today = date('Y-m-d');

            // 1. 更新开奖历史缓存（最近30条）
            self::updateLotteryHistory($machineId, $type, $turn, $bet, $now);

            // 2. 更新今日统计缓存
            self::updateTodayStats($machineId, $type);

            // 3. 持久化到数据库
            self::saveLotteryRecord($machineId, $type, $turn, $bet, $now, $playerId, $departmentId);

            // 4. 更新每日统计表
            self::updateDailyStats($machineId, $today, $type);

            // 5. 推送到前端
            self::pushToFrontend($machineId, $type, $turn, $bet);

            Log::info('虚拟打赏灯更新成功', [
                'machine_id' => $machineId,
                'type' => $type,
                'turn' => $turn / 3,  // 转数除以3
                'bet' => $bet,
            ]);
        } catch (\Exception $e) {
            Log::error('虚拟打赏灯更新失败', [
                'machine_id' => $machineId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 更新开奖历史缓存
     */
    private static function updateLotteryHistory(
        int    $machineId,
        string $type,
        float  $turn,
        float  $bet,
        int    $time
    ): void
    {
        $historyKey = "machine:{$machineId}:lottery_history";
        $history = Cache::get($historyKey, []);

        // 添加到开头
        array_unshift($history, [
            'type' => $type,
            'turn' => round($turn / 3, 2),  // 转数除以3
            'bet' => $bet,
            'time' => $time,
        ]);

        // 只保留30条
        if (count($history) > 30) {
            $history = array_slice($history, 0, 30);
        }

        // 缓存7天
        Cache::set($historyKey, $history, 86400 * 7);
    }

    /**
     * 更新今日统计缓存
     */
    private static function updateTodayStats(int $machineId, string $type): void
    {
        $statsKey = "machine:{$machineId}:today_stats";
        $stats = Cache::get($statsKey, [
            'bb_count' => 0,
            'rb_count' => 0,
            'total_open' => 0,
            'total_wash' => 0,
            'total_bet' => 0,
            'total_win' => 0,
        ]);

        // 增加对应类型的计数
        if ($type === 'BB') {
            $stats['bb_count']++;
        } elseif ($type === 'RB') {
            $stats['rb_count']++;
        }

        // 缓存到当天23:59:59
        $expireTime = strtotime('today 23:59:59') - time();
        Cache::set($statsKey, $stats, max($expireTime, 60));
    }

    /**
     * 保存开奖记录到数据库
     */
    private static function saveLotteryRecord(
        int    $machineId,
        string $type,
        float  $turn,
        float  $bet,
        int    $time,
        ?int   $playerId,
        ?int   $departmentId
    ): void
    {
        Db::table('machine_lottery_history')->insert([
            'machine_id' => $machineId,
            'player_id' => $playerId,
            'department_id' => $departmentId ?? 0,
            'lottery_type' => $type,
            'use_turn' => $turn,
            'draw_bet' => $bet,
            'draw_time' => date('Y-m-d H:i:s', $time),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 更新每日统计表
     */
    private static function updateDailyStats(int $machineId, string $date, string $type): void
    {
        $field = $type === 'BB' ? 'bb_count' : 'rb_count';

        // 使用 INSERT ... ON DUPLICATE KEY UPDATE
        Db::table('machine_daily_stats')->insertOrIgnore([
            'machine_id' => $machineId,
            'stats_date' => $date,
            $field => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 如果已存在，则更新
        Db::table('machine_daily_stats')
            ->where('machine_id', $machineId)
            ->where('stats_date', $date)
            ->increment($field, 1, ['updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 推送到前端
     */
    private static function pushToFrontend(int $machineId, string $type, float $turn, float $bet): void
    {
        $statsKey = "machine:{$machineId}:today_stats";
        $stats = Cache::get($statsKey, []);

        sendSocketMessage("machine-data-counter-{$machineId}", [
            'msg_type' => 'lottery_update',
            'data' => [
                'type' => $type,
                'turn' => round($turn / 3, 2),
                'bet' => $bet,
                'time' => time(),
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * 获取虚拟打赏灯完整数据
     *
     * @param int $machineId
     * @return array
     */
    public static function getData(int $machineId): array
    {
        // 1. 当前转数（从机台缓存读取）
        $nowTurn = Cache::get("machine_data_{$machineId}_now_turn", 0);

        // 2. 开奖历史
        $history = Cache::get("machine:{$machineId}:lottery_history", []);

        // 3. 今日统计
        $todayStats = self::getTodayStats($machineId);

        // 4. 昨日统计
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $yesterdayStats = self::getDailyStats($machineId, $yesterday);

        // 5. 前日统计
        $dayBefore = date('Y-m-d', strtotime('-2 days'));
        $dayBeforeStats = self::getDailyStats($machineId, $dayBefore);

        // 6. 当前游戏状态
        $rewardStatus = Cache::get("machine_data_{$machineId}_reward_status", 0);
        $bbStatus = Cache::get("machine_data_{$machineId}_bb_status", 0);
        $rbStatus = Cache::get("machine_data_{$machineId}_rb_status", 0);

        return [
            'current_turn' => round($nowTurn / 3, 2),  // 转数除以3
            'reward_status' => $rewardStatus,
            'bb_status' => $bbStatus,
            'rb_status' => $rbStatus,
            'history' => $history,
            'today' => $todayStats,
            'yesterday' => $yesterdayStats,
            'day_before' => $dayBeforeStats,
        ];
    }

    /**
     * 获取今日统计（优先从缓存）
     */
    private static function getTodayStats(int $machineId): array
    {
        $statsKey = "machine:{$machineId}:today_stats";
        $cacheStats = Cache::get($statsKey);

        if ($cacheStats) {
            return $cacheStats;
        }

        // 从数据库读取
        $today = date('Y-m-d');
        return self::getDailyStats($machineId, $today);
    }

    /**
     * 获取指定日期的统计
     */
    private static function getDailyStats(int $machineId, string $date): array
    {
        $stats = Db::table('machine_daily_stats')
            ->where('machine_id', $machineId)
            ->where('stats_date', $date)
            ->first();

        if (!$stats) {
            return [
                'bb_count' => 0,
                'rb_count' => 0,
                'total_open' => 0,
                'total_wash' => 0,
                'total_bet' => 0,
                'total_win' => 0,
            ];
        }

        return [
            'bb_count' => $stats->bb_count ?? 0,
            'rb_count' => $stats->rb_count ?? 0,
            'total_open' => $stats->total_open_point ?? 0,
            'total_wash' => $stats->total_wash_point ?? 0,
            'total_bet' => $stats->total_bet ?? 0,
            'total_win' => $stats->total_win ?? 0,
        ];
    }

    /**
     * 更新上分记录
     */
    public static function updateOpenPoint(int $machineId, float $point): void
    {
        $today = date('Y-m-d');
        $statsKey = "machine:{$machineId}:today_stats";

        // 更新缓存
        $stats = Cache::get($statsKey, []);
        $stats['total_open'] = bcadd($stats['total_open'] ?? 0, $point, 2);
        $expireTime = strtotime('today 23:59:59') - time();
        Cache::set($statsKey, $stats, max($expireTime, 60));

        // 更新数据库
        Db::table('machine_daily_stats')
            ->where('machine_id', $machineId)
            ->where('stats_date', $today)
            ->increment('total_open_point', $point, ['updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 更新下分记录
     */
    public static function updateWashPoint(int $machineId, float $point): void
    {
        $today = date('Y-m-d');
        $statsKey = "machine:{$machineId}:today_stats";

        // 更新缓存
        $stats = Cache::get($statsKey, []);
        $stats['total_wash'] = bcadd($stats['total_wash'] ?? 0, $point, 2);
        $expireTime = strtotime('today 23:59:59') - time();
        Cache::set($statsKey, $stats, max($expireTime, 60));

        // 更新数据库
        Db::table('machine_daily_stats')
            ->where('machine_id', $machineId)
            ->where('stats_date', $today)
            ->increment('total_wash_point', $point, ['updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 更新押注记录
     */
    public static function updateBet(int $machineId, float $betChange): void
    {
        if ($betChange <= 0) {
            return;
        }

        $today = date('Y-m-d');
        $statsKey = "machine:{$machineId}:today_stats";

        // 更新缓存
        $stats = Cache::get($statsKey, []);
        $stats['total_bet'] = bcadd($stats['total_bet'] ?? 0, $betChange, 2);
        $expireTime = strtotime('today 23:59:59') - time();
        Cache::set($statsKey, $stats, max($expireTime, 60));

        // 更新数据库
        Db::table('machine_daily_stats')
            ->where('machine_id', $machineId)
            ->where('stats_date', $today)
            ->increment('total_bet', $betChange, ['updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 更新得分记录
     */
    public static function updateWin(int $machineId, float $win): void
    {
        $today = date('Y-m-d');
        $statsKey = "machine:{$machineId}:today_stats";

        // 更新缓存
        $stats = Cache::get($statsKey, []);
        $stats['total_win'] = $win;
        $expireTime = strtotime('today 23:59:59') - time();
        Cache::set($statsKey, $stats, max($expireTime, 60));

        // 更新数据库
        Db::table('machine_daily_stats')
            ->where('machine_id', $machineId)
            ->where('stats_date', $today)
            ->update([
                'total_win' => $win,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * 推送当前转数更新
     */
    public static function pushTurnUpdate(int $machineId, float $turn): void
    {
        sendSocketMessage("machine-data-counter-{$machineId}", [
            'msg_type' => 'turn_update',
            'data' => [
                'current_turn' => round($turn / 3, 2),
            ],
        ]);
    }
}