<?php

namespace app\service;

use app\model\GameType;
use app\model\Lottery;
use app\model\Machine;
use app\model\MachineReport;
use app\model\Notice;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGameRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerPlatformCash;
use Exception;
use support\Cache;
use support\Db;
use Webman\Push\PushException;
use yzh52521\WebmanLock\Locker;

class LotteryServices
{
    // 缓存配置
    const CACHE_KEY_LOTTERY_LIST = 'machine_lottery_list_';  // + type
    const CACHE_KEY_BURST = 'machine_lottery_burst:';        // + lottery_id

    // Redis 彩金累积键
    const REDIS_KEY_LOTTERY_AMOUNT = 'machine_lottery_amount:';

    // 性能优化配置
    const BURST_CHECK_INTERVAL = 5;           // 爆彩检查间隔（秒）
    const REDIS_KEY_LAST_BURST_CHECK = 'machine_lottery_last_burst_check:';
    const DB_SYNC_THRESHOLD = 10;            // 累积达到此金额后同步到数据库
    const DB_SYNC_INTERVAL = 2;               // 定期同步到数据库的间隔（秒）

    // 实时推送优化配置
    const PUSH_DEBOUNCE_INTERVAL = 1;         // 推送防抖间隔（秒）
    const REDIS_KEY_LAST_PUSH_TIME = 'machine_lottery_last_push_time';
    const REDIS_KEY_LAST_PUSH_HASH = 'machine_lottery_last_push_hash';

    // 其他配置
    const BURST_DURATION_BUFFER = 3;          // 爆彩缓冲时间（分钟），用于Redis自动过期的兜底机制

    public $slotLotteryList;
    public $jackLotteryList;
    public $machineCache;
    /** @var Machine $machine */
    private $machine;
    /** @var Player $player */
    private $player;

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
                \support\Log::debug('实时推送防抖：距离上次推送时间过短，跳过本次推送', [
                    'last_push_time' => $lastPushTime,
                    'interval' => self::PUSH_DEBOUNCE_INTERVAL,
                ]);
                return;
            }

            // 获取彩金池数据
            $lotteryServices = (new LotteryServices())->setJackLotteryList()->setSlotLotteryList();
            $gameLotteryPool = GameLotteryServices::getLotteryPool();

            // 构建消息数据
            $messageData = [
                'slot_amount' => self::formatLotteryListForPush($lotteryServices->slotLotteryList),
                'jack_amount' => self::formatLotteryListForPush($lotteryServices->jackLotteryList),
                'game_lottery_list' => self::formatGameLotteryPoolForPush($gameLotteryPool),
            ];

            // 数据变化检测：计算数据哈希值
            $currentHash = md5(json_encode($messageData));
            $lastHash = $redis->get(self::REDIS_KEY_LAST_PUSH_HASH);

            // 如果数据没有变化，跳过推送
            if ($currentHash === $lastHash) {
                \support\Log::debug('实时推送数据检测：数据无变化，跳过推送');
                return;
            }

            // 发送消息
            sendSocketMessage('group-lottery-pool', $messageData);

            // 更新最后推送时间和数据哈希
            $redis->set(self::REDIS_KEY_LAST_PUSH_TIME, time());
            $redis->set(self::REDIS_KEY_LAST_PUSH_HASH, $currentHash);

            \support\Log::debug('实时推送彩池数据成功', [
                'slot_count' => count($messageData['slot_amount']),
                'jack_count' => count($messageData['jack_amount']),
                'game_count' => count($messageData['game_lottery_list']),
            ]);
        } catch (\Throwable $e) {
            \support\Log::error('实时推送彩池数据失败: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * 格式化彩金列表用于推送
     * @param $lotteryList
     * @return array
     */
    private static function formatLotteryListForPush($lotteryList): array
    {
        $result = [];

        /** @var Lottery $lottery */
        foreach ($lotteryList as $lottery) {
            // 使用 lottery.amount（独立彩池金额）
            $amount = floatval($lottery->amount);

            // 从Redis获取实时金额并累加
            try {
                $redis = \support\Redis::connection()->client();
                $redisKey = self::REDIS_KEY_LOTTERY_AMOUNT . $lottery->id;
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
     * 格式化电子游戏彩金池数据用于推送
     * @param array $gameLotteryPool
     * @return array
     */
    private static function formatGameLotteryPoolForPush(array $gameLotteryPool): array
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
     * 清理发送消息缓存
     * @param $playerId
     * @param $machineId
     * @return bool
     */
    public
    static function clearNoticeCache(
        $playerId,
        $machineId
    ): bool
    {
        return Cache::delete('lottery_allow_notice_' . $playerId . '_' . $machineId);
    }

    /**
     * 发送待审核消息
     * @return void
     * @throws PushException
     */
    public static function reviewedMessage()
    {
        /** @var PlayerLotteryRecord $playerLotteryRecord */
        $playerLotteryRecord = PlayerLotteryRecord::where('status', PlayerLotteryRecord::STATUS_UNREVIEWED)->first();
        if (!empty($playerLotteryRecord)) {
            sendSocketMessage('private-admin_group-admin-1', [
                'msg_type' => 'player_examine_lottery',
                'id' => $playerLotteryRecord->id,
                'player_id' => $playerLotteryRecord->player_id,
            ]);
        }
        $subQuery = PlayerLotteryRecord::query()
            ->select(DB::raw('MAX(id) as id'))
            ->where('status', PlayerLotteryRecord::STATUS_UNREVIEWED)
            ->groupBy('department_id');
        $PlayerLotteryRecordList = PlayerLotteryRecord::query()
            ->whereIn('id', $subQuery)
            ->get();
        if (!empty($PlayerLotteryRecordList)) {
            /** @var PlayerLotteryRecord $item */
            foreach ($PlayerLotteryRecordList as $item) {
                sendSocketMessage('private-admin_group-channel-' . $item->department_id, [
                    'msg_type' => 'player_examine_lottery',
                    'id' => $item->id,
                    'player_id' => $item->player_id,
                ]);
            }
        }
    }

    /**
     * 设置机台数据
     * @param Machine $machine
     * @return LotteryServices
     */
    public function setMachine(Machine $machine): LotteryServices
    {
        $this->machine = $machine;
        return $this;
    }

    /**
     * 设置玩家数据
     * @param Player $player
     * @return LotteryServices
     */
    public function setPlayer(Player $player): LotteryServices
    {
        $this->player = $player;
        return $this;
    }

    /**
     * 获取机台数据
     * @return LotteryServices
     * @throws Exception
     */
    public function getMachineCacheData(): LotteryServices
    {
        $machineLiveData = Cache::get('machine_live_status_' . $this->machine->id);
        if (empty($machineLiveData)) {
            throw new Exception('无机台数据');
        }
        $checkTime = Cache::get('check_lottery_machine_date' . $this->machine->id) ?? 0;
        if ($machineLiveData['time'] <= $checkTime) {
            throw new Exception('记录已处理');
        }
        switch ($this->machine->type) {
            case GameType::TYPE_SLOT:
                if (empty($machineLiveData['pressure'])) {
                    throw new Exception('彩金计算:机台压分错误');
                }
                if (!isset($machineLiveData['last_data']['pressure'])) {
                    throw new Exception('彩金计算:机台上轮总压分错误');
                }
                // 增加彩金
                $this->addLotteryPool($machineLiveData['pressure'], $machineLiveData['last_data']['pressure']);
                break;
            case GameType::TYPE_STEEL_BALL:
                if (empty($machineLiveData['total_turn'])) {
                    throw new Exception('彩金计算:机台本轮总转数错误');
                }
                if (!isset($machineLiveData['last_data']['total_turn'])) {
                    throw new Exception('彩金计算:机台上轮总转数错误');
                }
                // 设置处理完成缓存
                $this->addLotteryPool($machineLiveData['total_turn'], $machineLiveData['last_data']['total_turn']);
                break;
            default:
                throw new Exception('机台类型错误');
        }
        $this->machineCache = $machineLiveData;
        Cache::set('check_lottery_machine_date' . $this->machine->id, $machineLiveData['time']);

        return $this;
    }

    /**
     * 累积彩池（新版：独立彩池模式）
     * @param $newNum
     * @param $lastNum
     * @return LotteryServices
     * @throws Exception
     */
    public function addLotteryPool($newNum, $lastNum): LotteryServices
    {
        // 基本验证
        if (empty($newNum)) {
            throw new Exception('机台新数据错误');
        }
        if (empty($lastNum)) {
            throw new Exception('机台上次数据错误');
        }
        if ($newNum == $lastNum) {
            throw new Exception('机台新数据等于上次数据');
        }
        if ($newNum < $lastNum) {
            throw new Exception('机台新数据小于上次数据');
        }
        if ($this->machine->machineCategory->lottery_add_status != 1) {
            throw new Exception('机台分类未开启彩池累积');
        }
        if ($this->machine->machineCategory->lottery_point <= 0) {
            throw new Exception('机台分类彩池累积单位设置错误');
        }

        $machineType = $this->machine->type;
        if ($machineType != GameType::TYPE_SLOT && $machineType != GameType::TYPE_STEEL_BALL) {
            throw new Exception('机台类型错误');
        }

        // 计算本次累积的基数
        $num = $newNum - $lastNum;
        $baseAmount = bcmul($num, $this->machine->machineCategory->lottery_point, 4);

        if ($baseAmount <= 0) {
            throw new Exception('彩金累积金额不能为0');
        }

        // 获取彩金列表（包含随机和固定彩金）
        $lotteryList = $this->machine->type == GameType::TYPE_SLOT ? $this->slotLotteryList : $this->jackLotteryList;

        if (empty($lotteryList)) {
            throw new Exception('未找到彩金配置');
        }

        /** @var Lottery $lottery */
        foreach ($lotteryList as $lottery) {
            // 检查是否达到最大彩池限制
            if ($lottery->max_pool_amount > 0 && $lottery->amount >= $lottery->max_pool_amount) {
                \support\Log::info('彩金池累计已达最大彩池上限:', [
                    'lottery_id' => $lottery->id,
                    'name' => $lottery->name,
                    'amount' => $lottery->amount,
                    'max_pool_amount' => $lottery->max_pool_amount,
                ]);
                continue;
            }

            // 按该彩金的pool_ratio计算累积金额
            $addAmount = bcmul($baseAmount, bcdiv($lottery->pool_ratio, 100, 4), 4);

            if ($addAmount <= 0) {
                \support\Log::info('彩金池累计为 0', [
                    'lottery_id' => $lottery->id,
                    'pool_ratio' => $lottery->pool_ratio,
                    'addAmount' => $addAmount,
                ]);
                continue;
            }

            // 累加前检查保底金额：如果启用了保底金额且当前彩池低于保底金额，先补充到保底金额
            if ($lottery->auto_refill_status == 1 && $lottery->auto_refill_amount > 0) {
                if ($lottery->amount < $lottery->auto_refill_amount) {
                    $refillAmount = bcsub($lottery->auto_refill_amount, $lottery->amount, 4);
                    $beforeRefillAmount = $lottery->amount;
                    $lottery->amount = $lottery->auto_refill_amount;

                    // 记录累加前补充日志
                    \support\Log::info('彩金池累加前自动补充到保底金额:', [
                        'lottery_id' => $lottery->id,
                        'lottery_name' => $lottery->name,
                        'before_refill_amount' => $beforeRefillAmount,
                        'target_amount' => $lottery->auto_refill_amount,
                        'refill_amount' => $refillAmount,
                        'after_refill_amount' => $lottery->amount,
                        'player_id' => $this->player->id,
                        'uuid' => $this->player->uuid,
                        'machine_id' => $this->machine->id,
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
            \support\Log::info('彩金池累积:', [
                'lottery_id' => $lottery->id,
                'name' => $lottery->name,
                'uuid' => $this->player->uuid,
                'player_id' => $this->player->id,
                'machine_id' => $this->machine->id,
                'num' => $num,
                'base_amount' => $baseAmount,
                'pool_ratio' => $lottery->pool_ratio,
                'add_amount' => $addAmount,
                'old_amount' => $lottery->amount,
                'new_amount' => $newAmount,
                'max_pool_amount' => $lottery->max_pool_amount,
            ]);

            // 使用 Redis 原子操作累积彩金（性能优化）
            try {
                $redisKey = self::REDIS_KEY_LOTTERY_AMOUNT . $lottery->id;
                $redis = \support\Redis::connection()->client();

                // 使用 Redis 的 INCRBYFLOAT 原子操作累积
                $currentRedisAmount = $redis->incrByFloat($redisKey, (float)$addAmount);

                // 注意：不要更新内存中的 lottery.amount，同步时会从数据库 refresh() 重新读取
                // 避免内存值覆盖导致数据丢失（Redis累积金额会在同步时叠加到数据库值）
                // $lottery->amount = $newAmount;  // ← 已禁用

                // 实时推送已禁用，改用定时任务推送（LotteryPoolSocket）
                // self::pushLotteryPoolData();

                // 优化：只在达到阈值或超过时间间隔时才同步到数据库
                $shouldSyncToDB = false;

                // 检查是否需要同步到数据库
                if ($currentRedisAmount >= self::DB_SYNC_THRESHOLD) {
                    $shouldSyncToDB = true;
                    \support\Log::debug('达到同步阈值，将彩金同步到数据库', [
                        'lottery_id' => $lottery->id,
                        'redis_amount' => $currentRedisAmount,
                        'threshold' => self::DB_SYNC_THRESHOLD,
                    ]);
                } else {
                    // 检查距离上次同步的时间
                    $lastSyncKey = 'machine_lottery_last_sync:' . $lottery->id;
                    $lastSync = $redis->get($lastSyncKey);

                    if (!$lastSync || (time() - $lastSync) >= self::DB_SYNC_INTERVAL) {
                        $shouldSyncToDB = true;
                        \support\Log::debug('达到同步时间间隔，将彩金同步到数据库', [
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
                        // 重新从数据库读取最新值，避免内存值覆盖导致数据丢失
                        $lottery->refresh();

                        // 累加Redis中的金额到数据库金额
                        $oldAmount = $lottery->amount;
                        $lottery->amount = bcadd($lottery->amount, $accumulatedAmount, 4);

                        // 更新数据库
                        $lottery->save();

                        // 清除 Redis 累积计数（重置为0）
                        $redis->del($redisKey);

                        // 更新最后同步时间
                        $lastSyncKey = 'machine_lottery_last_sync:' . $lottery->id;
                        $redis->set($lastSyncKey, time());

                        // 清除彩金缓存
                        self::clearLotteryListCache($machineType);

                        \support\Log::debug('彩金已同步到数据库', [
                            'lottery_id' => $lottery->id,
                            'old_amount' => $oldAmount,
                            'accumulated' => $accumulatedAmount,
                            'new_amount' => $lottery->amount,
                        ]);

                        // 注意：推送已在Redis累积时触发，这里不需要重复推送
                    }
                }
            } catch (\Exception $e) {
                // Redis 操作失败，降级到直接数据库操作
                \support\Log::warning('Redis 操作失败，降级到数据库操作', [
                    'error' => $e->getMessage(),
                    'lottery_id' => $lottery->id,
                    'add_amount' => $addAmount
                ]);

                // 更新内存中的金额
                $lottery->amount = $newAmount;

                // 直接保存到数据库
                $lottery->save();

                // 清除彩金缓存
                self::clearLotteryListCache($machineType);
            }

            // 优化爆彩检查频率：使用防抖机制，避免每次累积都检查
            $this->checkAndTriggerBurstWithDebounce($lottery);
        }

        return $this;
    }

    /**
     * 实时中奖（新版：概率派彩模式）
     * @return bool
     * @throws Exception|PushException
     */
    public function checkLottery(): bool
    {
        if ($this->machine->machineCategory->lottery_assign_status == 0) {
            return false;
        }

        $lotteryList = $this->machine->type == GameType::TYPE_SLOT ? $this->slotLotteryList : $this->jackLotteryList;

        // 固定彩金达成（保持原有逻辑）
        $fixedAllowLottery = [
            'machine_id' => $this->machine->id,
            'msg_type' => 'player_lottery_allow',
            'machine_name' => $this->machine->name,
            'machine_code' => $this->machine->code,
            'machine_odds' => $this->machine->odds_x . ':' . $this->machine->odds_y,
            'machine_type' => $this->machine->type,
            'player_id' => $this->player->id,
            'player_uuid' => $this->player->uuid,
            'player_phone' => $this->player->phone,
            'has_win' => 0,
            'lottery_id' => '',
            'lottery_name' => '',
            'lottery_sort' => '',
            'lottery_type' => '',
            'lottery_condition' => 0,
            'amount' => 0,
            'lottery_pool_amount' => 0,
            'lottery_multiple' => 1,
            'next_lottery' => []
        ];

        $condition = $this->getCondition();

        /** @var Lottery $lottery */
        foreach ($lotteryList as $key => $lottery) {
            // ===== 1. 固定彩金处理（新版：从独立彩池派发）=====
            if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_FIXED && $this->machine->type == GameType::TYPE_SLOT) {
                if ($lottery->condition <= $condition) {
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

                    // 4. 发放金额向下取整（只保留整数位）
                    $amount = floor($amount);

                    // 彩金倍数标记（只由双倍派彩决定）
                    $lotteryMultiple = $isDoubled ? 2 : 1;

                    if ($amount >= $fixedAllowLottery['amount']) {
                        $fixedAllowLottery['lottery_id'] = $lottery->id;
                        $fixedAllowLottery['lottery_rate'] = $isDoubled ? ($lottery->rate * 2) : $lottery->rate;
                        $fixedAllowLottery['lottery_name'] = $lottery->name;
                        $fixedAllowLottery['lottery_sort'] = $lottery->sort;
                        $fixedAllowLottery['lottery_type'] = $lottery->lottery_type;
                        $fixedAllowLottery['lottery_condition'] = $lottery->condition;
                        $fixedAllowLottery['amount'] = $amount;
                        $fixedAllowLottery['lottery_pool_amount'] = $lottery->amount;
                        $fixedAllowLottery['lottery_multiple'] = $lotteryMultiple;
                        $fixedAllowLottery['is_doubled'] = $isDoubled ? 1 : 0;
                        if (isset($lotteryList[$key - 1]) && !empty($lotteryList[$key - 1])) {
                            if ($lotteryList[$key - 1]->lottery_type == Lottery::LOTTERY_TYPE_FIXED) {
                                /** @var Lottery $nextLottery */
                                $nextLottery = $lotteryList[$key - 1];
                                $fixedAllowLottery['next_lottery'] = [
                                    'id' => $nextLottery->id,
                                    'game_type' => $nextLottery->game_type,
                                    'name' => $nextLottery->name,
                                    'rate' => $nextLottery->rate,
                                    'lottery_type' => $nextLottery->lottery_type,
                                    'condition' => $nextLottery->condition,
                                    'max_amount' => $nextLottery->max_amount,
                                ];
                            }
                        }
                    }
                }
            }

            // ===== 2. 随机彩金处理（新版：概率模式）=====
            if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_RANDOM) {
                // 计算下注金额（根据机台类型）
                $bet = $this->calculateBetAmount();

                // 记录派彩检查
                \support\Log::info('开始派彩检查:', [
                    'lottery_id' => $lottery->id,
                    'lottery_name' => $lottery->name,
                    'bet' => $bet,
                    'player_id' => $this->player->id,
                    'uuid' => $this->player->uuid,
                ]);

                // 获取并处理爆彩状态
                $burstInfo = $this->getBurstInfo($lottery);

                // 处理派彩检查（每次下注检查一次）
                $this->processLotteryCheck($lottery, $bet, 1, $burstInfo);
            }
        }

        // 发送固定彩金中奖消息
        if (isset($fixedAllowLottery['amount']) && $fixedAllowLottery['amount'] > 0) {
            $lotteryId = self::getNoticeCache($this->player->id, $this->machine->id);
            if (empty($lotteryId) || $lotteryId != $fixedAllowLottery['lottery_id']) {
                sendSocketMessage('player-' . $this->player->id, $fixedAllowLottery);
                self::setNoticeCache($this->player->id, $this->machine->id, $fixedAllowLottery['lottery_id']);
            }
        }

        return true;
    }

    /**
     * 计算下注金额
     * @return float
     */
    private function calculateBetAmount(): float
    {
        $condition = $this->getCondition();
        $odds = $this->machine->odds_x / $this->machine->odds_y;
        return $condition * $odds;
    }

    /**
     * 处理派彩检查（概率模式）
     * @param Lottery $lottery
     * @param float|int $bet
     * @param int $participateTimes
     * @param array $burstInfo
     * @return void
     * @throws Exception
     * @throws PushException
     */
    private function processLotteryCheck(
        Lottery   $lottery,
        float|int $bet,
        int       $participateTimes,
        array     $burstInfo
    ): void
    {
        // 应用爆彩概率倍数到中奖检查
        $adjustedWinRatio = bcmul($lottery->win_ratio, $burstInfo['multiplier'], 6);

        // 循环检查多次派彩机会
        for ($i = 1; $i <= $participateTimes; $i++) {
            $service = new LotteryProbabilityService();
            $result = $service->checkSmart($adjustedWinRatio);

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

            // 4. 发放金额向下取整（只保留整数位）
            $amount = floor($amount);

            // 彩金倍数标记（只由双倍派彩决定，爆彩只影响概率不影响金额倍数）
            $lotteryMultiple = $isDoubled ? 2 : 1;

            // 检查中奖条件
            if ($result && $amount > 0) {
                // 尝试派发彩金（支持多次中奖，不跳出循环）
                $this->tryDistributeLottery($lottery, $amount, $lotteryMultiple, $bet, $burstInfo, $i, $participateTimes, $isDoubled);
            }
        }
    }

    /**
     * 获取机台达成条件
     * @return int|mixed
     */
    public
    function getCondition()
    {
        return $this->machine->type == GameType::TYPE_SLOT ? $this->machineCache['seven_display'] ?? 0 : $this->machineCache['display_score'] ?? 0;
    }

    /**
     * 检查是否应用双倍逻辑
     * @param Lottery $lottery
     * @return bool
     */
    private function shouldApplyDouble(Lottery $lottery): bool
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

        \support\Log::info('双倍逻辑触发:', [
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
     * 尝试派发彩金（新版：从lottery.amount扣减）
     * @param Lottery $lottery
     * @param int $amount
     * @param int $lotteryMultiple
     * @param float|int $bet
     * @param array $burstInfo
     * @param int $attemptIndex
     * @param int $totalAttempts
     * @param bool $isDoubled
     * @return bool
     * @throws Exception
     * @throws PushException
     */
    private function tryDistributeLottery(
        Lottery   $lottery,
        float $amount,
        int       $lotteryMultiple,
        float|int $bet,
        array     $burstInfo,
        int       $attemptIndex,
        int       $totalAttempts,
        bool      $isDoubled = false
    ): bool
    {
        // 增加业务锁
        $actionLockerKey = 'machine_lottery_pool_random_locker_' . $lottery->id;
        $lock = Locker::lock($actionLockerKey, 2, true);
        if (!$lock->acquire()) {
            return false;
        }

        DB::beginTransaction();
        try {
            // 重新加载彩金数据，检查余额
            $lottery->refresh();
            if ($lottery->amount < $amount) {
                \support\Log::error('彩金池余额不足', [
                    'lottery_id' => $lottery->id,
                    'required' => $amount,
                    'available' => $lottery->amount,
                ]);
                DB::rollback();
                return false;
            }

            // 创建派彩记录
            $playerLotteryRecord = $this->createLotteryRecord($lottery, $amount, $lotteryMultiple, $bet, $isDoubled);

            // 记录中奖日志
            $this->logWinning($lottery, $amount, $burstInfo, $attemptIndex, $totalAttempts, $isDoubled);

            // 发送站内信
            $notice = $this->sendNotice($playerLotteryRecord->id, $playerLotteryRecord->lottery_name);

            // 更新玩家钱包（加彩金金额）
            // 1. 从 Redis 读取余额（唯一可信源）
            $beforeAmount = WalletService::getBalance($this->player->id);

            // 2. 使用 WalletService 原子性加款（Redis）
            $newBalance = WalletService::atomicIncrement($this->player->id, $amount);

            // 3. 同步到数据库（冷备份）
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
            if (!$machineWallet) {
                \support\Log::error('随机彩金派发失败：玩家钱包不存在', [
                    'player_id' => $this->player->id,
                ]);
                DB::rollback();
                return false;
            }
            $machineWallet->money = $newBalance;
            $machineWallet->saveWithoutEvents();

            // 创建交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $this->player->id;
            $playerDeliveryRecord->department_id = $this->player->department_id;
            $playerDeliveryRecord->target = $playerLotteryRecord->getTable();
            $playerDeliveryRecord->target_id = $playerLotteryRecord->id;
            $playerDeliveryRecord->platform_id = PlayerPlatformCash::PLATFORM_SELF;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_LOTTERY;
            $playerDeliveryRecord->source = 'lottery_random';
            $playerDeliveryRecord->amount = $amount;
            $playerDeliveryRecord->amount_before = $beforeAmount;
            $playerDeliveryRecord->amount_after = $newBalance;
            $playerDeliveryRecord->tradeno = '';
            $playerDeliveryRecord->remark = '随机彩金派彩';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            // 扣减彩金池（从lottery.amount扣减）
            // 根据rate和是否双倍计算实际扣减金额
            $rate = $lottery->rate > 0 ? $lottery->rate : 100;
            $baseDeductAmount = bcmul($lottery->amount, bcdiv($rate, 100, 4), 2);
            $lottery->amount = bcsub($lottery->amount, $baseDeductAmount, 2);

            // 派彩成功后补充到目标金额（如果启用了自动补充）
            if ($lottery->auto_refill_status == 1 && $lottery->auto_refill_amount > 0) {
                $beforeRefillAmount = $lottery->amount;

                // 只有当彩池低于目标金额时才补充
                if ($lottery->amount < $lottery->auto_refill_amount) {
                    $refillAmount = bcsub($lottery->auto_refill_amount, $lottery->amount, 4);
                    $lottery->amount = $lottery->auto_refill_amount;

                    // 记录派彩后补充日志
                    \support\Log::info('彩金池派彩后自动补充到目标金额:', [
                        'lottery_id' => $lottery->id,
                        'lottery_name' => $lottery->name,
                        'before_refill_amount' => $beforeRefillAmount,
                        'target_amount' => $lottery->auto_refill_amount,
                        'refill_amount' => $refillAmount,
                        'after_refill_amount' => $lottery->amount,
                        'deduct_amount' => $baseDeductAmount,
                        'player_id' => $this->player->id,
                        'uuid' => $this->player->uuid,
                        'machine_id' => $this->machine->id,
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
            self::clearLotteryListCache($this->machine->type);

            // 实时推送已禁用，改用定时任务推送（LotteryPoolSocket）
            // self::pushLotteryPoolData();

            // 发送派彩和通知消息
            $this->sendWinningMessages($playerLotteryRecord, $lottery, $notice, $burstInfo, $isDoubled);

            return true;
        } catch (\Exception $e) {
            DB::rollback();
            \support\Log::error('派发彩金失败', [
                'error' => $e->getMessage(),
                'lottery_id' => $lottery->id,
            ]);
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 创建彩金记录
     * @param Lottery $lottery
     * @param int $amount
     * @param int $lotteryMultiple
     * @param float|int $bet
     * @param bool $isDoubled
     * @return PlayerLotteryRecord
     */
    private function createLotteryRecord(
        Lottery   $lottery,
        float $amount,
        int       $lotteryMultiple,
        float|int $bet,
        bool      $isDoubled = false
    ): PlayerLotteryRecord
    {
        $odds = $this->machine->odds_x . ':' . $this->machine->odds_y;
        if ($this->machine->type == GameType::TYPE_STEEL_BALL) {
            $odds = $this->machine->machineCategory->name;
        }
        /** @var PlayerGameRecord $playerGameRecord */
        $playerGameRecord = PlayerGameRecord::query()
            ->where('player_id', $this->player->id)
            ->where('machine_id', $this->machine->id)
            ->orderBy('id', 'desc')
            ->first();
        $playerLotteryRecord = new PlayerLotteryRecord();
        $playerLotteryRecord->player_id = $this->machine->gaming_user_id;
        $playerLotteryRecord->uuid = $this->player->uuid;
        $playerLotteryRecord->player_phone = $this->player->phone ?? '';
        $playerLotteryRecord->player_name = $this->player->name ?? '';
        $playerLotteryRecord->is_coin = $this->player->is_coin;
        $playerLotteryRecord->is_promoter = $this->player->is_promoter;
        $playerLotteryRecord->is_test = $this->player->is_test;
        $playerLotteryRecord->department_id = $this->player->department_id;
        $playerLotteryRecord->machine_id = $this->machine->id;
        $playerLotteryRecord->machine_name = $this->machine->name;
        $playerLotteryRecord->machine_code = $this->machine->code;
        $playerLotteryRecord->game_type = $this->machine->type;
        $playerLotteryRecord->odds = $odds;
        $playerLotteryRecord->bet = $bet;
        $playerLotteryRecord->amount = $amount;
        $playerLotteryRecord->is_max = $amount == $lottery->max_amount ? 1 : 0;
        $playerLotteryRecord->lottery_id = $lottery->id;
        $playerLotteryRecord->lottery_name = $lottery->name;
        $playerLotteryRecord->lottery_pool_amount = $lottery->amount;
        $playerLotteryRecord->lottery_type = $lottery->lottery_type;
        $playerLotteryRecord->lottery_multiple = $lotteryMultiple;
        $playerLotteryRecord->lottery_sort = $lottery->sort;
        $playerLotteryRecord->cate_rate = $this->machine->machineCategory->lottery_rate;
        $playerLotteryRecord->status = PlayerLotteryRecord::STATUS_COMPLETE;
        $playerLotteryRecord->player_game_record_id = $playerGameRecord->id;

        // 记录rate信息（如果是双倍则标记为2倍rate）
        if ($isDoubled) {
            $playerLotteryRecord->lottery_rate = $lottery->rate * 2;
        } else {
            $playerLotteryRecord->lottery_rate = $lottery->rate;
        }

        $playerLotteryRecord->save();

        // 更新机台报表（因为是新建记录，updated事件不会触发）
        $this->updateMachineReport($playerLotteryRecord);

        return $playerLotteryRecord;
    }

    /**
     * 记录中奖日志
     * @param Lottery $lottery
     * @param int $amount
     * @param array $burstInfo
     * @param int $attemptIndex
     * @param int $totalAttempts
     * @param bool $isDoubled
     * @return void
     */
    private function logWinning(
        Lottery $lottery,
        float $amount,
        array   $burstInfo,
        int     $attemptIndex,
        int     $totalAttempts,
        bool    $isDoubled = false
    ): void
    {
        if ($burstInfo['is_bursting']) {
            \support\Log::info('【爆彩中奖】玩家在爆彩期间中奖:', [
                'lottery_id' => $lottery->id,
                'lottery_name' => $lottery->name,
                'player_id' => $this->player->id,
                'uuid' => $this->player->uuid,
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
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
                'machine_code' => $this->machine->code,
                'is_doubled' => $isDoubled,
            ]);
        } else {
            \support\Log::info('【普通中奖】玩家中奖:', [
                'lottery_id' => $lottery->id,
                'lottery_name' => $lottery->name,
                'player_id' => $this->player->id,
                'uuid' => $this->player->uuid,
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
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
     * @param Lottery $lottery
     * @param Notice $notice
     * @param array $burstInfo
     * @param bool $isDoubled
     * @return void
     * @throws PushException
     */
    private function sendWinningMessages(
        PlayerLotteryRecord $record,
        Lottery             $lottery,
        Notice              $notice,
        array               $burstInfo,
        bool                $isDoubled = false
    ): void
    {
        // 发送派彩消息（给中奖玩家）
        sendSocketMessage('player-' . $this->player->id, [
            'msg_type' => 'player_lottery_allow',
            'machine_id' => $this->machine->id,
            'machine_name' => $this->machine->name,
            'machine_code' => $this->machine->code,
            'machine_odds' => $this->machine->odds_x . ':' . $this->machine->odds_y,
            'machine_type' => $this->machine->type,
            'player_id' => $record->player_id,
            'has_win' => 1,
            'lottery_record_id' => $record->id,
            'lottery_id' => $record->lottery_id,
            'lottery_name' => $record->lottery_name,
            'lottery_sort' => $lottery->sort,
            'lottery_type' => $lottery->lottery_type,
            'amount' => $record->amount,
            'lottery_pool_amount' => $lottery->amount,
            'lottery_multiple' => $record->lottery_multiple,
            'is_burst' => $burstInfo['is_bursting'] ? 1 : 0,
            'burst_multiplier' => $burstInfo['multiplier'],
            'is_doubled' => $isDoubled ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s', strtotime($record->created_at)),
            'lottery_rate' => $record->lottery_rate,
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
            'machine_name' => $record->machine_name,
            'machine_code' => $record->machine_code,
            'lottery_name' => $record->lottery_name,
            'lottery_type' => $record->lottery_type,
            'game_type' => $record->game_type,
            'created_at' => date('Y-m-d H:i:s', strtotime($record->created_at)),
            'lottery_multiple' => $record->lottery_multiple,
            'is_burst' => $burstInfo['is_bursting'] ? 1 : 0,
            'is_doubled' => $isDoubled ? 1 : 0,
            'lottery_rate' => $record->lottery_rate,
            'notice_num' => Notice::query()->where('player_id', $this->player->id)->where('status', 0)->count('*')
        ]);

        // 发送全频道广播（新增）
        $broadcastMessage = [
            'msg_type' => 'machine_lottery_win_broadcast',
            'lottery_id' => $lottery->id,
            'lottery_name' => $lottery->name,
            'lottery_type' => $lottery->lottery_type,
            'game_type' => $this->machine->type,
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'machine_name' => $this->machine->name,
            'player_id' => $this->player->id,
            'player_name' => $this->player->name ?? $this->player->uuid,
            'player_uuid' => $this->player->uuid,
            'amount' => $record->amount,
            'lottery_pool_amount' => $lottery->amount,
            'created_at' => date('Y-m-d H:i:s', strtotime($record->created_at)),
            'is_burst' => $burstInfo['is_bursting'] ? 1 : 0,
            'burst_multiplier' => $burstInfo['multiplier'],
            'is_doubled' => $isDoubled ? 1 : 0,
            'lottery_rate' => $record->lottery_rate,
            'title' => '🎊 恭喜玩家中獎！',
            'content' => sprintf(
                '恭喜玩家在%s機台 %s 中贏得 %s%d 彩金！',
                $this->machine->code,
                $lottery->name,
                $isDoubled ? '【雙倍】' : '',
                $record->amount
            ),
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
        $notice->content = '恭喜您在' . ($this->machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠') . $this->machine->code . '機台獲得了' . $lotteryName . '的彩金獎勵彩金金額';
        $notice->save();

        return $notice;
    }

    /**
     * 获取发送消息缓存
     * @param $playerId
     * @param $machineId
     * @return mixed
     */
    public
    static function getNoticeCache(
        $playerId,
        $machineId
    )
    {
        return Cache::get('lottery_allow_notice_' . $playerId . '_' . $machineId);
    }

    /**
     * 设置发送消息缓存
     * @param $playerId
     * @param $machineId
     * @param $lotteryId
     * @return bool
     */
    public
    static function setNoticeCache(
        $playerId,
        $machineId,
        $lotteryId
    ): bool
    {
        return Cache::set('lottery_allow_notice_' . $playerId . '_' . $machineId, $lotteryId);
    }

    /**
     * 固定彩金逻辑
     * @param $condition
     * @param bool $hasLottery
     * @param bool $hasSend
     * @return PlayerLotteryRecord|null|array
     * @throws Exception
     */
    public function fixedPotCheckLottery($condition, bool $hasLottery = false, bool $hasSend = true)
    {
        if ($this->machine->machineCategory->lottery_assign_status == 0) {
            return null;
        }
        $lotteryList = [];
        if ($this->machine->type == GameType::TYPE_SLOT) {
            $this->setSlotLotteryList(Lottery::LOTTERY_TYPE_FIXED);
            $lotteryList = $this->slotLotteryList;
        }
        if ($this->machine->type == GameType::TYPE_STEEL_BALL) {
            $this->setJackLotteryList(Lottery::LOTTERY_TYPE_FIXED);
            $lotteryList = $this->jackLotteryList;
        }
        if (!empty($lotteryList)) {
            // 固定彩金达成
            $fixedAllowLottery = [
                'machine_id' => $this->machine->id,
                'msg_type' => 'player_lottery_allow',
                'machine_name' => $this->machine->name,
                'machine_code' => $this->machine->code,
                'machine_odds' => $this->machine->odds_x . ':' . $this->machine->odds_y,
                'machine_type' => $this->machine->type,
                'player_id' => $this->player->id,
                'player_uuid' => $this->player->uuid,
                'player_phone' => $this->player->phone,
                'has_win' => 1,
                'lottery_id' => '',
                'lottery_name' => '',
                'lottery_sort' => '',
                'lottery_type' => '',
                'lottery_condition' => 0,
                'amount' => 0,
                'lottery_pool_amount' => 0,
                'lottery_multiple' => 1,
                'next_lottery' => []
            ];
            $lotteryIndex = 0;
            $isLottery = false;
            /** @var Lottery $lottery */
            foreach ($lotteryList as $key => $lottery) {
                if ($lottery->condition <= $condition) {
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

                    // 4. 发放金额向下取整（只保留整数位）
                    $amount = floor($amount);

                    // 彩金倍数标记（只由双倍派彩决定）
                    $lotteryMultiple = $isDoubled ? 2 : 1;

                    // 只会发放最大金额的中奖
                    if ($amount >= $fixedAllowLottery['amount']) {
                        $fixedAllowLottery['lottery_id'] = $lottery->id;
                        $fixedAllowLottery['lottery_rate'] = $isDoubled ? ($lottery->rate * 2) : $lottery->rate;
                        $fixedAllowLottery['lottery_name'] = $lottery->name;
                        $fixedAllowLottery['lottery_sort'] = $lottery->sort;
                        $fixedAllowLottery['lottery_type'] = $lottery->lottery_type;
                        $fixedAllowLottery['lottery_condition'] = $lottery->condition;
                        $fixedAllowLottery['amount'] = $amount;
                        $fixedAllowLottery['max_amount'] = $lottery->max_amount;
                        $fixedAllowLottery['lottery_pool_amount'] = $lottery->amount;
                        $fixedAllowLottery['lottery_multiple'] = $lotteryMultiple;
                        $fixedAllowLottery['is_doubled'] = $isDoubled ? 1 : 0;
                        $lotteryIndex = $key;
                    }
                    $isLottery = true;
                }
            }
            $odds = $this->machine->odds_x . ':' . $this->machine->odds_y;
            if ($this->machine->type == GameType::TYPE_STEEL_BALL) {
                $odds = $this->machine->machineCategory->name;
            }
            if ($hasLottery && $isLottery) {
                return [
                    'player_id' => $this->player->id,
                    'uuid' => $this->player->uuid,
                    'player_phone' => $this->player->phone,
                    'player_name' => $this->player->name,
                    'department_id' => $this->player->department_id,
                    'machine_id' => $this->machine->id,
                    'machine_name' => $this->machine->name,
                    'machine_code' => $this->machine->code,
                    'machine_type' => $this->machine->type,
                    'game_type' => $this->machine->type,
                    'has_win' => 1,
                    'odds' => $odds,
                    'amount' => $fixedAllowLottery['amount'],
                    'is_max' => $fixedAllowLottery['amount'] == $fixedAllowLottery['max_amount'] ? 1 : 0,
                    'lottery_id' => $fixedAllowLottery['lottery_id'],
                    'lottery_name' => $fixedAllowLottery['lottery_name'],
                    'lottery_pool_amount' => $fixedAllowLottery['lottery_pool_amount'],
                    'lottery_rate' => $fixedAllowLottery['lottery_rate'],
                    'cate_rate' => $this->machine->machineCategory->lottery_rate,
                    'lottery_type' => Lottery::LOTTERY_TYPE_FIXED,
                    'lottery_multiple' => $fixedAllowLottery['lottery_multiple'],
                    'lottery_sort' => $fixedAllowLottery['lottery_sort'],
                    'is_doubled' => $fixedAllowLottery['is_doubled'],
                    'has_lottery' => true,
                    'next_lottery' => !empty($lotteryList[$lotteryIndex - 1]) ? $lotteryList[$lotteryIndex - 1] : []
                ];
            }
            if (isset($fixedAllowLottery['amount']) && $fixedAllowLottery['amount'] > 0) {
                // 增加业务锁（参考随机彩金逻辑）
                $actionLockerKey = 'machine_lottery_pool_fixed_locker_' . $fixedAllowLottery['lottery_id'];
                $lock = Locker::lock($actionLockerKey, 2, true);
                if (!$lock->acquire()) {
                    \support\Log::warning('固定彩金派发锁定失败', [
                        'lottery_id' => $fixedAllowLottery['lottery_id'],
                        'player_id' => $this->player->id,
                    ]);
                    return null;
                }

                DB::beginTransaction();
                try {
                    // 重新加载彩金数据，检查余额（参考随机彩金逻辑）
                    /** @var Lottery $lotteryModel */
                    $lotteryModel = Lottery::query()->where('id', $fixedAllowLottery['lottery_id'])->lockForUpdate()->first();
                    if (!$lotteryModel) {
                        \support\Log::error('固定彩金不存在', [
                            'lottery_id' => $fixedAllowLottery['lottery_id'],
                        ]);
                        DB::rollback();
                        return null;
                    }

                    if ($lotteryModel->amount < $fixedAllowLottery['amount']) {
                        \support\Log::error('固定彩金池余额不足', [
                            'lottery_id' => $lotteryModel->id,
                            'required' => $fixedAllowLottery['amount'],
                            'available' => $lotteryModel->amount,
                        ]);
                        DB::rollback();
                        return null;
                    }

                    // 查询最近的游戏记录（参考随机彩金逻辑）
                    /** @var PlayerGameRecord $playerGameRecord */
                    $playerGameRecord = PlayerGameRecord::query()
                        ->where('player_id', $this->player->id)
                        ->where('machine_id', $this->machine->id)
                        ->orderBy('id', 'desc')
                        ->first();

                    // 生成派彩记录
                    $playerLotteryRecord = new PlayerLotteryRecord();
                    $playerLotteryRecord->player_id = $this->player->id;
                    $playerLotteryRecord->uuid = $this->player->uuid;
                    $playerLotteryRecord->player_phone = $this->player->phone ?? '';
                    $playerLotteryRecord->player_name = $this->player->name ?? '';
                    $playerLotteryRecord->is_coin = $this->player->is_coin;
                    $playerLotteryRecord->is_promoter = $this->player->is_promoter;
                    $playerLotteryRecord->is_test = $this->player->is_test;
                    $playerLotteryRecord->department_id = $this->player->department_id;
                    $playerLotteryRecord->machine_id = $this->machine->id;
                    $playerLotteryRecord->machine_name = $this->machine->name;
                    $playerLotteryRecord->machine_code = $this->machine->code;
                    $playerLotteryRecord->game_type = $this->machine->type;
                    $playerLotteryRecord->odds = $odds;
                    $playerLotteryRecord->amount = $fixedAllowLottery['amount'];
                    $playerLotteryRecord->is_max = $fixedAllowLottery['amount'] == $fixedAllowLottery['max_amount'] ? 1 : 0;
                    $playerLotteryRecord->lottery_id = $fixedAllowLottery['lottery_id'];
                    $playerLotteryRecord->lottery_name = $fixedAllowLottery['lottery_name'];
                    $playerLotteryRecord->lottery_pool_amount = $lotteryModel->amount;
                    $playerLotteryRecord->lottery_rate = $fixedAllowLottery['lottery_rate'];
                    $playerLotteryRecord->cate_rate = $this->machine->machineCategory->lottery_rate;
                    $playerLotteryRecord->lottery_type = Lottery::LOTTERY_TYPE_FIXED;
                    $playerLotteryRecord->lottery_multiple = $fixedAllowLottery['lottery_multiple'];
                    $playerLotteryRecord->lottery_sort = $fixedAllowLottery['lottery_sort'];
                    $playerLotteryRecord->status = PlayerLotteryRecord::STATUS_COMPLETE;
                    $playerLotteryRecord->player_game_record_id = $playerGameRecord ? $playerGameRecord->id : 0;
                    $playerLotteryRecord->save();

                    // 更新机台报表（因为是新建记录，updated事件不会触发）
                    $this->updateMachineReport($playerLotteryRecord);

                    // 记录中奖日志（参考随机彩金逻辑）
                    \support\Log::info('【固定彩金中奖】玩家中奖:', [
                        'lottery_id' => $lotteryModel->id,
                        'lottery_name' => $lotteryModel->name,
                        'player_id' => $this->player->id,
                        'uuid' => $this->player->uuid,
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'amount' => $fixedAllowLottery['amount'],
                        'lottery_rate' => $fixedAllowLottery['lottery_rate'],
                        'is_doubled' => $fixedAllowLottery['is_doubled'],
                        'pool_amount' => $lotteryModel->amount,
                    ]);

                    // 发送站内信
                    $notice = $this->sendNotice($playerLotteryRecord->id, $playerLotteryRecord->lottery_name);

                    // 更新玩家钱包（加彩金金额）
                    /** @var PlayerPlatformCash $machineWallet */
                    $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
                    if (!$machineWallet) {
                        \support\Log::error('固定彩金派发失败：玩家钱包不存在', [
                            'player_id' => $this->player->id,
                        ]);
                        DB::rollback();
                        return null;
                    }

                    $beforeAmount = $machineWallet->money;
                    $machineWallet->money = bcadd($machineWallet->money, $fixedAllowLottery['amount'], 2);
                    $machineWallet->save();

                    // 创建交易记录
                    $playerDeliveryRecord = new PlayerDeliveryRecord();
                    $playerDeliveryRecord->player_id = $this->player->id;
                    $playerDeliveryRecord->department_id = $this->player->department_id;
                    $playerDeliveryRecord->target = $playerLotteryRecord->getTable();
                    $playerDeliveryRecord->target_id = $playerLotteryRecord->id;
                    $playerDeliveryRecord->platform_id = PlayerPlatformCash::PLATFORM_SELF;
                    $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_LOTTERY;
                    $playerDeliveryRecord->source = 'lottery_fixed';
                    $playerDeliveryRecord->amount = $fixedAllowLottery['amount'];
                    $playerDeliveryRecord->amount_before = $beforeAmount;
                    $playerDeliveryRecord->amount_after = $machineWallet->money;
                    $playerDeliveryRecord->tradeno = '';
                    $playerDeliveryRecord->remark = '固定彩金派彩';
                    $playerDeliveryRecord->user_id = 0;
                    $playerDeliveryRecord->user_name = '';
                    $playerDeliveryRecord->save();

                    // 扣减彩金池（从 lottery.amount 扣减）
                    $rate = $lotteryModel->rate > 0 ? $lotteryModel->rate : 100;
                    $baseDeductAmount = bcmul($lotteryModel->amount, bcdiv($rate, 100, 4), 2);
                    $lotteryModel->amount = bcsub($lotteryModel->amount, $baseDeductAmount, 2);

                    // 派彩成功后补充到保底金额（参考随机彩金逻辑）
                    if ($lotteryModel->auto_refill_status == 1 && $lotteryModel->auto_refill_amount > 0) {
                        $beforeRefillAmount = $lotteryModel->amount;

                        // 只有当彩池低于保底金额时才补充
                        if ($lotteryModel->amount < $lotteryModel->auto_refill_amount) {
                            $refillAmount = bcsub($lotteryModel->auto_refill_amount, $lotteryModel->amount, 4);
                            $lotteryModel->amount = $lotteryModel->auto_refill_amount;

                            // 记录派彩后补充日志
                            \support\Log::info('固定彩金池派彩后自动补充到保底金额:', [
                                'lottery_id' => $lotteryModel->id,
                                'lottery_name' => $lotteryModel->name,
                                'before_refill_amount' => $beforeRefillAmount,
                                'target_amount' => $lotteryModel->auto_refill_amount,
                                'refill_amount' => $refillAmount,
                                'after_refill_amount' => $lotteryModel->amount,
                                'deduct_amount' => $baseDeductAmount,
                                'player_id' => $this->player->id,
                                'uuid' => $this->player->uuid,
                                'machine_id' => $this->machine->id,
                                'trigger_time' => date('Y-m-d H:i:s'),
                            ]);
                        }
                    }

                    // 更新彩金池的最后中奖信息和中奖次数
                    $lotteryModel->last_player_id = $this->player->id;
                    $lotteryModel->last_player_name = $this->player->name;
                    $lotteryModel->last_award_amount = $fixedAllowLottery['amount'];
                    $lotteryModel->lottery_times = $lotteryModel->lottery_times + 1;

                    $lotteryModel->save();

                    DB::commit();

                    // 清除彩金缓存（事务提交后）
                    self::clearLotteryListCache($this->machine->type);

                    // 实时推送已禁用，改用定时任务推送（LotteryPoolSocket）
                    // self::pushLotteryPoolData();

                    if ($hasSend) {
                        sendSocketMessage('player-' . $this->player->id, $fixedAllowLottery);
                    }

                    // 发送中奖通知消息
                    sendSocketMessage('player-' . $this->player->id, [
                        'msg_type' => 'player_notice',
                        'player_id' => $this->player->id,
                        'notice_type' => Notice::TYPE_LOTTERY,
                        'notice_title' => $notice->title,
                        'notice_content' => $notice->content,
                        'amount' => $playerLotteryRecord->amount,
                        'machine_name' => $playerLotteryRecord->machine_name,
                        'machine_code' => $playerLotteryRecord->machine_code,
                        'lottery_name' => $playerLotteryRecord->lottery_name,
                        'lottery_type' => $playerLotteryRecord->lottery_type,
                        'game_type' => $playerLotteryRecord->game_type,
                        'lottery_multiple' => $playerLotteryRecord->lottery_multiple,
                        'lottery_rate' => $playerLotteryRecord->lottery_rate,
                        'notice_num' => Notice::query()->where('player_id', $this->player->id)->where('status', 0)->count('*')
                    ]);

                    // 发送全频道广播
                    $broadcastMessage = [
                        'msg_type' => 'machine_lottery_win_broadcast',
                        'lottery_id' => $playerLotteryRecord->lottery_id,
                        'lottery_name' => $playerLotteryRecord->lottery_name,
                        'lottery_type' => $playerLotteryRecord->lottery_type,
                        'game_type' => $this->machine->type,
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'machine_name' => $this->machine->name,
                        'player_id' => $this->player->id,
                        'player_name' => $this->player->name ?? $this->player->uuid,
                        'player_uuid' => $this->player->uuid,
                        'amount' => $playerLotteryRecord->amount,
                        'lottery_pool_amount' => $lotteryModel->amount,
                        'lottery_rate' => $playerLotteryRecord->lottery_rate,
                        'is_doubled' => $fixedAllowLottery['is_doubled'],
                        'title' => '🎊 恭喜玩家中奖！',
                        'content' => sprintf(
                            '恭喜玩家在%s机台 %s 中赢得 %s%d 彩金！',
                            $this->machine->code,
                            $playerLotteryRecord->lottery_name,
                            $fixedAllowLottery['is_doubled'] ? '【双倍】' : '',
                            $playerLotteryRecord->amount
                        ),
                    ];

                    // 发送到广播频道
                    sendSocketMessage('broadcast', $broadcastMessage);

                    // 发送到彩池频道
                    sendSocketMessage('group-lottery-pool', $broadcastMessage);
                } catch (\Exception $e) {
                    DB::rollback();
                    \support\Log::error('固定彩金派发失败', [
                        'error' => $e->getMessage(),
                        'lottery_id' => $fixedAllowLottery['lottery_id'] ?? null,
                        'player_id' => $this->player->id,
                    ]);
                    throw new Exception($e->getMessage());
                }
            }
        }

        return $playerLotteryRecord ?? null;
    }

    /**
     * 设置斯洛彩金数据
     * @param $lotteryType
     * @return $this
     */
    public function setSlotLotteryList($lotteryType = null): LotteryServices
    {
        $query = Lottery::where('status', 1)
            ->where('game_type', GameType::TYPE_SLOT)
            ->whereNull('deleted_at')
            ->orderBy('lottery_type', 'asc')
            ->orderBy('condition', 'desc');

        if ($lotteryType) {
            $query->where('lottery_type', $lotteryType);
        }
        $fixedSort = 0;
        $randomSort = 0;
        $list = $query->get();
        /** @var Lottery $lottery */
        foreach ($list as $lottery) {
            if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_FIXED) {
                $fixedSort++;
                $lottery->sort = $fixedSort;
            }
            if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_RANDOM) {
                $randomSort++;
                $lottery->sort = $randomSort;
            }
        }
        $this->slotLotteryList = $list;

        return $this;
    }

    /**
     * 设置钢珠彩金数据
     * @param $lotteryType
     * @return $this
     */
    public function setJackLotteryList($lotteryType = null): LotteryServices
    {
        $query = Lottery::where('status', 1)
            ->where('game_type', GameType::TYPE_STEEL_BALL)
            ->whereNull('deleted_at')
            ->orderBy('lottery_type', 'asc')
            ->orderBy('condition', 'desc');

        if ($lotteryType) {
            $query->where('lottery_type', $lotteryType);
        }
        $fixedSort = 0;
        $randomSort = 0;
        $list = $query->get();
        /** @var Lottery $lottery */
        foreach ($list as $lottery) {
            if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_FIXED) {
                $fixedSort++;
                $lottery->sort = $fixedSort;
            }
            if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_RANDOM) {
                $randomSort++;
                $lottery->sort = $randomSort;
            }
        }
        $this->jackLotteryList = $list;

        return $this;
    }

    /**
     * 清除彩金列表缓存
     * @param int $type 机台类型
     * @return bool
     */
    public static function clearLotteryListCache(int $type): bool
    {
        return Cache::delete(self::CACHE_KEY_LOTTERY_LIST . $type);
    }

    /**
     * 清除所有彩金相关缓存
     * @return void
     */
    public static function clearAllCache(): void
    {
        // 清除斯洛彩金缓存
        Cache::delete(self::CACHE_KEY_LOTTERY_LIST . GameType::TYPE_SLOT);
        // 清除钢珠彩金缓存
        Cache::delete(self::CACHE_KEY_LOTTERY_LIST . GameType::TYPE_STEEL_BALL);
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

            // 获取所有启用的彩金
            $lotteryList = Lottery::query()
                ->where('status', 1)
                ->where('lottery_type', Lottery::LOTTERY_TYPE_RANDOM)
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
                        $lastSyncKey = 'machine_lottery_last_sync:' . $lottery->id;
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
                self::clearAllCache();
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

    /**
     * 获取爆彩信息
     * @param Lottery $lottery
     * @return array
     */
    private function getBurstInfo(Lottery $lottery): array
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
     * @param Lottery $lottery
     * @return bool
     */
    private function startBurst(Lottery $lottery): bool
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
        \support\Log::info('【爆彩开启】彩金池触发爆彩（概率性触发）:', [
            'lottery_id' => $lottery->id,
            'lottery_name' => $lottery->name,
            'game_type' => $lottery->game_type,
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
     * 结束爆彩
     * @param Lottery $lottery
     * @return void
     */
    private function endBurst(Lottery $lottery): void
    {
        $this->sendBurstGlobalNotice($lottery, 'end');
        $redis = \support\Redis::connection();
        $redis->del(self::CACHE_KEY_BURST . $lottery->id);

        \support\Log::info('【爆彩结束】彩金池爆彩时间已结束:', [
            'lottery_id' => $lottery->id,
            'lottery_name' => $lottery->name,
            'game_type' => $lottery->game_type,
        ]);
    }

    /**
     * 计算爆彩概率倍数
     * 根据爆彩剩余时间，渐进式提升中奖概率
     * @param Lottery $lottery
     * @param int $elapsedSeconds 已经过的秒数
     * @return float
     */
    private function calculateBurstMultiplier(Lottery $lottery, int $elapsedSeconds): float
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
     * 根据彩池比例计算爆彩触发概率
     * @param Lottery $lottery
     * @param float $poolPercentage 当前彩池占最大彩池的百分比
     * @return float 返回触发概率（0-100）
     */
    private function getBurstTriggerProbability(Lottery $lottery, float $poolPercentage): float
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
     * @param Lottery $lottery
     * @return void
     */
    private function checkAndTriggerBurst(Lottery $lottery): void
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

        \support\Log::debug('爆彩概率检查:', [
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
     * @param Lottery $lottery
     * @return void
     */
    private function checkAndTriggerBurstWithDebounce(Lottery $lottery): void
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
            \support\Log::error('防抖爆彩检查失败:', [
                'lottery_id' => $lottery->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 更新机台报表（彩金统计）
     * @param PlayerLotteryRecord $playerLotteryRecord
     * @return void
     */
    private function updateMachineReport(PlayerLotteryRecord $playerLotteryRecord): void
    {
        try {
            $date = date('Y-m-d');
            /** @var MachineReport $machineReport */
            $machineReport = MachineReport::query()
                ->where('machine_id', $playerLotteryRecord->machine_id)
                ->where('date', $date)
                ->where('department_id', $playerLotteryRecord->department_id)
                ->where('odds', $playerLotteryRecord->odds)
                ->first();

            if (!empty($machineReport)) {
                $machineReport->lottery_amount = bcadd($machineReport->lottery_amount, $playerLotteryRecord->amount ?? 0, 2);
            } else {
                $machineReport = new MachineReport();
                $machineReport->machine_id = $playerLotteryRecord->machine_id;
                $machineReport->department_id = $playerLotteryRecord->department_id;
                $machineReport->lottery_amount = $playerLotteryRecord->amount;
                $machineReport->date = $date;
                $machineReport->odds = $playerLotteryRecord->odds;
            }
            $machineReport->save();

            \support\Log::info('更新机台报表彩金统计', [
                'machine_id' => $playerLotteryRecord->machine_id,
                'lottery_amount' => $playerLotteryRecord->amount,
                'total_lottery_amount' => $machineReport->lottery_amount,
                'date' => $date,
            ]);
        } catch (\Exception $e) {
            \support\Log::error('更新机台报表失败', [
                'error' => $e->getMessage(),
                'player_lottery_record_id' => $playerLotteryRecord->id,
            ]);
        }
    }

    /**
     * 发送爆彩全局通知
     * @param Lottery $lottery
     * @param string $type start|win|end
     * @param array $extraData
     * @return void
     */
    private function sendBurstGlobalNotice(Lottery $lottery, string $type, array $extraData = []): void
    {
        try {
            $message = [];
            $message['msg_type'] = 'machine_lottery_burst_notice';
            $message['lottery_id'] = $lottery->id;
            $message['lottery_name'] = $lottery->name;
            $message['game_type'] = $lottery->game_type;
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
                    '恭喜玩家在%s机台 %s 爆彩活动中赢得 %s%d 彩金！',
                    $extraData['machine_code'] ?? '',
                    $lottery->name,
                    $doubleText,
                    $extraData['amount'] ?? 0
                );
                $message['amount'] = $extraData['amount'] ?? 0;
                $message['player_name'] = $extraData['player_name'] ?? '';
                $message['machine_code'] = $extraData['machine_code'] ?? '';
                $message['is_doubled'] = $isDoubled ? 1 : 0;
            } elseif ($type === 'end') {
                // 爆彩结束通知
                $message['title'] = '⏰ 爆彩活动结束';
                $message['content'] = sprintf(
                    '%s 爆彩活动已结束，感谢参与！',
                    $lottery->name
                );
            }

            // 发送到全局广播频道
            sendSocketMessage('broadcast', $message);
        } catch (\Exception $e) {
            \support\Log::error('发送爆彩全局通知失败:', [
                'error' => $e->getMessage(),
                'lottery_id' => $lottery->id,
            ]);
        }
    }
}
