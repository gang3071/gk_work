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

    // 冷却期配置
    const COOLDOWN_DURATION = 1800;           // 冷却期时长（秒），30分钟
    const REDIS_KEY_LOTTERY_COOLDOWN = 'machine_lottery_cooldown:';  // 彩金冷却期键

    // 累计打码量配置
    const REDIS_KEY_ACCUMULATED_BET = 'player_%d_lottery_%d_accumulated_bet';  // 玩家累计打码量键

    // 统计数据键（仅随机彩金）
    const REDIS_KEY_LOTTERY_STATS_TOTAL = 'machine_lottery_stats:total:';      // 总开奖次数
    const REDIS_KEY_LOTTERY_STATS_WIN = 'machine_lottery_stats:win:';          // 总中奖次数
    const REDIS_KEY_LOTTERY_STATS_DAILY_TOTAL = 'machine_lottery_stats:daily:total:';  // 每日开奖次数
    const REDIS_KEY_LOTTERY_STATS_DAILY_WIN = 'machine_lottery_stats:daily:win:';      // 每日中奖次数

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
                return;
            }

            // 发送消息
            sendSocketMessage('group-lottery-pool', $messageData);

            // 更新最后推送时间和数据哈希（✅ 使用 setex 原子操作）
            $redis->setex(self::REDIS_KEY_LAST_PUSH_TIME, 86400 * 7, time());
            $redis->setex(self::REDIS_KEY_LAST_PUSH_HASH, 86400 * 7, $currentHash);
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
            if ($lottery->max_pool_amount > 0) {
                $amount = min($amount, floatval($lottery->max_pool_amount));
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

        // 计算本次累积的基数（增量）
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
                continue;
            }

            // 按该彩金的pool_ratio计算累积金额
            $addAmount = bcmul($baseAmount, bcdiv($lottery->pool_ratio, 100, 4), 4);

            if ($addAmount <= 0) {
                continue;
            }

            // 累加前检查保底金额：如果启用了保底金额且当前彩池低于保底金额，先补充到保底金额
            if ($lottery->auto_refill_status == 1 && $lottery->auto_refill_amount > 0) {
                if ($lottery->amount < $lottery->auto_refill_amount) {
                    $lottery->amount = $lottery->auto_refill_amount;
                }
            }

            $newAmount = bcadd($lottery->amount, $addAmount, 4);

            // 检查是否超过最大彩池限制
            if ($lottery->max_pool_amount > 0 && $newAmount > $lottery->max_pool_amount) {
                $newAmount = $lottery->max_pool_amount;
                $addAmount = bcsub($lottery->max_pool_amount, $lottery->amount, 4);
            }

            // 使用 Redis 原子操作累积彩金（性能优化）
            try {
                $redisKey = self::REDIS_KEY_LOTTERY_AMOUNT . $lottery->id;
                $redis = \support\Redis::connection()->client();

                // 获取累加前的Redis金额
                $beforeRedisAmount = (float)($redis->get($redisKey) ?? 0);

                // 使用 Redis 的 INCRBYFLOAT 原子操作累积
                $currentRedisAmount = $redis->incrByFloat($redisKey, (float)$addAmount);

                // 计算总彩池（DB + Redis）
                // 注意：不要更新内存中的 lottery.amount，同步时会从数据库 refresh() 重新读取
                // 避免内存值覆盖导致数据丢失（Redis累积金额会在同步时叠加到数据库值）
                // $lottery->amount = $newAmount;  // ← 已禁用
                // 优化：只在达到阈值或超过时间间隔时才同步到数据库
                $shouldSyncToDB = false;

                // 检查是否需要同步到数据库
                if ($currentRedisAmount >= self::DB_SYNC_THRESHOLD) {
                    $shouldSyncToDB = true;
                } else {
                    // 检查距离上次同步的时间
                    $lastSyncKey = 'machine_lottery_last_sync:' . $lottery->id;
                    $lastSync = $redis->get($lastSyncKey);

                    if (!$lastSync || (time() - $lastSync) >= self::DB_SYNC_INTERVAL) {
                        $shouldSyncToDB = true;
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
                        $lottery->amount = bcadd($lottery->amount, $accumulatedAmount, 4);

                        // ✅ 同步时也要检查保底金额（自动补充机制）
                        // 如果启用了保底金额且当前彩池低于保底金额，补充到保底金额
                        if ($lottery->auto_refill_status == 1 && $lottery->auto_refill_amount > 0) {
                            if ($lottery->amount < $lottery->auto_refill_amount) {
                                $lottery->amount = $lottery->auto_refill_amount;
                            }
                        }

                        // 更新数据库
                        $lottery->save();

                        // 清除 Redis 累积计数（重置为0）
                        $redis->del($redisKey);

                        // 更新最后同步时间（✅ 使用 setex 原子操作）
                        $lastSyncKey = 'machine_lottery_last_sync:' . $lottery->id;
                        $redis->setex($lastSyncKey, 86400 * 7, time());

                        // 清除彩金缓存
                        self::clearLotteryListCache($machineType);
                        // 注意：推送已在Redis累积时触发，这里不需要重复推送
                    }
                }
            } catch (\Exception $e) {
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
    /**
     * 检查彩金
     * @param float $incrementNum 本次游戏的增量（newNum - lastNum）
     * @return bool
     */
    public function checkLottery(float $incrementNum = 0): bool
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
            'next_lottery' => [],
            'created_at' => date('Y-m-d H:i:s')
        ];

        $condition = $this->getCondition();

        /** @var Lottery $lottery */
        foreach ($lotteryList as $key => $lottery) {
            // ===== 1. 固定彩金处理（基于条件触发，不检查打码量）=====
            if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_FIXED) {
                // 检查条件是否满足（固定彩金只看 condition，不看打码量）
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

            // ===== 2. 随机彩金处理（新版：概率模式 + 累计打码量）=====
            if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_RANDOM) {
                // 计算本次下注金额（根据机台类型和增量）
                $betAmount = $this->calculateBetAmount($incrementNum);

                // 🔧 临时调试日志
                \support\Log::info('随机彩金检查', [
                    'lottery_id' => $lottery->id,
                    'lottery_name' => $lottery->name,
                    'incrementNum' => $incrementNum,
                    'betAmount' => $betAmount,
                    'bet_amount_limit' => $lottery->bet_amount,
                    'win_ratio' => $lottery->win_ratio,
                ]);

                // ✅ 累计打码量机制（2026-07-30）
                // 检查是否启用最低打码量限制
                if ($lottery->bet_amount > 0) {
                    // 🔧 临时调试日志
                    \support\Log::info('进入打码量累积逻辑', [
                        'lottery_id' => $lottery->id,
                        'betAmount' => $betAmount,
                        'requiredAmount' => $lottery->bet_amount,
                    ]);

                    // 累加玩家的打码量
                    $accumulatedResult = $this->accumulateBetAmount($lottery->id, $betAmount);

                    // 🔧 临时调试日志
                    \support\Log::info('打码量累积结果', [
                        'lottery_id' => $lottery->id,
                        'before' => $accumulatedResult['before'],
                        'after' => $accumulatedResult['after'],
                        'can_participate' => $accumulatedResult['can_participate'],
                        'participate_times' => $accumulatedResult['participate_times'],
                    ]);

                    // 如果累计未达到最低打码量，跳过抽奖
                    if (!$accumulatedResult['can_participate']) {
                        \support\Log::info('打码量不足，跳过抽奖', [
                            'lottery_id' => $lottery->id,
                            'accumulated' => $accumulatedResult['after'],
                            'required' => $lottery->bet_amount,
                        ]);
                        continue;
                    }

                    // 获取并处理爆彩状态
                    $burstInfo = $this->getBurstInfo($lottery);

                    // 处理派彩检查（参与次数由累计打码量决定）
                    // ✅ 修复：bet 字段应记录本次下注金额，而不是最低打码量
                    $this->processLotteryCheck(
                        $lottery,
                        $betAmount,  // 使用本次下注金额作为 bet 记录
                        $accumulatedResult['participate_times'],
                        $burstInfo
                    );
                } else {
                    // 未启用最低打码量限制，使用原逻辑
                    // 获取并处理爆彩状态
                    $burstInfo = $this->getBurstInfo($lottery);

                    // 处理派彩检查（每次下注检查一次）
                    $this->processLotteryCheck($lottery, $betAmount, 1, $burstInfo);
                }
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
     * 计算下注金额（用于累计打码量）
     * @return float
     */
    /**
     * 计算下注金额（用于打码量累积）
     * @param float $incrementNum 本次游戏的增量（newNum - lastNum）
     * @return float
     */
    private function calculateBetAmount(float $incrementNum): float
    {
        // Slot机和钢珠机：使用本次增量 × turn_used_point 计算下注金额
        // ✅ 修复：与彩池累加逻辑一致，使用 incrementNum（newNum - lastNum）
        if ($this->machine->type == GameType::TYPE_SLOT || $this->machine->type == GameType::TYPE_STEEL_BALL) {
            $turnUsedPoint = $this->machine->machineCategory->turn_used_point ?? 0;

            // 使用本次增量计算下注金额（与彩池累加逻辑一致）
            $betAmount = bcmul($incrementNum, $turnUsedPoint, 4);

            return floatval($betAmount);
        }

        return 0;
    }

    /**
     * 累计玩家打码量（用于最低打码量限制）
     *
     * ✅ 使用 Lua 脚本实现原子操作，避免并发竞态条件
     *
     * @param int $lotteryId 彩金ID
     * @param float $betAmount 本次下注金额
     * @return array [
     *   'before' => 累加前金额,
     *   'after' => 累加后金额,
     *   'can_participate' => 是否可以参与抽奖,
     *   'participate_times' => 可参与次数
     * ]
     */
    private function accumulateBetAmount(int $lotteryId, float $betAmount): array
    {
        \support\Log::info('🔧 accumulateBetAmount 方法开始', [
            'lottery_id' => $lotteryId,
            'bet_amount' => $betAmount,
            'player_id' => $this->player->id,
        ]);

        $redis = \support\Redis::connection()->client();

        // Redis 键：player_{player_id}_lottery_{lottery_id}_accumulated_bet
        $redisKey = sprintf(
            self::REDIS_KEY_ACCUMULATED_BET,
            $this->player->id,
            $lotteryId
        );

        \support\Log::info('🔧 准备查询彩金配置', [
            'lottery_id' => $lotteryId,
        ]);

        // 获取彩金配置的最低打码量
        $lottery = Lottery::find($lotteryId);
        if (!$lottery) {
            \support\Log::error('累计打码量：彩金不存在', [
                'lottery_id' => $lotteryId,
                'player_id' => $this->player->id,
            ]);
            return [
                'before' => 0,
                'after' => 0,
                'can_participate' => false,
                'participate_times' => 0,
            ];
        }

        \support\Log::info('🔧 彩金配置查询成功', [
            'lottery_id' => $lotteryId,
            'bet_amount_limit' => $lottery->bet_amount,
        ]);

        $requiredAmount = $lottery->bet_amount ?? 0;

        // ✅ 使用 Lua 脚本原子性累加打码量并计算参与次数
        $lua = <<<'LUA'
-- KEYS[1] = Redis 键
-- ARGV[1] = 本次下注金额
-- ARGV[2] = 最低打码量
-- ARGV[3] = 过期时间（秒）

local key = KEYS[1]
local betAmount = tonumber(ARGV[1]) or 0
local requiredAmount = tonumber(ARGV[2]) or 0
local ttl = tonumber(ARGV[3]) or 604800

-- 获取当前累计金额
local before = tonumber(redis.call('GET', key)) or 0

-- 累加本次下注金额
local after = before + betAmount

-- 计算可以参与的次数和剩余金额
local participateTimes = 0
local canParticipate = 0
local remaining = after

if requiredAmount > 0 and after >= requiredAmount then
    -- 计算可以参与几次（向下取整）
    participateTimes = math.floor(after / requiredAmount)
    canParticipate = 1

    -- 计算剩余金额（取模）
    remaining = after - (participateTimes * requiredAmount)
end

-- 原子性更新 Redis（带过期时间）
redis.call('SETEX', key, ttl, tostring(remaining))

-- 返回结果
return cjson.encode({
    before = before,
    after = after,
    can_participate = canParticipate == 1,
    participate_times = participateTimes,
    remaining = remaining
})
LUA;

        try {
            \support\Log::info('🔧 准备执行 Lua 脚本', [
                'redis_key' => $redisKey,
                'bet_amount' => $betAmount,
                'required_amount' => $requiredAmount,
            ]);

            $resultJson = $redis->eval(
                $lua,
                1,  // KEYS 数量
                $redisKey,         // KEYS[1]
                $betAmount,        // ARGV[1]
                $requiredAmount,   // ARGV[2]
                86400 * 7          // ARGV[3] - 7天过期
            );

            \support\Log::info('🔧 Lua 脚本执行完成', [
                'result_json' => $resultJson,
            ]);

            $result = json_decode($resultJson, true);

            // ✅ 检查 JSON 解析是否成功
            if (!is_array($result)) {
                throw new \Exception('Lua 脚本返回的 JSON 解析失败: ' . substr($resultJson, 0, 100));
            }

            \support\Log::info('🔧 accumulateBetAmount 方法返回', [
                'result' => $result,
            ]);

            return [
                'before' => (float)($result['before'] ?? 0),
                'after' => (float)($result['after'] ?? 0),
                'can_participate' => (bool)($result['can_participate'] ?? false),
                'participate_times' => (int)($result['participate_times'] ?? 0),
            ];

        } catch (\Exception $e) {
            \support\Log::error('累计打码量 Lua 脚本执行失败', [
                'lottery_id' => $lotteryId,
                'player_id' => $this->player->id,
                'bet_amount' => $betAmount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 降级处理：返回不能参与
            return [
                'before' => 0,
                'after' => 0,
                'can_participate' => false,
                'participate_times' => 0,
            ];
        }
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
        // 🔧 临时禁用：冷却期检查（方便测试）
        // TODO: 测试完成后恢复此检查
        // if ($this->isInCooldown($lottery->id)) {
        //     return;
        // }

        // 应用爆彩概率倍数到中奖检查
        // 🔧 修复：使用sprintf格式化，避免科学计数法导致bcmul报错（2026-05-11）
        $winRatioStr = sprintf("%.9f", $lottery->win_ratio);
        $multiplierStr = sprintf("%.9f", $burstInfo['multiplier']);
        $adjustedWinRatio = bcmul($winRatioStr, $multiplierStr, 9);

        // 循环检查多次派彩机会
        for ($i = 1; $i <= $participateTimes; $i++) {
            // 记录总抽奖次数（仅随机彩金）
            if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_RANDOM) {
                $this->incrementLotteryStats($lottery->id, 'total', 1);
            }

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
                // 记录中奖次数（仅随机彩金）
                if ($lottery->lottery_type == Lottery::LOTTERY_TYPE_RANDOM) {
                    $this->incrementLotteryStats($lottery->id, 'win', 1);
                }

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
                DB::rollback();
                return false;
            }

            // 验证玩家钱包存在
            $machineWallet = $this->player->machine_wallet()->first();
            if (!$machineWallet) {
                DB::rollback();
                return false;
            }

            // 获取玩家当前余额（用于记录交易前余额）
            $beforeAmount = WalletService::getBalance($this->player->id);

            // 创建派彩记录
            $playerLotteryRecord = $this->createLotteryRecord($lottery, $amount, $lotteryMultiple, $bet, $isDoubled);

            // 记录中奖日志
            $this->logWinning($lottery, $amount, $burstInfo, $attemptIndex, $totalAttempts, $isDoubled);

            // 发送站内信
            $notice = $this->sendNotice($playerLotteryRecord->id, $playerLotteryRecord->lottery_name);

            // 创建交易记录（先记录预期的交易，余额变化在事务提交后执行）
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
            $playerDeliveryRecord->amount_after = bcadd($beforeAmount, $amount, 2);  // 预期余额
            $playerDeliveryRecord->tradeno = '';
            $playerDeliveryRecord->remark = '随机彩金派彩';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            // 扣减彩金池（从lottery.amount扣减）
            // 根据rate计算实际扣减金额
            $rate = $lottery->rate > 0 ? $lottery->rate : 100;
            $baseDeductAmount = bcmul($lottery->amount, bcdiv($rate, 100, 4), 2);
            $lottery->amount = bcsub($lottery->amount, $baseDeductAmount, 2);

            // 派彩成功后补充到目标金额（如果启用了自动补充）
            if ($lottery->auto_refill_status == 1 && $lottery->auto_refill_amount > 0) {
                // 只有当彩池低于目标金额时才补充
                if ($lottery->amount < $lottery->auto_refill_amount) {
                    $lottery->amount = $lottery->auto_refill_amount;
                }
            }

            // 更新彩金池的最后中奖信息和中奖次数
            $lottery->last_player_id = $this->player->id;
            $lottery->last_player_name = $this->player->name;
            $lottery->last_award_amount = $amount;
            $lottery->lottery_times = $lottery->lottery_times + 1;

            $lottery->save();

            // ✅ 提交数据库事务（此时所有数据库操作成功）
            DB::commit();

            // ✅ 事务提交后，更新 Redis 余额（两阶段提交）
            // 优势：数据库操作失败时 Redis 不会被修改，保证数据一致性
            // 风险：Redis 操作失败时数据库已提交，需要补偿机制
            try {
                $incrementResult = WalletService::atomicIncrement($this->player->id, $amount);
                $newBalance = $incrementResult['balance'];

                // 更新交易记录的实际余额（异步更新，不影响主流程）
                try {
                    $playerDeliveryRecord->amount_after = $newBalance;
                    $playerDeliveryRecord->save();
                } catch (\Exception $e) {
                    // 余额记录更新失败不影响主流程
                    \support\Log::warning('更新交易记录实际余额失败', [
                        'delivery_id' => $playerDeliveryRecord->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Exception $e) {
                // ❌ Redis 加款失败（严重问题：数据库已提交但 Redis 未更新）
                // 记录补偿日志，需要人工或定时任务补偿
                \support\Log::critical('彩金派发：Redis 加款失败，需要补偿', [
                    'player_id' => $this->player->id,
                    'amount' => $amount,
                    'lottery_id' => $lottery->id,
                    'delivery_record_id' => $playerDeliveryRecord->id,
                    'lottery_record_id' => $playerLotteryRecord->id,
                    'error' => $e->getMessage(),
                    'compensate_action' => 'WalletService::atomicIncrement',
                    'compensate_params' => [
                        'player_id' => $this->player->id,
                        'amount' => $amount,
                    ],
                ]);

                // 尝试回滚彩池扣减（尽力而为，可能失败）
                try {
                    $lottery->amount = bcadd($lottery->amount, $baseDeductAmount, 2);
                    $lottery->lottery_times = $lottery->lottery_times - 1;
                    $lottery->save();
                } catch (\Exception $rollbackException) {
                    \support\Log::critical('彩池回滚失败', [
                        'lottery_id' => $lottery->id,
                        'error' => $rollbackException->getMessage(),
                    ]);
                }

                // 不抛出异常，避免影响后续流程
                // return false;  // 可选：返回 false 表示失败
            }

            // 设置冷却期：中奖后30分钟内不再触发中奖
            $this->setCooldown($lottery->id);

            // 清除彩金缓存（事务提交后）
            self::clearLotteryListCache($this->machine->type);

            // 实时推送已禁用，改用定时任务推送（LotteryPoolSocket）
            // self::pushLotteryPoolData();

            // 发送派彩和通知消息
            $this->sendWinningMessages($playerLotteryRecord, $lottery, $notice, $burstInfo, $isDoubled);

            return true;
        } catch (\Exception $e) {
            DB::rollback();
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
                'next_lottery' => [],
                'created_at' => date('Y-m-d H:i:s')
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

                    // ✅ 返回 sort 最小的彩金（门槛最低的）
                    // 先满足触发条件，然后在满足条件的彩金中选 sort 最小的
                    if ($fixedAllowLottery['lottery_sort'] === '' || $lottery->sort < $fixedAllowLottery['lottery_sort']) {
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
                // ✅ 获取语言（默认繁体中文）
                $lang = locale() ?? 'zh_TW';

                // ✅ 组装下一档彩金信息
                $nextLottery = null;

                // 查找下一档彩金（sort 比当前小的，即门槛更高的）
                // lotteryList 按 order DESC 排序，sort 按遍历顺序生成
                // sort 越大 = 门槛越低，sort 越小 = 门槛越高
                // 下一档 = sort 比当前小的彩金中最接近的
                $closestLottery = null;
                foreach ($lotteryList as $lottery) {
                    if ($lottery->condition > $condition) {
                        // 记录最后一个符合条件的（condition 最接近当前值的）
                        $closestLottery = $lottery;
                    }
                }

                if ($closestLottery) {
                    // 计算下一档奖励金额（考虑双倍、最大金额限制）
                    $nextRate = $closestLottery->rate > 0 ? $closestLottery->rate : 100;
                    $nextAmount = bcmul($closestLottery->amount, bcdiv($nextRate, 100, 4), 2);
                    if ($this->shouldApplyDouble($closestLottery)) {
                        $nextAmount = bcmul($nextAmount, 2, 2);
                    }
                    if ($closestLottery->max_status == 1 && $closestLottery->max_amount > 0 && $nextAmount > $closestLottery->max_amount) {
                        $nextAmount = floatval($closestLottery->max_amount);
                    }
                    $nextAmount = floor($nextAmount);

                    $nextLottery = [
                        'name' => $closestLottery->name,
                        'condition' => $closestLottery->condition,
                        'amount' => $nextAmount,
                    ];
                }

                // ✅ 生成多语言提示文案
                if ($nextLottery) {
                    // 有下一档彩金
                    $lotteryHint = trans('lottery_hint_with_next', [
                        '{current_name}' => $fixedAllowLottery['lottery_name'],
                        '{current_amount}' => $fixedAllowLottery['amount'],
                        '{next_condition}' => $nextLottery['condition'],
                        '{next_name}' => $nextLottery['name'],
                        '{next_amount}' => $nextLottery['amount'],
                    ], 'message', $lang);
                } else {
                    // 已是最高档位
                    $lotteryHint = trans('lottery_hint_max', [
                        '{current_name}' => $fixedAllowLottery['lottery_name'],
                        '{current_amount}' => $fixedAllowLottery['amount'],
                    ], 'message', $lang);
                }

                // ✅ 记录语言和翻译结果
                return [
                    'has_win' => 1,
                    'lottery_name' => $fixedAllowLottery['lottery_name'],
                    'amount' => $fixedAllowLottery['amount'],
                    'current_condition' => $condition,
                    'next_lottery' => $nextLottery,
                    'lottery_hint' => $lotteryHint,  // ← 多语言提示文案
                ];
            }
            if (isset($fixedAllowLottery['amount']) && $fixedAllowLottery['amount'] > 0) {
                // 增加业务锁（参考随机彩金逻辑）
                $actionLockerKey = 'machine_lottery_pool_fixed_locker_' . $fixedAllowLottery['lottery_id'];
                $lock = Locker::lock($actionLockerKey, 2, true);
                if (!$lock->acquire()) {
                    return null;
                }

                DB::beginTransaction();
                try {
                    // 重新加载彩金数据，检查余额（参考随机彩金逻辑）
                    /** @var Lottery $lotteryModel */
                    $lotteryModel = Lottery::query()->where('id', $fixedAllowLottery['lottery_id'])->lockForUpdate()->first();
                    if (!$lotteryModel) {
                        DB::rollback();
                        return null;
                    }

                    if ($lotteryModel->amount < $fixedAllowLottery['amount']) {
                        DB::rollback();
                        return null;
                    }

                    // 验证玩家钱包存在
                    $machineWallet = $this->player->machine_wallet()->first();
                    if (!$machineWallet) {
                        DB::rollback();
                        return null;
                    }

                    // 获取玩家当前余额（用于记录交易前余额）
                    $beforeAmount = \app\service\WalletService::getBalance($this->player->id);

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
                    // 发送站内信
                    $notice = $this->sendNotice($playerLotteryRecord->id, $playerLotteryRecord->lottery_name);

                    // 创建交易记录（先记录预期的交易，余额变化在事务提交后执行）
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
                    $playerDeliveryRecord->amount_after = bcadd($beforeAmount, $fixedAllowLottery['amount'], 2);  // 预期余额
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
                        }
                    }

                    // 更新彩金池的最后中奖信息和中奖次数
                    $lotteryModel->last_player_id = $this->player->id;
                    $lotteryModel->last_player_name = $this->player->name;
                    $lotteryModel->last_award_amount = $fixedAllowLottery['amount'];
                    $lotteryModel->lottery_times = $lotteryModel->lottery_times + 1;

                    $lotteryModel->save();

                    // ✅ 提交数据库事务（此时所有数据库操作成功）
                    DB::commit();

                    // ✅ 事务提交后，更新 Redis 余额（两阶段提交）
                    // 优势：数据库操作失败时 Redis 不会被修改，保证数据一致性
                    // 风险：Redis 操作失败时数据库已提交，需要补偿机制
                    try {
                        $result = \app\service\WalletService::add($this->player->id, $fixedAllowLottery['amount']);
                        if ($result['success']) {
                            $afterAmount = $result['balance'];

                            // 更新交易记录的实际余额（异步更新，不影响主流程）
                            try {
                                $playerDeliveryRecord->amount_after = $afterAmount;
                                $playerDeliveryRecord->save();
                            } catch (\Exception $e) {
                                // 余额记录更新失败不影响主流程
                                \support\Log::warning('更新固定彩金交易记录实际余额失败', [
                                    'delivery_id' => $playerDeliveryRecord->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        } else {
                            throw new \Exception($result['error'] ?? 'Redis 加款失败');
                        }
                    } catch (\Exception $e) {
                        // ❌ Redis 加款失败（严重问题：数据库已提交但 Redis 未更新）
                        // 记录补偿日志，需要人工或定时任务补偿
                        \support\Log::critical('固定彩金派发：Redis 加款失败，需要补偿', [
                            'player_id' => $this->player->id,
                            'amount' => $fixedAllowLottery['amount'],
                            'lottery_id' => $fixedAllowLottery['lottery_id'],
                            'delivery_record_id' => $playerDeliveryRecord->id,
                            'lottery_record_id' => $playerLotteryRecord->id,
                            'error' => $e->getMessage(),
                            'compensate_action' => 'WalletService::add',
                            'compensate_params' => [
                                'player_id' => $this->player->id,
                                'amount' => $fixedAllowLottery['amount'],
                            ],
                        ]);

                        // 尝试回滚彩池扣减（尽力而为，可能失败）
                        try {
                            $lotteryModel->refresh();  // 重新加载最新数据
                            $lotteryModel->amount = bcadd($lotteryModel->amount, $baseDeductAmount, 2);
                            $lotteryModel->lottery_times = $lotteryModel->lottery_times - 1;
                            $lotteryModel->save();
                        } catch (\Exception $rollbackException) {
                            \support\Log::critical('固定彩金彩池回滚失败', [
                                'lottery_id' => $fixedAllowLottery['lottery_id'],
                                'error' => $rollbackException->getMessage(),
                            ]);
                        }

                        // 不抛出异常，避免影响后续流程
                    }

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
            ->orderBy('sort', 'desc');

        if ($lotteryType) {
            $query->where('lottery_type', $lotteryType);
        }
        $list = $query->get();
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
            ->orderBy('sort', 'desc');

        if ($lotteryType) {
            $query->where('lottery_type', $lotteryType);
        }
        $list = $query->get();
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
     * 清除玩家的累计打码量（玩家离开机台时调用）
     *
     * @param int $playerId 玩家ID
     * @param int $machineType 机台类型（1=Slot, 2=钢珠）
     * @return int 清除的键数量
     */
    public static function clearPlayerAccumulatedBet(int $playerId, int $machineType): int
    {
        try {
            $redis = \support\Redis::connection()->client();

            // 获取该机台类型的所有彩金
            $lotteries = Lottery::query()
                ->where('status', 1)
                ->where('game_type', $machineType)
                ->where('lottery_type', Lottery::LOTTERY_TYPE_RANDOM)
                ->whereNull('deleted_at')
                ->get();

            $clearedCount = 0;

            /** @var Lottery $lottery */
            foreach ($lotteries as $lottery) {
                // 只清除启用了打码量限制的彩金
                if ($lottery->bet_amount > 0) {
                    $redisKey = sprintf(self::REDIS_KEY_ACCUMULATED_BET, $playerId, $lottery->id);
                    $existed = $redis->del($redisKey);

                    if ($existed) {
                        $clearedCount++;
                    }
                }
            }

            return $clearedCount;
        } catch (\Exception $e) {
            \support\Log::error('清除玩家累计打码量失败:', [
                'player_id' => $playerId,
                'machine_type' => $machineType,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * 增加彩金统计数据（仅随机彩金）
     *
     * @param int $lotteryId 彩金ID
     * @param string $type total|win
     * @param int $count 增加数量
     * @return void
     */
    private function incrementLotteryStats(int $lotteryId, string $type, int $count = 1): void
    {
        try {
            $redis = \support\Redis::connection()->client();
            $today = date('Y-m-d');

            // 获取清除时间标记（用于惰性清理）
            $clearTimeKey = 'machine_lottery_stats:clear_time:' . $lotteryId;
            $currentClearTime = $redis->get($clearTimeKey) ?: '';

            if ($type === 'total') {
                $statsKey = self::REDIS_KEY_LOTTERY_STATS_TOTAL . $lotteryId;
                $dailyKey = self::REDIS_KEY_LOTTERY_STATS_DAILY_TOTAL . $lotteryId . ':' . $today;
                $versionKey = $statsKey . ':version';

                // Lua 脚本：检查版本，必要时清零，然后累加
                $luaScript = <<<'LUA'
local stats_key = KEYS[1]
local daily_key = KEYS[2]
local version_key = KEYS[3]

local increment = tonumber(ARGV[1])
local current_clear_time = ARGV[2]
local ttl = tonumber(ARGV[3])

local old_total = tonumber(redis.call('GET', stats_key) or 0)
local old_daily = tonumber(redis.call('GET', daily_key) or 0)

local last_version = redis.call('GET', version_key) or ''

local was_cleared = 0
if last_version ~= current_clear_time then
    redis.call('SET', stats_key, 0)
    redis.call('SET', daily_key, 0)
    redis.call('SET', version_key, current_clear_time)
    old_total = 0
    old_daily = 0
    was_cleared = 1
end

local new_total = redis.call('INCRBY', stats_key, increment)
local new_daily = redis.call('INCRBY', daily_key, increment)
redis.call('EXPIRE', daily_key, ttl)

return {old_total, new_total, old_daily, new_daily, was_cleared}
LUA;

                $result = $redis->eval($luaScript, [
                    $statsKey,
                    $dailyKey,
                    $versionKey,
                    $count,
                    $currentClearTime,
                    86400 * 2 // TTL: 2天
                ], 3);

            } elseif ($type === 'win') {
                $statsKey = self::REDIS_KEY_LOTTERY_STATS_WIN . $lotteryId;
                $dailyKey = self::REDIS_KEY_LOTTERY_STATS_DAILY_WIN . $lotteryId . ':' . $today;
                $versionKey = $statsKey . ':version';

                $luaScript = <<<'LUA'
local stats_key = KEYS[1]
local daily_key = KEYS[2]
local version_key = KEYS[3]

local increment = tonumber(ARGV[1])
local current_clear_time = ARGV[2]
local ttl = tonumber(ARGV[3])

local old_total = tonumber(redis.call('GET', stats_key) or 0)
local old_daily = tonumber(redis.call('GET', daily_key) or 0)

local last_version = redis.call('GET', version_key) or ''

local was_cleared = 0
if last_version ~= current_clear_time then
    redis.call('SET', stats_key, 0)
    redis.call('SET', daily_key, 0)
    redis.call('SET', version_key, current_clear_time)
    old_total = 0
    old_daily = 0
    was_cleared = 1
end

local new_total = redis.call('INCRBY', stats_key, increment)
local new_daily = redis.call('INCRBY', daily_key, increment)
redis.call('EXPIRE', daily_key, ttl)

return {old_total, new_total, old_daily, new_daily, was_cleared}
LUA;

                $result = $redis->eval($luaScript, [
                    $statsKey,
                    $dailyKey,
                    $versionKey,
                    $count,
                    $currentClearTime,
                    86400 * 2
                ], 3);
            }
        } catch (\Exception $e) {
            \support\Log::error('记录彩金统计失败', [
                'lottery_id' => $lotteryId,
                'type' => $type,
                'count' => $count,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取彩金统计数据
     *
     * @param int $lotteryId 彩金ID
     * @return array
     */
    public static function getLotteryStats(int $lotteryId): array
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
     * 清除彩金统计数据
     *
     * @param int $lotteryId 彩金ID
     * @return bool
     */
    public static function clearLotteryStats(int $lotteryId): bool
    {
        try {
            $redis = \support\Redis::connection()->client();

            // 设置清除时间标记（用于惰性清理）（✅ 使用 setex 原子操作）
            $clearTimeKey = 'machine_lottery_stats:clear_time:' . $lotteryId;
            $redis->setex($clearTimeKey, 86400 * 30, time());  // 30天过期

            // 删除所有统计键
            $keysToDelete = [
                self::REDIS_KEY_LOTTERY_STATS_TOTAL . $lotteryId,
                self::REDIS_KEY_LOTTERY_STATS_WIN . $lotteryId,
                self::REDIS_KEY_LOTTERY_STATS_TOTAL . $lotteryId . ':version',
                self::REDIS_KEY_LOTTERY_STATS_WIN . $lotteryId . ':version',
            ];

            // 删除所有每日统计键（使用 SCAN 查找）
            $pattern = 'machine_lottery_stats:daily:*:' . $lotteryId . ':*';
            $cursor = 0;
            do {
                $result = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
                $cursor = $result[0];
                $keys = $result[1] ?? [];

                if (!empty($keys)) {
                    $keysToDelete = array_merge($keysToDelete, $keys);
                }
            } while ($cursor != 0);

            // 批量删除
            if (!empty($keysToDelete)) {
                $redis->del(...$keysToDelete);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
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

                        // 更新最后同步时间（✅ 使用 setex 原子操作）
                        $lastSyncKey = 'machine_lottery_last_sync:' . $lottery->id;
                        $redis->setex($lastSyncKey, 86400 * 7, time());

                        $result['synced_count']++;
                        $result['details'][] = [
                            'lottery_id' => $lottery->id,
                            'name' => $lottery->name,
                            'old_amount' => $oldAmount,
                            'accumulated' => $accumulatedAmount,
                            'new_amount' => $lottery->amount,
                        ];
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'lottery_id' => $lottery->id,
                        'error' => $e->getMessage(),
                    ];
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
        // 设置爆彩开始时间，过期时间为爆彩持续时长+缓冲时间
        $expireSeconds = ($lottery->burst_duration + self::BURST_DURATION_BUFFER) * 60;
        $redis->setex($key, $expireSeconds, $currentTime);

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

            // 更新最后检查时间（✅ 使用 setex 原子操作）
            $redis->setex($lastCheckKey, 3600, time());  // 1小时过期

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

    /**
     * 检查彩金是否在冷却期内
     * @param int $lotteryId
     * @return bool
     */
    private function isInCooldown(int $lotteryId): bool
    {
        try {
            $redis = \support\Redis::connection()->client();
            $key = self::REDIS_KEY_LOTTERY_COOLDOWN . $lotteryId;
            return $redis->exists($key) > 0;
        } catch (\Exception $e) {
            \support\Log::error('检查彩金冷却期失败', [
                'lottery_id' => $lotteryId,
                'error' => $e->getMessage(),
            ]);
            // 出错时保守处理，不阻止中奖检查
            return false;
        }
    }

    /**
     * 获取彩金剩余冷却时间（秒）
     * @param int $lotteryId
     * @return int
     */
    private function getCooldownRemainingTime(int $lotteryId): int
    {
        try {
            $redis = \support\Redis::connection()->client();
            $key = self::REDIS_KEY_LOTTERY_COOLDOWN . $lotteryId;
            $ttl = $redis->ttl($key);
            return $ttl > 0 ? $ttl : 0;
        } catch (\Exception $e) {
            \support\Log::error('获取彩金冷却期剩余时间失败', [
                'lottery_id' => $lotteryId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * 设置彩金冷却期（中奖后30分钟内不再触发中奖）
     * @param int $lotteryId
     * @return void
     */
    private function setCooldown(int $lotteryId): void
    {
        try {
            $redis = \support\Redis::connection()->client();
            $key = self::REDIS_KEY_LOTTERY_COOLDOWN . $lotteryId;
            $redis->setex($key, self::COOLDOWN_DURATION, time());
        } catch (\Exception $e) {
            \support\Log::error('设置彩金冷却期失败', [
                'lottery_id' => $lotteryId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
