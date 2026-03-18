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
 * Class Slot
 * @property int $auto 自动状态
 * @property int $move_point 移分状态
 * @property int $reward_status 开奖状态
 * @property int $rb_status rb状态
 * @property int $bb_status bb状态
 * @property int $play_start_time 开始游戏时间
 * @property int $gaming_user_id 游戏中玩家
 * @property int $gaming 是否游戏中
 * @property int $point 当前分数
 * @property int $score 当前得分
 * @property int $bet 机台压分
 * @property int $last_play_time 最后游戏时间
 * @property int $win 机台总得分
 * @property int $bb bb
 * @property int $rb rb
 * @property int $open_point 开分次数
 * @property int $wash_point 洗分次数
 * @property int $keep_seconds 保留时长
 * @property int $keeping 保留状态
 * @property int $keeping_user_id 保留玩家
 * @property int $last_keep_at 最后保留时间
 * @property int $player_pressure 玩家進入時原始壓分
 * @property int $player_score 玩家進入時原始得分
 * @property int $player_open_point 玩家开分
 * @property int $player_wash_point 玩家洗分
 * @property int $last_point_at 玩家最后上下分时间
 * @property int $action_time 操作时间
 * @property int $change_point_card_status 开分卡状态
 * @property int $gift_bet 玩家开分增点时押注
 * @property int $gift_condition 增点完成条件
 * @property int $now_turn 当前转数
 * @property int $has_lock 机台锁
 *
 * @package app\service\machine
 */
class Slot extends MachineServices implements BaseMachine
{
    const PREFIX = 'A2'; //前缀

    const ALL = 'all'; //机台状态
    const OPEN_ONE = '41'; //开分一次
    const OPEN_TEN = '42'; //开分10次
    const WASH_ZERO = '43'; //洗分&清零
    const WASH_POINT = '44'; //洗分
    const MOVE_POINT_ON = '45'; //移分 長ON
    const MOVE_POINT_OFF = '46'; //移分 OFF
    const ALL_DOWN = '47'; //清除 BET WIN BB RB
    const OPEN_FIVE = '49'; //開分*5
    const OPEN_ANY_POINT = '4A'; //开任意数
    const REWARD_SWITCH = '2D'; //大賞燈切換
    const REWARD_SWITCH_OPT = '64'; // 大赏灯操作
    const MACHINE_BUSY = '1F'; // 忙碌中

    const OUTPUT = '4B'; //
    const ALL_OFF = '00'; //全OFF
    const U1_ON = '01'; //U1 ON
    const U2_ON = '02'; //U2 ON
    const U3_ON = '03'; //U3 ON
    const U4_ON = '04'; //U4 ON
    const U5_ON = '05'; //U5 ON
    const U6_ON = '06'; //U6 ON
    const U7_ON = '07'; //U7 ON
    const U8_ON = '08'; //U8 ON
    const U1_PULSE = '21'; //U1 PULSE
    const U2_PULSE = '22'; //U2 PULSE
    const U3_PULSE = '23'; //U3 PULSE
    const U4_PULSE = '24'; //U4 PULSE
    const U5_PULSE = '25'; //U5 PULSE
    const U6_PULSE = '26'; //U6 PULSE
    const U7_PULSE = '27'; //U7 PULSE
    const U8_PULSE = '28'; //U8 PULSE

    const OPEN_TESTING = '20'; //开分卡测试
    const READ_SCORE = '21'; //读取开分卡分数
    const READ_CREDIT2 = '22'; //讀取CREDIT2
    const READ_BET = '23'; //读取 BET
    const READ_WIN = '24'; //读取 WIN
    const READ_BB = '25'; //读取 BB
    const READ_RB = '26'; //读取 RB
    const OPEN_TABLE = '27'; //讀取 開分錶
    const WASH_TABLE = '28'; //請取 洗分錶
    const INSERT_COIN_TABLE = '29'; //讀取 投幣錶
    const OUT_COIN_TABLE = '2A'; //讀取 退幣錶
    const ALL_UP = '4C'; //全部上转
    //自动卡
    const OUT_ON = 'AA5708000001150D'; //启动自动
    const OUT_OFF = 'AA5708000002F70D'; //停止自动
    const PRESSURE = 'AA5708000003A90D'; //押分
    const START = 'AA57080000042A0D'; //启动
    const STOP_ONE = 'AA5708000005740D'; //停1
    const STOP_TWO = 'AA5708000006960D'; //停2
    const STOP_THREE = 'AA5708000007C80D'; //停3
    const TESTING = 'AA57080000004B0D'; //测试连接
    const GET_AUTO_STATUS = 'AA52082000000D0D'; //获取自动状态
    const AUTO_START = 'AA5208200081DF0D'; //开启自动
    const AUTO_STOP = 'AA5208200080810D'; //停止自动
    const AUTO = 'AA520820'; //自动状态

    const TYPE_OPEN_CARD = 1; //开分卡命令
    const TYPE_OUT_CARD = 2; //自动卡

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
            $this->cacheDataKey . '_point',
            $this->cacheDataKey . '_score',
            $this->cacheDataKey . '_bet',
            $this->cacheDataKey . '_last_play_time',
            $this->cacheDataKey . '_win',
            $this->cacheDataKey . '_bb',
            $this->cacheDataKey . '_rb',
            $this->cacheDataKey . '_open_point',
            $this->cacheDataKey . '_wash_point',
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
            $this->cacheDataKey . '_rb_status',
            $this->cacheDataKey . '_bb_status',
            $this->cacheDataKey . '_has_lock',
        ];
        $this->machineInfo = [
            'auto',
            'move_point',
            'reward_status',
            'bet',
            'win',
            'bb',
            'rb',
            'rb_status',
            'bb_status',
            'has_lock',
        ];
        $this->lang = $lang;
        $this->cacheData = $this->getMachineCache();
        $this->log = Log::channel('slot_machine');
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
                $criticalFields = ['gaming', 'gaming_user_id', 'last_play_time', 'point', 'bet', 'keeping'];
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
                    'point' => $machineCacheInfo[$this->cacheDataKey . '_point'],
                    'score' => $machineCacheInfo[$this->cacheDataKey . '_score'],
                    'bet' => $machineCacheInfo[$this->cacheDataKey . '_bet'],
                    'last_play_time' => $machineCacheInfo[$this->cacheDataKey . '_last_play_time'],
                    'win' => $machineCacheInfo[$this->cacheDataKey . '_win'],
                    'bb' => $machineCacheInfo[$this->cacheDataKey . '_bb'],
                    'rb' => $machineCacheInfo[$this->cacheDataKey . '_rb'],
                    'open_point' => $machineCacheInfo[$this->cacheDataKey . '_open_point'],
                    'wash_point' => $machineCacheInfo[$this->cacheDataKey . '_wash_point'],
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
                    'now_turn' => $machineCacheInfo[$this->cacheDataKey . '_now_turn'],
                    'rb_status' => $machineCacheInfo[$this->cacheDataKey . '_rb_status'],
                    'bb_status' => $machineCacheInfo[$this->cacheDataKey . '_bb_status'],
                    'has_lock' => $machineCacheInfo[$this->cacheDataKey . '_has_lock'],
                ];
                switch ($name) {
                    case 'gaming_user_id':
                        if (!empty($this->machine->gamingPlayer)) {
                            $this->sendMachineRealTimeInformation($this->machine->gamingPlayer->department_id,
                                'game_start',
                                $info);
                        }
                        break;
                    case 'auto':
                    case 'move_point':
                    case 'reward_status':
                    case 'bet':
                    case 'last_point_at':
                    case 'wash_point':
                    case 'keep_seconds':
                    case 'has_lock':
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
     * 斯洛自动卡
     * @param string $message 消息
     * @return bool
     */
    public function slotAutoCmd(string $message): bool
    {
        try {
            $msg = strtoupper(bin2hex($message));
            slotCheckCRC8($msg);
            $fun = substr($msg, 0, 8);
            if ($fun == Slot::AUTO && $this->machine->is_special == 0) {
                $status = decodeStatus(substr($msg, 10, 2));
                $auto = substr($status, 7, 1);
                $this->auto = $auto;
            }
            switch ($msg) {
                case Slot::OUT_ON:
                    if ($this->machine->is_special == 0) {
                        $this->sendCmd(self::GET_AUTO_STATUS, 0, $this->machine->gaming_user_id ? 'player' : '',
                            $this->machine->gaming_user_id, 1);
                    } else {
                        $this->auto = 1;
                    }
                    break;
                case Slot::OUT_OFF:
                    if ($this->machine->is_special == 0) {
                        $this->sendCmd(self::GET_AUTO_STATUS, 0, $this->machine->gaming_user_id ? 'player' : '',
                            $this->machine->gaming_user_id, 1);
                    } else {
                        $this->auto = 0;
                    }
                    break;
                case Slot::AUTO_START:
                case Slot::AUTO_STOP:
                case Slot::PRESSURE:
                case Slot::START:
                case Slot::STOP_ONE:
                case Slot::STOP_TWO:
                case Slot::STOP_THREE:
                case Slot::TESTING:
                    break;
                default:
                    return false;
            }
        } catch (\Exception $e) {
            $this->log->error('消息处理错误: ', [$msg, $e->getTraceAsString(), $e->getMessage()]);
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
        $autoUid = $this->machine->auto_card_domain . ':' . $this->machine->auto_card_port;
        try {
            if (!Gateway::isUidOnline($uid) || !Gateway::isUidOnline($autoUid)) {
                throw new Exception(trans('machine_has_offline', ['{code}' => $this->machine->code], 'message'));
            }
            switch ($cmd) {
                case self::REWARD_SWITCH:
                    Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . $cmd, $data, self::TYPE_OPEN_CARD,
                        self::REWARD_SWITCH_OPT)));
                    break;
                case self::OUTPUT . self::ALL_OFF:
                case self::OUTPUT . self::U1_ON:
                case self::OUTPUT . self::U2_ON:
                case self::OUTPUT . self::U3_ON:
                case self::OUTPUT . self::U4_ON:
                case self::OUTPUT . self::U5_ON:
                case self::OUTPUT . self::U6_ON:
                case self::OUTPUT . self::U7_ON:
                case self::OUTPUT . self::U8_ON:
                case self::OUTPUT . self::U1_PULSE:
                case self::OUTPUT . self::U2_PULSE:
                case self::OUTPUT . self::U3_PULSE:
                case self::OUTPUT . self::U4_PULSE:
                case self::OUTPUT . self::U5_PULSE:
                case self::OUTPUT . self::U6_PULSE:
                case self::OUTPUT . self::U7_PULSE:
                case self::OUTPUT . self::U8_PULSE:
                    Gateway::sendToUid($uid,
                        hex2bin($this->createCmd(self::PREFIX . self::OUTPUT, $data, self::TYPE_OPEN_CARD,
                            self::U1_PULSE)));
                    break;
                case self::OUT_ON:
                case self::OUT_OFF:
                case self::PRESSURE:
                case self::START:
                case self::STOP_ONE:
                case self::STOP_TWO:
                case self::STOP_THREE:
                case self::TESTING:
                case self::GET_AUTO_STATUS:
                    Gateway::sendToUid($autoUid, hex2bin($this->createCmd($cmd, $data, self::TYPE_OUT_CARD)));
                    break;
                case self::OPEN_ONE:
                case self::OPEN_TEN:
                case self::OPEN_ANY_POINT:
                    $this->openPoint($uid, $cmd, $data, $source, $source_id);
                    break;
                case self::WASH_ZERO:
                    $this->washPoint($uid, $source, $source_id);
                    break;
                case self::WASH_POINT:
                    $this->washSurplusPoint($uid, $source, $source_id);
                    break;
                case Slot::READ_CREDIT2:
                case Slot::READ_BET:
                case Slot::READ_WIN:
                case Slot::READ_BB:
                case Slot::READ_RB:
                case Slot::ALL_DOWN:
                    $this->machineAction($uid, $cmd, $source, $source_id);
                    break;
                default:
                    Gateway::sendToUid($uid,
                        hex2bin($this->createCmd(self::PREFIX . $cmd, $data, self::TYPE_OPEN_CARD)));
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
                self::OPEN_ONE,
                self::OPEN_TEN,
                self::WASH_ZERO
            ])) {
                $this->has_lock = 1;
                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->machine->gaming_user_id);
            }
            $this->log->error('发送指令异常: ', [
                $cmd,
                $source,
                $source_id,
                $this->machine->code
            ]);
            throw new Exception($e->getMessage());
        }
        saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($this->getAllData()), $cmd, 1,
            $isSystem, $data);

        return true;
    }

    /**
     * 创建指令
     * @param string $cmd 操作指令
     * @param mixed $data 数据
     * @param int $type 类型
     * @param string $option 可选值
     * @return string
     * @throws Exception
     */
    private function createCmd(string $cmd, $data, int $type, string $option = '00'): string
    {
        switch ($type) {
            case self::TYPE_OPEN_CARD:
                $decodeData = encodeData($data);
                $cmd .= "{$option}00" . $decodeData;
                $cmd .= encodeDataXor55($data) . '0000'; // 异或位处理
                $cmd .= crc8(hex2bin($cmd), 0x31, 0x00, 0x00);
                break;
            case self::TYPE_OUT_CARD:
                break;
            default:
                throw new Exception('命令错误');
        }
        return $cmd;
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
            Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . $cmd, $data, self::TYPE_OPEN_CARD)));
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
                    $this->log->error('指令超时异常', ['slot -> openPoint', [$this->machine->code, $cmd]]);
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
        $rbStatus = $this->rb_status == 1 ? trans('machine_status_yes', [],
            'machine_action') : trans('machine_status_no', [], 'machine_action');
        $hasLock = $this->has_lock == 1 ? trans('machine_status_yes', [],
            'machine_action') : trans('machine_status_no', [], 'machine_action');
        if (empty($fun)) {
            $nowTurn = $this->now_turn;
            $description .= trans('machine_auto_status', [], 'machine_action') . $autoStatus . PHP_EOL;
            $description .= trans('machine_bb_status', [], 'machine_action') . $bbStatus . PHP_EOL;
            $description .= trans('machine_rb_status', [], 'machine_action') . $rbStatus . PHP_EOL;
            $description .= trans('machine_has_lock', [], 'machine_action') . $hasLock . PHP_EOL;
            $description .= trans('machine_move_point_status', [],
                    'machine_action') . ($this->move_point == 1 ? trans('machine_status_yes', [],
                    'machine_action') : trans('machine_status_no', [], 'machine_action')) . PHP_EOL;
            $description .= trans('machine_lottery_status', [], 'machine_action') . $lotteryStatus . PHP_EOL;
            $description .= trans('machine_point', [], 'machine_action') . ($this->point ?? 0) . PHP_EOL;
            $description .= trans('machine_score', [], 'machine_action') . ($this->score ?? 0) . PHP_EOL;
            $description .= trans('machine_bet', [], 'machine_action') . ($this->bet ?? 0) . PHP_EOL;
            $description .= trans('machine_win', [], 'machine_action') . ($this->win ?? 0) . PHP_EOL;
            $description .= trans('machine_bb', [], 'machine_action') . ($this->bb ?? 0) . PHP_EOL;
            $description .= trans('machine_rb', [], 'machine_action') . ($this->rb ?? 0) . PHP_EOL;
            $description .= trans('now_turn', [], 'machine_action') . ($nowTurn > 0 ? ceil($nowTurn / 3) : 0) . PHP_EOL;
            $description .= trans('machine_open_point', [], 'machine_action') . ($this->open_point ?? 0) . PHP_EOL;
            $description .= trans('machine_wash_point', [], 'machine_action') . ($this->wash_point ?? 0);
        } else {
            $description .= trans('function.' . GameType::TYPE_SLOT . '_' . Machine::CONTROL_TYPE_MEI . '.' . $fun, [],
                'machine_action');
            switch ($fun) {
                case Slot::READ_SCORE:
                    $description .= ': ' . $this->point;
                    break;
                case Slot::READ_CREDIT2:
                    $description .= ': ' . $this->score;
                    break;
                case Slot::READ_BET:
                    $description .= ': ' . $this->bet;
                    break;
                case Slot::READ_WIN:
                    $description .= ': ' . $this->win;
                    break;
                case Slot::READ_RB:
                    $description .= ': ' . $this->rb;
                    break;
                case Slot::OPEN_TABLE:
                    $description .= ': ' . $this->open_point;
                    break;
                case Slot::WASH_TABLE:
                    $description .= ': ' . $this->wash_point;
                    break;
            }
        }
        return $description;
    }

    /**
     * 洗分
     * @param string $uid
     * @param string $source
     * @param int $source_id
     * @param int $attempts
     * @return void
     * @throws Exception
     * @throws PushException
     */
    private function washPoint(string $uid, string $source = 'player', int $source_id = 0, int $attempts = 0): void
    {
        $maxRetries = 8;
        $expirationTime = 1000000;
        try {
            $beforePoint = $this->point;
            if ($beforePoint == 0) {
                return;
            }
            Gateway::sendToUid($uid,
                hex2bin($this->createCmd(self::PREFIX . self::WASH_ZERO, 0, self::TYPE_OPEN_CARD)));
            $beforeActionTime = $this->action_time;
            $handleDuration = 0;
            $sleep = 50000; // 5毫秒取一次值
            while (true) {
                $point = $this->point;
                $actionTime = $this->action_time;
                if ($actionTime > $beforeActionTime && $point == 0) {
                    if ($source == 'admin') {
                        sendSocketMessage('private-admin-1-' . $source_id, [
                            'msg_type' => 'machine_action_result',
                            'id' => $this->machine->id,
                            'description' => $this->getDescription(),
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
                $this->log->error('指令超时异常', ['slot -> washPoint', [$this->machine->code]]);
                throw new Exception(trans('machine_action_fail', [], 'message'));
            }
            usleep(50000);
            $this->washPoint($uid, $source, $source_id, $attempts);
        }
    }

    /**
     * 洗分(留分)
     * @param string $uid
     * @param string $source
     * @param int $source_id
     * @return void
     * @throws Exception
     * @throws PushException
     */
    private function washSurplusPoint(string $uid, string $source = 'player', int $source_id = 0): void
    {
        try {
            if ($this->reward_status == 1) {
                throw new Exception(trans('machine_reward_drawing', ['{code}' => $this->machine->code], 'message'));
            }
            $beforePoint = $this->point;
            if ($beforePoint == 0) {
                return;
            }
            Gateway::sendToUid($uid,
                hex2bin($this->createCmd(self::PREFIX . self::WASH_POINT, 0, self::TYPE_OPEN_CARD)));
            Gateway::sendToUid($uid,
                hex2bin($this->createCmd(self::PREFIX . self::READ_SCORE, 0, self::TYPE_OPEN_CARD)));
            $beforeActionTime = $this->action_time;
            $handleDuration = 0;
            $sleep = 50000; // 5毫秒取一次值
            while (true) {
                $point = $this->point;
                $actionTime = $this->action_time;
                if ($actionTime > $beforeActionTime && $point < $beforePoint) {
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
            if ($cmd == Slot::ALL_DOWN) {
                Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . $cmd, 0, self::TYPE_OPEN_CARD, '7F')));
            } else {
                Gateway::sendToUid($uid, hex2bin($this->createCmd(self::PREFIX . $cmd, 0, self::TYPE_OPEN_CARD)));
            }
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
            checkCRC8($msg);
            $fun = substr($msg, 2, 2);
            $data = decodeData($msg); // 解码数据位
            checkSlotXor55($msg, $data);
            $orgBbStatus = $this->bb_status;
            $orgRbStatus = $this->rb_status;
            $status1 = decodeStatus(substr($msg, 4, 2));
            $status2 = decodeStatus(substr($msg, 6, 2));
            $this->rb_status = substr($status2, 4, 1);
            $this->bb_status = substr($status2, 5, 1);
            $this->action_time = getMillisecond();
            $nowTurn = $this->now_turn;
            $this->log->info('接收指令', [
                'slot',
                [
                    '2' => decodeStatus(substr($msg, 6, 2)),
                    'msg' => $msg,
                    'auto' => $this->auto,
                    'now_turn' => $nowTurn > 0 ? intval(ceil($nowTurn / 3)) : 0,
                    'reward_status' => $this->reward_status,
                    'bb_status' => $this->bb_status,
                    'rb_status' => $this->rb_status,
                    'bet' => $this->bet,
                    'code' => $this->machine->code,
                ]
            ]);
            // 开奖状态和rush确变状态只要一个为1进入开奖状态
            if ($this->bb_status == 1 || $this->rb_status == 1) {
                $this->reward_status = 1;
            }
            // 开奖状态和rush确变状态都为0并且当前状态为开奖中
            if ($this->bb_status == 0 && $this->rb_status == 0) {
                $rewardStatus = $this->reward_status;
                $this->reward_status = 0;
                if ($rewardStatus == 1) {
                    // 开奖结束后需剔除其他观看中玩家
                    sendSocketMessage('group-' . $this->machine->id, [
                        'msg_type' => 'machine_reward_end',
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'gaming_user_id' => $this->machine->gaming_user_id,
                    ]);
                }
            }
            if ($orgBbStatus == 0 && $orgRbStatus == 0 && $this->bb_status == 1 && $this->now_turn > 0) {
                $machineLotteryRecord = new MachineLotteryRecord();
                $machineLotteryRecord->machine_id = $this->machine->id;
                $machineLotteryRecord->player_id = $this->machine->gaming_user_id ?? 0;
                $machineLotteryRecord->department_id = $this->machine->gamingPlayer->department_id ?? 0;
                $machineLotteryRecord->draw_bet = $this->bet;
                $machineLotteryRecord->use_turn = $this->now_turn;
                $machineLotteryRecord->save();
                $this->now_turn = 0;
            }
            if ($orgRbStatus == 1 && $this->bb_status == 0 && $this->rb_status == 0 && $this->now_turn > 0) {
                $this->now_turn = 0;
            }
            $this->move_point = substr($status1, 6, 1);
            $gamingUserId = $this->machine->gaming_user_id;

            switch ($fun) {
                case Slot::MACHINE_BUSY:
                    throw new \Exception('slot机器' . $this->machine->code . '机器忙碌中');
                case Slot::OPEN_ONE:
                case Slot::OPEN_TEN:
                case Slot::WASH_ZERO:
                case Slot::WASH_POINT:
                case Slot::OPEN_FIVE:
                case Slot::MOVE_POINT_ON:
                case Slot::MOVE_POINT_OFF:
                    break;
                case Slot::OPEN_ANY_POINT:
                    Redis::publish($domain . ':' . $port, '设备返回的消息');
                    break;
                case Slot::READ_SCORE:
                    if ($data > 0 && $this->point != $data && !empty($gamingUserId)) {
                        Client::send('play-activity', [
                            'machine_id' => $this->machine->id,
                            'player_id' => $gamingUserId,
                            'point' => $data,
                        ]);
                    }
                    if ($data == 0 && $this->gaming == 1) {
                        $this->gift_condition = 0;
                        Cache::delete('gift_cache_' . $this->machine->id . '_' . $this->machine->gaming_user_id);
                    }
                    $this->point = $data;
                    if ($data >= $this->gift_condition) {
                        $this->gift_condition = 0;
                        Cache::delete('gift_cache_' . $this->machine->id . '_' . $this->machine->gaming_user_id);
                    }
                    $this->setActionVersion($fun);
                    break;
                case Slot::READ_CREDIT2:
                    $this->score = $data;
                    $this->setActionVersion($fun);
                    break;
                case Slot::READ_BET:
                    if ($this->bet > 0 && $this->bet > $data && $this->change_point_card_status == 0) {
                        sendMachineException($this->machine, Notice::TYPE_MACHINE_BET);
                        $this->bet = $data;
                        if ($this->gaming == 1) {
                            $this->player_pressure = $this->bet;
                        }
                        return true;
                    }
                    if ($this->bet > 0 && $this->bet != $data && !empty($gamingUserId) && $this->change_point_card_status == 0) {
                        $this->last_play_time = time();
                        if ($this->reward_status == 0) {
                            Client::send('lottery-machine', [
                                'num' => $data,
                                'last_num' => $this->bet,
                                'machine_id' => $this->machine->id,
                                'player_id' => $gamingUserId,
                            ]);
                        }
                        Client::send('play-keep-machine', [
                            'change_amount' => abs($data - $this->bet),
                            'machine_id' => $this->machine->id,
                            'player_id' => $gamingUserId,
                        ]);
                    }
                    if ($this->reward_status == 0) {
                        $nowTurn = $this->now_turn;
                        $bet = $this->bet;
                        $this->now_turn = bcadd($nowTurn, bcsub($data, $bet, 2), 2);
                    }
                    $this->bet = $data;
                    $this->change_point_card_status = 0;
                    $this->setActionVersion($fun);
                    break;
                case Slot::READ_WIN:
                    $this->win = $data;
                    $this->setActionVersion($fun);
                    break;
                case Slot::READ_BB:
                    $this->bb = $data;
                    $this->setActionVersion($fun);
                    break;
                case Slot::ALL_DOWN:
                    $this->setActionVersion($fun);
                    break;
                case Slot::READ_RB:
                    $this->rb = $data;
                    $this->setActionVersion($fun);
                    break;
                case Slot::OPEN_TABLE:
                    $this->open_point = $data;
                    break;
                case Slot::WASH_TABLE:
                    $this->wash_point = $data;
                    break;
                case Slot::OPEN_TESTING:
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
                    'machine_code' => $this->machine->code
                ]
            ]);
            return false;
        }

        return true;
    }
}
