<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\Machine;
use Exception;
use support\Cache;
use support\Log;
use Webman\Push\PushException;

/**
 * 精灵球机台服务
 *
 * 协议格式：
 * 命令开头(0xFA 0xEA) | 命令类别(1B) | 具体命令(1B) | 数据长度(1B) | 数据域(NB) | 异或(1B) | 和(1B) | 结尾(0xFB 0xEB)
 *
 * @property int $point 当前分数
 * @property int $score 当前得分
 * @property int $bet 本次压分
 * @property int $win 游戏结果
 * @property int $open_point 总上分
 * @property int $wash_point 总洗分
 * @property int $insert_money 总投入
 * @property int $gaming 游戏状态
 * @property int $gaming_user_id 游戏中玩家
 * @property int $keep_seconds 保留时长
 * @property int $keeping 保留状态
 * @property int $keeping_user_id 保留玩家
 * @property int $last_keep_at 最后保留时间
 * @property int $has_lock 机台锁
 * @property string $light_holes 亮灯洞口
 * @property string $fall_holes 落入洞口
 * @property int $jp_level 当前JP等级(1-5)
 * @property int $jackpot_score 彩金分数
 * @property int $ball_count 球数设置
 * @property int $light_count 灯数设置
 * @property int $game_type 游戏类型(1=A, 2=B)
 * @property string $multiplier 关卡倍数
 * @property string $uid 设备UID
 * @property string $version 主板版本号
 * @property int $door_status 开关门状态(1=开, 0=关)
 * @property int $game_enabled 游戏使能状态
 * @property int $last_point_at 最后上分时间
 * @property int $action_time 操作时间
 *
 * @package app\service\machine
 */
class PokemonBall extends MachineServices implements BaseMachine
{
    // ==================== 帧格式常量 ====================
    const FRAME_START_1 = 0xFA;
    const FRAME_START_2 = 0xEA;
    const FRAME_END_1 = 0xFB;
    const FRAME_END_2 = 0xEB;

    // ==================== 命令类别 ====================
    const CMD_CATEGORY_SERVER = '01';   // 服务器/安卓 → 主板
    const CMD_CATEGORY_BOARD = '02';    // 主板 → 服务器/安卓
    const CMD_CATEGORY_RESPONSE = 'F1'; // 主板回复（服务器/安卓命令）
    const CMD_CATEGORY_ACK = 'F2';      // 安卓回复（主板上传命令）

    // ==================== 连接管理指令 ====================
    const CMD_CONNECT = '0101';         // 发起连接
    const CMD_HEARTBEAT = '0102';       // 联机心跳
    const CMD_GAME_ENABLE = '0103';     // 游戏使能
    const CMD_VERSION = '0104';         // 获取版本号

    // ==================== 主板上传指令（主板 → 服务器）====================
    const CMD_INSERT_MONEY = '0201';    // 投入金额
    const CMD_SCORE_UP = '0202';        // 上分金额
    const CMD_WASH_SCORE = '0203';      // 洗分金额
    const CMD_GAME_START = '0105';      // 游戏开始
    const CMD_BET_AMOUNT = '0106';      // 本次压分
    const CMD_LIGHT_HOLES = '0107';     // 亮灯洞口
    const CMD_FALL_HOLES = '0108';      // 落入洞口
    const CMD_GAME_RESULT = '0109';     // 游戏结果
    const CMD_BUTTON_SIGNAL = '010B';   // 按钮信号
    const CMD_ASK_TIME = '010D';        // 询问时间
    const CMD_DOOR_SWITCH = '010E';     // 开门微动
    const CMD_JP_ENTER = '0113';        // 主板上传进入JP1-JP5

    // ==================== 服务器下发指令（服务器 → 主板）====================
    const CMD_GAME_END = '010C';        // 本次游戏结束
    const CMD_CONFIRM_CLOSE = '010F';   // 确认关门
    const CMD_SCORE_UP_AMOUNT = '0110'; // 上分数量
    const CMD_SCORE_DOWN = '0111';      // 下分
    const CMD_ENTER_JP1 = '0112';       // 进入JP1
    const CMD_JACKPOT_SCORE = '0114';   // 彩金分数（高字节在前）
    const CMD_ADD_SUB_START = '0115';   // 加分/减分/启动
    const CMD_QUERY_UID = '0116';       // 查询UID
    const CMD_SET_BALL_LIGHT = '0121';  // 设置球数/游戏类型/灯数
    const CMD_SET_MULTIPLIER = '0122';  // 设置关卡倍数
    const CMD_ASK_ACCOUNT = '0123';     // 查询帐目

    // ==================== 操作指令常量（用于UI按钮）====================
    const ALL = 'all';                      // 机台状态
    const WASH_ZERO = 'wash_zero';          // 洗分&清零
    const OPEN_ANY_POINT = 'open_any';      // 开任意分
    const SCORE_UP = 'score_up';            // 上分
    const SCORE_DOWN = 'score_down';        // 下分
    const GAME_ENABLE_ON = 'game_on';       // 允许游戏
    const GAME_ENABLE_OFF = 'game_off';     // 禁止游戏
    const GAME_END = 'game_end';            // 游戏结束
    const ENTER_JP1 = 'enter_jp1';          // 进入JP1
    const SET_JACKPOT = 'set_jackpot';      // 设置彩金分数
    const QUERY_UID = 'query_uid';          // 查询UID
    const QUERY_ACCOUNT = 'query_account';  // 查询帐目
    const SET_MULTIPLIER = 'set_multiplier'; // 设置关卡倍数
    const ADD_SCORE = 'add_score';          // 加分
    const SUB_SCORE = 'sub_score';          // 减分
    const START_GAME = 'start_game';        // 启动
    const AUTO_START = 'auto_start';        // 自动启动
    const SET_BALL_COUNT = 'set_ball';      // 设置球数
    const SET_LIGHT_COUNT = 'set_light';    // 设置灯数

    // ==================== 加分/减分/启动子命令 ====================
    const ADD_SUB_ADD = 1;      // 加分
    const ADD_SUB_SUB = 2;      // 减分
    const ADD_SUB_START = 3;    // 启动
    const ADD_SUB_AUTO = 4;     // 自动启动

    // ==================== 游戏使能值 ====================
    const GAME_ENABLE_VALUE = 0x01;     // 可进行游戏
    const GAME_DISABLE_VALUE = 0x00;    // 不可进行游戏

    /**
     * @var array 缓存数据Key数组
     */
    public $cacheData = [];

    /**
     * @var int 过期时间
     */
    public $expirationTime = 5000000;

    /**
     * @var Log|null 日志实例
     */
    public $log = null;

    /**
     * @var bool 机台连接状态（用于无安卓模式区分连接/心跳）
     */
    protected bool $connected = false;

    /**
     * 构造函数
     *
     * @param Machine $machine 机台对象
     * @param string $lang 语言代码
     */
    public function __construct(Machine $machine, string $lang = 'zh_CN')
    {
        $this->machine = $machine;
        $this->cacheKey = self::CACHE_PREFIX . $this->machine->id;
        $this->cacheDataKey = self::MACHINE_DATA_PREFIX . $this->machine->id;
        $this->cacheDataKeyArr = [
            $this->cacheDataKey . '_point',
            $this->cacheDataKey . '_score',
            $this->cacheDataKey . '_bet',
            $this->cacheDataKey . '_win',
            $this->cacheDataKey . '_open_point',
            $this->cacheDataKey . '_wash_point',
            $this->cacheDataKey . '_insert_money',
            $this->cacheDataKey . '_gaming',
            $this->cacheDataKey . '_gaming_user_id',
            $this->cacheDataKey . '_keep_seconds',
            $this->cacheDataKey . '_keeping',
            $this->cacheDataKey . '_keeping_user_id',
            $this->cacheDataKey . '_last_keep_at',
            $this->cacheDataKey . '_has_lock',
            $this->cacheDataKey . '_light_holes',
            $this->cacheDataKey . '_fall_holes',
            $this->cacheDataKey . '_jp_level',
            $this->cacheDataKey . '_jackpot_score',
            $this->cacheDataKey . '_ball_count',
            $this->cacheDataKey . '_light_count',
            $this->cacheDataKey . '_game_type',
            $this->cacheDataKey . '_multiplier',
            $this->cacheDataKey . '_uid',
            $this->cacheDataKey . '_version',
            $this->cacheDataKey . '_door_status',
            $this->cacheDataKey . '_game_enabled',
            $this->cacheDataKey . '_last_point_at',
            $this->cacheDataKey . '_action_time',
        ];
        $this->machineInfo = [
            'point',
            'score',
            'bet',
            'win',
            'gaming',
            'has_lock',
            'jp_level',
            'jackpot_score',
            'game_enabled',
            'door_status',
        ];
        $this->lang = $lang;
        $this->cacheData = $this->getMachineCache();
        $this->log = Log::channel('pokemon_ball_machine');
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
                $value = Cache::get($key, 0);
                return $value;
            } catch (\Exception $e) {
                try {
                    $value = Cache::get($key, 0);
                    \support\Log::warning('Redis缓存读取失败后重试成功', [
                        'machine_id' => $this->machine->id,
                        'field' => $name,
                        'error' => $e->getMessage()
                    ]);
                    return $value;
                } catch (\Exception $e2) {
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
                $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                if (!$saveResult) {
                    $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                }
            } catch (\Exception $e) {
                try {
                    $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
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

            // 关键字段保存失败时记录日志
            if (!$saveResult) {
                $mustSuccessFields = ['has_lock', 'gaming', 'gaming_user_id'];
                if (in_array($name, $mustSuccessFields)) {
                    \support\Log::critical('关键字段Redis保存失败', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'value' => $value
                    ]);
                }
            }

            // 推送实时信息
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
                    'point' => $machineCacheInfo[$this->cacheDataKey . '_point'],
                    'score' => $machineCacheInfo[$this->cacheDataKey . '_score'],
                    'bet' => $machineCacheInfo[$this->cacheDataKey . '_bet'],
                    'win' => $machineCacheInfo[$this->cacheDataKey . '_win'],
                    'keep_seconds' => $machineCacheInfo[$this->cacheDataKey . '_keep_seconds'],
                    'keeping' => $machineCacheInfo[$this->cacheDataKey . '_keeping'],
                    'keeping_user_id' => $machineCacheInfo[$this->cacheDataKey . '_keeping_user_id'],
                    'last_keep_at' => $machineCacheInfo[$this->cacheDataKey . '_last_keep_at'],
                    'last_point_at' => $machineCacheInfo[$this->cacheDataKey . '_last_point_at'],
                    'action_time' => $machineCacheInfo[$this->cacheDataKey . '_action_time'],
                    'has_lock' => $machineCacheInfo[$this->cacheDataKey . '_has_lock'],
                    'jp_level' => $machineCacheInfo[$this->cacheDataKey . '_jp_level'],
                    'jackpot_score' => $machineCacheInfo[$this->cacheDataKey . '_jackpot_score'],
                    'game_enabled' => $machineCacheInfo[$this->cacheDataKey . '_game_enabled'],
                    'door_status' => $machineCacheInfo[$this->cacheDataKey . '_door_status'],
                ];

                switch ($name) {
                    case 'gaming_user_id':
                        if (!empty($this->machine->gamingPlayer)) {
                            $this->sendMachineRealTimeInformation($this->machine->gamingPlayer->department_id,
                                'game_start', $info);
                        }
                        break;
                    case 'point':
                    case 'score':
                    case 'bet':
                    case 'win':
                    case 'has_lock':
                    case 'jp_level':
                    case 'jackpot_score':
                    case 'game_enabled':
                    case 'last_point_at':
                    case 'keep_seconds':
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
    public function getAllData(): iterable
    {
        return Cache::getMultiple($this->cacheDataKeyArr, 0);
    }

    /**
     * 构建协议帧
     *
     * @param string $cmdCategory 命令类别（2字符hex）
     * @param string $cmd 具体命令（2字符hex）
     * @param string $dataHex 数据域（hex字符串，可为空）
     * @return string 完整帧的hex字符串
     */
    public static function buildFrame(string $cmdCategory, string $cmd, string $dataHex = ''): string
    {
        $dataLen = strlen($dataHex) / 2;
        $dataLenHex = sprintf('%02X', $dataLen);

        // 计算异或
        $xor = hexdec($cmdCategory);
        $xor ^= hexdec($cmd);
        $xor ^= hexdec($dataLenHex);

        if (!empty($dataHex)) {
            for ($i = 0; $i < strlen($dataHex); $i += 2) {
                $xor ^= hexdec(substr($dataHex, $i, 2));
            }
        }

        $xorHex = sprintf('%02X', $xor & 0xFF);

        // 计算和
        $sum = hexdec($cmdCategory);
        $sum += hexdec($cmd);
        $sum += hexdec($dataLenHex);

        if (!empty($dataHex)) {
            for ($i = 0; $i < strlen($dataHex); $i += 2) {
                $sum += hexdec(substr($dataHex, $i, 2));
            }
        }
        $sum += hexdec($xorHex);

        $sumHex = sprintf('%02X', $sum & 0xFF);

        // 组装完整帧
        $frame = sprintf('FAEA%s%s%s%s%s%sFBEB',
            $cmdCategory,
            $cmd,
            $dataLenHex,
            $dataHex,
            $xorHex,
            $sumHex
        );

        return strtoupper($frame);
    }

    /**
     * 处理精灵球机台消息
     *
     * @param string $msg 消息（hex字符串）
     * @return bool
     */
    public function pokemonBallCmd(string $msg): bool
    {
        try {
            // 验证帧格式
            if (strlen($msg) < 20) {
                $this->log->warning('[PokemonBall] 消息长度异常', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'msg' => $msg,
                    'msg_length' => strlen($msg),
                ]);
                return false;
            }

            // 验证帧头帧尾
            $start = substr($msg, 0, 4);
            $end = substr($msg, -4);
            if ($start !== 'FAEA' || $end !== 'FBEB') {
                $this->log->warning('[PokemonBall] 帧格式错误', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'msg' => $msg,
                    'start' => $start,
                    'end' => $end,
                ]);
                return false;
            }

            // 解析帧
            $cmdCategory = substr($msg, 4, 2);
            $cmd = substr($msg, 6, 2);
            $dataLen = hexdec(substr($msg, 8, 2));
            $dataHex = substr($msg, 10, $dataLen * 2);

            // 无安卓模式下(cmd_category=0x01)，主板上传数据前4字节是唯一码
            $uid = '';
            if ($cmdCategory === self::CMD_CATEGORY_SERVER && $dataLen >= 4) {
                $uid = substr($dataHex, 0, 8); // 4字节 = 8个hex字符
                $dataHex = substr($dataHex, 8);
                $dataLen = $dataLen - 4;
            }

            // 记录原始消息（心跳消息不记录）
            $isHeartbeat = ($cmd === '02');
            if (!$isHeartbeat) {
                $this->log->info('[PokemonBall] 接收消息', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'raw' => $msg,
                    'category' => $cmdCategory,
                    'cmd' => $cmd,
                    'data_len' => $dataLen,
                    'data_hex' => $dataHex,
                    'uid' => $uid,
                ]);
            }

            // 根据命令类别处理
            switch ($cmdCategory) {
                case self::CMD_CATEGORY_SERVER:  // 0x01: 无安卓模式下主板上传
                case self::CMD_CATEGORY_BOARD:   // 0x02: 有安卓模式下主板上传
                    return $this->handleBoardCommand($cmd, $dataHex);
                case self::CMD_CATEGORY_RESPONSE: // 0xF1: 主板回复（服务器/安卓命令）
                    return $this->handleResponseCommand($cmd, $dataHex);
                case self::CMD_CATEGORY_ACK:     // 0xF2: 安卓回复（主板上传命令）
                    return $this->handleAckCommand($cmd, $dataHex);
                default:
                    $this->log->warning('[PokemonBall] 未知命令类别', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'category' => $cmdCategory,
                    ]);
                    return false;
            }
        } catch (\Exception $e) {
            $this->log->error('[PokemonBall] 处理消息异常', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'msg' => $msg,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 处理主板上传指令
     *
     * @param string $cmd 指令代码
     * @param string $dataHex 数据
     * @return bool
     */
    protected function handleBoardCommand(string $cmd, string $dataHex): bool
    {
        $baseLog = [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
        ];

        switch ($cmd) {
            case substr(self::CMD_CONNECT, 2, 2): // 0x01: 发起连接 或 心跳（无安卓模式下相同命令码）
                // 无安卓模式下，连接和心跳都用 0x01 0x01，根据连接状态区分
                // 超过30秒未收到心跳，视为断线重连
                if ($this->connected && $this->action_time > 0 && (time() - $this->action_time) > 30) {
                    $this->connected = false;
                    $this->log->warning('[PokemonBall] 心跳超时，重置连接状态', array_merge($baseLog, [
                        'last_action' => $this->action_time,
                        'timeout' => time() - $this->action_time,
                    ]));
                    // 断线后发送禁止游戏指令
                    $this->sendGameEnable(0);
                }

                if (!$this->connected) {
                    // 首次收到或重连 → 连接命令，回复 0xF1 0x01 确认连接
                    $this->connected = true;
                    $this->action_time = time();
                    $this->log->info('[PokemonBall] 机台连接', array_merge($baseLog, [
                        'uid' => $dataHex,
                    ]));
                    $this->sendFrame(self::CMD_CATEGORY_RESPONSE, '01'); // 回复 F1 01

                    // 连接后默认发送允许游戏指令
                    $this->sendGameEnable(1);
                } else {
                    // 已连接 → 心跳（静默处理）
                    $this->action_time = time();
                    $this->sendFrame(self::CMD_CATEGORY_RESPONSE, '02'); // 回复 F1 02
                }
                return true;

            case substr(self::CMD_HEARTBEAT, 2, 2): // 0x02: 心跳（有安卓模式，静默处理）
                $this->action_time = time();
                $this->sendFrame(self::CMD_CATEGORY_RESPONSE, '02'); // 回复 F1 02
                return true;

            case substr(self::CMD_INSERT_MONEY, 2, 2): // 投入金额 (0x02 0x01)
                $amount = hexdec($dataHex);
                $this->insert_money = ($this->insert_money ?? 0) + $amount;
                $this->log->info('[PokemonBall] 投入金额', array_merge($baseLog, [
                    'amount' => $amount,
                    'total_insert' => $this->insert_money,
                ]));
                $this->sendFrame(self::CMD_CATEGORY_ACK, '01'); // 回复 F2 01
                return true;

            case substr(self::CMD_SCORE_UP, 2, 2): // 上分金额 (0x02 0x02)
                $amount = hexdec($dataHex);
                $this->point = ($this->point ?? 0) + $amount;
                $this->open_point = ($this->open_point ?? 0) + $amount;
                $this->last_point_at = time();
                $this->log->info('[PokemonBall] 上分', array_merge($baseLog, [
                    'amount' => $amount,
                    'point' => $this->point,
                    'open_point' => $this->open_point,
                ]));
                $this->sendFrame(self::CMD_CATEGORY_ACK, '02'); // 回复 F2 02
                return true;

            case substr(self::CMD_WASH_SCORE, 2, 2): // 洗分金额 (0x02 0x03)
                $amount = hexdec($dataHex);
                $this->point = max(0, ($this->point ?? 0) - $amount);
                $this->wash_point = ($this->wash_point ?? 0) + $amount;
                $this->log->info('[PokemonBall] 洗分', array_merge($baseLog, [
                    'amount' => $amount,
                    'point' => $this->point,
                    'wash_point' => $this->wash_point,
                ]));
                $this->sendFrame(self::CMD_CATEGORY_ACK, '03'); // 回复 F2 03
                return true;

            case substr(self::CMD_GAME_START, 2, 2): // 游戏开始
                $this->gaming = 1;
                $this->log->info('[PokemonBall] 游戏开始', $baseLog);
                return true;

            case substr(self::CMD_BET_AMOUNT, 2, 2): // 本次压分
                // 去掉前缀 AB154771，获取实际数据
                $actualData = $this->extractActualData($dataHex);
                $amount = hexdec($actualData);
                $this->bet = $amount;
                $this->log->info('[PokemonBall] 压分', array_merge($baseLog, [
                    'raw_data' => $dataHex,
                    'actual_data' => $actualData,
                    'amount' => $amount,
                ]));
                return true;

            case substr(self::CMD_LIGHT_HOLES, 2, 2): // 亮灯洞口
                // 去掉前缀，解析位掩码
                $actualData = $this->extractActualData($dataHex);
                $holes = $this->parseHoleBitmask($actualData);
                $this->light_holes = $actualData;
                $this->log->info('[PokemonBall] 亮灯洞口', array_merge($baseLog, [
                    'raw_data' => $dataHex,
                    'actual_data' => $actualData,
                    'light_holes' => $holes,
                    'light_holes_binary' => $this->hexToBinaryString($actualData),
                ]));
                return true;

            case substr(self::CMD_FALL_HOLES, 2, 2): // 落入洞口
                // 去掉前缀，解析落入洞口（与亮灯不同）
                $actualData = $this->extractActualData($dataHex);
                $fallHole = $this->parseFallHole($actualData);
                $this->fall_holes = $actualData;
                $this->log->info('[PokemonBall] 落入洞口', array_merge($baseLog, [
                    'raw_data' => $dataHex,
                    'actual_data' => $actualData,
                    'fall_hole' => $fallHole,
                    'fall_hole_text' => $fallHole > 0 ? $fallHole . '号洞' : '未知',
                    'fall_holes_binary' => $this->hexToBinaryString($actualData),
                ]));
                return true;

            case substr(self::CMD_GAME_RESULT, 2, 2): // 游戏结果 (0x01 0x09)
                // 去掉前缀，解析4字节结果
                $actualData = $this->extractActualData($dataHex);
                $resultParsed = $this->parseGameResult($actualData);

                // 记录游戏结果日志
                $this->log->info('[PokemonBall] 游戏结果', array_merge($baseLog, [
                    'is_winner' => $resultParsed['is_winner'],
                    'is_winner_text' => $resultParsed['is_winner'] ? '中奖' : '未中奖',
                    'level' => $resultParsed['level'],
                    'hole9_full' => $resultParsed['hole9_full'],
                    'hole9_full_text' => $resultParsed['hole9_full'] ? '已满' : '未满',
                    'light3_full' => $resultParsed['light3_full'],
                    'light3_full_text' => $resultParsed['light3_full'] ? '已满' : '未满',
                    'raw_data' => $dataHex,
                    'actual_data' => $actualData,
                ]));
                $this->sendFrame(self::CMD_CATEGORY_RESPONSE, '09'); // 回复 F1 09

                // 自动发送游戏结束指令给主板
                $this->log->info('[PokemonBall] 游戏结束', array_merge($baseLog, [
                    'game_over' => true,
                ]));
                $this->sendFrame(self::CMD_CATEGORY_SERVER, '0C'); // 发送 01 0C
                return true;

            case substr(self::CMD_BUTTON_SIGNAL, 2, 2): // 按钮信号
                $this->action_time = time();
                $this->log->info('[PokemonBall] 按钮信号', array_merge($baseLog, [
                    'action_time' => $this->action_time,
                ]));
                return true;

            case substr(self::CMD_ASK_TIME, 2, 2): // 询问时间 (0x01 0x0D)
                // 回复时间：年(2字节) 月 日 时 分
                $now = getdate();
                $timeHex = sprintf('%04X%02X%02X%02X%02X', $now['year'], $now['mon'], $now['mday'], $now['hours'], $now['minutes']);
                $this->log->info('[PokemonBall] 询问时间', array_merge($baseLog, [
                    'response_time' => $timeHex,
                ]));
                $this->sendFrame(self::CMD_CATEGORY_RESPONSE, '0D', $timeHex); // 回复 F1 0D
                return true;

            case substr(self::CMD_DOOR_SWITCH, 2, 2): // 开门微动
                $status = hexdec($dataHex);
                $this->door_status = $status;
                $this->log->info('[PokemonBall] 开关门状态', array_merge($baseLog, [
                    'status' => $status,
                    'status_text' => $status ? '开门' : '关门',
                ]));
                return true;

            case substr(self::CMD_JP_ENTER, 2, 2): // 进入JP (0x01 0x13)
                $level = hexdec($dataHex);
                $this->jp_level = $level;
                $this->log->info('[PokemonBall] 进入JP', array_merge($baseLog, [
                    'level' => $level,
                ]));
                $this->sendFrame(self::CMD_CATEGORY_RESPONSE, '13'); // 回复 F1 13
                return true;

            default:
                $this->log->warning('[PokemonBall] 未知主板指令', array_merge($baseLog, [
                    'cmd' => $cmd,
                    'data_hex' => $dataHex,
                ]));
                return false;
        }
    }

    /**
     * 提取实际数据（去掉 AB154771 前缀）
     *
     * @param string $dataHex 原始数据
     * @return string 实际数据
     */
    protected function extractActualData(string $dataHex): string
    {
        // AB154771 是固定前缀（8个字符 = 4字节）
        $prefix = 'AB154771';
        if (strlen($dataHex) > 8 && strtoupper(substr($dataHex, 0, 8)) === $prefix) {
            return substr($dataHex, 8);
        }
        return $dataHex;
    }

    /**
     * 解析洞口位掩码
     *
     * @param string $hex 数据（2字节 = 4个hex字符）
     * @return array 亮灯的洞口号数组（1-9）
     */
    protected function parseHoleBitmask(string $hex): array
    {
        $holes = [];
        if (strlen($hex) < 4) {
            return $holes;
        }

        // 2字节数据，大端序（高字节在前）
        // 合并为16位值
        $high = hexdec(substr($hex, 0, 2));
        $low = hexdec(substr($hex, 2, 2));
        $value = ($high << 8) | $low;

        // 九宫格布局:
        // 1 2 3
        // 4 5 6
        // 7 8 9

        // 亮灯位映射表（根据文档：0x01 0x23 → 9,6,2,1号洞）
        // 高字节 bit 0 → 9号洞
        // 低字节 bit 0-7 → 1-8号洞
        // 验证: 0x01=00000001→bit0→9号洞, 0x23=00100011→bit0,1,5→1,2,6号洞
        $bitMap = [
            15 => 9,  // 高字节 bit 0 → 9号洞
            0 => 1,   // 低字节 bit 0 → 1号洞
            1 => 2,   // 低字节 bit 1 → 2号洞
            2 => 3,   // 低字节 bit 2 → 3号洞
            3 => 4,   // 低字节 bit 3 → 4号洞
            4 => 5,   // 低字节 bit 4 → 5号洞
            5 => 6,   // 低字节 bit 5 → 6号洞
            6 => 7,   // 低字节 bit 6 → 7号洞
            7 => 8,   // 低字节 bit 7 → 8号洞
        ];

        foreach ($bitMap as $bit => $hole) {
            if (($value >> $bit) & 1) {
                $holes[] = $hole;
            }
        }

        sort($holes);
        return $holes;
    }

    /**
     * 解析落入洞口号
     *
     * @param string $hex 数据（2字节 = 4个hex字符）
     * @return int 洞口号（1-9），0表示未知
     */
    protected function parseFallHole(string $hex): int
    {
        if (strlen($hex) < 4) {
            return 0;
        }

        $high = hexdec(substr($hex, 0, 2));
        $low = hexdec(substr($hex, 2, 2));
        $value = ($high << 8) | $low;

        // 入洞位映射表（与亮灯不同！）
        // 已确认: 0004→5, 0080→2, 8000→8
        // 入洞使用非标准映射
        $fallBitMap = [
            2 => 5,   // bit 2 → 5号洞 (已确认)
            7 => 2,   // bit 7 → 2号洞 (已确认)
            15 => 8,  // bit 15 → 8号洞 (已确认)
            0 => 1,   // 待验证
            1 => 3,   // 待验证
            3 => 4,   // 待验证
            4 => 6,   // 待验证
            5 => 7,   // 待验证
            6 => 9,   // 待验证
        ];

        foreach ($fallBitMap as $bit => $hole) {
            if (($value >> $bit) & 1) {
                return $hole;
            }
        }

        return 0;
    }

    /**
     * 将hex字符串转换为二进制字符串（用于日志）
     *
     * @param string $hex hex字符串
     * @return string 二进制字符串
     */
    protected function hexToBinaryString(string $hex): string
    {
        $binary = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $byte = hexdec(substr($hex, $i, 2));
            $binary .= sprintf('%08b', $byte) . ' ';
        }
        return trim($binary);
    }

    /**
     * 解析游戏结果（4字节）
     *
     * @param string $hex 8个hex字符（4字节）
     * @return array 解析结果
     */
    protected function parseGameResult(string $hex): array
    {
        $result = [
            'is_winner' => false,
            'level' => 0,
            'hole9_full' => false,
            'light3_full' => false,
        ];

        if (strlen($hex) < 8) {
            return $result;
        }

        // 字节1：是否中奖
        $result['is_winner'] = (hexdec(substr($hex, 0, 2)) === 1);

        // 字节2：当前关卡
        $result['level'] = hexdec(substr($hex, 2, 2));

        // 字节3：9洞累积是否满了
        $result['hole9_full'] = (hexdec(substr($hex, 4, 2)) === 1);

        // 字节4：3关累积灯是否满了
        $result['light3_full'] = (hexdec(substr($hex, 6, 2)) === 1);

        return $result;
    }

    /**
     * 处理主板回复指令
     *
     * @param string $cmd 指令代码
     * @param string $dataHex 数据
     * @return bool
     */
    protected function handleResponseCommand(string $cmd, string $dataHex): bool
    {
        // 回复指令类别 F1 对应原始指令类别 01，转换后查找名称
        $originalCmd = self::CMD_CATEGORY_SERVER . $cmd;
        $cmdName = $this->getProtocolCmdName($originalCmd);

        // 解析数据含义
        $parsed = $this->parseResponseData($cmd, $dataHex);

        $this->log->info('[PokemonBall] 收到主板回复', array_merge([
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'cmd' => $cmd,
            'cmd_name' => $cmdName,
            'data_hex' => $dataHex,
            'data_len' => strlen($dataHex) / 2,
        ], $parsed));
        return true;
    }

    /**
     * 解析主板回复数据
     *
     * @param string $cmd 指令代码
     * @param string $dataHex 数据
     * @return array 解析结果
     */
    protected function parseResponseData(string $cmd, string $dataHex): array
    {
        $result = [];

        // 无安卓模式下，回复数据可能包含：
        // 1. 唯一码（前4字节 = 8个hex字符）
        // 2. AB154771 前缀（4字节 = 8个hex字符）
        $actualData = $dataHex;

        // 去除唯一码（如果存在）
        if (strlen($actualData) > 8) {
            $uid = substr($actualData, 0, 8);
            $actualData = substr($actualData, 8);
            $result['uid'] = $uid;
        }

        // 去除 AB154771 前缀（如果存在）
        $actualData = $this->extractActualData($actualData);

        switch ($cmd) {
            case '03': // 游戏使能回复
                $result['data_desc'] = '无数据';
                break;

            case '04': // 版本号回复
                if (strlen($actualData) >= 4) {
                    $major = hexdec(substr($actualData, 0, 2));
                    $minor = hexdec(substr($actualData, 2, 2));
                    $result['version'] = "$major.$minor";
                    $result['data_desc'] = "版本号: $major.$minor";
                }
                break;

            case '09': // 游戏结果回复
                $result['data_desc'] = '无数据';
                break;

            case '10': // 上分数量回复
                $result['data_desc'] = '无数据';
                break;

            case '11': // 下分回复
                if (strlen($actualData) >= 6) {
                    $amount = hexdec(substr($actualData, 0, 6));
                    $result['amount'] = $amount;
                    $result['data_desc'] = "下分金额: $amount";
                }
                break;

            case '12': // 进入JP1回复
                $result['data_desc'] = '无数据';
                break;

            case '14': // 彩金分数回复
                if (strlen($actualData) >= 6) {
                    $score = hexdec(substr($actualData, 0, 6));
                    $result['jackpot_score'] = $score;
                    $result['data_desc'] = "彩金分数: $score";
                }
                break;

            case '15': // 加分/减分/启动回复
                if (strlen($actualData) >= 2) {
                    $status = hexdec(substr($actualData, 0, 2));
                    $statusText = match ($status) {
                        1 => '加分成功',
                        2 => '减分成功',
                        3 => '启动成功',
                        4 => '自动启动成功',
                        default => "状态: $status",
                    };
                    $result['status'] = $status;
                    $result['status_text'] = $statusText;
                    $result['data_desc'] = $statusText;
                }
                break;

            case '16': // 查询UID回复
                if (strlen($actualData) >= 16) {
                    $uid = substr($actualData, 0, 16);
                    $ballCount = hexdec(substr($actualData, 0, 2));
                    $gameType = hexdec(substr($actualData, 2, 2));
                    $lightCount = hexdec(substr($actualData, 4, 2));
                    $result['uid'] = $uid;
                    $result['ball_count'] = $ballCount;
                    $result['game_type'] = $gameType;
                    $result['game_type_text'] = $gameType === 1 ? 'A型' : 'B型';
                    $result['light_count'] = $lightCount;
                    $result['data_desc'] = "UID: $uid, 球数: $ballCount, 类型: " . ($gameType === 1 ? 'A' : 'B') . ", 灯数: $lightCount";
                }
                break;

            case '21': // 设置球数/灯数回复
                $result['data_desc'] = '无数据';
                break;

            case '22': // 设置关卡倍数回复
                $result['data_desc'] = '无数据';
                break;

            case '23': // 查询帐目回复
                if (strlen($actualData) >= 12) {
                    $insertTotal = hexdec(substr($actualData, 0, 4));
                    $openTotal = hexdec(substr($actualData, 4, 4));
                    $washTotal = hexdec(substr($actualData, 8, 4));
                    $result['insert_total'] = $insertTotal;
                    $result['open_total'] = $openTotal;
                    $result['wash_total'] = $washTotal;
                    $result['data_desc'] = "投币: $insertTotal, 上分: $openTotal, 洗分: $washTotal";
                }
                break;

            default:
                $result['data_desc'] = '未知数据';
                break;
        }

        return $result;
    }

    /**
     * 处理安卓回复指令（0xF2）
     *
     * @param string $cmd 指令代码
     * @param string $dataHex 数据
     * @return bool
     */
    protected function handleAckCommand(string $cmd, string $dataHex): bool
    {
        // 安卓回复对应原始主板上传指令类别 02
        $originalCmd = self::CMD_CATEGORY_BOARD . $cmd;
        $cmdName = $this->getProtocolCmdName($originalCmd);

        $this->log->info('[PokemonBall] 收到安卓回复', [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'cmd' => $cmd,
            'cmd_name' => $cmdName,
            'data_hex' => $dataHex,
            'data_len' => strlen($dataHex) / 2,
        ]);
        return true;
    }

    /**
     * 获取协议指令名称
     *
     * @param string $fullCmd 完整指令代码（类别+命令，如 '0101'）
     * @return string
     */
    protected function getProtocolCmdName(string $fullCmd): string
    {
        return match ($fullCmd) {
            self::CMD_CONNECT => '发起连接',
            self::CMD_HEARTBEAT => '联机心跳',
            self::CMD_GAME_ENABLE => '游戏使能',
            self::CMD_VERSION => '获取版本号',
            self::CMD_INSERT_MONEY => '投入金额',
            self::CMD_SCORE_UP => '上分金额',
            self::CMD_WASH_SCORE => '洗分金额',
            self::CMD_GAME_START => '游戏开始',
            self::CMD_BET_AMOUNT => '本次压分',
            self::CMD_LIGHT_HOLES => '亮灯洞口',
            self::CMD_FALL_HOLES => '落入洞口',
            self::CMD_GAME_RESULT => '游戏结果',
            self::CMD_BUTTON_SIGNAL => '按钮信号',
            self::CMD_ASK_TIME => '询问时间',
            self::CMD_DOOR_SWITCH => '开门微动',
            self::CMD_JP_ENTER => '进入JP',
            self::CMD_GAME_END => '游戏结束',
            self::CMD_CONFIRM_CLOSE => '确认关门',
            self::CMD_SCORE_UP_AMOUNT => '上分数量',
            self::CMD_SCORE_DOWN => '下分',
            self::CMD_ENTER_JP1 => '进入JP1',
            self::CMD_JACKPOT_SCORE => '彩金分数',
            self::CMD_ADD_SUB_START => '加分/减分/启动',
            self::CMD_QUERY_UID => '查询UID',
            self::CMD_SET_BALL_LIGHT => '设置球数/灯数',
            self::CMD_SET_MULTIPLIER => '设置关卡倍数',
            self::CMD_ASK_ACCOUNT => '查询帐目',
            default => '未知指令(' . $fullCmd . ')',
        };
    }

    /**
     * 发送协议帧到机台
     *
     * @param string $cmdCategory 命令类别
     * @param string $cmd 具体命令
     * @param string $dataHex 数据
     * @return bool
     */
    /**
     * 发送游戏使能指令
     *
     * @param int $enabled 1=允许游戏, 0=禁止游戏
     * @return bool
     */
    public function sendGameEnable(int $enabled): bool
    {
        $dataHex = sprintf('%02X', $enabled);
        $this->log->info('[PokemonBall] 发送游戏使能', [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'enabled' => $enabled,
            'enabled_text' => $enabled ? '允许游戏' : '禁止游戏',
        ]);
        return $this->sendFrame(self::CMD_CATEGORY_SERVER, '03', $dataHex);
    }

    protected function sendFrame(string $cmdCategory, string $cmd, string $dataHex = ''): bool
    {
        $frame = self::buildFrame($cmdCategory, $cmd, $dataHex);

        // 获取机台连接UID
        $uid = $this->machine->domain . ':' . $this->machine->port;

        // 判断是否为心跳响应（F1 02），心跳响应不记录日志
        $isHeartbeatResponse = ($cmdCategory === self::CMD_CATEGORY_RESPONSE && $cmd === '02');

        if (!$isHeartbeatResponse) {
            $this->log->info('[PokemonBall] 发送帧', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'uid' => $uid,
                'category' => $cmdCategory,
                'cmd' => $cmd,
                'data_hex' => $dataHex,
                'frame' => $frame,
            ]);
        }

        try {
            // 检查机台是否在线
            if (!\GatewayWorker\Lib\Gateway::isUidOnline($uid)) {
                $this->log->warning('[PokemonBall] 机台离线', [
                    'machine_id' => $this->machine->id,
                    'uid' => $uid,
                ]);
                return false;
            }

            // 发送帧到机台
            \GatewayWorker\Lib\Gateway::sendToUid($uid, hex2bin($frame));

            if (!$isHeartbeatResponse) {
            }

            return true;
        } catch (Exception $e) {
            $this->log->error('[PokemonBall] 发送失败', [
                'machine_id' => $this->machine->id,
                'frame' => $frame,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 发送操作指令
     *
     * @param string $cmd 指令代码（操作常量）
     * @param int $data 数据
     * @param string $source 操作来源 (player/admin)
     * @param int $source_id 来源ID
     * @return bool
     * @throws Exception
     */
    public function sendCmd(
        string $cmd,
        int $data = 0,
        string $source = 'admin',
        int $source_id = 0
    ): bool {
        $frame = $this->buildCommandFrame($cmd, $data);

        if ($frame === null) {
            $this->log->warning('未知的精灵球操作指令', [
                'machine_id' => $this->machine->id,
                'cmd' => $cmd,
                'data' => $data,
            ]);
            return false;
        }

        try {
            // 检查机台是否在线
            $uid = $this->machine->domain . ':' . $this->machine->port;
            if (!\GatewayWorker\Lib\Gateway::isUidOnline($uid)) {
                throw new Exception(trans('machine_has_offline', ['{code}' => $this->machine->code], 'message'));
            }

            // 发送指令到机台
            \GatewayWorker\Lib\Gateway::sendToUid($uid, hex2bin($frame));

            $operatorType = $source === 'admin' ? '【管理员操作】' : '【玩家操作】';
            $this->log->info($operatorType . '精灵球指令', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'frame' => $frame,
                'data' => $data,
                'source' => $source,
                'source_id' => $source_id,
            ]);

            // 如果是管理员操作，发送结果通知
            if ($source === 'admin') {
                sendSocketMessage('private-admin-1-' . $source_id, [
                    'msg_type' => 'machine_action_result',
                    'id' => $this->machine->id,
                    'description' => $this->getDescription($cmd),
                ]);
            }

            return true;
        } catch (Exception $e) {
            $this->log->error('精灵球指令发送失败', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'frame' => $frame ?? '',
                'data' => $data,
                'source' => $source,
                'source_id' => $source_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 获取指令描述
     *
     * @param string $fun 指令代码
     * @return string
     */
    public function getDescription(string $fun = ''): string
    {
        return match ($fun) {
            self::ALL => '查询状态',
            self::WASH_ZERO => '洗分清零',
            self::OPEN_ANY_POINT => '开分',
            self::SCORE_UP => '上分',
            self::SCORE_DOWN => '下分',
            self::GAME_ENABLE_ON => '允许游戏',
            self::GAME_ENABLE_OFF => '禁止游戏',
            self::GAME_END => '游戏结束',
            self::ENTER_JP1 => '进入JP',
            self::SET_JACKPOT => '设置彩金',
            self::QUERY_UID => '查询UID',
            self::QUERY_ACCOUNT => '查询帐目',
            self::SET_MULTIPLIER => '设置倍数',
            self::ADD_SCORE => '加分',
            self::SUB_SCORE => '减分',
            self::START_GAME => '启动',
            self::AUTO_START => '自动启动',
            self::SET_BALL_COUNT => '设置球数',
            self::SET_LIGHT_COUNT => '设置灯数',
            default => '未知操作',
        };
    }

    /**
     * 将操作常量转换为协议帧
     *
     * @param string $cmd 操作常量
     * @param int $data 数据
     * @return string|null 协议帧hex字符串
     */
    protected function buildCommandFrame(string $cmd, int $data = 0): ?string
    {
        $frame = match ($cmd) {
            // 查询帐目 / 机台状态 → 命令 0x23
            self::ALL, self::QUERY_ACCOUNT
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '23'),

            // 洗分清零 / 下分 → 命令 0x11
            self::WASH_ZERO, self::SCORE_DOWN
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '11'),

            // 开任意分 / 上分 → 命令 0x10 + 分数(3字节)
            self::OPEN_ANY_POINT, self::SCORE_UP
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '10', sprintf('%06X', $data)),

            // 游戏使能开 → 命令 0x03 + 0x01
            self::GAME_ENABLE_ON
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '03', '01'),

            // 游戏使能关 → 命令 0x03 + 0x00
            self::GAME_ENABLE_OFF
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '03', '00'),

            // 游戏结束 → 命令 0x0C
            self::GAME_END
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '0C'),

            // 进入JP1 → 命令 0x12
            self::ENTER_JP1
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '12'),

            // 设置彩金分数 → 命令 0x14 + 分数(3字节)
            self::SET_JACKPOT
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '14', sprintf('%06X', $data)),

            // 查询UID → 命令 0x16
            self::QUERY_UID
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '16'),

            // 设置关卡倍数 → 命令 0x22 + 倍数(10字节)
            self::SET_MULTIPLIER
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '22', sprintf('%020X', $data)),

            // 加分/减分/启动/自动启动 → 命令 0x15 + 子命令(1字节)
            self::ADD_SCORE
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '15', sprintf('%02X', self::ADD_SUB_ADD)),
            self::SUB_SCORE
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '15', sprintf('%02X', self::ADD_SUB_SUB)),
            self::START_GAME
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '15', sprintf('%02X', self::ADD_SUB_START)),
            self::AUTO_START
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '15', sprintf('%02X', self::ADD_SUB_AUTO)),

            // 设置球数/灯数 → 命令 0x21 + 数量(1字节)
            self::SET_BALL_COUNT, self::SET_LIGHT_COUNT
                => self::buildFrame(self::CMD_CATEGORY_SERVER, '21', sprintf('%02X', $data)),

            default => null,
        };

        // 记录指令构建日志
        if ($frame !== null) {
            $this->log->info('[PokemonBall] 构建指令帧', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'cmd_name' => $this->getDescription($cmd),
                'data' => $data,
                'frame' => $frame,
            ]);
        } else {
            $this->log->warning('[PokemonBall] 未知指令', [
                'machine_id' => $this->machine->id,
                'cmd' => $cmd,
                'data' => $data,
            ]);
        }

        return $frame;
    }

    /**
     * 获取精灵球操作列表
     * @return array
     */
    public static function getPokemonBallAction(): array
    {
        return [
            self::ALL,
            self::WASH_ZERO,
            self::OPEN_ANY_POINT,
            self::SCORE_UP,
            self::SCORE_DOWN,
            self::GAME_ENABLE_ON,
            self::GAME_ENABLE_OFF,
            self::GAME_END,
            self::ENTER_JP1,
            self::SET_JACKPOT,
            self::QUERY_UID,
            self::QUERY_ACCOUNT,
            self::SET_MULTIPLIER,
            self::ADD_SCORE,
            self::SUB_SCORE,
            self::START_GAME,
            self::AUTO_START,
            self::SET_BALL_COUNT,
            self::SET_LIGHT_COUNT,
        ];
    }
}
