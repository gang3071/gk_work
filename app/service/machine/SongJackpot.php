<?php

namespace app\service\machine;

use app\model\GameType;
use app\model\Machine;
use app\model\Notice;
use app\service\LotteryServices;
use Exception;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Str;
use support\Cache;
use support\Log;
use support\Redis;
use Webman\Push\PushException;
use Webman\RedisQueue\Client;

/**
 * Class Jackpot
 * @property int $auto 自动状态
 * @property int $reward_status 开奖状态
 * @property int $rush_status rush状态
 * @property int $bb_status bb状态
 * @property int $play_start_time 开始游戏时间
 * @property int $gaming_user_id 游戏中玩家
 * @property int $gaming 是否游戏中
 * @property int $turn 当前转数
 * @property int $point 当前分数
 * @property int $score 当前珠数
 * @property int $last_play_time 最后游戏时间
 * @property int $open_point 开分次数
 * @property int $wash_point 洗分次数
 * @property int $keep_seconds 保留时长
 * @property int $keeping 保留状态
 * @property int $keeping_user_id 保留玩家
 * @property int $last_keep_at 最后保留时间
 * @property int $player_win_number 玩家使用转数
 * @property int $player_open_point 玩家开分
 * @property int $player_wash_point 玩家洗分
 * @property int $last_point_at 玩家最后上下分时间
 * @property int $player_turn_base 玩家转数基准点（缓存）
 * @property int $handle_status 圖柄確認状态
 * @property int $win_number 讀取中洞對獎次數
 * @property int $action_time 操作时间
 * @property int $push_auto push auto状态
 * @property int $change_point_card_status 开分卡状态
 * @property int $gift_bet 玩家开分增点时押注
 * @property int $now_turn 当前转数
 * @property int $has_lock 机台锁
 * @property int $pre_wash_point 预洗分点数
 *
 * @package app\service\machine
 */
class SongJackpot extends MachineServices implements BaseMachine
{
    const ALL = 'all'; //机台状态
    const MACHINE_POINT = '46cea2'; //读取机台当前分
    const MACHINE_SCORE = '46cea5'; //读取机台当前得分
    const MACHINE_TURN = '46cea6'; //读取机台当前转数
    const WIN_NUMBER = '46cea9'; //讀取中洞對獎次數

    const GET_MACHINE_POINT = '46c0'; //读取机台当前分
    const AUTO_MACHINE_POINT = '46c6'; //读取机台当前分自动
    const GET_MACHINE_SCORE = '46da'; //读取机台当前得分
    const FAULT1_MACHINE_SCORE = '46db'; //读取机台当前得分
    const FAULT_MACHINE_SCORE = '46dc'; //读取机台当前得分
    const GET_MACHINE_TURN = '46de'; //读取机台当前转数
    const GET_WIN_NUMBER = '46d0'; //读取机台当前转数
    const REWARD_WIN_NUMBER = '46d5'; //读取机台当前转数开奖

    const CHECK = '46cfb4'; //故排
    const MACHINE_OPEN = '46cebe'; //开机
    const MACHINE_CLOSE = '46cebc'; //关机
    const REWARD_SWITCH = '46ceb8';// 大賞燈切換
    const PUSH_THREE = '46ceb6'; //(連發PUSH)
    const PUSH_ONE = '46ceb2'; //(单發PUSH)
    const TURN_DOWN_ALL = '46cec9'; //全部下转
    const TURN_UP_ALL = '46cecb'; //全部上转
    const SCORE_TO_POINT = '46cec8'; //得分转分数
    const OPEN_ANY_POINT = '46ca'; //开任意分数
    const CLEAR_LOG = '46ccba'; //清除历史记录
    const WASH_ZERO = '46cc'; //洗分清零
    const AUTO_UP_TURN = '46cecd'; //自动上转(开始游戏)
    const AUTO_STOP = '46cece'; //停止游戏
    const TURN_TO_POINT = '46ceca'; //下转一次
    const POINT_TO_TURN = '46cec1'; //上转一次

    const TESTING = '46c0'; //心跳
    const TESTING2 = '46c6'; //心跳

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
            $this->cacheDataKey . '_move_point',
            $this->cacheDataKey . '_reward_status',
            $this->cacheDataKey . '_play_start_time',
            $this->cacheDataKey . '_gaming_user_id',
            $this->cacheDataKey . '_gaming',
            $this->cacheDataKey . '_turn',
            $this->cacheDataKey . '_point',
            $this->cacheDataKey . '_score',
            $this->cacheDataKey . '_last_play_time',
            $this->cacheDataKey . '_open_point',
            $this->cacheDataKey . '_wash_point',
            $this->cacheDataKey . '_keep_seconds',
            $this->cacheDataKey . '_keeping',
            $this->cacheDataKey . '_keeping_user_id',
            $this->cacheDataKey . '_last_keep_at',
            $this->cacheDataKey . '_player_win_number',
            $this->cacheDataKey . '_player_open_point',
            $this->cacheDataKey . '_player_wash_point',
            $this->cacheDataKey . '_last_point_at',
            $this->cacheDataKey . '_player_turn_base',
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_win_number',
            $this->cacheDataKey . '_push_auto',
            $this->cacheDataKey . '_change_point_card_status',
            $this->cacheDataKey . '_gift_bet',
            $this->cacheDataKey . '_now_turn',
            $this->cacheDataKey . '_rush_status',
            $this->cacheDataKey . '_has_lock',
            $this->cacheDataKey . '_pre_wash_point',
        ];
        $this->machineInfo = [
            'auto',
            'reward_status',
            'turn',
            'point',
            'score',
            'win_number',
            'push_auto',
            'has_lock',
        ];
        $this->lang = $lang;
        $this->cacheData = $this->getMachineCache();
        $this->log = Log::channel('song_jackpot_machine');
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
                    \support\Log::warning('Redis缓存读取失败后重试成功', [
                        'machine_id' => $this->machine->id,
                        'field' => $name,
                        'error' => $e->getMessage()
                    ]);
                    return $value;
                } catch (\Exception $e2) {
                    // 重试仍失败，记录错误日志并返回默认值
                    \support\Log::error('Redis缓存读取失败（重试1次后仍失败）', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'key' => $key,
                        'error' => $e2->getMessage()
                    ]);
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
     * @throws PushException
     */
    public function __set($name, $value)
    {
        $key = $this->cacheDataKey . '_' . $name;
        if (in_array($key, $this->cacheDataKeyArr)) {
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

            // 关键字段保存失败时记录额外日志
            if (!$saveResult) {
                $criticalFields = ['gaming', 'gaming_user_id', 'last_play_time', 'point', 'turn', 'keeping', 'win_number'];
                if (in_array($name, $criticalFields)) {
                    \support\Log::error('关键字段Redis保存失败', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'value' => $value
                    ]);
                }
            }

            $machineCacheInfo = $this->getAllData() ?? [];
            if (!empty($machineCacheInfo)) {
                $info = [
                    'id' => $this->machine->id,
                    'last_game_at' => $this->machine->last_game_at,
                    'odds_x' => $this->machine->odds_x,
                    'odds_y' => $this->machine->odds_y,
                    'type' => $this->machine->type,
                    'gaming_user_id' => $this->machine->gaming_user_id,
                    'gaming' => $this->machine->gaming,
                    'auto' => $machineCacheInfo[$this->cacheDataKey . '_auto'],
                    'move_point' => $machineCacheInfo[$this->cacheDataKey . '_move_point'],
                    'reward_status' => $machineCacheInfo[$this->cacheDataKey . '_reward_status'],
                    'play_start_time' => $machineCacheInfo[$this->cacheDataKey . '_play_start_time'],
                    'turn' => $machineCacheInfo[$this->cacheDataKey . '_turn'],
                    'point' => $machineCacheInfo[$this->cacheDataKey . '_point'],
                    'score' => $machineCacheInfo[$this->cacheDataKey . '_score'],
                    'last_play_time' => $machineCacheInfo[$this->cacheDataKey . '_last_play_time'],
                    'open_point' => $machineCacheInfo[$this->cacheDataKey . '_open_point'],
                    'wash_point' => $machineCacheInfo[$this->cacheDataKey . '_wash_point'],
                    'keep_seconds' => $machineCacheInfo[$this->cacheDataKey . '_keep_seconds'],
                    'keeping' => $machineCacheInfo[$this->cacheDataKey . '_keeping'],
                    'keeping_user_id' => $machineCacheInfo[$this->cacheDataKey . '_keeping_user_id'],
                    'last_keep_at' => $machineCacheInfo[$this->cacheDataKey . '_last_keep_at'],
                    'player_win_number' => $machineCacheInfo[$this->cacheDataKey . '_player_win_number'],
                    'player_open_point' => $machineCacheInfo[$this->cacheDataKey . '_player_open_point'],
                    'player_wash_point' => $machineCacheInfo[$this->cacheDataKey . '_player_wash_point'],
                    'last_point_at' => $machineCacheInfo[$this->cacheDataKey . '_last_point_at'],
                    'player_turn_base' => $machineCacheInfo[$this->cacheDataKey . '_player_turn_base'] ?? 0,
                    'action_time' => $machineCacheInfo[$this->cacheDataKey . '_action_time'],
                    'win_number' => $machineCacheInfo[$this->cacheDataKey . '_win_number'],
                    'push_auto' => $machineCacheInfo[$this->cacheDataKey . '_push_auto'],
                    'change_point_card_status' => $machineCacheInfo[$this->cacheDataKey . '_change_point_card_status'],
                    'now_turn' => $machineCacheInfo[$this->cacheDataKey . '_now_turn'],
                    'rush_status' => $machineCacheInfo[$this->cacheDataKey . '_rush_status'],
                    'has_lock' => $machineCacheInfo[$this->cacheDataKey . '_has_lock'],
                ];
                switch ($name) {
                    case 'gaming_user_id':
                        if (!empty($this->machine->gamingPlayer)) {
                            $this->sendMachineRealTimeInformation($this->machine->gamingPlayer->department_id,
                                'game_start', $info);
                        }
                        break;
                    case 'auto':
                    case 'turn':
                    case 'win_number':
                    case 'push_auto':
                    case 'reward_status':
                    case 'last_point_at':
                    case 'wash_point':
                    case 'keep_seconds':
                    case 'score':
                    case 'rush_status':
                    case 'bb_status':
                        if (!empty($this->machine->gamingPlayer)) {
                            $this->sendMachineRealTimeInformation($this->machine->gamingPlayer->department_id,
                                'game_info_change', $info);
                        }
                        break;
                }
                if (in_array($name, $this->machineInfo) && !empty($this->machine->gaming_user_id)) {
                    $this->sendMachineNowInfoMessage($this->machine->gaming_user_id, $this->machine->id, $name, $info);
                }
            }
        }
    }

    /**
     * 获取所有属性
     * @return iterable
     */
    private function getAllData(): iterable
    {
        return Cache::getMultiple($this->cacheDataKeyArr, 0);
    }

    /**
     * 钢珠消息处理
     * @param string $msg
     * @return bool
     */
    public function jackPotCmd(string $msg): bool
    {
        $domain = $this->machine->domain;
        $port = $this->machine->port;
        try {
            $len = mb_strlen($msg);
            if ($len != 30 && $len != 10 && $len != 16 && $len != 14 && $len != 12 && $len != 36) {
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
            $fun = substr($msg, 0, 6);
            $fun1 = substr($msg, 0, 4);
            $gamingUserId = $this->machine->gaming_user_id;
            $orgRewardStatus = $this->reward_status; // 开奖
            $orgAuto = $this->auto; // 自动
            $orgPoint = $this->point; // 原始分数
            $orgTurn = $this->turn; // 原始转数
            $orgScore = $this->score; // 原始转数
            $orgNowTurn = $this->now_turn; // 原始中中对接次数
            $orgWinNumber = $this->win_number; // 中洞兑奖次数
            if ($len == '36' && ($fun1 == self::TESTING || $fun1 == self::TESTING2)) {
                if (substr($msg, 18, 2) != 'da') {
                    $this->has_lock = 1;
                    sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $gamingUserId);
                    throw new \Exception('机台故障');
                }
                [$nowPoint, $nowRatio, $nowWinNumber, $nowScore, $nowTurn] = self::parseHeartbeat($msg);
                $nowAuto = substr($msg, 2, 2) == 'c6' ? 1 : 0;
                $nowRewardStatus = substr($msg, 10, 2) == 'd0' ? 0 : 1;
                $this->log->error('机台当前数据: ', [
                    [
                        'msg' => $msg,
                        'machine_code' => $this->machine->code,
                        'nowRewardStatus' => $nowRewardStatus,
                        'nowAuto' => $nowAuto,
                        'nowPoint' => $nowPoint,
                        'nowRatio' => $nowRatio,
                        'nowWinNumber' => $nowWinNumber,
                        'nowTurn' => $nowTurn,
                        'orgNowTurn' => $orgNowTurn,
                        'nowScore' => $nowScore,
                    ],
                    [
                        'orgRewardStatus' => $orgRewardStatus,
                        'orgAuto' => $orgAuto,
                        'orgPoint' => $orgPoint,
                        'orgTurn' => $orgTurn,
                        'orgWinNumber' => $orgWinNumber,
                        'orgNowTurn' => $orgNowTurn,
                        'orgScore' => $orgScore,
                    ],
                ]);
                $this->point = $nowPoint;
                $this->auto = $nowAuto;
                $this->win_number = $nowWinNumber;
                $this->score = $nowScore;
                $this->turn = $nowTurn;
                $this->reward_status = $nowRewardStatus;
                $this->now_turn = $nowWinNumber;
                if ($nowRewardStatus == 1 && $orgRewardStatus == 0) {
                    $machineLotteryRecord = new MachineLotteryRecord();
                    $machineLotteryRecord->machine_id = $this->machine->id;
                    $machineLotteryRecord->player_id = $this->machine->gaming_user_id ?? 0;
                    $machineLotteryRecord->department_id = $this->machine->gamingPlayer->department_id ?? 0;
                    $machineLotteryRecord->draw_bet = $this->win_number;
                    $machineLotteryRecord->use_turn = $this->now_turn;
                    $machineLotteryRecord->save();
                }
                // 开奖结束
                if ($nowRewardStatus == 0) {
                    if ($orgRewardStatus == 1) {
                        if (!empty($this->machine->gamingPlayer)) {
                            (new LotteryServices())->setMachine($this->machine)->setPlayer($this->machine->gamingPlayer)->fixedPotCheckLottery($nowScore);
                        }
                        if ($nowScore > 0 && !empty($this->machine->gaming_user_id)) {
                            Client::send('play-activity', [
                                'machine_id' => $this->machine->id,
                                'player_id' => $this->machine->gaming_user_id,
                                'point' => $nowScore,
                            ]);
                        }
                        // 开奖结束后需剔除其他观看中玩家
                        sendSocketMessage('group-' . $this->machine->id, [
                            'msg_type' => 'machine_reward_end',
                            'machine_id' => $this->machine->id,
                            'machine_code' => $this->machine->code,
                            'gaming_user_id' => $this->machine->gaming_user_id,
                        ]);
                        $this->sendCmd(self::SCORE_TO_POINT, 0, 'player', $this->machine->gaming_user_id);
                    }
                }
                if ($orgWinNumber > 0 && $orgWinNumber > $nowWinNumber && $this->change_point_card_status == 0 && $nowRewardStatus == 0 && $orgRewardStatus == 0) {
                    sendMachineException($this->machine, Notice::TYPE_MACHINE_WIN_NUMBER);
                    $this->win_number = $nowWinNumber;
                    return true;
                }
                if ($nowRewardStatus == 0) {
                    if (!empty($gamingUserId)) {
                        // turn 是"剩余转数"，玩家游玩时会减少
                        $turnDelta = bcsub($nowTurn, $orgTurn, 2);

                        // 检查是否刚执行过上转下转操作（检查缓存标记）
                        $isTurnAction = Cache::get('turn_action_flag_' . $this->machine->id);

                        // 调试日志：记录turn的变化情况
                        $this->log->info('心跳turn变化检测', [
                            'machine_code' => $this->machine->code,
                            'now_turn' => $nowTurn,
                            'org_turn' => $orgTurn,
                            'turn_delta' => $turnDelta,
                            'current_player_win_number' => $this->player_win_number,
                            'is_turn_action' => $isTurnAction ? 'yes' : 'no'
                        ]);

                        // 如果检测到上转下转标记，跳过本次累加
                        if ($isTurnAction) {
                            $this->log->info('检测到上转/下转操作标记，跳过本次累加', [
                                'machine_code' => $this->machine->code,
                                'turn_delta' => $turnDelta
                            ]);
                        }
                        // turn是剩余转数，负增量说明玩家消耗了转数（正常游玩）
                        // 但需要过滤大幅减少（可能是下转操作）
                        else if (bccomp($turnDelta, '0', 2) < 0 && bccomp($turnDelta, '-10', 2) >= 0) {
                            // 负增量在 -10 到 0 之间，且无上转下转标记，说明是正常游玩消耗
                            $playerNumber = $this->player_win_number;
                            $consumed = abs($turnDelta);  // 消耗的转数（绝对值）
                            $this->player_win_number = bcadd($playerNumber, $consumed, 2);

                            $this->log->info('累加玩家使用转数', [
                                'machine_code' => $this->machine->code,
                                'turn_delta' => $turnDelta,
                                'consumed' => $consumed,
                                'player_win_number' => $this->player_win_number
                            ]);
                        } else if (bccomp($turnDelta, '-10', 2) < 0) {
                            $this->log->info('turn大幅减少，可能是下转操作，不累加', [
                                'machine_code' => $this->machine->code,
                                'turn_delta' => $turnDelta
                            ]);
                        } else if (bccomp($turnDelta, '0', 2) > 0) {
                            $this->log->info('turn增加，可能是上转操作，不累加', [
                                'machine_code' => $this->machine->code,
                                'turn_delta' => $turnDelta
                            ]);
                        }
                    } else {
                        $this->log->info('没有游戏中的玩家，跳过turn累加', [
                            'machine_code' => $this->machine->code,
                            'gaming_user_id' => $gamingUserId
                        ]);
                    }
                } else {
                    if (!empty($gamingUserId)) {
                        $this->log->info('开奖状态中，跳过turn累加', [
                            'machine_code' => $this->machine->code,
                            'reward_status' => $nowRewardStatus
                        ]);
                    }
                }
                if ($nowTurn <= 0 && !empty($gamingUserId)) {
                    Cache::delete('gift_cache_' . $this->machine->id . '_' . $gamingUserId);
                }
                if ($orgWinNumber > 0 && $orgWinNumber < $nowWinNumber && !empty($gamingUserId) && $this->change_point_card_status == 0) {
                    $this->last_play_time = time();
                    if ($nowRewardStatus == 0) {
                        Client::send('play-keep-machine', [
                            'change_amount' => abs($nowWinNumber - $orgWinNumber),
                            'machine_id' => $this->machine->id,
                            'player_id' => $gamingUserId,
                        ]);
                        Client::send('lottery-machine', [
                            'num' => $nowWinNumber,
                            'last_num' => $orgWinNumber,
                            'machine_id' => $this->machine->id,
                            'player_id' => $gamingUserId,
                        ]);
                    }
                }
                $this->sendMachineNowStatusMessage($this->machine->id);
            } else {
                switch ($fun) {
                    case self::REWARD_SWITCH:
                    case self::MACHINE_OPEN:
                    case self::MACHINE_CLOSE:
                    case self::TURN_DOWN_ALL:
                    case self::TURN_UP_ALL:
                    case self::PUSH_THREE:
                    case self::PUSH_ONE:
                    case self::CLEAR_LOG:
                    case self::CHECK:
                    case self::POINT_TO_TURN:
                    case self::TURN_TO_POINT:
                        $this->setActionVersion($fun);
                        break;
                    case self::AUTO_UP_TURN:
                        $this->auto = 1;
                        $this->setActionVersion($fun);
                        break;
                    case self::AUTO_STOP:
                        $this->auto = 0;
                        $this->setActionVersion($fun);
                        break;
                    default:
                        $action = substr($msg, 0, 4);
                        switch ($action) {
                            case self::OPEN_ANY_POINT:
                                Redis::publish($domain . ':' . $port, '设备返回的消息');
                                $this->setActionVersion(substr($msg, 0, 6));
                                break;
                            case self::FAULT1_MACHINE_SCORE:
                            case self::FAULT_MACHINE_SCORE:
                                $this->has_lock = 1;
                                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $gamingUserId);
                                throw new \Exception('机台故障');
                            case self::WASH_ZERO:
                                Redis::publish($domain . ':' . $port, '设备返回的消息');
                                $cmd = substr($msg, 0, 6);
                                $this->setActionVersion($cmd);
                                $uid = $this->machine->domain . ':' . $this->machine->port;
                                Gateway::sendToUid($uid, hex2bin($cmd . $s1 . $s2));
                                break;
                            case self::GET_MACHINE_POINT:
                            case self::AUTO_MACHINE_POINT:
                                $point = self::parseScore(substr($msg, 4, 6));
                                $this->point = $point;
                                $this->log->error('当前分数', [$point]);
                                $this->setActionVersion(self::MACHINE_POINT);
                                break;
                            case self::GET_MACHINE_SCORE:
                                $score = self::parseScore(substr($msg, 4, 6));
                                $this->score = $score;
                                $this->log->error('当前得分', [$score]);
                                $this->setActionVersion(self::MACHINE_SCORE);
                                break;
                            case self::GET_MACHINE_TURN:
                                $turn = self::parseScore('00' . substr($msg, 4, 4));
                                $this->turn = $turn;
                                $this->log->error('当前转数', [$turn]);

                                // 检查是否是上转/下转后的主动获取（检查标记）
                                $isTurnAction = Cache::get('turn_action_flag_' . $this->machine->id);
                                if ($isTurnAction && !empty($gamingUserId)) {
                                    // 更新基准点（使用缓存属性，不修改数据库）
                                    $this->player_turn_base = $turn;

                                    // 清除标记
                                    Cache::delete('turn_action_flag_' . $this->machine->id);

                                    $this->log->info('更新玩家转数基准点', [
                                        'machine_code' => $this->machine->code,
                                        'new_base' => $turn,
                                        'player_win_number' => $this->player_win_number
                                    ]);
                                }

                                $this->setActionVersion(self::MACHINE_TURN);
                                break;
                            case self::GET_WIN_NUMBER:
                            case self::REWARD_WIN_NUMBER:
                                $winNumber = self::parseScore('00' . substr($msg, 6, 4));
                                // 检查 winNumber 变化的合理性，防止异常值写入
                                $oldWinNumber = $this->win_number;
                                $delta = $winNumber - $oldWinNumber;
                                // winNumber 在正常游戏中不应该突然变化超过100
                                // 也不应该大幅减少（除非是机器重启，但那样应该是从0开始）
                                if (abs($delta) > 100) {
                                    $this->log->error('检测到异常的winNumber值，拒绝更新', [
                                        'machine_code' => $this->machine->code,
                                        'old_win_number' => $oldWinNumber,
                                        'new_win_number' => $winNumber,
                                        'delta' => $delta,
                                        'raw_msg' => $msg,
                                        'extracted_hex' => substr($msg, 4, 6),
                                        'command' => $action ?? $fun
                                    ]);
                                    // 不更新 win_number，保持原值
                                    // 但仍然设置 action version 以免阻塞等待
                                } else {
                                    $this->win_number = $winNumber;
                                }

                                $this->setActionVersion(self::WIN_NUMBER);
                                break;
                            case self::SCORE_TO_POINT:
                                $this->setActionVersion(self::SCORE_TO_POINT);
                                break;
                            default:
                                throw new \Exception('不存在的指令');
                        }
                        break;
                }
            }

        } catch (\Exception $e) {
            $this->log->error('消息处理错误: ', [
                $e->getMessage(),
                [
                    'msg' => $msg,
                    'action' => $fun ?? '',
                    'machine_code' => $this->machine->code,
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
            'point_section' => substr($cleanCommand, 4, 6),   // 当前分数
            'ratio_section' => substr($cleanCommand, 12, 2),   // 趴数
            'win_number_section' => substr($cleanCommand, 14, 4), // 中洞兑奖次数
            'score_section' => substr($cleanCommand, 20, 6), // 得分
            'turn_section' => substr($cleanCommand, 28, 4)     // 剩余转数
        ];
        $ratioArr = [
            '00' => '10',
            '01' => '11',
            '02' => '12',
            '03' => '13',
            '04' => '14',
            '05' => '15',
        ];
        return [
            self::parseScore($parts['point_section']),
            $ratioArr[$parts['ratio_section']],
            self::parseScore('00' . $parts['win_number_section']),
            self::parseScore($parts['score_section']),
            self::parseScore('00' . $parts['turn_section']),
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
            switch ($cmd) {
                case self::SCORE_TO_POINT:
                    if ($this->reward_status == 1) {
                        throw new Exception(trans('machine_reward_drawing', ['{code}' => $this->machine->code],
                            'message'));
                    }
                    $this->machineAction($uid, $cmd, $source, $source_id);
                    break;
                case self::TURN_UP_ALL:
                    if ($this->point < 100) {
                        throw new Exception(trans('machine_point_insufficient', ['{code}' => $this->machine->code],
                            'message'));
                    }
                    Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));

                    // 上转操作后，主动获取转数以更新基准点
                    usleep(100000); // 等待100ms让机台处理命令
                    // 设置标记：表示这是上转下转后的主动获取
                    Cache::set('turn_action_flag_' . $this->machine->id, true, 5);
                    Gateway::sendToUid($uid, hex2bin($this->createCmd(self::MACHINE_TURN)));
                    $this->log->info('全部上转后主动获取转数', ['machine_code' => $this->machine->code]);
                    break;
                case self::TURN_DOWN_ALL:
                case self::POINT_TO_TURN:
                case self::TURN_TO_POINT:
                    // 上转/下转操作，先执行命令，再主动获取转数
                    $this->machineAction($uid, $cmd, $source, $source_id);

                    // 命令执行完成后，主动获取转数以更新基准点
                    usleep(100000); // 等待100ms让机台处理命令
                    // 设置标记：表示这是上转下转后的主动获取
                    Cache::set('turn_action_flag_' . $this->machine->id, true, 5);
                    Gateway::sendToUid($uid, hex2bin($this->createCmd(self::MACHINE_TURN)));
                    $this->log->info('上转/下转后主动获取转数', [
                        'machine_code' => $this->machine->code,
                        'command' => $cmd
                    ]);
                    break;
                case self::MACHINE_SCORE:
                case self::MACHINE_POINT:
                case self::MACHINE_TURN:
                case self::WIN_NUMBER:
                    $this->machineAction($uid, $cmd, $source, $source_id);
                    break;
                case self::OPEN_ANY_POINT:
                    $code = sprintf('%02x', rand(0, 0x63));
                    $this->log->info('上分编号为', [$code]);
                    $this->openPoint($uid, $cmd . $code, $data, $source, $source_id);
                    break;
                case self::WASH_ZERO:
                    $code = sprintf('%02x', rand(0, 0x63));
                    $this->washPoint($uid, $cmd . $code, $this->pre_wash_point, $source, $source_id);
                    break;
                case self::AUTO_UP_TURN:
                    if ($this->reward_status == 1) {
                        throw new Exception(trans('machine_reward_drawing', ['{code}' => $this->machine->code],
                            'message'));
                    }
                    if ($this->score > 0) {
                        throw new Exception(trans('machine_sore_exist',
                            ['{code}' => $this->machine->code, '{score}' => $this->score], 'message'));
                    }
                    $auto = $this->auto;
                    if ($auto == 1) {
                        Gateway::sendToUid($uid, hex2bin($this->createCmd(self::AUTO_STOP)));
                    } else {
                        Gateway::sendToUid($uid, hex2bin($this->createCmd(self::AUTO_UP_TURN)));
                    }
                    break;
                default:
                    Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));
                    break;
            }
        } catch (Exception $e) {
            if (in_array($cmd, [
                self::OPEN_ANY_POINT,
                self::WASH_ZERO
            ])) {
                $this->has_lock = 1;
                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->machine->gaming_user_id);
            }
            throw new Exception($e->getMessage());
        }
        if ($source == 'admin') {
            sendSocketMessage('private-admin-1-' . $source_id, [
                'msg_type' => 'machine_action_result',
                'id' => $this->machine->id,
                'description' => $this->getDescription($cmd),
            ]);
        }
        saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($this->getAllData()), $cmd, 1,
            $isSystem, $data);

        return true;
    }

    /**
     * 读取当前分数
     * @param string $uid
     * @param string $cmd
     * @param string $source
     * @param int $source_id
     * @param int $attempts
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
            Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd)));
            $handleDuration = 0;
            $sleep = 5000; // 5毫秒取一次值
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
                    throw new Exception(trans('machine_action_fail', [], 'message'));
                }
                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception $e) {
            $attempts++;
            if ($attempts >= $maxRetries) {
                $this->log->error('指令超时异常', ['jackpot -> machineAction -> ' . $cmd, [$this->machine->code]]);
                throw new Exception(trans('machine_action_fail', [], 'message'));
            }
            usleep(50000);
            $this->machineAction($uid, $cmd, $source, $source_id, $attempts);
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
        $this->log->error('发送指令:', [$cmd . $s1 . $s2]);
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
     * 获取操作版本号
     * @param $name
     * @return float
     */
    public function getActionVersion($name): float
    {
        return (float)Cache::get($this->cacheDataKey . '_' . 'action_' . $name);
    }

    /**
     * 获取机台信息描述
     * @param string $fun 操作指令
     * @return string
     */
    public function getDescription(string $fun = ''): string
    {
        locale(Str::replace('-', '_', $this->lang));
        $description = '';
        $autoStatus = $this->auto == 1 ? trans('machine_status_yes', [], 'machine_action') : trans('machine_status_no',
            [], 'machine_action');
        $lotteryStatus = $this->reward_status == 1 ? trans('machine_status_yes', [],
            'machine_action') : trans('machine_status_no', [], 'machine_action');
        if (empty($fun)) {
            $nowTurn = $this->now_turn;
            $description .= trans('machine_auto_status', [], 'machine_action') . $autoStatus . PHP_EOL;
            $description .= trans('machine_lottery_status', [], 'machine_action') . $lotteryStatus . PHP_EOL;
            $description .= trans('machine_point', [], 'machine_action') . ($this->point ?? 0) . PHP_EOL;
            $description .= trans('machine_score', [], 'machine_action') . ($this->score ?? 0) . PHP_EOL;
            $description .= trans('machine_turn', [], 'machine_action') . ($this->turn ?? 0) . PHP_EOL;
            $description .= trans('now_turn', [], 'machine_action') . ($nowTurn ?? 0) . PHP_EOL;
        } else {
            $description .= trans('function.' . GameType::TYPE_STEEL_BALL . '_' . Machine::CONTROL_TYPE_SONG . '.' . $fun,
                [], 'machine_action');
            switch ($fun) {
                case SongJackpot::MACHINE_POINT:
                    $description .= ': ' . $this->point;
                    break;
                case SongJackpot::MACHINE_SCORE:
                    $description .= ': ' . $this->score;
                    break;
                case SongJackpot::MACHINE_TURN:
                    $description .= ': ' . $this->turn;
                    break;
                case SongJackpot::WIN_NUMBER:
                    $description .= ': ' . $this->win_number;
                    break;
            }
        }

        return $description;
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
        try {
            $beforeActionTime = $this->setActionVersion($cmd);
            Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));
            $handleDuration = 0;
            $sleep = 50000; // 5毫秒取一次值
            while (true) {
                $actionTime = $this->getActionVersion($cmd);
                if ($actionTime > $beforeActionTime) {
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
                    throw new Exception(trans('machine_action_fail', [], 'message'));
                }
                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception) {
            $this->log->error('指令超时异常', ['slot -> machineAction', [$this->machine->code]]);
            throw new Exception(trans('machine_action_fail', [], 'message'));
        }
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
        try {
            $beforeActionTime = $this->setActionVersion($cmd);
            Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd . 'c1', 0)));
            $handleDuration = 0;
            $sleep = 50000; // 5毫秒取一次值
            while (true) {
                $actionTime = $this->getActionVersion($cmd);
                if ($actionTime > $beforeActionTime) {
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
                    throw new Exception(trans('machine_action_fail', [], 'message'));
                }
                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception) {
            $attempts++;
            if ($attempts >= $maxRetries) {
                $this->log->error('指令超时异常', ['slot -> machineAction', [$this->machine->code]]);
                throw new Exception(trans('machine_action_fail', [], 'message'));
            }
            usleep(50000);
            $this->washPoint($uid, $cmd, $data, $source, $source_id, $attempts);
        }
    }
}
