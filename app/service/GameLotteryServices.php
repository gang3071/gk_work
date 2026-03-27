<?php

namespace app\service;

use app\model\GameExtend;
use app\model\GameLottery;
use app\model\Notice;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use support\Cache;
use support\Db;
use support\Log;
use Webman\Push\PushException;
use yzh52521\WebmanLock\Locker;

class GameLotteryServices
{
    // 缓存配置
    const CACHE_KEY_LOTTERY_POOL = 'game_pool_3';
    const CACHE_KEY_LOTTERY_LIST = 'game_lottery_list';
    const CACHE_KEY_BURST = 'game_lottery_burst:';
    const CACHE_TTL_LOTTERY = 300; // 5分钟

    // Redis 彩金累积键
    const REDIS_KEY_LOTTERY_AMOUNT = 'game_lottery_amount:';

    // 性能优化配置
    const BURST_CHECK_INTERVAL = 5;           // 爆彩检查间隔（秒）
    const REDIS_KEY_LAST_BURST_CHECK = 'game_lottery_last_burst_check:';
    const DB_SYNC_THRESHOLD = 10;            // 累积达到此金额后同步到数据库
    const DB_SYNC_INTERVAL = 1;               // 定期同步到数据库的间隔（秒）

    // 实时推送优化配置
    const PUSH_DEBOUNCE_INTERVAL = 1;         // 推送防抖间隔（秒）
    const REDIS_KEY_LAST_PUSH_TIME = 'game_lottery_last_push_time';
    const REDIS_KEY_LAST_PUSH_HASH = 'game_lottery_last_push_hash';

    // 统计数据键
    const REDIS_KEY_LOTTERY_STATS_TOTAL = 'game_lottery_stats:total:';      // 总开奖次数
    const REDIS_KEY_LOTTERY_STATS_WIN = 'game_lottery_stats:win:';          // 总中奖次数
    const REDIS_KEY_LOTTERY_STATS_DAILY_TOTAL = 'game_lottery_stats:daily:total:';  // 每日开奖次数
    const REDIS_KEY_LOTTERY_STATS_DAILY_WIN = 'game_lottery_stats:daily:win:';      // 每日中奖次数

    // 其他配置
    const MAX_BET_AMOUNT = 1000000000;           // 最大下注金额
    const BURST_DURATION_BUFFER = 3;          // 爆彩缓冲时间（分钟），用于Redis自动过期的兜底机制

    private Player $player;
    private $log;
    /**
     * @var Builder[]|Collection
     */
    private array|Collection $lotteryList;

    /**
     * 设置玩家数据
     * @param Player $player
     * @return GameLotteryServices
     */
    public function setPlayer(Player $player): GameLotteryServices
    {
        $this->player = $player;
        return $this;
    }

    /**
     * 实时推送彩池数据变化（带防抖和数据变化检测）
     * @return void
     */
    public static function pushLotteryPoolData(): void
    {
        try {
            $redis = \support\Redis::connection()->client();

            // 防抖检查：距离上次推送不足间隔时间则跳过
            $lastPushTime = $redis->get(self::REDIS_KEY_LAST_PUSH_TIME);
            if ($lastPushTime && (time() - $lastPushTime) < self::PUSH_DEBOUNCE_INTERVAL) {
                \support\Log::debug('电子游戏彩池实时推送防抖：距离上次推送时间过短，跳过本次推送', [
                    'last_push_time' => $lastPushTime,
                    'interval' => self::PUSH_DEBOUNCE_INTERVAL,
                ]);
                return;
            }

            // 获取彩金池数据
            $gameLotteryPool = self::getLotteryPool();

            // 构建消息数据
            $messageData = [
                'game_lottery_list' => self::formatGameLotteryPoolForPush($gameLotteryPool),
            ];

            // 数据变化检测：计算数据哈希值
            $currentHash = md5(json_encode($messageData));
            $lastHash = $redis->get(self::REDIS_KEY_LAST_PUSH_HASH);

            // 如果数据没有变化，跳过推送
            if ($currentHash === $lastHash) {
                \support\Log::debug('电子游戏彩池实时推送数据检测：数据无变化，跳过推送');
                return;
            }

            // 触发机台彩池的实时推送（包含电子游戏彩池）
            LotteryServices::pushLotteryPoolData();

            // 更新最后推送时间和数据哈希
            $redis->set(self::REDIS_KEY_LAST_PUSH_TIME, time());
            $redis->set(self::REDIS_KEY_LAST_PUSH_HASH, $currentHash);

            \support\Log::debug('电子游戏彩池实时推送成功', [
                'game_count' => count($messageData['game_lottery_list']),
            ]);
        } catch (\Throwable $e) {
            \support\Log::error('电子游戏彩池实时推送失败: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * 格式化电子游戏彩金池数据用于推送
     * @param array|null $gameLotteryPool
     * @return array
     */
    private static function formatGameLotteryPoolForPush(?array $gameLotteryPool): array
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

    /**
     * 获取彩金池
     * @return array|null
     */
    public static function getLotteryPool(): ?array
    {
        $pool = Cache::get(self::CACHE_KEY_LOTTERY_POOL);
        if (empty($pool)) {
            $pool = GameLottery::query()
                ->select([
                    'id',
                    'name',
                    'amount',
                    'max_pool_amount',
                    'burst_duration',
                ])
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->orderBy('sort', 'desc')
                ->get()
                ->toArray();

            Cache::set(self::CACHE_KEY_LOTTERY_POOL, $pool, self::CACHE_TTL_LOTTERY);
        }

        // 性能优化：从 Redis 获取实时累积金额（如果存在）
        try {
            $redis = \support\Redis::connection()->client();
            foreach ($pool as &$item) {
                $redisKey = self::REDIS_KEY_LOTTERY_AMOUNT . $item['id'];
                $redisAmount = $redis->get($redisKey);

                // 如果 Redis 中有累积金额，加到数据库金额上
                if ($redisAmount !== false && $redisAmount > 0) {
                    $item['amount'] = bcadd($item['amount'], $redisAmount, 4);
                }
            }
        } catch (\Exception $e) {
            // Redis 读取失败时，降级使用数据库金额
            \support\Log::error('从 Redis 获取彩金实时金额失败', ['error' => $e->getMessage()]);
        }

        return $pool;
    }

    /**
     * 清除彩金池缓存
     * @return bool
     */
    public static function clearLotteryPoolCache(): bool
    {
        return Cache::delete(self::CACHE_KEY_LOTTERY_POOL);
    }

    /**
     * 设置日志
     * @return GameLotteryServices
     */
    public function setLog(): GameLotteryServices
    {
        $this->log = Log::channel('game_lottery');
        return $this;
    }

    /**
     * 设置电子游戏彩金数据
     * @return $this
     */
    public function setLotteryList(): GameLotteryServices
    {
        $cachedList = Cache::get(self::CACHE_KEY_LOTTERY_LIST);

        if (empty($cachedList)) {
            $this->lotteryList = GameLottery::query()
                ->select([
                    'id',
                    'name',
                    'amount',
                    'status',
                    'sort',
                    'rate',
                    'double_status',
                    'double_amount',
                    'pool_ratio',
                    'win_ratio',
                    'base_bet_amount',
                    'max_amount',
                    'max_status',
                    'max_pool_amount',
                    'burst_status',
                    'burst_duration',
                    'burst_multiplier_config',
                    'burst_trigger_config',
                    'lottery_type',
                ])
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->orderBy('sort', 'desc')
                ->get();

            // 缓存为数组以减少序列化开销
            Cache::set(self::CACHE_KEY_LOTTERY_LIST, $this->lotteryList->toArray(), self::CACHE_TTL_LOTTERY);
        } else {
            // 从缓存的数组重建模型集合
            $this->lotteryList = GameLottery::query()->hydrate($cachedList);
        }

        return $this;
    }

    /**
     * 清除彩金列表缓存
     * @return bool
     */
    public static function clearLotteryListCache(): bool
    {
        return Cache::delete(self::CACHE_KEY_LOTTERY_LIST);
    }

    /**
     * 清除所有游戏彩金相关缓存
     * 包括彩金池缓存和彩金列表缓存
     * 适用于批量更新或管理后台配置变更时调用
     * @return void
     */
    public static function clearAllCache(): void
    {
        self::clearLotteryPoolCache();
        self::clearLotteryListCache();
    }

    /**
     * 实时中奖
     * @param float|int $bet
     * @param int $playGameRecordId
     * @return bool
     * @throws PushException
     * @throws Exception
     */
    public function checkLottery(float|int $bet = 0, int $playGameRecordId = 0): bool
    {
        // 输入验证
        if ($bet <= 0) {
            $this->log->warning('下注金额无效', ['bet' => $bet]);
            return false;
        }

        if ($bet > self::MAX_BET_AMOUNT) {
            $this->log->warning('下注金额超过最大限制', [
                'bet' => $bet,
                'max_allowed' => self::MAX_BET_AMOUNT
            ]);
            return false;
        }

        $lotteryList = $this->lotteryList;
        /** @var GameLottery $lottery */
        foreach ($lotteryList as $lottery) {
            // 检查是否应该处理这个彩金
            if (!$this->shouldCheckLottery($lottery, $bet)) {
                continue;
            }

            // 计算参与派彩次数
            $participateTimes = intval(floor($bet / $lottery->base_bet_amount));

            // 记录打码量检查通过
            $this->log->info('打码量检查通过，开始派彩检查:', [
                'lottery_id' => $lottery->id,
                'lottery_name' => $lottery->name,
                'bet' => $bet,
                'win_ratio' => $lottery->win_ratio,
                'base_bet_amount' => $lottery->base_bet_amount,
                'participate_times' => $participateTimes,
                'player_id' => $this->player->id,
                'uuid' => $this->player->uuid,
            ]);

            // 获取并处理爆彩状态
            $burstInfo = $this->getBurstInfo($lottery);

            // 处理派彩检查
            $this->processLotteryCheck($lottery, $bet, $participateTimes, $burstInfo, $playGameRecordId);
        }

        return true;
    }

    /**
     * 检查是否应该处理这个彩金
     * @param GameLottery $lottery
     * @param float|int $bet
     * @return bool
     */
    private function shouldCheckLottery(GameLottery $lottery, float|int $bet): bool
    {
        // 检查基础打码量
        if ($lottery->base_bet_amount <= 0) {
            $this->log->warning('彩金基础打码量配置错误:', [
                'lottery_id' => $lottery->id,
                'lottery_name' => $lottery->name,
                'base_bet_amount' => $lottery->base_bet_amount,
            ]);
            return false;
        }

        // 计算参与派彩次数
        $participateTimes = intval(floor($bet / $lottery->base_bet_amount));

        // 打码量不足
        if ($participateTimes <= 0) {
            $this->log->debug('打码量不足，跳过派彩检查:', [
                'lottery_id' => $lottery->id,
                'lottery_name' => $lottery->name,
                'bet' => $bet,
                'base_bet_amount' => $lottery->base_bet_amount,
                'participate_times' => $participateTimes,
            ]);
            return false;
        }

        return true;
    }

    /**
     * 获取爆彩信息
     * @param GameLottery $lottery
     * @return array
     */
    private function getBurstInfo(GameLottery $lottery): array
    {
        [$isBursting, $startTime, $elapsedSeconds] = $this->getBurstStatus($lottery->id);

        $burstMultiplier = 1.0;
        if ($isBursting) {
            $burstMultiplier = $this->calculateBurstMultiplier($lottery, $elapsedSeconds);

            // 检查爆彩是否已结束
            $totalSeconds = $lottery->burst_duration * 60;
            if ($elapsedSeconds >= $totalSeconds) {
                // 爆彩时间已结束
                $this->endBurst($lottery);
                $isBursting = false;
                $burstMultiplier = 1.0;
            }
        }

        return [
            'is_bursting' => $isBursting,
            'multiplier' => $burstMultiplier,
            'elapsed_seconds' => $elapsedSeconds,
        ];
    }

    /**
     * 结束爆彩
     * @param GameLottery $lottery
     * @return void
     */
    private function endBurst(GameLottery $lottery): void
    {
        $this->sendBurstGlobalNotice($lottery, 'end');
        $redis = \support\Redis::connection();
        $redis->del(self::CACHE_KEY_BURST . $lottery->id);
    }

    /**
     * 处理派彩检查
     * @param GameLottery $lottery
     * @param float|int $bet
     * @param int $participateTimes
     * @param array $burstInfo
     * @param int $playGameRecordId
     * @return void
     * @throws Exception
     * @throws PushException
     */
    private function processLotteryCheck(
        GameLottery $lottery,
        float|int   $bet,
        int         $participateTimes,
        array       $burstInfo,
        int         $playGameRecordId
    ): void
    {
        // 应用爆彩概率倍数到中奖检查
        $adjustedWinRatio = bcmul($lottery->win_ratio, $burstInfo['multiplier'], 8);

        // 记录理论检查次数（用于准确评估概率配置）
        // 即使中途中奖退出，也应该记录完整的检查机会数，这样统计才能反映真实概率
        $this->incrementLotteryStats($lottery->id, 'total', $participateTimes);

        // 循环检查多次派彩机会
        for ($i = 1; $i <= $participateTimes; $i++) {
            $service = new LotteryProbabilityService();
            $result = $service->checkSmart($adjustedWinRatio);

            // 计算彩金金额（包含rate、double、max逻辑）
            $isDoubled = false;
            $amount = $this->calculateBurstAmount($lottery, $isDoubled);

            // 彩金倍数标记
            $lotteryMultiple = $burstInfo['is_bursting'] ? intval($burstInfo['multiplier']) : 1;

            // 检查中奖条件
            if ($result && $amount > 0) {
                // 记录中奖次数统计
                $this->incrementLotteryStats($lottery->id, 'win', 1);

                // 获取统计数据用于日志
                $stats = $this->getLotteryStats($lottery->id);

                $this->log->info('🎉 派彩检查命中，玩家中奖!', [
                    'lottery_id' => $lottery->id,
                    'lottery_name' => $lottery->name,
                    'player_id' => $this->player->id,
                    'uuid' => $this->player->uuid,
                    'bet' => $bet,
                    'amount' => $amount,
                    'win_at_attempt' => $i,
                    'total_attempts' => $participateTimes,
                    'single_win_ratio' => $adjustedWinRatio,
                    'single_win_ratio_percent' => round(floatval($adjustedWinRatio) * 100, 4) . '%',
                    'is_doubled' => $isDoubled,
                    'is_bursting' => $burstInfo['is_bursting'],
                    'burst_multiplier' => $burstInfo['multiplier'],
                    // 统计数据
                    'stats_total_checks' => $stats['total'],
                    'stats_total_wins' => $stats['win'],
                    'stats_actual_win_rate' => $stats['win_rate'],
                    'stats_daily_checks' => $stats['daily_total'],
                    'stats_daily_wins' => $stats['daily_win'],
                    'stats_daily_win_rate' => $stats['daily_win_rate'],
                ]);

                $this->tryDistributeLottery($lottery, $amount, $lotteryMultiple, $bet, $playGameRecordId, $burstInfo, $i, $participateTimes, $isDoubled);

                // 跳出当前彩金的检查循环，继续检查下一个彩金
                break;
            }
        }
    }

    /**
     * 增加彩金统计数据
     * @param int $lotteryId
     * @param string $type total|win
     * @param int $count
     * @return void
     */
    private function incrementLotteryStats(int $lotteryId, string $type, int $count = 1): void
    {
        try {
            $redis = \support\Redis::connection()->client();
            $today = date('Y-m-d');

            if ($type === 'total') {
                // 总开奖次数
                $newTotal = $redis->incrBy(self::REDIS_KEY_LOTTERY_STATS_TOTAL . $lotteryId, $count);
                // 每日开奖次数（24小时过期）
                $dailyKey = self::REDIS_KEY_LOTTERY_STATS_DAILY_TOTAL . $lotteryId . ':' . $today;
                $newDailyTotal = $redis->incrBy($dailyKey, $count);
                $redis->expire($dailyKey, 86400 * 2); // 保留2天

                // 获取彩金名称
                $lotteryName = $this->getLotteryName($lotteryId);

                // 记录日志
                $this->log->info('【彩金统计】开奖次数增加', [
                    'lottery_id' => $lotteryId,
                    'lottery_name' => $lotteryName,
                    'type' => '开奖检查',
                    'increment' => $count,
                    'total_checks' => $newTotal,
                    'daily_checks' => $newDailyTotal,
                    'date' => $today,
                ]);
            } elseif ($type === 'win') {
                // 总中奖次数
                $newWin = $redis->incrBy(self::REDIS_KEY_LOTTERY_STATS_WIN . $lotteryId, $count);
                // 每日中奖次数（24小时过期）
                $dailyKey = self::REDIS_KEY_LOTTERY_STATS_DAILY_WIN . $lotteryId . ':' . $today;
                $newDailyWin = $redis->incrBy($dailyKey, $count);
                $redis->expire($dailyKey, 86400 * 2); // 保留2天

                // 获取累计开奖次数用于计算中奖率
                $totalChecks = (int)$redis->get(self::REDIS_KEY_LOTTERY_STATS_TOTAL . $lotteryId) ?: 0;
                $winRate = $totalChecks > 0 ? round(($newWin / $totalChecks) * 100, 4) : 0;

                // 获取彩金名称
                $lotteryName = $this->getLotteryName($lotteryId);

                // 记录日志
                $this->log->info('【彩金统计】中奖次数增加', [
                    'lottery_id' => $lotteryId,
                    'lottery_name' => $lotteryName,
                    'type' => '中奖',
                    'increment' => $count,
                    'total_wins' => $newWin,
                    'total_checks' => $totalChecks,
                    'win_rate' => $winRate . '%',
                    'daily_wins' => $newDailyWin,
                    'date' => $today,
                ]);
            }
        } catch (\Exception $e) {
            $this->log->error('记录彩金统计失败', [
                'lottery_id' => $lotteryId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取彩金名称
     * @param int $lotteryId
     * @return string
     */
    private function getLotteryName(int $lotteryId): string
    {
        try {
            if (empty($this->lotteryList)) {
                $this->setLotteryList();
            }

            foreach ($this->lotteryList as $lottery) {
                if ($lottery->id === $lotteryId) {
                    return $lottery->name;
                }
            }

            return '未知彩金';
        } catch (\Exception) {
            return '彩金ID:' . $lotteryId;
        }
    }

    /**
     * 获取彩金统计数据
     * @param int $lotteryId
     * @return array
     */
    private function getLotteryStats(int $lotteryId): array
    {
        try {
            $redis = \support\Redis::connection()->client();
            $today = date('Y-m-d');

            // 获取总统计
            $totalChecks = (int)$redis->get(self::REDIS_KEY_LOTTERY_STATS_TOTAL . $lotteryId) ?: 0;
            $totalWins = (int)$redis->get(self::REDIS_KEY_LOTTERY_STATS_WIN . $lotteryId) ?: 0;

            // 获取每日统计
            $dailyTotalKey = self::REDIS_KEY_LOTTERY_STATS_DAILY_TOTAL . $lotteryId . ':' . $today;
            $dailyWinKey = self::REDIS_KEY_LOTTERY_STATS_DAILY_WIN . $lotteryId . ':' . $today;
            $dailyChecks = (int)$redis->get($dailyTotalKey) ?: 0;
            $dailyWins = (int)$redis->get($dailyWinKey) ?: 0;

            // 计算中奖率
            $winRate = $totalChecks > 0 ? round(($totalWins / $totalChecks) * 100, 4) . '%' : '0%';
            $dailyWinRate = $dailyChecks > 0 ? round(($dailyWins / $dailyChecks) * 100, 4) . '%' : '0%';

            return [
                'total' => $totalChecks,
                'win' => $totalWins,
                'win_rate' => $winRate,
                'daily_total' => $dailyChecks,
                'daily_win' => $dailyWins,
                'daily_win_rate' => $dailyWinRate,
            ];
        } catch (\Exception $e) {
            $this->log->error('获取彩金统计失败', [
                'lottery_id' => $lotteryId,
                'error' => $e->getMessage(),
            ]);
            return [
                'total' => 0,
                'win' => 0,
                'win_rate' => '0%',
                'daily_total' => 0,
                'daily_win' => 0,
                'daily_win_rate' => '0%',
            ];
        }
    }

    /**
     * 尝试派发彩金
     * @param GameLottery $lottery
     * @param int $amount
     * @param int $lotteryMultiple
     * @param float|int $bet
     * @param int $playGameRecordId
     * @param array $burstInfo
     * @param int $attemptIndex
     * @param int $totalAttempts
     * @param bool $isDoubled
     * @return bool
     * @throws Exception
     * @throws PushException
     */
    private function tryDistributeLottery(
        GameLottery $lottery,
        int         $amount,
        int         $lotteryMultiple,
        float|int   $bet,
        int         $playGameRecordId,
        array       $burstInfo,
        int         $attemptIndex,
        int         $totalAttempts,
        bool        $isDoubled = false
    ): bool
    {
        // 增加业务锁
        $actionLockerKey = 'game_lottery_pool_random_locker_' . $lottery->id;
        $lock = Locker::lock($actionLockerKey, 2, true);
        if (!$lock->acquire()) {
            return false;
        }

        DB::beginTransaction();
        try {
            // 先同步 Redis 累积金额到数据库（避免数据不一致）
            $redis = \support\Redis::connection()->client();
            $redisKey = self::REDIS_KEY_LOTTERY_AMOUNT . $lottery->id;
            $accumulatedAmount = $redis->get($redisKey);

            if ($accumulatedAmount && $accumulatedAmount > 0) {
                // 有未同步的累积金额，先同步到数据库
                $lottery->amount = bcadd($lottery->amount, $accumulatedAmount, 4);
                $lottery->save();
                $redis->del($redisKey);  // 清除已同步的累积

                $this->log->info('派发前同步Redis累积金额到数据库', [
                    'lottery_id' => $lottery->id,
                    'accumulated' => $accumulatedAmount,
                    'new_amount' => $lottery->amount,
                ]);
            }

            // 重新加载彩金数据，检查余额
            $lottery->refresh();
            if ($lottery->amount < $amount) {
                $this->log->error('彩金池余额不足', [
                    'lottery_id' => $lottery->id,
                    'required' => $amount,
                    'available' => $lottery->amount,
                ]);
                DB::rollback();
                return false;
            }

            // 创建派彩记录
            $playerLotteryRecord = $this->createLotteryRecord($lottery, $amount, $lotteryMultiple, $bet, $playGameRecordId, $isDoubled);

            // 记录中奖日志
            $this->logWinning($lottery, $amount, $burstInfo, $attemptIndex, $totalAttempts, $isDoubled);

            // 发送站内信
            $notice = $this->sendNotice($playerLotteryRecord->id, $playerLotteryRecord->lottery_name);

            // 更新玩家钱包（加彩金金额）
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
            if (!$machineWallet) {
                $this->log->error('电子游戏彩金派发失败：玩家钱包不存在', [
                    'player_id' => $this->player->id,
                ]);
                DB::rollback();
                return false;
            }

            $beforeAmount = $machineWallet->money;
            $machineWallet->money = bcadd($machineWallet->money, $amount, 2);
            $machineWallet->save();

            // 创建交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $this->player->id;
            $playerDeliveryRecord->department_id = $this->player->department_id;
            $playerDeliveryRecord->target = $playerLotteryRecord->getTable();
            $playerDeliveryRecord->target_id = $playerLotteryRecord->id;
            $playerDeliveryRecord->platform_id = PlayerPlatformCash::PLATFORM_SELF;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_LOTTERY;
            $playerDeliveryRecord->source = 'lottery_game';
            $playerDeliveryRecord->amount = $amount;
            $playerDeliveryRecord->amount_before = $beforeAmount;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = '';
            $playerDeliveryRecord->remark = '电子游戏彩金派彩';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            // 扣减彩金池（根据rate计算实际扣减金额）
            $rate = $lottery->rate > 0 ? $lottery->rate : 100;
            $baseDeductAmount = bcmul($lottery->amount, bcdiv($rate, 100, 4), 4);
            $lottery->amount = bcsub($lottery->amount, $baseDeductAmount, 4);

            // 派彩成功后补充到保底金额（如果启用了自动补充）
            if ($lottery->auto_refill_status == 1 && $lottery->auto_refill_amount > 0) {
                $beforeRefillAmount = $lottery->amount;

                // 只有当彩池低于保底金额时才补充
                if ($lottery->amount < $lottery->auto_refill_amount) {
                    $refillAmount = bcsub($lottery->auto_refill_amount, $lottery->amount, 4);
                    $lottery->amount = $lottery->auto_refill_amount;

                    // 记录派彩后补充日志
                    $this->log->info('彩金池派彩后自动补充到保底金额:', [
                        'lottery_id' => $lottery->id,
                        'lottery_name' => $lottery->name,
                        'before_refill_amount' => $beforeRefillAmount,
                        'target_amount' => $lottery->auto_refill_amount,
                        'refill_amount' => $refillAmount,
                        'after_refill_amount' => $lottery->amount,
                        'deduct_amount' => $baseDeductAmount,
                        'player_id' => $this->player->id,
                        'uuid' => $this->player->uuid,
                        'trigger_time' => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            // 更新彩金池的最后中奖信息和中奖次数
            $lottery->last_player_id = $this->player->id;
            $lottery->last_player_name = $this->player->name;
            $lottery->last_award_amount = $amount;
            $lottery->lottery_times = $lottery->lottery_times + 1;

            $lottery->save();

            DB::commit();

            // 清除彩金缓存（事务提交后）
            self::clearLotteryPoolCache();
            self::clearLotteryListCache();

            // 实时推送彩池数据变化
            self::pushLotteryPoolData();

            // 发送派彩和通知消息
            $this->sendWinningMessages($playerLotteryRecord, $lottery, $notice, $burstInfo, $isDoubled);

            return true;
        } catch (\Exception $e) {
            DB::rollback();
            $this->log->error('派发彩金失败', [
                'error' => $e->getMessage(),
                'lottery_id' => $lottery->id,
            ]);
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 创建彩金记录
     * @param GameLottery $lottery
     * @param int $amount
     * @param int $lotteryMultiple
     * @param float|int $bet
     * @param int $playGameRecordId
     * @param bool $isDoubled
     * @return PlayerLotteryRecord
     */
    private function createLotteryRecord(
        GameLottery $lottery,
        int         $amount,
        int         $lotteryMultiple,
        float|int   $bet,
        int         $playGameRecordId,
        bool        $isDoubled = false
    ): PlayerLotteryRecord
    {
        $playerLotteryRecord = new PlayerLotteryRecord();
        $playerLotteryRecord->player_id = $this->player->id;
        $playerLotteryRecord->uuid = $this->player->uuid;
        $playerLotteryRecord->player_phone = $this->player->phone ?? '';
        $playerLotteryRecord->player_name = $this->player->name ?? '';
        $playerLotteryRecord->is_coin = $this->player->is_coin;
        $playerLotteryRecord->is_promoter = $this->player->is_promoter;
        $playerLotteryRecord->is_test = $this->player->is_test;
        $playerLotteryRecord->department_id = $this->player->department_id;
        $playerLotteryRecord->source = PlayerLotteryRecord::SOURCE_GAME;
        $playerLotteryRecord->bet = $bet;
        $playerLotteryRecord->play_game_record_id = $playGameRecordId;
        $playerLotteryRecord->amount = $amount;
        $playerLotteryRecord->is_max = $amount == $lottery->max_amount ? 1 : 0;
        $playerLotteryRecord->lottery_id = $lottery->id;
        $playerLotteryRecord->lottery_name = $lottery->name;
        $playerLotteryRecord->lottery_pool_amount = $lottery->amount;
        // 记录rate信息（如果是双倍则标记为2倍rate）
        if ($isDoubled) {
            $playerLotteryRecord->lottery_rate = $lottery->rate * 2;
        } else {
            $playerLotteryRecord->lottery_rate = $lottery->rate;
        }
        $playerLotteryRecord->lottery_type = $lottery->lottery_type;
        $playerLotteryRecord->lottery_multiple = $lotteryMultiple;
        $playerLotteryRecord->lottery_sort = $lottery->sort;
        $playerLotteryRecord->cate_rate = $lottery->rate;
        $playerLotteryRecord->status = PlayerLotteryRecord::STATUS_COMPLETE;
        $playerLotteryRecord->save();

        return $playerLotteryRecord;
    }

    /**
     * 记录中奖日志
     * @param GameLottery $lottery
     * @param int $amount
     * @param array $burstInfo
     * @param int $attemptIndex
     * @param int $totalAttempts
     * @param bool $isDoubled
     * @return void
     */
    private function logWinning(
        GameLottery $lottery,
        int         $amount,
        array       $burstInfo,
        int         $attemptIndex,
        int         $totalAttempts,
        bool        $isDoubled = false
    ): void
    {
        if ($burstInfo['is_bursting']) {
            $this->log->info('【爆彩中奖】玩家在爆彩期间中奖:', [
                'lottery_id' => $lottery->id,
                'lottery_name' => $lottery->name,
                'player_id' => $this->player->id,
                'uuid' => $this->player->uuid,
                'burst_multiplier' => $burstInfo['multiplier'] . 'x',
                'burst_amount' => $amount,
                'lottery_rate' => $lottery->rate,
                'is_doubled' => $isDoubled,
                'pool_amount' => $lottery->amount,
                'elapsed_seconds' => $burstInfo['elapsed_seconds'],
                'burst_duration' => $lottery->burst_duration * 60 . '秒',
                'win_at_attempt' => $attemptIndex,
                'total_attempts' => $totalAttempts,
            ]);

            // 发送爆彩中奖全局通知
            $this->sendBurstGlobalNotice($lottery, 'win', [
                'amount' => $amount,
                'player_name' => $this->player->name ?? $this->player->uuid,
                'is_doubled' => $isDoubled,
            ]);
        } else {
            $this->log->info('【普通中奖】玩家中奖:', [
                'lottery_id' => $lottery->id,
                'lottery_name' => $lottery->name,
                'player_id' => $this->player->id,
                'uuid' => $this->player->uuid,
                'amount' => $amount,
                'lottery_rate' => $lottery->rate,
                'is_doubled' => $isDoubled,
                'pool_amount' => $lottery->amount,
                'win_at_attempt' => $attemptIndex,
                'total_attempts' => $totalAttempts,
            ]);
        }
    }

    /**
     * 发送中奖消息
     * @param PlayerLotteryRecord $record
     * @param GameLottery $lottery
     * @param Notice $notice
     * @param array $burstInfo
     * @param bool $isDoubled
     * @return void
     * @throws PushException
     */
    private function sendWinningMessages(
        PlayerLotteryRecord $record,
        GameLottery         $lottery,
        Notice              $notice,
        array               $burstInfo,
        bool                $isDoubled = false
    ): void
    {
        // 获取游戏名称或机台信息
        $gameName = '';
        $machineInfo = '';

        if ($record->source == PlayerLotteryRecord::SOURCE_GAME) {
            // 电子游戏：获取游戏名称
            if ($record->play_game_record_id) {
                $playGameRecord = PlayGameRecord::query()
                    ->where('id', $record->play_game_record_id)
                    ->first();

                if ($playGameRecord) {
                    $gameExtend = GameExtend::query()
                        ->where('platform_id', $playGameRecord->platform_id)
                        ->where('code', $playGameRecord->game_code)
                        ->first();

                    $gameName = $gameExtend->name ?? '';
                }
            }
        } elseif ($record->source == PlayerLotteryRecord::SOURCE_MACHINE) {
            // 实体机台：获取机台名称和编号
            $machineInfo = $record->machine_name . '(' . $record->machine_code . ')';
        }

        // 发送派彩消息（给中奖玩家）
        sendSocketMessage('player-' . $this->player->id, [
            'msg_type' => 'game_player_lottery_allow',
            'player_id' => $record->player_id,
            'has_win' => 1,
            'lottery_record_id' => $record->id,
            'lottery_id' => $record->lottery_id,
            'lottery_name' => $record->lottery_name,
            'lottery_sort' => $lottery->sort,
            'lottery_type' => $lottery->lottery_type,
            'amount' => $record->amount,
            'lottery_pool_amount' => $lottery->amount,
            'lottery_rate' => $record->lottery_rate,
            'is_doubled' => $isDoubled ? 1 : 0,
            'lottery_multiple' => $record->lottery_multiple,
            'is_burst' => $burstInfo['is_bursting'] ? 1 : 0,
            'burst_multiplier' => $burstInfo['multiplier'],
            'game_name' => $gameName,
            'machine_info' => $machineInfo,
            'source' => $record->source,
            'next_lottery' => []
        ]);

        // 发送站内消息（给中奖玩家）
        sendSocketMessage('player-' . $this->player->id, [
            'msg_type' => 'player_notice',
            'player_id' => $this->player->id,
            'notice_type' => Notice::TYPE_LOTTERY,
            'notice_title' => $notice->title,
            'notice_content' => $notice->content,
            'amount' => $record->amount,
            'lottery_rate' => $record->lottery_rate,
            'is_doubled' => $isDoubled ? 1 : 0,
            'game_type' => $record->game_type,
            'lottery_multiple' => $record->lottery_multiple,
            'is_burst' => $burstInfo['is_bursting'] ? 1 : 0,
            'game_name' => $gameName,
            'machine_info' => $machineInfo,
            'source' => $record->source,
            'notice_num' => Notice::query()->where('player_id', $this->player->id)->where('status', 0)->count('*')
        ]);

        // 构建广播内容
        $doubleText = $isDoubled ? '【雙倍】' : '';
        if ($record->source == PlayerLotteryRecord::SOURCE_GAME) {
            // 电子游戏
            $contentText = sprintf(
                '恭喜玩家在電子遊戲%s %s 中贏得 %s%d 彩金！',
                $gameName ? '【' . $gameName . '】' : '',
                $lottery->name,
                $doubleText,
                $record->amount
            );
        } else {
            // 实体机台
            $contentText = sprintf(
                '恭喜玩家在機台 %s %s 中贏得 %s%d 彩金！',
                $machineInfo,
                $lottery->name,
                $doubleText,
                $record->amount
            );
        }

        // 发送全频道广播
        $broadcastMessage = [
            'msg_type' => 'game_lottery_win_broadcast',
            'lottery_id' => $lottery->id,
            'lottery_name' => $lottery->name,
            'lottery_type' => $lottery->lottery_type,
            'player_id' => $this->player->id,
            'player_name' => $this->player->name ?? $this->player->uuid,
            'player_uuid' => $this->player->uuid,
            'amount' => $record->amount,
            'lottery_pool_amount' => $lottery->amount,
            'is_burst' => $burstInfo['is_bursting'] ? 1 : 0,
            'burst_multiplier' => $burstInfo['multiplier'],
            'is_doubled' => $isDoubled ? 1 : 0,
            'lottery_rate' => $record->lottery_rate,
            'source' => $record->source,
            'game_name' => $gameName,
            'machine_info' => $machineInfo,
            'title' => '🎊 恭喜玩家中獎！',
            'content' => $contentText,
        ];

        // 发送到广播频道
        sendSocketMessage('broadcast', $broadcastMessage);

        // 发送到彩池频道
        sendSocketMessage('group-lottery-pool', $broadcastMessage);
    }

    /**
     * 发送消息
     * @param $recordId
     * @param $lotteryName
     * @return Notice
     */
    public function sendNotice($recordId, $lotteryName): Notice
    {
        // 发送站内信
        $notice = new Notice();
        $notice->department_id = $this->player->department_id;
        $notice->player_id = $this->player->id;
        $notice->source_id = $recordId;
        $notice->type = Notice::TYPE_LOTTERY;
        $notice->receiver = Notice::RECEIVER_PLAYER;
        $notice->is_private = 1;
        $notice->title = '彩金派彩';
        $notice->content = '恭喜您在电子游戏中获得' . $lotteryName . '的彩金獎勵彩金金額';
        $notice->save();

        return $notice;
    }

    /**
     * 获取彩金的爆彩状态
     * @param int $lotteryId
     * @return array [isBursting, startTime, elapsedSeconds]
     */
    private function getBurstStatus(int $lotteryId): array
    {
        $redis = \support\Redis::connection();
        $key = self::CACHE_KEY_BURST . $lotteryId;
        $startTime = $redis->get($key);

        if (!$startTime) {
            return [false, null, 0];
        }

        $startTime = intval($startTime);
        $currentTime = time();
        $elapsedSeconds = $currentTime - $startTime;

        return [true, $startTime, $elapsedSeconds];
    }

    /**
     * 开启爆彩状态
     * @param GameLottery $lottery
     * @return bool
     */
    private function startBurst(GameLottery $lottery): bool
    {
        $redis = \support\Redis::connection();
        $key = self::CACHE_KEY_BURST . $lottery->id;
        $currentTime = time();

        // 计算彩池比例（使用最大彩池金额）
        $poolPercentage = ($lottery->amount / $lottery->max_pool_amount) * 100;

        // 设置爆彩开始时间，过期时间为爆彩持续时长+缓冲时间
        $expireSeconds = ($lottery->burst_duration + self::BURST_DURATION_BUFFER) * 60;
        $redis->setex($key, $expireSeconds, $currentTime);

        // 从数据库配置读取爆彩倍数配置，用于日志记录
        $multiplierConfig = $lottery->getBurstMultiplierConfig();

        // 记录爆彩开始日志
        $this->log->info('【爆彩开启】彩金池触发爆彩（概率性触发）:', [
            'lottery_id' => $lottery->id,
            'lottery_name' => $lottery->name,
            'pool_amount' => $lottery->amount,
            'max_pool_amount' => $lottery->max_pool_amount,
            'pool_percentage' => round($poolPercentage, 2) . '%',
            'trigger_probability' => $this->getBurstTriggerProbability($lottery, $poolPercentage) . '%',
            'burst_duration' => $lottery->burst_duration . '分钟',
            'start_time' => date('Y-m-d H:i:s', $currentTime),
            'initial_multiplier' => $multiplierConfig['initial'] . 'x',
            'max_multiplier' => $multiplierConfig['final'] . 'x',
        ]);

        // 发送全局通知：爆彩开启
        $this->sendBurstGlobalNotice($lottery, 'start');

        return true;
    }

    /**
     * 根据彩池比例计算爆彩触发概率
     * 彩池越满，触发概率越高，采用渐进式概率增长
     * @param GameLottery $lottery
     * @param float $poolPercentage 当前彩池占最大彩池的百分比
     * @return float 返回触发概率（0-100）
     */
    private function getBurstTriggerProbability(GameLottery $lottery, float $poolPercentage): float
    {
        // 从数据库配置读取爆彩触发概率
        $triggerConfig = $lottery->getBurstTriggerConfig();

        // 根据不同的彩池比例阶段，返回不同的触发概率
        if ($poolPercentage >= 95) {
            return $triggerConfig['95'];
        } elseif ($poolPercentage >= 90) {
            return $triggerConfig['90'];
        } elseif ($poolPercentage >= 85) {
            return $triggerConfig['85'];
        } elseif ($poolPercentage >= 80) {
            return $triggerConfig['80'];
        } elseif ($poolPercentage >= 75) {
            return $triggerConfig['75'];
        } elseif ($poolPercentage >= 70) {
            return $triggerConfig['70'];
        } elseif ($poolPercentage >= 65) {
            return $triggerConfig['65'];
        } elseif ($poolPercentage >= 60) {
            return $triggerConfig['60'];
        } elseif ($poolPercentage >= 50) {
            return $triggerConfig['50'];
        } elseif ($poolPercentage >= 40) {
            return $triggerConfig['40'];
        } elseif ($poolPercentage >= 30) {
            return $triggerConfig['30'];
        } elseif ($poolPercentage >= 20) {
            return $triggerConfig['20'];
        } else {
            return 0.0;  // 20%以下彩池不触发爆彩
        }
    }

    /**
     * 检查并可能触发爆彩（概率性触发）
     * @param GameLottery $lottery
     * @return void
     */
    private function checkAndTriggerBurst(GameLottery $lottery): void
    {
        // 如果未开启爆彩功能或未设置最大彩池金额，则跳过
        if ($lottery->burst_status != 1 || $lottery->max_pool_amount <= 0) {
            return;
        }

        // 检查是否已经在爆彩中
        [$isBursting, ,] = $this->getBurstStatus($lottery->id);
        if ($isBursting) {
            return;
        }

        // 计算当前彩池占最大彩池的百分比
        $poolPercentage = ($lottery->amount / $lottery->max_pool_amount) * 100;

        // 获取当前彩池比例对应的触发概率
        $triggerProbability = $this->getBurstTriggerProbability($lottery, $poolPercentage);

        // 如果没有触发概率（彩池比例过低），则跳过
        if ($triggerProbability <= 0) {
            return;
        }

        // 概率检查：生成随机数判断是否触发
        $randomNumber = mt_rand(1, 10000) / 100; // 生成 0.01 到 100.00 的随机数（精确到小数点后2位）

        $this->log->debug('爆彩概率检查:', [
            'lottery_id' => $lottery->id,
            'lottery_name' => $lottery->name,
            'pool_amount' => $lottery->amount,
            'max_pool_amount' => $lottery->max_pool_amount,
            'pool_percentage' => round($poolPercentage, 2) . '%',
            'trigger_probability' => $triggerProbability . '%',
            'random_number' => $randomNumber,
            'will_trigger' => $randomNumber <= $triggerProbability ? 'YES' : 'NO',
        ]);

        // 如果随机数小于等于触发概率，则触发爆彩
        if ($randomNumber <= $triggerProbability) {
            $this->startBurst($lottery);
        }
    }

    /**
     * 带防抖机制的爆彩检查（性能优化版本）
     * 避免每次累积都检查，减少性能开销
     * @param GameLottery $lottery
     * @return void
     */
    private function checkAndTriggerBurstWithDebounce(GameLottery $lottery): void
    {
        try {
            // 检查距离上次检查的时间，实现防抖
            $redis = \support\Redis::connection()->client();
            $lastCheckKey = self::REDIS_KEY_LAST_BURST_CHECK . $lottery->id;
            $lastCheck = $redis->get($lastCheckKey);

            // 如果距离上次检查不足设定的间隔时间，则跳过本次检查
            if ($lastCheck && (time() - $lastCheck) < self::BURST_CHECK_INTERVAL) {
                return;
            }

            // 更新最后检查时间
            $redis->set($lastCheckKey, time());

            // 执行实际的爆彩检查
            $this->checkAndTriggerBurst($lottery);

        } catch (\Exception $e) {
            $this->log->error('防抖爆彩检查失败:', [
                'lottery_id' => $lottery->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 计算爆彩概率倍数
     * 根据爆彩剩余时间，渐进式提升中奖概率
     * 倍数已大幅提高，确保爆彩期间更容易中奖
     * @param GameLottery $lottery
     * @param int $elapsedSeconds 已经过的秒数
     * @return float
     */
    private function calculateBurstMultiplier(GameLottery $lottery, int $elapsedSeconds): float
    {
        $totalSeconds = $lottery->burst_duration * 60;
        $remainingSeconds = $totalSeconds - $elapsedSeconds;

        // 如果爆彩已结束，返回正常概率
        if ($remainingSeconds <= 0) {
            return 1.0;
        }

        // 计算剩余时间百分比
        $remainingPercentage = ($remainingSeconds / $totalSeconds) * 100;

        // 从数据库配置读取爆彩倍数
        $multiplierConfig = $lottery->getBurstMultiplierConfig();

        // 渐进式提升：剩余时间越少，概率倍数越高
        if ($remainingPercentage <= 10) {
            return $multiplierConfig['final'];
        } elseif ($remainingPercentage <= 30) {
            return $multiplierConfig['stage_4'];
        } elseif ($remainingPercentage <= 50) {
            return $multiplierConfig['stage_3'];
        } elseif ($remainingPercentage <= 70) {
            return $multiplierConfig['stage_2'];
        } else {
            return $multiplierConfig['initial'];
        }
    }

    /**
     * 计算派奖金额
     * @param GameLottery $lottery
     * @param bool $isDoubled 是否触发双倍（引用参数）
     * @return int
     */
    private function calculateBurstAmount(GameLottery $lottery, &$isDoubled = false): int
    {
        // 1. 根据rate计算派彩金额（默认100%全派）
        $rate = $lottery->rate > 0 ? $lottery->rate : 100;
        $amount = bcmul($lottery->amount, bcdiv($rate, 100, 4), 2);

        // 2. 检查是否应用双倍逻辑
        $isDoubled = false;
        if ($this->shouldApplyDouble($lottery)) {
            $amount = bcmul($amount, 2, 2);
            $isDoubled = true;
        }

        // 3. 应用最大金额限制（双倍后也不能超过）
        if ($lottery->max_status == 1) {
            if ($lottery->max_amount > 0 && $amount > $lottery->max_amount) {
                $amount = floatval($lottery->max_amount);
            }
        }

        return floatval($amount);
    }

    /**
     * 检查是否应用双倍逻辑
     * @param GameLottery $lottery
     * @return bool
     */
    private function shouldApplyDouble(GameLottery $lottery): bool
    {
        // 检查双倍状态是否开启
        if ($lottery->double_status != 1) {
            return false;
        }

        // 检查彩金池金额是否达到双倍开启金额
        if ($lottery->double_amount <= 0) {
            return false;
        }

        if ($lottery->amount < $lottery->double_amount) {
            return false;
        }

        $this->log->info('双倍逻辑触发:', [
            'lottery_id' => $lottery->id,
            'lottery_name' => $lottery->name,
            'amount' => $lottery->amount,
            'double_amount' => $lottery->double_amount,
            'double_status' => $lottery->double_status,
            'rate' => $lottery->rate,
        ]);

        return true;
    }

    /**
     * 发送爆彩全局通知
     * @param GameLottery $lottery
     * @param string $type start|win|end
     * @param array $extraData
     * @return void
     */
    private function sendBurstGlobalNotice(GameLottery $lottery, string $type, array $extraData = []): void
    {
        try {
            $message = [];
            $message['msg_type'] = 'game_lottery_burst_notice';
            $message['lottery_id'] = $lottery->id;
            $message['lottery_name'] = $lottery->name;
            $message['burst_type'] = $type;

            if ($type === 'start') {
                // 爆彩开启通知
                $message['title'] = '🎉 彩金池爆彩开启！';
                $message['content'] = sprintf(
                    '%s 爆彩活动正式开启！持续时间：%d分钟',
                    $lottery->name,
                    $lottery->burst_duration
                );
                $message['pool_amount'] = $lottery->amount;
            } elseif ($type === 'win') {
                // 有玩家中奖通知
                $isDoubled = $extraData['is_doubled'] ?? false;
                $doubleText = $isDoubled ? '【双倍】' : '';
                $message['title'] = '🎊 恭喜玩家中得爆彩大奖！';
                $message['content'] = sprintf(
                    '恭喜玩家在 %s 爆彩活动中赢得 %s%d 彩金！',
                    $lottery->name,
                    $doubleText,
                    $extraData['amount'] ?? 0
                );
                $message['amount'] = $extraData['amount'] ?? 0;
                $message['is_doubled'] = $isDoubled ? 1 : 0;
                $message['player_name'] = $extraData['player_name'] ?? '';
            } elseif ($type === 'end') {
                // 爆彩结束通知
                $message['title'] = '⏰ 爆彩活动结束';
                $message['content'] = sprintf(
                    '%s 爆彩活动已结束，感谢参与！',
                    $lottery->name
                );
            }

            // 发送到全局频道
            sendSocketMessage('broadcast', $message);
        } catch (\Exception $e) {
            $this->log->error('发送爆彩全局通知失败:', [
                'error' => $e->getMessage(),
                'lottery_id' => $lottery->id,
            ]);
        }
    }

    /**
     * 累积彩池
     * @param $bet
     * @return GameLotteryServices
     * @throws Exception
     */
    public function addLotteryPool($bet): GameLotteryServices
    {
        if ($bet <= 0) {
            throw new Exception('压注金额错误');
        }
        $lotteryList = $this->lotteryList;
        /** @var GameLottery $lottery */
        foreach ($lotteryList as $lottery) {
            // 检查是否达到最大彩池限制（最大彩池必须设置）
            if ($lottery->max_pool_amount > 0 && $lottery->amount >= $lottery->max_pool_amount) {
                $this->log->info('彩金池累计已达最大彩池上限:', [
                    'lottery_id' => $lottery->id,
                    'name' => $lottery->name,
                    'amount' => $lottery->amount,
                    'max_pool_amount' => $lottery->max_pool_amount,
                ]);
                continue;
            }
            $addAmount = bcmul($bet, bcdiv($lottery->pool_ratio, 100, 4), 4);
            if ($addAmount <= 0) {
                $this->log->info('彩金池累计为 0');
                continue;
            }

            // 累加前检查保底金额：如果启用了保底金额且当前彩池低于保底金额，先补充到保底金额
            if ($lottery->auto_refill_status == 1 && $lottery->auto_refill_amount > 0) {
                if ($lottery->amount < $lottery->auto_refill_amount) {
                    $refillAmount = bcsub($lottery->auto_refill_amount, $lottery->amount, 4);
                    $beforeRefillAmount = $lottery->amount;
                    $lottery->amount = $lottery->auto_refill_amount;

                    // 记录累加前补充日志
                    $this->log->info('彩金池累加前自动补充到保底金额:', [
                        'lottery_id' => $lottery->id,
                        'lottery_name' => $lottery->name,
                        'before_refill_amount' => $beforeRefillAmount,
                        'target_amount' => $lottery->auto_refill_amount,
                        'refill_amount' => $refillAmount,
                        'after_refill_amount' => $lottery->amount,
                        'player_id' => $this->player->id,
                        'uuid' => $this->player->uuid,
                        'trigger_time' => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $newAmount = bcadd($lottery->amount, $addAmount, 4);
            // 检查是否超过最大彩池限制
            if ($lottery->max_pool_amount > 0 && $newAmount > $lottery->max_pool_amount) {
                $newAmount = $lottery->max_pool_amount;
                $addAmount = bcsub($lottery->max_pool_amount, $lottery->amount, 4);
            }
            // 记录彩金累积日志
            $this->log->info('彩金池累积:', [
                'lottery_id' => $lottery->id,
                'name' => $lottery->name,
                'uuid' => $this->player->uuid,
                'player_id' => $this->player->id,
                'bet' => $bet,
                'pool_ratio' => $lottery->pool_ratio,
                'department_id' => $this->player->department_id,
                'add_amount' => $addAmount,
                'amount' => $lottery->amount,
                'max_pool_amount' => $lottery->max_pool_amount,
            ]);
            // 使用 Redis 原子操作累积彩金（性能优化）
            try {
                $redisKey = self::REDIS_KEY_LOTTERY_AMOUNT . $lottery->id;
                $redis = \support\Redis::connection()->client();

                // 使用 Redis 的 INCRBYFLOAT 原子操作累积
                $currentRedisAmount = $redis->incrByFloat($redisKey, (float)$addAmount);

                // 更新内存中的金额（用于后续逻辑）
                $lottery->amount = $newAmount;

                // 实时推送彩池数据变化（基于Redis变化）
                self::pushLotteryPoolData();

                // 优化：只在达到阈值或超过时间间隔时才同步到数据库
                $shouldSyncToDB = false;

                // 检查是否需要同步到数据库
                if ($currentRedisAmount >= self::DB_SYNC_THRESHOLD) {
                    $shouldSyncToDB = true;
                    $this->log->debug('达到同步阈值，将彩金同步到数据库', [
                        'lottery_id' => $lottery->id,
                        'redis_amount' => $currentRedisAmount,
                        'threshold' => self::DB_SYNC_THRESHOLD,
                    ]);
                } else {
                    // 检查距离上次同步的时间
                    $lastSyncKey = 'game_lottery_last_sync:' . $lottery->id;
                    $lastSync = $redis->get($lastSyncKey);

                    if (!$lastSync || (time() - $lastSync) >= self::DB_SYNC_INTERVAL) {
                        $shouldSyncToDB = true;
                        $this->log->debug('达到同步时间间隔，将彩金同步到数据库', [
                            'lottery_id' => $lottery->id,
                            'interval' => self::DB_SYNC_INTERVAL,
                        ]);
                    }
                }

                // 如果需要同步到数据库
                if ($shouldSyncToDB) {
                    // 从 Redis 获取累积的总金额并同步到数据库
                    $accumulatedAmount = $redis->get($redisKey);

                    if ($accumulatedAmount > 0) {
                        // 更新数据库
                        $lottery->save();

                        // 清除 Redis 累积计数（重置为0）
                        $redis->del($redisKey);

                        // 更新最后同步时间
                        $lastSyncKey = 'game_lottery_last_sync:' . $lottery->id;
                        $redis->set($lastSyncKey, time());

                        // 清除彩金缓存
                        self::clearLotteryPoolCache();
                        self::clearLotteryListCache();

                        $this->log->debug('彩金已同步到数据库', [
                            'lottery_id' => $lottery->id,
                            'amount' => $lottery->amount,
                            'accumulated' => $accumulatedAmount,
                        ]);

                        // 注意：推送已在Redis累积时触发，这里不需要重复推送
                    }
                }
            } catch (\Exception $e) {
                // Redis 操作失败，降级到直接数据库操作
                $this->log->warning('Redis 操作失败，降级到数据库操作', [
                    'error' => $e->getMessage(),
                    'lottery_id' => $lottery->id,
                    'add_amount' => $addAmount
                ]);

                // 更新内存中的金额
                $lottery->amount = $newAmount;

                // 直接保存到数据库
                $lottery->save();

                // 清除彩金缓存
                self::clearLotteryPoolCache();
                self::clearLotteryListCache();
            }

            // 优化爆彩检查频率：使用防抖机制，避免每次累积都检查
            $this->checkAndTriggerBurstWithDebounce($lottery);
        }
        return $this;
    }

    /**
     * 强制同步所有彩金池的 Redis 数据到数据库
     * 可用于定时任务或手动触发
     * @return array 返回同步结果
     */
    public static function forceSyncRedisToDatabase(): array
    {
        $result = [
            'success' => true,
            'synced_count' => 0,
            'details' => [],
            'errors' => [],
        ];

        try {
            $redis = \support\Redis::connection()->client();

            // 获取所有启用的彩金池
            $lotteryList = GameLottery::query()
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->get();

            foreach ($lotteryList as $lottery) {
                try {
                    $redisKey = self::REDIS_KEY_LOTTERY_AMOUNT . $lottery->id;
                    $accumulatedAmount = $redis->get($redisKey);

                    // 如果 Redis 中有累积金额
                    if ($accumulatedAmount !== false && $accumulatedAmount > 0) {
                        // 更新数据库中的金额
                        $oldAmount = $lottery->amount;
                        $lottery->amount = bcadd($lottery->amount, $accumulatedAmount, 4);
                        $lottery->save();

                        // 清除 Redis 累积计数
                        $redis->del($redisKey);

                        // 更新最后同步时间
                        $lastSyncKey = 'game_lottery_last_sync:' . $lottery->id;
                        $redis->set($lastSyncKey, time());

                        $result['synced_count']++;
                        $result['details'][] = [
                            'lottery_id' => $lottery->id,
                            'name' => $lottery->name,
                            'old_amount' => $oldAmount,
                            'accumulated' => $accumulatedAmount,
                            'new_amount' => $lottery->amount,
                        ];

                        \support\Log::info('彩金强制同步成功', [
                            'lottery_id' => $lottery->id,
                            'name' => $lottery->name,
                            'accumulated' => $accumulatedAmount,
                            'new_amount' => $lottery->amount,
                        ]);
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'lottery_id' => $lottery->id,
                        'error' => $e->getMessage(),
                    ];

                    \support\Log::error('彩金强制同步失败', [
                        'lottery_id' => $lottery->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 同步后清除彩金缓存
            if ($result['synced_count'] > 0) {
                self::clearLotteryPoolCache();
                self::clearLotteryListCache();
            }

        } catch (\Exception $e) {
            $result['success'] = false;
            $result['errors'][] = [
                'error' => '全局错误: ' . $e->getMessage(),
            ];

            \support\Log::error('彩金强制同步全局失败', [
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

}
