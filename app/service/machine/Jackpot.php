<?php

namespace app\service\machine;

use app\model\GameType;
use app\model\Machine;
use app\model\MachineLotteryRecord;
use app\model\Notice;
use app\service\LotteryServices;
use Exception;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Str;
use support\Cache;
use support\Log;
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
 * @property int $handle_status 圖柄確認状态
 * @property int $win_number 讀取中洞對獎次數
 * @property int $action_time 操作时间
 * @property int $push_auto push auto状态
 * @property int $change_point_card_status 开分卡状态
 * @property int $gift_bet 玩家开分增点时押注
 * @property int $now_turn 当前转数
 * @property int $has_lock 机台锁
 *
 * @package app\service\machine
 */
class Jackpot extends MachineServices implements BaseMachine
{
    const PREFIX = 'A2'; //前缀

    const ALL = 'all'; //机台状态
    const OPEN_ONE = '41'; //开分一次
    const OPEN_TEN = '42'; //开分10次

    const WASH_ZERO = '43'; //洗分清零
    const WASH_ZERO_REMAINDER = '44'; //洗分清零留余数

    const AUTO_UP_TURN = '45'; //自动上转(开始游戏)
    const RESET_READY_TURN = '46'; //重設預備轉入的轉數
    const TURN_DOWN_ALL = '47'; //全部下转

    const TURN_TO_POINT = '48'; //转数转分数
    const POINT_TO_TURN = '49'; //分数转转数

    const OPEN_ANY_POINT = '4A'; //开任意分数
    const SCORE_TO_POINT = '4B'; //得分转分数
    const TURN_UP_ALL = '4C'; //全部上转
    const OP_3 = '4D'; //開 OP_3 個保轉
    const CLEAR_GIVE = '4E'; //清除開贈要求
    const CLEAR_LOG = '4F'; //清除历史记录
    const BB_RUSH = '2B'; //读取BB rush

    const TESTING = '20'; //测试连接
    const MACHINE_POINT = '21'; //读取机台当前分
    const MACHINE_SCORE = '22'; //读取机台当前得分
    const MACHINE_TURN = '23'; //读取机台当前转数
    const WIN_NUMBER = '24'; //讀取中洞對獎次數
    const READ_OPEN_POINT = '25'; //讀取总開分
    const READ_WASH_POINT = '26'; //讀取总下分
    const REWARD_SWITCH = '2D';// 大賞燈切換
    const REWARD_SWITCH_OPT = '64';
    const PUSH = '2E'; //PUSH 0000停止
    const PUSH_STOP = '00'; //PUSH 0000停止
    const PUSH_ONE = '01'; //PUSH 1下
    const PUSH_TWO = '02'; //PUSH 1秒2下
    const PUSH_THREE = '03'; //PUSH 1秒5下

    const KEEPING = '40'; //保留

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
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_win_number',
            $this->cacheDataKey . '_push_auto',
            $this->cacheDataKey . '_change_point_card_status',
            $this->cacheDataKey . '_gift_bet',
            $this->cacheDataKey . '_now_turn',
            $this->cacheDataKey . '_rush_status',
            $this->cacheDataKey . '_bb_status',
            $this->cacheDataKey . '_has_lock',
        ];
        $this->machineInfo = [
            'auto',
            'move_point',
            'reward_status',
            'turn',
            'point',
            'score',
            'win_number',
            'push_auto',
            'rush_status',
            'bb_status',
            'has_lock',
        ];
        $this->lang = $lang;
        $this->cacheData = $this->getMachineCache();
        $this->log = Log::channel('jackpot_machine');
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
                    'action_time' => $machineCacheInfo[$this->cacheDataKey . '_action_time'],
                    'win_number' => $machineCacheInfo[$this->cacheDataKey . '_win_number'],
                    'push_auto' => $machineCacheInfo[$this->cacheDataKey . '_push_auto'],
                    'change_point_card_status' => $machineCacheInfo[$this->cacheDataKey . '_change_point_card_status'],
                    'now_turn' => $machineCacheInfo[$this->cacheDataKey . '_now_turn'],
                    'rush_status' => $machineCacheInfo[$this->cacheDataKey . '_rush_status'],
                    'bb_status' => $machineCacheInfo[$this->cacheDataKey . '_bb_status'],
                    'has_lock' => $machineCacheInfo[$this->cacheDataKey . '_has_lock'],
                ];
                switch ($name) {
                    case 'gaming_user_id':
                        if (!empty($value)) {
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
     * @param string $message 消息
     * @return bool
     */
    public function jackPotCmd(string $message): bool
    {
        try {
            $msg = strtoupper(bin2hex($message));
            jackPotCheckCRC8($msg); // 检查crc8
            $fun = substr($msg, 2, 2);
            $data = jackpotDecodeData($msg);
            checkJackpotXor55($msg, $data);
            $status1 = decodeStatus(substr($msg, 4, 2));
            $orgBbStatus = $this->bb_status;
            $orgRushStatus = $this->rush_status;
            $this->bb_status = substr($status1, 7, 1); // 开奖状态
            $this->rush_status = substr($status1, 6, 1); // rush确变状态
            $this->handle_status = substr($status1, 4, 1); // 圖柄確認
            $this->auto = substr($status1, 5, 1); // 自动状态
            $this->action_time = getMillisecond();
            $this->log->info('机器接收指令日志', [
                'jackPot -> jackPotCmd',
                [
                    'code' => $this->machine->code,
                    'msg' => $msg,
                    'auto' => $this->auto,
                    'now_turn' => $this->now_turn,
                    'reward_status' => $this->reward_status,
                    'bb_status' => $this->bb_status,
                    'rush_status' => $this->rush_status,
                    'player_win_number' => $this->player_win_number,
                ]
            ]);
            // 开奖状态和rush确变状态只要一个为1进入开奖状态
            if ($this->bb_status == 1 || $this->rush_status == 1) {
                $this->reward_status = 1;
                $this->last_play_time = time();
            }
            // 开奖状态和rush确变状态都为0并且当前状态为开奖中
            if ($this->bb_status == 0 && $this->rush_status == 0) {
                $rewardStatus = $this->reward_status;
                $this->reward_status = 0;
                if ($rewardStatus == 1) {
                    if (!empty($this->machine->gamingPlayer)) {
                        (new LotteryServices())->setMachine($this->machine)->setPlayer($this->machine->gamingPlayer)->fixedPotCheckLottery($this->score);
                    }
                    if ($this->score > 0 && !empty($this->machine->gaming_user_id)) {
                        Client::send('play-activity', [
                            'machine_id' => $this->machine->id,
                            'player_id' => $this->machine->gaming_user_id,
                            'point' => $this->score,
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
            if ($orgBbStatus == 0 && $orgRushStatus == 0 && $this->bb_status == 1 && $this->rush_status == 1 && $this->now_turn > 0) {
                $machineLotteryRecord = new MachineLotteryRecord();
                $machineLotteryRecord->machine_id = $this->machine->id;
                $machineLotteryRecord->player_id = $this->machine->gaming_user_id ?? 0;
                $machineLotteryRecord->department_id = $this->machine->gamingPlayer->department_id ?? 0;
                $machineLotteryRecord->draw_bet = $this->win_number;
                $machineLotteryRecord->use_turn = $this->now_turn;
                $machineLotteryRecord->save();
                $this->now_turn = 0;
            }
            if (($orgBbStatus == 0 && $orgRushStatus == 1 && $this->bb_status == 0 && $this->rush_status == 0 && $this->now_turn > 0) || ($orgBbStatus == 1 && $this->bb_status == 0 && $this->rush_status == 1 && $this->now_turn > 0)) {
                $this->now_turn = 0;
            }

            $gamingUserId = $this->machine->gaming_user_id;
            switch ($fun) {
                case Jackpot::TURN_UP_ALL:
                case Jackpot::TURN_TO_POINT:
                case Jackpot::TURN_DOWN_ALL:
                case Jackpot::POINT_TO_TURN:
                case Jackpot::SCORE_TO_POINT:
                case Jackpot::OPEN_ONE:
                case Jackpot::OPEN_TEN:
                case Jackpot::OPEN_ANY_POINT:
                case Jackpot::WASH_ZERO:
                case Jackpot::WASH_ZERO_REMAINDER:
                case Jackpot::AUTO_UP_TURN:
                case Jackpot::BB_RUSH:
                    $this->setActionVersion($fun);
                    break;
                case Jackpot::MACHINE_POINT:
                    $this->point = $data;
                    $this->setActionVersion($fun);
                    break;
                case Jackpot::CLEAR_LOG:
                    $this->win_number = 0;
                    $this->setActionVersion($fun);
                    break;
                case Jackpot::MACHINE_SCORE:
                    $this->score = $data;
                    if ($data > 0) {
                        $this->reward_status = 1;
                    }
                    $this->setActionVersion($fun);
                    break;
                case Jackpot::MACHINE_TURN:
                    $this->turn = $data;
                    if ($data <= 0 && !empty($this->machine->gaming_user_id)) {
                        Cache::delete('gift_cache_' . $this->machine->id . '_' . $this->machine->gaming_user_id);
                    }
                    $this->setActionVersion($fun);
                    break;
                case Jackpot::WIN_NUMBER:
                    if ($this->win_number > 0 && $this->win_number > $data && $this->change_point_card_status == 0) {
                        sendMachineException($this->machine, Notice::TYPE_MACHINE_WIN_NUMBER);
                        if (!empty($gamingUserId)) {
                            if ($this->auto == 1) {
                                $this->sendCmd(self::AUTO_UP_TURN, 0, 'player', $gamingUserId, 1);
                            }
                        }
                        $this->win_number = $data;
                        return true;
                    }
                    if ($this->win_number > 0 && $this->win_number != $data && !empty($gamingUserId) && $this->change_point_card_status == 0) {
                        $this->last_play_time = time();
                        if ($this->reward_status == 0) {
                            Client::send('play-keep-machine', [
                                'change_amount' => abs($data - $this->win_number),
                                'machine_id' => $this->machine->id,
                                'player_id' => $gamingUserId,
                            ]);
                            Client::send('lottery-machine', [
                                'num' => $data,
                                'last_num' => $this->win_number,
                                'machine_id' => $this->machine->id,
                                'player_id' => $gamingUserId,
                            ]);
                        }
                    }
                    if (($this->rush_status == 0 && $this->bb_status == 0) || ($this->rush_status == 1 && $this->bb_status == 0)) {
                        $nowTurn = $this->now_turn;
                        $bet = $this->win_number;
                        $this->now_turn = bcadd($nowTurn, bcsub($data, $bet, 2), 2);
                        if (!empty($gamingUserId)) {
                            $playerNumber = $this->player_win_number;
                            $this->player_win_number = bcadd($playerNumber, bcsub($data, $bet, 2), 2);
                        }
                    }
                    $this->win_number = $data;
                    $this->change_point_card_status = 0;
                    $this->setActionVersion($fun);
                    break;
                case Jackpot::READ_OPEN_POINT:
                    $this->open_point = $data;
                    $this->setActionVersion($fun);
                    break;
                case Jackpot::READ_WASH_POINT:
                    $this->wash_point = $data;
                    $this->setActionVersion($fun);
                    break;
                case Jackpot::PUSH:
                    $pushStatus = substr($msg, 4, 2);
                    if ($pushStatus == Jackpot::PUSH_STOP) {
                        $this->push_auto = 0;
                    }
                    if ($pushStatus == Jackpot::PUSH_THREE) {
                        $this->push_auto = 1;
                    }
                    $this->setActionVersion($fun);
                    break;
                case Jackpot::TESTING:
                    $this->sendMachineNowStatusMessage($this->machine->id);
                    break;
                default:
                    return false;
            }
        } catch (\Exception $e) {
            $this->log->error('消息处理错误: ', [
                $e->getMessage(),
                [
                    'msg' => $msg,
                    'action' => $fun ?? '',
                    'trace' => $e->getTraceAsString(),
                    'machineInfo' => $this->getMachineCache(),
                ]
            ]);
            return false;
        }

        return true;
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
            switch ($cmd) {
                case self::TESTING:
                default:
                    Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . $cmd, $data)));
                    if ($source == 'admin') {
                        sendSocketMessage('private-admin-1-' . $source_id, [
                            'msg_type' => 'machine_action_result',
                            'id' => $this->machine->id,
                            'description' => $this->getDescription($cmd),
                        ]);
                    }
                    break;
                case self::PUSH . self::PUSH_STOP:
                    Gateway::sendToUid($uid,
                        hex2bin($this->createCmd(self::PREFIX . self::PUSH, $data, self::PUSH_STOP)));
                    break;
                case self::PUSH . self::PUSH_ONE:
                    Gateway::sendToUid($uid,
                        hex2bin($this->createCmd(self::PREFIX . self::PUSH, $data, self::PUSH_ONE)));
                    break;
                case self::PUSH . self::PUSH_TWO:
                    Gateway::sendToUid($uid,
                        hex2bin($this->createCmd(self::PREFIX . self::PUSH, $data, self::PUSH_TWO)));
                    break;
                case self::PUSH . self::PUSH_THREE:
                    Gateway::sendToUid($uid,
                        hex2bin($this->createCmd(self::PREFIX . self::PUSH, $data, self::PUSH_THREE)));
                    break;
                case self::REWARD_SWITCH . self::REWARD_SWITCH_OPT:
                    Gateway::sendToUid($uid,
                        hex2bin($this->createCmd(self::PREFIX . self::REWARD_SWITCH, $data, self::REWARD_SWITCH_OPT)));
                    break;
                case self::OPEN_ANY_POINT:
                case self::OPEN_ONE:
                case self::OPEN_TEN:
                    $this->openPoint($uid, $cmd, $data, $source, $source_id);
                    break;
                case self::SCORE_TO_POINT:
                    if ($this->reward_status == 1) {
                        throw new Exception(trans('machine_reward_drawing', ['{code}' => $this->machine->code],
                            'message'));
                    }
                    $this->machineAction($uid, $cmd, $source, $source_id);
                    break;
                case self::WASH_ZERO_REMAINDER:
                case self::MACHINE_SCORE:
                case self::MACHINE_POINT:
                case self::MACHINE_TURN:
                case self::WIN_NUMBER:
                case self::TURN_DOWN_ALL:
                case self::TURN_UP_ALL:
                case self::POINT_TO_TURN:
                case self::TURN_TO_POINT:
                case self::WASH_ZERO:
                case self::CLEAR_LOG:
                    $this->machineAction($uid, $cmd, $source, $source_id);
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
                    Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . $cmd, $data)));
                    break;
            }
        } catch (Exception $e) {
            if (in_array($cmd, [
                self::OPEN_ANY_POINT,
                self::OPEN_ONE,
                self::OPEN_TEN,
                self::WASH_ZERO
            ])) {
                $this->has_lock = 1;
                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->machine->gaming_user_id);
            }
            throw new Exception($e->getMessage());
        }
        saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($this->getAllData()), $cmd, 1,
            $isSystem, $data);

        return true;
    }

    /**
     * 创建cmd
     * @param string $cmd 指令
     * @param int $data 数据
     * @param string $option 可选值
     * @return string
     * @throws Exception
     */
    private function createCmd(string $cmd, int $data = 0, string $option = '00'): string
    {
        $decodeData = jackpotEncodeData($data);
        $cmd .= "{$option}00" . $decodeData;
        $cmd .= jackpotEncodeDataXor55($data) . '000000000000'; // 异或位处理
        $cmd .= crc8(hex2bin($cmd), 0x31, 0x00, 0x00, true, true, false);
        return $cmd . 'DD';
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
        $bbStatus = $this->bb_status == 1 ? trans('machine_status_yes', [],
            'machine_action') : trans('machine_status_no', [], 'machine_action');
        $rushStatus = $this->rush_status == 1 ? trans('machine_status_yes', [],
            'machine_action') : trans('machine_status_no', [], 'machine_action');
        if (empty($fun)) {
            $nowTurn = $this->now_turn;
            $description .= trans('machine_auto_status', [], 'machine_action') . $autoStatus . PHP_EOL;
            $description .= trans('machine_lottery_status', [], 'machine_action') . $lotteryStatus . PHP_EOL;
            $description .= trans('machine_bb_status', [], 'machine_action') . $bbStatus . PHP_EOL;
            $description .= trans('machine_rush_status', [], 'machine_action') . $rushStatus . PHP_EOL;
            $description .= trans('machine_point', [], 'machine_action') . ($this->point ?? 0) . PHP_EOL;
            $description .= trans('machine_score', [], 'machine_action') . ($this->score ?? 0) . PHP_EOL;
            $description .= trans('machine_turn', [], 'machine_action') . ($this->turn ?? 0) . PHP_EOL;
            $description .= trans('now_turn', [], 'machine_action') . ($nowTurn ?? 0) . PHP_EOL;
            $description .= trans('machine_open_point', [], 'machine_action') . ($this->open_point ?? 0) . PHP_EOL;
            $description .= trans('machine_wash_point', [], 'machine_action') . ($this->wash_point ?? 0);
        } else {
            $description .= trans('function.' . GameType::TYPE_STEEL_BALL . '_' . Machine::CONTROL_TYPE_SONG . '.' . $fun,
                [], 'machine_action');
            switch ($fun) {
                case Jackpot::MACHINE_POINT:
                    $description .= ': ' . $this->point;
                    break;
                case Jackpot::MACHINE_SCORE:
                    $description .= ': ' . $this->score;
                    break;
                case Jackpot::MACHINE_TURN:
                    $description .= ': ' . $this->turn;
                    break;
                case Jackpot::WIN_NUMBER:
                    $description .= ': ' . $this->win_number;
                    break;
                case Jackpot::READ_OPEN_POINT:
                    $description .= ': ' . $this->open_point;
                    break;
                case Jackpot::READ_WASH_POINT:
                    $description .= ': ' . $this->wash_point;
                    break;
            }
        }

        return $description;
    }

    /**
     * 开分
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
        int    $data = 0,
        string $source = 'player',
        int    $source_id = 0
    ): void
    {
        try {
            $beforePoint = $this->point;
            Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . $cmd, $data)));
            Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . self::MACHINE_POINT)));
            $beforeActionTime = $this->action_time;
            $handleDuration = 0;
            $sleep = 50000; // 5毫秒取一次值
            while (true) {
                $point = $this->point;
                $actionTime = $this->action_time;
                if ($actionTime > $beforeActionTime && $beforePoint < $point) {
                    if ($source == 'admin') {
                        sendSocketMessage('private-admin-1-' . $source_id, [
                            'msg_type' => 'machine_action_result',
                            'id' => $this->machine->id,
                            'description' => $this->getDescription(),
                        ]);
                    }
                    return;
                }
                if ($handleDuration >= $this->expirationTime) { // 只跑1.5秒钟
                    $this->log->error('指令超时异常', ['jackpot -> openPoint', [$this->machine->code]]);
                    throw new Exception(trans('machine_action_fail', [], 'message'));
                }
                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
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
            if ($cmd == Jackpot::CLEAR_LOG) {
                Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . $cmd, 0, '09')));
            } else {
                Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . $cmd)));
            }
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
     * 获取操作版本号
     * @param $name
     * @return float
     */
    public function getActionVersion($name): float
    {
        return (float)Cache::get($this->cacheDataKey . '_' . 'action_' . $name);
    }
}
