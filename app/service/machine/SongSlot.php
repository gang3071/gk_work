<?php

namespace app\service\machine;

use app\model\GameType;
use app\model\Machine;
use app\model\MachineLotteryRecord;
use app\model\Notice;
use Exception;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Str;
use support\Cache;
use support\Log;
use support\Redis;
use Webman\Push\PushException;
use Webman\RedisQueue\Client;

/**
 * Class SongSlot
 * @property int $auto 自动状态
 * * @property int $reward_status 开奖状态
 * * @property int $play_start_time 开始游戏时间
 * * @property int $gaming_user_id 游戏中玩家
 * * @property int $gaming 是否游戏中
 * * @property int $point 当前分数
 * * @property int $score 当前得分
 * * @property int $bet 机台压分
 * * @property int $last_play_time 最后游戏时间
 * * @property int $win 机台总得分
 * * @property int $bb bb
 * * @property int $rb rb
 * * @property int $keep_seconds 保留时长
 * * @property int $keeping 保留状态
 * * @property int $keeping_user_id 保留玩家
 * * @property int $last_keep_at 最后保留时间
 * * @property int $player_pressure 玩家進入時原始壓分
 * * @property int $player_score 玩家進入時原始得分
 * * @property int $player_open_point 玩家开分
 * * @property int $player_wash_point 玩家洗分
 * * @property int $last_point_at 玩家最后上下分时间
 * * @property int $action_time 操作时间
 * * @property int $change_point_card_status 开分卡状态
 * * @property int $gift_bet 玩家开分增点时押注
 * * @property int $gift_condition 增点完成条件
 * * @property int $now_turn 当前转数
 * * @property int $has_lock 机台锁
 * * @property int $pre_wash_point 预洗分点数
 * *
 * * @package app\service\machine
 */
class SongSlot extends MachineServices implements BaseMachine
{
    const ALL = 'all'; //机台状态
    const OPEN_ANY_POINT = 'afca'; //开任意数
    const WASH_ZERO = 'afcc'; //洗分&清零
    const TESTING = 'afc0'; //心跳
    const TESTING2 = 'afc6'; //心跳

    const READ_SCORE = 'afcbc5'; //读取开分
    const READ_WIN = 'afcbc9'; //讀取得分
    const READ_BET = 'afcbc7'; //读取压分
    const READ_STATUS = 'afcbc3'; //读取当前状态

    const GET_SCORE = 'afc5'; //读取开分卡分数
    const GET_WIN = 'afc9'; //讀取 得分
    const GET_BET = 'afc7'; //读取 BET
    const GET_STATUS = 'afc3'; //读取当前状态

    const REWARD_SWITCH = 'afceb8'; //大賞燈切換
    const CHECK = 'afcfb4'; //故排
    const START = 'afceb2'; //启动
    const OUT_ON = 'afceb6'; //启动自动
    const OUT_OFF = 'afceb2'; //停止自动
    const STOP_ONE = 'afceb3'; //停1
    const STOP_TWO = 'afceb4'; //停2
    const STOP_THREE = 'afceb5'; //停3
    const MACHINE_OPEN = 'afcebe'; //开机
    const MACHINE_CLOSE = 'afcebc'; //关机
    const ALL_DOWN = 'afcfba'; //清除历史记录

    public $cacheData = [];

    public $expirationTime = 5000000; // 3秒内返回

    public $log = null;

    public function __construct(Machine $machine, $lang = 'zh_CN')
    {
        $this->machine = $machine;
        $this->cacheKey = self::CACHE_PREFIX . $this->machine->id;
        $this->cacheDataKey = self::MACHINE_DATA_PREFIX . $this->machine->id;
        $this->cacheDataKeyArr = [
            $this->cacheDataKey . '_auto',
            $this->cacheDataKey . '_reward_status',
            $this->cacheDataKey . '_play_start_time',
            $this->cacheDataKey . '_gaming_user_id',
            $this->cacheDataKey . '_gaming',
            $this->cacheDataKey . '_point',
            $this->cacheDataKey . '_score',
            $this->cacheDataKey . '_bet',
            $this->cacheDataKey . '_last_play_time',
            $this->cacheDataKey . '_win',
            $this->cacheDataKey . '_keep_seconds',
            $this->cacheDataKey . '_keeping',
            $this->cacheDataKey . '_keeping_user_id',
            $this->cacheDataKey . '_last_keep_at',
            $this->cacheDataKey . '_player_pressure',
            $this->cacheDataKey . '_player_score',
            $this->cacheDataKey . '_player_open_point',
            $this->cacheDataKey . '_player_wash_point',
            $this->cacheDataKey . '_last_point_at',
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_change_point_card_status',
            $this->cacheDataKey . '_gift_bet',
            $this->cacheDataKey . '_gift_condition',
            $this->cacheDataKey . '_now_turn',
            $this->cacheDataKey . '_has_lock',
            $this->cacheDataKey . '_pre_wash_point',
        ];
        $this->machineInfo = [
            'auto',
            'reward_status',
            'bet',
            'win',
            'has_lock',
        ];
        $this->lang = $lang;
        $this->cacheData = $this->getMachineCache();
        $this->log = Log::channel('song_slot_machine');
    }

    /**
     * 将3个数据字节转换回分数
     * @param array $bytes 3个字节的数组 [万位部分, 千位百位部分, 十位个位部分]
     * @return float|int 分数
     */
    public static function bytesToScore(array $bytes): float|int
    {
        if (count($bytes) < 3) {
            return 0;
        }

        $tenThousands = $bytes[0];
        $thousandsHundreds = $bytes[1];
        $tensOnes = $bytes[2];

        return $tenThousands * 10000 + $thousandsHundreds * 100 + $tensOnes;
    }

    /**
     * 获取属性
     * @param $name
     * @return mixed|null
     */
    public function __get($name)
    {
        $key = $this->cacheDataKey . '_' . $name;
        if (in_array($key, $this->cacheDataKeyArr)) {
            try {
                // 尝试从缓存获取
                $value = Cache::get($key, 0);
                return $value;
            } catch (\Exception $e) {
                // 获取失败时立即重试1次
                try {
                    $value = Cache::get($key, 0);
                    return $value;
                } catch (\Exception $e2) {
                    return 0;
                }
            }
        }
        return null;
    }

    /**
     * 设置属性
     * @param $name
     * @param $value
     * @return void
     */
    public function __set($name, $value)
    {
        $key = $this->cacheDataKey . '_' . $name;
        if (in_array($key, $this->cacheDataKeyArr)) {
            // ⚠️ CRITICAL：上分成功时（gaming_user_id 从0变为非0），立即更新 last_play_time
            // 原因：玩家刚上分就开始游戏，必须记录活动时间，否则可能被立即踢出
            if ($name === 'gaming_user_id' && !empty($value) && empty($this->gaming_user_id)) {
                Cache::set($this->cacheDataKey . '_last_play_time', time());
            }

            try {
                // 保存到缓存，失败时立即重试1次
                $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                if (!$saveResult) {
                    $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                }
            } catch (\Exception $e) {
                // 捕获异常后再重试1次
                try {
                    $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                    \support\Log::warning('Redis缓存保存异常后重试成功', [
                        'machine_id' => $this->machine->id,
                        'field' => $name,
                        'error' => $e->getMessage()
                    ]);
                } catch (\Exception $e2) {
                    $saveResult = false;
                    \support\Log::error('Redis缓存保存异常（重试1次后仍失败）', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'value' => $value,
                        'error' => $e2->getMessage()
                    ]);
                }
            }

            // ✅ 关键字段保存失败时必须抛出异常（防止 DB/Redis 数据不一致）
            if (!$saveResult) {
                // 最关键的三个字段：has_lock, gaming, gaming_user_id
                // 这些字段的不一致会导致严重的业务逻辑错误
                $mustSuccessFields = ['has_lock', 'gaming', 'gaming_user_id'];
                if (in_array($name, $mustSuccessFields)) {
                    \support\Log::critical('关键字段Redis保存失败，抛出异常', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'value' => $value
                    ]);

                    // ✅ 发送 Telegram CRITICAL 告警
                    try {
                        $token = env('TELEGRAM_BOT_TOKEN');
                        $chatId = env('TELEGRAM_CHAT_ID');

                        if (!empty($token) && !empty($chatId)) {
                            $telegram = new \app\service\TelegramService($token, $chatId, \Monolog\Logger::ERROR);
                            $telegram->sendAlert([
                                'datetime' => new \DateTime(),
                                'level_name' => 'CRITICAL',
                                'message' => '[MachineServices:SongSlot] Redis 关键字段保存失败',
                                'context' => [
                                    'machine_id' => $this->machine->id ?? null,
                                    'machine_code' => $this->machine->code ?? '',
                                    'field' => $name,
                                    'value' => $value,
                                    'error' => 'Redis关键字段保存失败，可能导致DB/Redis数据不一致',
                                ],
                            ]);
                        }
                    } catch (\Throwable $telegramEx) {
                        // Telegram 发送失败不影响主流程
                        \support\Log::warning('[MachineServices:SongSlot] Telegram 告警发送失败', [
                            'error' => $telegramEx->getMessage(),
                        ]);
                    }

                    throw new \Exception("Redis关键字段保存失败: {$name}，请立即检查Redis服务");
                }

                // 其他重要字段只记录日志
                $criticalFields = ['last_play_time', 'point', 'bet', 'keeping'];
                if (in_array($name, $criticalFields)) {
                    \support\Log::error('重要字段Redis保存失败', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'value' => $value
                    ]);
                }
            }

            // ✅ 自动推送机制（和 SongJackpot 一致）
            // 当 machineInfo 中的字段变化时，自动推送给玩家
            $machineCacheInfo = $this->getAllData() ?? [];
            if (!empty($machineCacheInfo)) {
                $info = [
                    'id' => $this->machine->id,
                    'last_game_at' => $this->machine->last_game_at,
                    'type' => $this->machine->type,
                    'gaming_user_id' => $machineCacheInfo[$this->cacheDataKey . '_gaming_user_id'] ?? 0,
                    'gaming' => $machineCacheInfo[$this->cacheDataKey . '_gaming'] ?? 0,
                    'auto' => $machineCacheInfo[$this->cacheDataKey . '_auto'],
                    'reward_status' => $machineCacheInfo[$this->cacheDataKey . '_reward_status'],
                    'play_start_time' => $machineCacheInfo[$this->cacheDataKey . '_play_start_time'],
                    'point' => $machineCacheInfo[$this->cacheDataKey . '_point'],
                    'bet' => $machineCacheInfo[$this->cacheDataKey . '_bet'],
                    'win' => $machineCacheInfo[$this->cacheDataKey . '_win'],
                    'last_play_time' => $machineCacheInfo[$this->cacheDataKey . '_last_play_time'],
                    'keep_seconds' => $machineCacheInfo[$this->cacheDataKey . '_keep_seconds'],
                    'keeping' => $machineCacheInfo[$this->cacheDataKey . '_keeping'],
                    'keeping_user_id' => $machineCacheInfo[$this->cacheDataKey . '_keeping_user_id'],
                    'last_keep_at' => $machineCacheInfo[$this->cacheDataKey . '_last_keep_at'],
                    'player_pressure' => $machineCacheInfo[$this->cacheDataKey . '_player_pressure'],
                    'player_score' => $machineCacheInfo[$this->cacheDataKey . '_player_score'],
                    'player_open_point' => $machineCacheInfo[$this->cacheDataKey . '_player_open_point'],
                    'player_wash_point' => $machineCacheInfo[$this->cacheDataKey . '_player_wash_point'],
                    'last_point_at' => $machineCacheInfo[$this->cacheDataKey . '_last_point_at'],
                    'action_time' => $machineCacheInfo[$this->cacheDataKey . '_action_time'],
                    'change_point_card_status' => $machineCacheInfo[$this->cacheDataKey . '_change_point_card_status'],
                    'gift_condition' => $machineCacheInfo[$this->cacheDataKey . '_gift_condition'],
                    'now_turn' => $machineCacheInfo[$this->cacheDataKey . '_now_turn'],
                    'has_lock' => $machineCacheInfo[$this->cacheDataKey . '_has_lock'],
                ];

                // ✅ 从 Redis 读取 gaming_user_id
                $currentGamingUserId = $machineCacheInfo[$this->cacheDataKey . '_gaming_user_id'] ?? 0;
                if (in_array($name, $this->machineInfo) && !empty($currentGamingUserId)) {
                    $this->sendMachineNowInfoMessage($currentGamingUserId, $this->machine->id, $name, $info);
                }
            }
        }
    }

    /**
     * 斯洛消息处理
     * @param string $msg
     * @return bool
     */
    public function slotCmd(string $msg): bool
    {
        $domain = $this->machine->domain;
        $port = $this->machine->port;
        try {
            $len = mb_strlen($msg);
            if ($len != 30 && $len != 10 && $len != 16 && $len != 14 && $len != 12 && $len != 22) {
                throw new \Exception('指令错误');
            }
            $s1 = substr($msg, -4, 2);
            $s2 = substr($msg, -2, 2);
            // 计算正确的校验位
            $data = substr($msg, 0, -4);
            $calculatedS1 = self::calculateS1($data);
            if ($s1 != $calculatedS1) {
                throw new \Exception('指令s1校验失败');
            }
            $calculatedS2 = self::calculateS2($data, $calculatedS1);
            if ($s2 != $calculatedS2) {
                throw new \Exception('指令s2校验失败');
            }
            $fun = substr($msg, 0, 4);
            $gamingUserId = $this->gaming_user_id; // ✅ 从 Redis 读取（实时数据），不从缓存的 Machine 对象读取
            switch ($fun) {
                case self::TESTING:
                case self::TESTING2:
                    $orgRewardStatus = $this->reward_status;
                    $orgBet = $this->bet;
                    $orgPoint = $this->point;
                    $orgNowTurn = $this->now_turn;
                    if (substr($msg, 18, 2) != 'da') {
                        $this->has_lock = 1;

                        // 详细记录机台锁的原因
                        Log::channel('machine_operations')->error('[SongSlot-MachineLock] 机台被锁', [
                            'machine_id' => $this->machine->id,
                            'machine_code' => $this->machine->code,
                            'machine_name' => $this->machine->name,
                            'lock_reason' => '心跳数据异常',
                            'exception_message' => '机台故障 - 心跳字节校验失败',
                            'heartbeat_msg' => $msg,
                            'expected_byte_18' => 'da',
                            'actual_byte_18' => substr($msg, 18, 2),
                            'player_id' => $gamingUserId,
                            'player_info' => $this->machine->gamingPlayer ? [
                                'uuid' => $this->machine->gamingPlayer->uuid,
                                'name' => $this->machine->gamingPlayer->name,
                                'phone' => $this->machine->gamingPlayer->phone,
                            ] : null,
                            'machine_state' => [
                                'point' => $this->point,
                                'bet' => $this->bet,
                                'win' => $this->win,
                                'auto' => $this->auto,
                            ],
                            'timestamp' => date('Y-m-d H:i:s'),
                        ]);

                        sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $gamingUserId);
                        throw new \Exception('机台故障');
                    }
                    [$nowPoint, $nowBet, $nowWin] = self::parseHeartbeat($msg);

                    if ($this->bet > 0 && $this->bet > $nowBet && $this->change_point_card_status == 0) {
                        sendMachineException($this->machine, Notice::TYPE_MACHINE_BET);
                        $this->bet = $nowBet;
                        if ($this->gaming == 1) {
                            $this->player_pressure = $this->bet;
                        }
                        return true;
                    }
                    $nowAuto = substr($msg, 2, 2) == 'c6' ? 1 : 0;
                    $nowRewardStatus = substr($msg, 10, 2) == 'd0' ? 0 : 1;
                    if ($orgRewardStatus == 0 && $nowRewardStatus == 1 && $this->now_turn > 0) {
                        $machineLotteryRecord = new MachineLotteryRecord();
                        $machineLotteryRecord->machine_id = $this->machine->id;
                        $machineLotteryRecord->player_id = $gamingUserId; // ✅ 使用从 Redis 读取的 gaming_user_id
                        $machineLotteryRecord->department_id = $this->machine->gamingPlayer->department_id ?? 0;
                        $machineLotteryRecord->draw_bet = $this->bet;
                        $machineLotteryRecord->use_turn = $this->now_turn;
                        $machineLotteryRecord->save();
                        $this->now_turn = 0;
                    }
                    if ($orgRewardStatus == 1 && $nowRewardStatus == 0) {
                        $this->now_turn = 0;
                        // 开奖结束后需剔除其他观看中玩家
                        sendSocketMessage('group-' . $this->machine->id, [
                            'msg_type' => 'machine_reward_end',
                            'machine_id' => $this->machine->id,
                            'machine_code' => $this->machine->code,
                            'gaming_user_id' => $this->machine->gaming_user_id,
                        ]);
                    } elseif ($nowRewardStatus == 0) {
                        $newTurn = bcadd($orgNowTurn, bcsub($nowBet, $orgBet, 2), 2);

                        // ✅ 诊断日志：检查转数增加条件（仅 S326）
                        $turnCondition1 = $newTurn > $orgNowTurn;
                        $turnCondition2 = !empty($gamingUserId);
                        $turnAllConditionsMet = $turnCondition1 && $turnCondition2;

                        // ✅ 修复：转数增加时也更新活动时间（即使押注金额不变）
                        if ($turnAllConditionsMet) {
                            // ⚠️ CRITICAL：重新读取 gaming_user_id，防止使用已被踢出的玩家ID
                            $currentGamingUserId = $this->gaming_user_id;
                            if (!empty($currentGamingUserId)) {
                                $this->last_play_time = time();
                            }
                        }

                        $this->now_turn = $newTurn;
                    }
                    // ✅ bet 是累加值，必须增加才说明有新押注
                    $betCondition1 = $orgBet > 0;
                    $betCondition2 = $orgBet < $nowBet;  // bet 增加说明有新押注
                    $betCondition3 = !empty($gamingUserId);
                    $betCondition4 = $this->change_point_card_status == 0;
                    $betAllConditionsMet = $betCondition1 && $betCondition2 && $betCondition3 && $betCondition4;

                    if ($betAllConditionsMet) {
                        // ⚠️ CRITICAL：重新读取 gaming_user_id，防止使用已被踢出的玩家ID
                        $currentGamingUserId = $this->gaming_user_id;
                        if (!empty($currentGamingUserId)) {
                            $this->last_play_time = time();
                        }
                        if ($this->reward_status == 0 && !empty($currentGamingUserId)) {
                            Client::send('lottery-machine', [
                                'num' => $nowBet,
                                'last_num' => $orgBet,
                                'machine_id' => $this->machine->id,
                                'player_id' => $currentGamingUserId,
                            ]);
                            $changeAmount = abs($nowBet - $orgBet);

                            Client::send('play-keep-machine', [
                                'change_amount' => $changeAmount,
                                'machine_id' => $this->machine->id,
                                'machine_cache_key' => sprintf('machine:domain:%s:port:%s:type:%s',
                                    $this->machine->domain, $this->machine->port, $this->machine->type
                                ),
                                'player_id' => $currentGamingUserId,
                                'gaming_user_id' => $currentGamingUserId,
                                'keep_seconds' => $this->keep_seconds,
                                'keeping' => $this->keeping,
                            ]);

                            // ✅ 同时投递打码量统计（change_amount 就是打码量）
                            if ($changeAmount > 0) {
                                $turnUsedPoint = $this->machine->turn_used_point ?? 0;
                                $betAmount = bcmul($changeAmount, $turnUsedPoint, 2);

                                Log::channel('bet_statistics')->debug('[BetStats] SongSlot 保留时计算打码量', [
                                    'machine_id' => $this->machine->id,
                                    'player_id' => $currentGamingUserId,
                                    'change_amount' => $changeAmount,
                                    'turn_used_point' => $turnUsedPoint,
                                    'bet_amount' => $betAmount,
                                    'now_bet' => $nowBet,
                                    'org_bet' => $orgBet,
                                ]);

                                if (bccomp($betAmount, '0', 2) > 0) {
                                    Log::channel('bet_statistics')->info('[BetStats] SongSlot 保留时投递打码量', [
                                        'machine_id' => $this->machine->id,
                                        'player_id' => $currentGamingUserId,
                                        'change_amount' => $changeAmount,
                                        'turn_used_point' => $turnUsedPoint,
                                        'bet_amount' => floatval($betAmount),
                                        'source' => 'keep_machine',
                                    ]);

                                    Client::send('bet-statistics', [
                                        'player_id' => $currentGamingUserId,
                                        'stat_type' => 'machine',
                                        'bet_amount' => floatval($betAmount),
                                        'source' => 'song_slot',
                                        'machine_id' => $this->machine->id,
                                        'created_at' => date('Y-m-d H:i:s'),
                                    ]);
                                } else {
                                    Log::channel('bet_statistics')->debug('[BetStats] SongSlot 保留时打码量为0，跳过投递', [
                                        'machine_id' => $this->machine->id,
                                        'change_amount' => $changeAmount,
                                        'turn_used_point' => $turnUsedPoint,
                                        'bet_amount' => $betAmount,
                                    ]);
                                }
                            }
                        }
                    }
                    if ($nowPoint > 0 && $orgPoint != $nowPoint && !empty($currentGamingUserId)) {
                        Client::send('play-activity', [
                            'machine_id' => $this->machine->id,
                            'player_id' => $currentGamingUserId,
                            'point' => $nowPoint,
                        ]);
                    }
                    if ($nowPoint == 0 && $this->gaming == 1) {
                        $this->gift_condition = 0;
                        Cache::delete('gift_cache_' . $this->machine->id . '_' . $this->machine->gaming_user_id);
                    }
                    if ($nowPoint >= $this->gift_condition) {
                        $this->gift_condition = 0;
                        Cache::delete('gift_cache_' . $this->machine->id . '_' . $this->machine->gaming_user_id);
                    }
                    $this->point = $nowPoint;
                    $this->bet = $nowBet;
                    $this->win = $nowWin;
                    $this->auto = $nowAuto;  // ✅ __set() 会自动推送（通过 machineInfo 机制）
                    $this->reward_status = $nowRewardStatus;  // ✅ __set() 会自动推送（通过 machineInfo 机制）
                    $this->sendMachineNowStatusMessage($this->machine->id);
                    break;
                case self::WASH_ZERO:
                    Redis::publish($domain . ':' . $port, '设备返回的消息');
                    $cmd = substr($msg, 0, 6);
                    $this->setActionVersion($cmd);
                    $washPoint = self::parseScore(substr($msg, 6, 6));
                    $preWashPoint = $this->pre_wash_point;
                    if ($preWashPoint != $washPoint) {
                        throw new \Exception('预下分不等于实际下分,请稍后尝试');
                    }
                    $this->pre_wash_point = 0;
                    self::sendCmd($cmd);
                    break;
                case self::OPEN_ANY_POINT:
                    Redis::publish($domain . ':' . $port, '设备返回的消息');
                    $this->setActionVersion(substr($msg, 0, 6));
                    break;
                case self::GET_BET:
                    $bet = self::parseScore(substr($msg, 4, 6));
                    $orgBet = $this->bet;
                    if (!empty($gamingUserId) && $this->reward_status == 0) {
                        $playerPressure = $this->player_pressure;
                        $this->player_pressure = bcadd($playerPressure, bcsub($bet, $orgBet, 2), 2);
                    }
                    $this->bet = $bet;
                    self::sendCmd('af');
                    $this->setActionVersion(self::READ_BET);
                    break;
                case self::GET_WIN:
                    $win = self::parseScore(substr($msg, 4, 6));
                    $orgWin = $this->win;
                    $this->win = $win;
                    self::sendCmd('af');
                    $this->setActionVersion(self::READ_WIN);
                    break;
                case self::GET_SCORE:
                    $point = self::parseScore(substr($msg, 4, 6));
                    $this->point = $point;
                    $this->setActionVersion(self::READ_SCORE);
                    break;
                case self::GET_STATUS:
                    $machineStatus = substr($msg, 8, 2);
                    if ($machineStatus != 'da') {
                        $this->has_lock = 1;

                        // 详细记录机台锁的原因
                        Log::channel('machine_operations')->error('[SongSlot-MachineLock] 机台被锁', [
                            'machine_id' => $this->machine->id,
                            'machine_code' => $this->machine->code,
                            'machine_name' => $this->machine->name,
                            'lock_reason' => '机台状态异常',
                            'exception_message' => '机台故障, 故障码: ' . $machineStatus,
                            'expected_status' => 'da',
                            'actual_status' => $machineStatus,
                            'status_msg' => $msg,
                            'player_id' => $gamingUserId,
                            'player_info' => $this->machine->gamingPlayer ? [
                                'uuid' => $this->machine->gamingPlayer->uuid,
                                'name' => $this->machine->gamingPlayer->name,
                                'phone' => $this->machine->gamingPlayer->phone,
                            ] : null,
                            'machine_state' => [
                                'point' => $this->point,
                                'bet' => $this->bet,
                                'win' => $this->win,
                                'auto' => $this->auto,
                            ],
                            'timestamp' => date('Y-m-d H:i:s'),
                        ]);

                        sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $gamingUserId);
                        throw new \Exception('机台故障, 故障码:' . $machineStatus);
                    }
                    $nowAuto = substr($msg, 4, 2) == 'c0' ? 0 : 1;
                    $nowRewardStatus = substr($msg, 6, 2) == 'd0' ? 0 : 1;

                    $this->auto = $nowAuto;  // ✅ __set() 会自动推送（通过 machineInfo 机制）
                    $this->reward_status = $nowRewardStatus;  // ✅ __set() 会自动推送（通过 machineInfo 机制）
                    $this->setActionVersion(self::READ_STATUS);
                    break;
                default:
                    $action = substr($msg, 0, 6);
                    switch ($action) {
                        case self::STOP_ONE:
                        case self::STOP_TWO:
                        case self::STOP_THREE:
                        case self::START:
                        case self::REWARD_SWITCH:
                        case self::MACHINE_OPEN:
                        case self::MACHINE_CLOSE:
                        case self::CHECK:
                        case self::OUT_ON:
                        case self::OUT_OFF:
                            $this->setActionVersion($action);
                            break;
                        default:
                            throw new \Exception('不存在的指令');
                    }
                    break;
            }
        } catch (\Exception $e) {
            $this->log->error('消息处理错误: ', [
                $e->getMessage(),
                [
                    'msg' => $msg,
                    'action' => $fun ?? '',
                    'machine_code' => $this->machine->code
                ]
            ]);
            return false;
        }

        return true;
    }

    /**
     * 计算S1校验位 (XOR异或校验)
     * @param string $data 指令数据（不含前缀，包含分机号）
     * @return string 16进制的S1校验位
     */
    public static function calculateS1(string $data): string
    {
        $bytes = str_split($data, 2);
        $xor = 0;
        foreach ($bytes as $byte) {
            $xor ^= hexdec($byte);
        }
        return str_pad(dechex($xor), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 计算S2校验位 (ADD累加校验)
     * @param string $data 指令数据（不含前缀，包含分机号）
     * @param string $s1 计算出的S1值
     * @return string 16进制的S2校验位（取最后2位）
     */
    public static function calculateS2(string $data, string $s1): string
    {
        $bytes = str_split($data, 2);
        $add = 0;
        foreach ($bytes as $byte) {
            $add += hexdec($byte);
        }
        $add += hexdec($s1);
        $result = $add & 0xFF;
        return str_pad(dechex($result), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 解析心跳指令中的压分数据
     * @param string $command 心跳指令（不含前缀和校验位）
     * @return array 解析结果
     */
    public static function parseHeartbeat(string $command): array
    {
        $cleanCommand = str_replace(' ', '', strtoupper(trim($command)));
        $parts = [
            'point_section' => substr($cleanCommand, 4, 6),   // C001051E: 当前分数
            'bet_section' => substr($cleanCommand, 12, 6), // D003083C: 押分状态
            'win_section' => substr($cleanCommand, 20, 6)     // DA026358: BET数据
        ];

        return [
            self::parseScore($parts['point_section']),
            self::parseScore($parts['bet_section']),
            self::parseScore($parts['win_section'])
        ];
    }

    /**
     * 解析当前分数 01 05 1E → 10530
     * 格式: xx yy zz
     * 其中 xx yy zz 为BCD码表示的分数
     */
    private static function parseScore($scoreSection): float|int
    {
        $bytes = str_split($scoreSection, 2);

        $bcd2 = $bytes[0]; // 01
        $bcd1 = $bytes[1]; // 05
        $bcd0 = $bytes[2]; // 1E

        return (hexdec($bcd2) * 10000) + (hexdec($bcd1) * 100) + hexdec($bcd0);
    }

    /**
     * 设置操作版本号
     * @param $name
     * @return float
     */
    public function setActionVersion($name): float
    {
        $version = getMillisecond();
        Cache::set($this->cacheDataKey . '_' . 'action_' . $name, $version, 60 * 60);
        return $version;
    }

    /**
     * @param string $cmd
     * @param int $data
     * @param string $source
     * @param int $source_id
     * @param int $isSystem
     * @return true
     * @throws Exception
     * @throws PushException
     */
    public function sendCmd(
        string $cmd,
        int    $data = 0,
        string $source = 'player',
        int    $source_id = 0,
        int    $isSystem = 0
    ): bool
    {
        $uid = $this->machine->domain . ':' . $this->machine->port;
        try {
            if (!Gateway::isUidOnline($uid)) {
                throw new Exception(trans('machine_has_offline', ['{code}' => $this->machine->code], 'message'));
            }
            if ($this->has_lock == 1 && $cmd != self::CHECK) {
                throw new Exception(trans('machine_lock', ['{code}' => $this->machine->code], 'message'));
            }

            // ⚠️ CRITICAL：玩家操作时立即更新 last_play_time，不等硬件响应
            // 原因：硬件响应是异步的，可能在系统踢人检查之后才到达
            if ($source == 'player') {
                $currentGamingUserId = $this->gaming_user_id;
                if (!empty($currentGamingUserId)) {
                    $this->last_play_time = time();
                }
            }

            switch ($cmd) {
                case self::OUT_ON:
                    $nowPoint = $this->point;
                    if ($nowPoint <= 6) {
                        throw new Exception(trans('point_not_enough', ['{code}' => $this->machine->code], 'message'));
                    }
                    $this->autoOn($uid, $cmd, $data, $source, $source_id);
                    break;
                case self::OUT_OFF:
                    $this->autoOff($uid, $cmd, $data, $source, $source_id);
                    break;
                case self::REWARD_SWITCH:
                case self::START:
                case self::STOP_ONE:
                case self::STOP_TWO:
                case self::STOP_THREE:
                case self::TESTING:
                    Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));
                    break;
                case self::OPEN_ANY_POINT:
                    $code = sprintf('%02x', rand(0, 0x63));
                    $this->openPoint($uid, $cmd . $code, $data, $source, $source_id);
                    break;
                case self::WASH_ZERO:
                    $code = sprintf('%02x', rand(0, 0x63));
                    $this->log->info('[SongSlot-sendCmd] 准备调用 washPoint', [
                        'machine_code' => $this->machine->code,
                        'cmd' => $cmd,
                        'code' => $code,
                        'uid' => $uid,
                        'source' => $source,
                        'source_id' => $source_id,
                        'current_point' => $this->point,
                    ]);
                    $point = $this->point;
                    $this->pre_wash_point = empty($data) ? $point : $data;
                    $this->log->info('发送下分操作: 预下分为', [$this->pre_wash_point]);
                    $this->washPoint($uid, $cmd . $code, $this->pre_wash_point, $source, $source_id);
                    break;
                case self::READ_BET:
                case self::READ_SCORE:
                case self::READ_WIN:
                case self::READ_STATUS:
                    $this->machineAction($uid, $cmd, $source, $source_id);
                    break;
                case self::ALL_DOWN:
                default:
                    Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));
                    if ($source == 'admin') {
                        sendSocketMessage('private-admin-1-' . $source_id, [
                            'msg_type' => 'machine_action_result',
                            'id' => $this->machine->id,
                            'description' => $this->getDescription($cmd),
                        ]);
                    }
                    break;
            }
        } catch (Exception $e) {
            if (in_array($cmd, [
                self::OPEN_ANY_POINT,
                self::WASH_ZERO
            ])) {
                $this->has_lock = 1;

                // 确定指令类型
                $cmdType = $cmd == self::OPEN_ANY_POINT ? '上分' : '下分';

                // 详细记录机台锁的原因
                Log::channel('machine_operations')->error("[SongSlot-MachineLock] {$cmdType}失败，机台被锁", [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'machine_name' => $this->machine->name,
                    'cmd' => $cmd,
                    'cmd_type' => $cmdType,
                    'cmd_data' => $data ?? 0,
                    'lock_reason' => '指令执行异常',
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'operator_type' => $source,
                    'operator_id' => $source_id,
                    'player_id' => $this->machine->gaming_user_id,
                    'player_info' => $this->machine->gamingPlayer ? [
                        'uuid' => $this->machine->gamingPlayer->uuid,
                        'name' => $this->machine->gamingPlayer->name,
                        'phone' => $this->machine->gamingPlayer->phone,
                    ] : null,
                    'machine_state' => [
                        'point' => $this->point,
                        'bet' => $this->bet,
                        'win' => $this->win,
                        'auto' => $this->auto,
                    ],
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);

                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->machine->gaming_user_id);
            }
            $operatorType = $source === 'admin' ? '【管理员操作】' : '【玩家操作】';
            $this->log->error($operatorType . '发送指令异常', [
                'operator_type' => $source,
                'operator_id' => $source_id,
                'cmd' => $cmd,
                'cmd_data' => $data ?? 0,
                'machine_code' => $this->machine->code,
                'error' => $e->getMessage(),
            ]);
            throw new Exception($e->getMessage());
        }
        // ✅ 优化：精简日志，只记录关键信息，移除冗余的 machine_data
        $operatorType = $source === 'admin' ? '【管理员操作】' : '【玩家操作】';
        $this->log->info($operatorType . '机台操作', [
            'operator_type' => $source,
            'operator_id' => $source_id,
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'player_id' => $this->machine->gamingPlayer->id ?? 0,
            'player_uuid' => $this->machine->gamingPlayer->uuid ?? '',
            'action' => $cmd,
            'action_desc' => $this->getDescription($cmd),
            'point' => $data,
            // 移除 machine_data（几千字节的冗余数据）
        ]);

        return true;
    }

    /**
     * 开启自动（异步模式 - 不等待机台返回）
     *
     * 发送指令后立即返回，机台状态变化后通过 Socket 推送通知前端
     * Socket 推送在 handleMsg() Line 508-510 处理
     *
     * @param string $uid Gateway UID
     * @param string $cmd 硬件指令
     * @param int $data 指令数据
     * @param string $source 来源（player/admin）
     * @param int $source_id 来源ID（玩家ID或管理员ID）
     * @return void
     * @throws Exception
     */
    private function autoOn(
        string $uid,
        string $cmd,
        int    $data,
        string $source = 'player',
        int    $source_id = 0,
    ): void
    {
        try {
            // ✅ 异步发送指令，不等待机台返回
            Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));

            // ✅ 后台管理员操作：立即推送操作结果（告知"已发送指令"）
            if ($source == 'admin') {
                sendSocketMessage('private-admin-1-' . $source_id, [
                    'msg_type' => 'machine_action_result',
                    'id' => $this->machine->id,
                    'description' => $this->getDescription(self::OPEN_ANY_POINT, $data) . ' (指令已发送)',
                ]);
            }

            // ✅ 机台状态变化后，handleMsg() Line 508-510 会自动推送 auto 状态到前端
            // 推送格式见 pushAutoStatus() 方法

        } catch (Exception $e) {
            $this->log->error('开启自动指令发送失败', [
                'machine_code' => $this->machine->code,
                'error' => $e->getMessage()
            ]);
            throw new Exception(trans('machine_action_fail', [], 'message'));
        }
    }

    /**
     * 创建指令
     * @param string $cmd 操作指令
     * @param mixed $data 数据
     * @return string
     */
    private function createCmd(string $cmd, mixed $data = 0): string
    {
        $hexString = '';
        if (!empty($data)) {
            $bytes = $this->scoreToBytes($data);
            $hexString = $this->toHexString($bytes);
        }
        $cmd .= $hexString;
        $s1 = self::calculateS1($cmd);
        $s2 = self::calculateS2($cmd, $s1);
        return $cmd . $s1 . $s2;
    }

    /**
     * 将分数转换为指令中的3个数据字节
     * @param int $score 分数 (0-99999)
     * @return array 3个字节的数组 [万位部分, 千位百位部分, 十位个位部分]
     */
    public static function scoreToBytes(int $score): array
    {
        // 确保分数在有效范围内
        $score = max(0, min(99999, $score));

        // 分解分数
        $tenThousands = intval($score / 10000);              // 万位
        $thousandsHundreds = intval(($score % 10000) / 100); // 千位百位组成的两位数
        $tensOnes = $score % 100;                           // 十位个位组成的两位数

        return [$tenThousands, $thousandsHundreds, $tensOnes];
    }

    /**
     * 将字节数组转换为十六进制字符串
     */
    public static function toHexString($bytes): string
    {
        return implode('', array_map(function ($b) {
            return strtoupper(str_pad(dechex($b), 2, '0', STR_PAD_LEFT));
        }, $bytes));
    }

    /**
     * 获取机台信息描述
     * @param string $fun 操作指令
     * @param int $data
     * @return string
     */
    public function getDescription(string $fun = '', int $data = 0): string
    {
        locale(Str::replace('-', '_', $this->lang));
        $description = '';
        $autoStatus = $this->auto == 1 ? trans('machine_status_yes', [], 'machine_action') : trans('machine_status_no',
            [], 'machine_action');
        $lotteryStatus = $this->reward_status == 1 ? trans('machine_status_yes', [],
            'machine_action') : trans('machine_status_no', [], 'machine_action');
        if (empty($fun)) {
            $description .= trans('machine_auto_status', [], 'machine_action') . $autoStatus . PHP_EOL;
            $description .= trans('machine_lottery_status', [], 'machine_action') . $lotteryStatus . PHP_EOL;
            $description .= trans('machine_point', [], 'machine_action') . ($this->point ?? 0) . PHP_EOL;
            $description .= trans('machine_bet', [], 'machine_action') . ($this->bet ?? 0) . PHP_EOL;
            $description .= trans('machine_win', [], 'machine_action') . ($this->win ?? 0) . PHP_EOL;
        } else {
            $description .= trans('function.' . GameType::TYPE_SLOT . '_' . Machine::CONTROL_TYPE_SONG . '.' . $fun, [],
                'machine_action');
            switch ($fun) {
                case SongSlot::READ_SCORE:
                    $description .= ': ' . $this->point;
                    break;
                case SongSlot::READ_WIN:
                    $description .= ': ' . $this->win;
                    break;
                case SongSlot::READ_BET:
                    $description .= ': ' . $this->bet;
                    break;
                case SongSlot::OPEN_ANY_POINT:
                case SongSlot::WASH_ZERO:
                    $description .= ': ' . $data;
                    break;
            }
        }
        return $description;
    }

    /**
     * 关闭自动（异步模式 - 不等待机台返回）
     *
     * 发送指令后立即返回，机台状态变化后通过 Socket 推送通知前端
     * Socket 推送在 handleMsg() Line 508-510 处理
     *
     * @param string $uid Gateway UID
     * @param string $cmd 硬件指令
     * @param int $data 指令数据
     * @param string $source 来源（player/admin）
     * @param int $source_id 来源ID（玩家ID或管理员ID）
     * @return void
     * @throws Exception
     */
    private function autoOff(
        string $uid,
        string $cmd,
        int    $data,
        string $source = 'player',
        int    $source_id = 0,
    ): void
    {
        try {
            // ✅ 异步发送指令，不等待机台返回
            Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));

            // ✅ 后台管理员操作：立即推送操作结果（告知"已发送指令"）
            if ($source == 'admin') {
                sendSocketMessage('private-admin-1-' . $source_id, [
                    'msg_type' => 'machine_action_result',
                    'id' => $this->machine->id,
                    'description' => $this->getDescription(self::OPEN_ANY_POINT, $data) . ' (指令已发送)',
                ]);
            }

            // ✅ 机台状态变化后，handleMsg() Line 508-510 会自动推送 auto 状态到前端
            // 推送格式见 pushAutoStatus() 方法

        } catch (Exception $e) {
            $this->log->error('关闭自动指令发送失败', [
                'machine_code' => $this->machine->code,
                'error' => $e->getMessage()
            ]);
            throw new Exception(trans('machine_action_fail', [], 'message'));
        }
    }

    /**
     * 读取当前分数
     * @param string $uid
     * @param string $cmd
     * @param int $data
     * @param string $source
     * @param int $source_id
     * @return void
     * @throws Exception
     * @throws PushException
     */
    private function openPoint(
        string $uid,
        string $cmd,
        int    $data,
        string $source = 'player',
        int    $source_id = 0,
    ): void
    {
        $expirationTime = 1000000;

        $this->log->info('[SongSlot-上分开始]', [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'cmd' => $cmd,
            'data' => $data,
            'source' => $source,
            'source_id' => $source_id,
            'before_point' => $this->point,
        ]);

        try {
            $beforeActionTime = $this->setActionVersion($cmd);
            Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));
            $handleDuration = 0;
            $sleep = 50000; // 5毫秒取一次值
            while (true) {
                $actionTime = $this->getActionVersion($cmd);
                if ($actionTime > $beforeActionTime) {
                    $this->log->info('[SongSlot-上分成功]', [
                        'machine_code' => $this->machine->code,
                        'cmd' => $cmd,
                        'data' => $data,
                        'after_point' => $this->point,
                        'duration_ms' => round($handleDuration / 1000, 2),
                    ]);

                    if ($source == 'admin') {
                        sendSocketMessage('private-admin-1-' . $source_id, [
                            'msg_type' => 'machine_action_result',
                            'id' => $this->machine->id,
                            'description' => $this->getDescription(self::OPEN_ANY_POINT, $data),
                        ]);
                    }
                    return;
                }
                if ($handleDuration >= $expirationTime) {
                    throw new Exception(trans('machine_connect_timeout', [], 'message'));
                }
                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception $e) {
            $this->log->error('[SongSlot-上分失败]', [
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'data' => $data,
                'error' => $e->getMessage(),
                'before_point' => $this->point,
            ]);
            throw new Exception(trans('machine_action_fail', [], 'message'));
        }
    }

    /**
     * 获取操作版本号
     * @param $name
     * @return float
     */
    public function getActionVersion($name): float
    {
        return (float)Cache::get($this->cacheDataKey . '_' . 'action_' . $name);
    }

    /**
     * 机台操作
     * @param string $uid
     * @param string $cmd
     * @param int $data
     * @param string $source
     * @param int $source_id
     * @param int $attempts 当前尝试次数
     * @return void
     * @throws Exception
     * @throws PushException
     */
    private function washPoint(
        string $uid,
        string $cmd,
        int    $data,
        string $source = 'player',
        int    $source_id = 0,
        int    $attempts = 0
    ): void
    {
        $maxRetries = 8;
        $expirationTime = 1000000;

        if ($attempts == 0) {
            $this->log->info('[SongSlot-下分开始]', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'data' => $data,
                'source' => $source,
                'source_id' => $source_id,
                'before_point' => $this->point,
            ]);
        }

        try {
            $beforeActionTime = $this->setActionVersion($cmd);
            $cmdHex = $this->createCmd($cmd . 'c1', 0);

            $this->log->info('[SongSlot-washPoint] 发送 WASH_ZERO 指令', [
                'machine_code' => $this->machine->code,
                'uid' => $uid,
                'cmd' => $cmd,
                'cmd_hex' => $cmdHex,
                'data' => $data,
                'before_point' => $this->point,
                'attempts' => $attempts,
            ]);

            Gateway::sendToUid($uid, hex2bin($cmdHex));
            $handleDuration = 0;
            $sleep = 50000; // 5毫秒取一次值
            while (true) {
                $actionTime = $this->getActionVersion($cmd);
                if ($actionTime > $beforeActionTime) {
                    $this->log->info('[SongSlot-下分成功]', [
                        'machine_code' => $this->machine->code,
                        'cmd' => $cmd,
                        'data' => $data,
                        'after_point' => $this->point,
                        'attempts' => $attempts + 1,
                        'duration_ms' => round($handleDuration / 1000, 2),
                    ]);

                    if ($source == 'admin') {
                        sendSocketMessage('private-admin-1-' . $source_id, [
                            'msg_type' => 'machine_action_result',
                            'id' => $this->machine->id,
                            'description' => $this->getDescription(self::WASH_ZERO, $data),
                        ]);
                    }
                    return;
                }
                if ($handleDuration >= $expirationTime) {
                    throw new Exception(trans('machine_connect_timeout', [], 'message'));
                }
                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception $e) {
            $attempts++;
            if ($attempts >= $maxRetries) {
                $this->log->error('[SongSlot-下分失败]', [
                    'machine_code' => $this->machine->code,
                    'cmd' => $cmd,
                    'data' => $data,
                    'error' => $e->getMessage(),
                    'attempts' => $attempts,
                    'before_point' => $this->point,
                ]);
                throw new Exception(trans('machine_connect_timeout', [], 'message'));
            }
            $this->log->warning('[SongSlot-下分重试]', [
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'attempts' => $attempts,
                'max_retries' => $maxRetries,
            ]);
            usleep(50000);
            $this->washPoint($uid, $cmd, $data, $source, $source_id, $attempts);
        }
    }

    /**
     * 机台操作
     * @param string $uid
     * @param string $cmd
     * @param string $source
     * @param int $source_id
     * @param int $attempts 当前尝试次数
     * @return void
     * @throws Exception
     * @throws PushException
     */
    private function machineAction(
        string $uid,
        string $cmd,
        string $source = 'player',
        int    $source_id = 0,
        int    $attempts = 0
    ): void
    {
        $maxRetries = 8;
        $expirationTime = 1000000;
        try {
            $beforeActionTime = $this->setActionVersion($cmd);
            Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, 0)));
            $handleDuration = 0;
            $sleep = 50000; // 5毫秒取一次值
            while (true) {
                $actionTime = $this->getActionVersion($cmd);
                if ($actionTime > $beforeActionTime) {
                    if ($source == 'admin') {
                        sendSocketMessage('private-admin-1-' . $source_id, [
                            'msg_type' => 'machine_action_result',
                            'id' => $this->machine->id,
                            'description' => $this->getDescription($cmd),
                        ]);
                    }
                    return;
                }
                if ($handleDuration >= $expirationTime) {
                    throw new Exception(trans('machine_connect_timeout', [], 'message'));
                }
                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception $e) {
            $attempts++;
            if ($attempts >= $maxRetries) {
                $this->log->error('指令超时异常', ['slot -> machineAction', [$this->machine->code], 'error' => $e->getMessage()]);
                throw new Exception(trans('machine_connect_timeout', [], 'message'));
            }
            usleep(50000);
            $this->machineAction($uid, $cmd, $source, $source_id, $attempts);
        }
    }

    /**
     * 获取所有属性
     * @return iterable
     */
    public function getAllData(): iterable
    {
        return Cache::getMultiple($this->cacheDataKeyArr, 0);
    }
}
