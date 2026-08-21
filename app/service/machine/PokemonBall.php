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

            $this->log->warning('[PokemonBall-pokemonBallCmd] 消息接受测试', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'msg' => $msg,
                'msg_length' => strlen($msg),
            ]);

            // 验证帧格式
            if (strlen($msg) < 20) {
                $this->log->warning('[PokemonBall-pokemonBallCmd] 消息长度异常', [
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
                $this->log->warning('[PokemonBall-pokemonBallCmd] 帧格式错误', [
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

            $this->log->info('[PokemonBall-pokemonBallCmd] 收到消息', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'cmd_category' => $cmdCategory,
                'cmd' => $cmd,
                'data_len' => $dataLen,
                'data_hex' => $dataHex,
            ]);

            // 根据命令类别处理
            switch ($cmdCategory) {
                case self::CMD_CATEGORY_BOARD:
                    return $this->handleBoardCommand($cmd, $dataHex);
                case self::CMD_CATEGORY_RESPONSE:
                    return $this->handleResponseCommand($cmd, $dataHex);
                default:
                    $this->log->warning('[PokemonBall-pokemonBallCmd] 未知命令类别', [
                        'machine_id' => $this->machine->id,
                        'cmd_category' => $cmdCategory,
                    ]);
                    return false;
            }
        } catch (\Exception $e) {
            $this->log->error('[PokemonBall-pokemonBallCmd] 处理消息异常', [
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
        switch ($cmd) {
            case substr(self::CMD_CONNECT, 2, 2): // 发起连接
                $this->log->info('[PokemonBall] 机台连接', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                ]);
                // 发送连接确认
                $this->sendFrame(self::CMD_CATEGORY_SERVER, '01');
                return true;

            case substr(self::CMD_HEARTBEAT, 2, 2): // 心跳
                // 更新操作时间
                $this->action_time = time();
                // 发送心跳响应
                $this->sendFrame(self::CMD_CATEGORY_SERVER, '02');
                return true;

            case substr(self::CMD_INSERT_MONEY, 2, 2): // 投入金额
                $amount = hexdec($dataHex);
                $this->insert_money = ($this->insert_money ?? 0) + $amount;
                $this->log->info('[PokemonBall] 投入金额', [
                    'machine_id' => $this->machine->id,
                    'amount' => $amount,
                ]);
                return true;

            case substr(self::CMD_SCORE_UP, 2, 2): // 上分金额
                $amount = hexdec($dataHex);
                $this->point = ($this->point ?? 0) + $amount;
                $this->open_point = ($this->open_point ?? 0) + $amount;
                $this->last_point_at = time();
                $this->log->info('[PokemonBall] 上分', [
                    'machine_id' => $this->machine->id,
                    'amount' => $amount,
                ]);
                return true;

            case substr(self::CMD_WASH_SCORE, 2, 2): // 洗分金额
                $amount = hexdec($dataHex);
                $this->point = max(0, ($this->point ?? 0) - $amount);
                $this->wash_point = ($this->wash_point ?? 0) + $amount;
                $this->log->info('[PokemonBall] 洗分', [
                    'machine_id' => $this->machine->id,
                    'amount' => $amount,
                ]);
                return true;

            case substr(self::CMD_GAME_START, 2, 2): // 游戏开始
                $this->gaming = 1;
                $this->log->info('[PokemonBall] 游戏开始', [
                    'machine_id' => $this->machine->id,
                ]);
                return true;

            case substr(self::CMD_BET_AMOUNT, 2, 2): // 本次压分
                $amount = hexdec($dataHex);
                $this->bet = $amount;
                $this->log->info('[PokemonBall] 压分', [
                    'machine_id' => $this->machine->id,
                    'amount' => $amount,
                ]);
                return true;

            case substr(self::CMD_LIGHT_HOLES, 2, 2): // 亮灯洞口
                $this->light_holes = $dataHex;
                $this->log->info('[PokemonBall] 亮灯洞口', [
                    'machine_id' => $this->machine->id,
                    'light_holes' => $dataHex,
                ]);
                return true;

            case substr(self::CMD_FALL_HOLES, 2, 2): // 落入洞口
                $this->fall_holes = $dataHex;
                $this->log->info('[PokemonBall] 落入洞口', [
                    'machine_id' => $this->machine->id,
                    'fall_holes' => $dataHex,
                ]);
                return true;

            case substr(self::CMD_GAME_RESULT, 2, 2): // 游戏结果
                $result = hexdec($dataHex);
                $this->win = ($this->win ?? 0) + $result;
                $this->score = ($this->score ?? 0) + $result;
                $this->gaming = 0;
                $this->log->info('[PokemonBall] 游戏结果', [
                    'machine_id' => $this->machine->id,
                    'result' => $result,
                ]);
                return true;

            case substr(self::CMD_BUTTON_SIGNAL, 2, 2): // 按钮信号
                $this->action_time = time();
                return true;

            case substr(self::CMD_ASK_TIME, 2, 2): // 询问时间
                $timeHex = sprintf('%08X', time());
                $this->sendFrame(self::CMD_CATEGORY_SERVER, '0D', $timeHex);
                return true;

            case substr(self::CMD_DOOR_SWITCH, 2, 2): // 开门微动
                $status = hexdec($dataHex);
                $this->door_status = $status;
                $this->log->info('[PokemonBall] 开关门状态', [
                    'machine_id' => $this->machine->id,
                    'status' => $status,
                ]);
                return true;

            case substr(self::CMD_JP_ENTER, 2, 2): // 进入JP
                $level = hexdec($dataHex);
                $this->jp_level = $level;
                $this->log->info('[PokemonBall] 进入JP', [
                    'machine_id' => $this->machine->id,
                    'level' => $level,
                ]);
                return true;

            default:
                $this->log->warning('[PokemonBall] 未知主板指令', [
                    'machine_id' => $this->machine->id,
                    'cmd' => $cmd,
                    'data_hex' => $dataHex,
                ]);
                return false;
        }
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
        $this->log->info('[PokemonBall] 收到主板回复', [
            'machine_id' => $this->machine->id,
            'cmd' => $cmd,
            'data_hex' => $dataHex,
        ]);
        return true;
    }

    /**
     * 发送协议帧到机台
     *
     * @param string $cmdCategory 命令类别
     * @param string $cmd 具体命令
     * @param string $dataHex 数据
     * @return bool
     */
    protected function sendFrame(string $cmdCategory, string $cmd, string $dataHex = ''): bool
    {
        $frame = self::buildFrame($cmdCategory, $cmd, $dataHex);

        try {
            $result = \app\service\MachineApiService::sendCmd(
                $this->machine->id,
                $frame,
                0,
                0,
                $this->lang
            );

            $this->log->info('[PokemonBall-sendFrame] 发送帧', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'cmd_category' => $cmdCategory,
                'cmd' => $cmd,
                'data_hex' => $dataHex,
                'frame' => $frame,
                'result' => $result,
            ]);

            return true;
        } catch (Exception $e) {
            $this->log->error('[PokemonBall-sendFrame] 发送帧失败', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'cmd_category' => $cmdCategory,
                'cmd' => $cmd,
                'data_hex' => $dataHex,
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
     * @param string $cmd 指令代码
     * @return string
     */
    protected function getDescription(string $cmd): string
    {
        return match ($cmd) {
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
        switch ($cmd) {
            case self::ALL:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '23');

            case self::WASH_ZERO:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '11');

            case self::OPEN_ANY_POINT:
                $dataHex = sprintf('%06X', $data);
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '10', $dataHex);

            case self::SCORE_UP:
                $dataHex = sprintf('%06X', $data);
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '10', $dataHex);

            case self::SCORE_DOWN:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '11');

            case self::GAME_ENABLE_ON:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '03', '01');

            case self::GAME_ENABLE_OFF:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '03', '00');

            case self::GAME_END:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '0C');

            case self::ENTER_JP1:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '12');

            case self::SET_JACKPOT:
                $dataHex = sprintf('%06X', $data);
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '14', $dataHex);

            case self::QUERY_UID:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '16');

            case self::QUERY_ACCOUNT:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '23');

            case self::SET_MULTIPLIER:
                $dataHex = sprintf('%020X', $data);
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '22', $dataHex);

            case self::ADD_SCORE:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '15', sprintf('%02X', self::ADD_SUB_ADD));

            case self::SUB_SCORE:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '15', sprintf('%02X', self::ADD_SUB_SUB));

            case self::START_GAME:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '15', sprintf('%02X', self::ADD_SUB_START));

            case self::AUTO_START:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '15', sprintf('%02X', self::ADD_SUB_AUTO));

            case self::SET_BALL_COUNT:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '21', sprintf('%02X', $data));

            case self::SET_LIGHT_COUNT:
                return self::buildFrame(self::CMD_CATEGORY_SERVER, '21', sprintf('%02X', $data));

            default:
                return null;
        }
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
