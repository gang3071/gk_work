<?php

namespace app\service\machine;

use app\model\AdminUser;
use app\model\GameType;
use app\model\Machine;
use app\model\MachineLotteryRecord;
use app\model\Notice;
use app\model\Player;
use app\service\LotteryServices;
use Exception;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Str;
use support\Cache;
use support\Log;
use support\Redis;
use Webman\Push\PushException;
use Webman\RedisQueue\Client;
use yzh52521\WebmanLock\Locker;

/**
 * 线下版老虎机（收账小卡）
 *
 * 基于 GD 收账小卡协议（2026-07-30）
 *
 * 协议要点：
 * - 波特率: 9600，停止位: 2
 * - 数据格式: 4字节BCD（每字节hex值转dec代表2位数字）
 * - 校验算法: SUM1=ADD, SUM2=XOR
 *
 * 主要指令：
 * - 查询账目: EA C4 → 回复开分码表、洗分码表、开分卡分数、机台分数
 * - 查询总玩+总赢: EA D8 → 回复总押分数、总赢分数
 * - 登入: EA C5（必须登入才能上下分）
 * - 登出: EA C3
 * - 上分: A5 XX C0 SUM1 SUM2（XX=开分次数）
 * - 下分: A5 00 C1 SUM1 SUM2
 * - 查询机台情况: EA D4 → 回复开分状态、洗分状态、转数
 * - 清除账目: EA DE
 * - 归0: EA E7（故障排除）
 *
 * 线下版特有功能：
 * - 开分码表: 外部开分累计金额（4字节BCD）
 * - 洗分码表: 外部洗分累计金额（4字节BCD）
 * - 登入机制: 必须先登入才能操作上下分
 * - 开机信号: FAH（机版刚开机时主动发送）
 *
 * 状态码：
 * - C9: 开分码表（开奖中时为CB）
 * - CA: 洗分码表
 * - E9: 开分卡分数（故障时为EE）
 * - D2: 开分完成（D3=开分中）
 * - D6: 洗分完成（D7=洗分中）
 * - D8: 总押分数（开奖中时为D7）
 * - D9: 总赢分数
 * - DC: 转数标志
 *
 * @property int $auto 自动状态（0=停止 1=启动）
 * @property int $reward_status 开奖状态（0=未开奖 1=开奖中）
 * @property int $play_start_time 开始游戏时间
 * @property int $gaming_user_id 游戏中玩家ID
 * @property int $gaming 是否游戏中
 * @property int $point 当前分数（金额）
 * @property int $score 当前得分（WIN）
 * @property int $bet 当前押分
 * @property int $win 总赢分数
 * @property int $last_play_time 最后游戏时间
 * @property int $action_time 操作时间
 * @property int $now_turn 当前累积转数
 * @property int $has_lock 机台锁定状态
 * @property int $is_login 登入状态（0=未登入 1=已登入）
 * @property int $external_open_count 外部开分码表（金额，非次数）
 * @property int $external_wash_count 外部洗分码表（金额，非次数）
 * @property int $open_card_point 开分卡分数
 * @property int $total_bet 总押分数
 * @property int $total_win 总赢分数
 *
 * @package app\service\machine
 * @author Claude Code
 * @date 2026-09-05
 */
class SongOfflineSlot extends MachineServices implements BaseMachine
{
    // ==================== 查询指令 ====================
    const QUERY_ACCOUNT = 'eac4';      // 查询账目（开分码表+洗分码表+开分卡分数+机台分数）
    const QUERY_TOTAL = 'ead8';        // 查询总玩+总赢
    const QUERY_STATUS = 'ead4';       // 查询机台情况（开分状态+洗分状态+转数）

    // ==================== 登入/登出 ====================
    const LOGIN = 'eac5';              // 登入
    const LOGOUT = 'eac3';             // 登出

    // ==================== 资金操作 ====================
    const OPEN_POINT = 'a5';           // 上分（需拼接次数和校验）
    const WASH_POINT = 'a5';           // 下分（需拼接参数和校验）

    // ==================== 管理指令 ====================
    const CLEAR_ACCOUNT = 'eade';      // 清除开洗分账+回补数
    const RESET_ZERO = 'eae7';         // 归0（E1时完整归0，回传EF；未E1时清除账目，回传EE）
    const CHECK = 'eae7';              // 故障排除（同归0指令）

    // ==================== 兼容性别名（用于控制器） ====================
    const ALL_DOWN = 'eade';           // 别名：清除历史记录（同CLEAR_ACCOUNT）
    const READ_BET = 'ead8';           // 别名：读取押分（同QUERY_TOTAL）
    const READ_WIN = 'ead8';           // 别名：读取得分（同QUERY_TOTAL）
    const WIN_NUMBER = 'ead4';         // 别名：查询累积转数（同QUERY_STATUS）

    // ==================== 心跳/回复状态码 ====================
    const HEARTBEAT_POWER_ON = 'fah';  // 开机信号（机版主动发送）
    const ERROR_E1 = 'e1';             // 需要归0

    // ==================== 查询回复状态码 ====================
    const REPLY_ACCOUNT = 'a6';        // 查询账目回复
    const REPLY_TOTAL = 'a7';          // 查询总玩+总赢回复
    const REPLY_STATUS = 'a7';         // 查询机台情况回复
    const REPLY_LOGIN = 'a7c5';        // 登入回复
    const REPLY_LOGOUT = 'a7c3';       // 登出回复

    // ==================== 操作回复状态码 ====================
    const REPLY_OPEN_SUCCESS = 'a5ca'; // 开分成功
    const REPLY_OPEN_FAIL = 'a5c5';    // 开分失败（未登入）
    const REPLY_WASH_SUCCESS = 'a5ca'; // 洗分成功
    const REPLY_WASH_FAIL = 'a5c5';    // 洗分失败（未登入）
    const REPLY_RESET_COMPLETE = 'ef'; // 完整归0完成
    const REPLY_RESET_CLEAR = 'ee';    // 清除账目完成

    // ==================== 账目状态标志 ====================
    const FLAG_OPEN_NORMAL = 'c9';     // 开分码表（正常）
    const FLAG_OPEN_REWARD = 'cb';     // 开分码表（开奖中）
    const FLAG_WASH = 'ca';            // 洗分码表
    const FLAG_CARD_NORMAL = 'e9';     // 开分卡分数（正常）
    const FLAG_CARD_FAULT = 'ee';      // 开分卡分数（故障）

    const FLAG_OPEN_DONE = 'd2';       // 开分完成
    const FLAG_OPEN_DOING = 'd3';      // 开分中
    const FLAG_WASH_DONE = 'd6';       // 洗分完成
    const FLAG_WASH_DOING = 'd7';      // 洗分中

    const FLAG_BET_NORMAL = 'd8';      // 总押分（正常）
    const FLAG_BET_REWARD = 'd7';      // 总押分（开奖中）
    const FLAG_WIN = 'd9';             // 总赢分数

    const FLAG_TURN = 'dc';            // 转数标志

    public $cacheData = [];
    public $expirationTime = 5000000;  // 5秒超时
    public $log = null;

    public function __construct(Machine $machine, $lang = 'zh_CN')
    {
        $this->machine = $machine;
        $this->cacheKey = self::CACHE_PREFIX . $this->machine->id;
        $this->cacheDataKey = self::MACHINE_DATA_PREFIX . $this->machine->id;

        // Redis缓存字段列表
        $this->cacheDataKeyArr = [
            $this->cacheDataKey . '_auto',           // 自动状态
            $this->cacheDataKey . '_reward_status',  // 开奖状态
            $this->cacheDataKey . '_play_start_time',
            $this->cacheDataKey . '_gaming_user_id',
            $this->cacheDataKey . '_gaming',
            $this->cacheDataKey . '_point',          // 机台分数
            $this->cacheDataKey . '_score',          // WIN得分
            $this->cacheDataKey . '_bet',            // 押分
            $this->cacheDataKey . '_win',            // 总赢
            $this->cacheDataKey . '_last_play_time',
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_now_turn',       // 累积转数
            $this->cacheDataKey . '_has_lock',
            $this->cacheDataKey . '_is_login',       // 登入状态
            $this->cacheDataKey . '_external_open_count',  // 外部开分码表（金额）
            $this->cacheDataKey . '_external_wash_count',  // 外部洗分码表（金额）
            $this->cacheDataKey . '_open_card_point',      // 开分卡分数
            $this->cacheDataKey . '_total_bet',            // 总押分
            $this->cacheDataKey . '_total_win',            // 总赢分
            $this->cacheDataKey . '_player_pressure',      // 玩家进入时原始压分
            $this->cacheDataKey . '_player_score',         // 玩家进入时原始得分
        ];

        // 推送到前端的关键字段
        $this->machineInfo = [
            'auto',
            'reward_status',
            'point',
            'bet',
            'win',
            'has_lock',
            'is_login',
        ];

        $this->lang = $lang;
        $this->cacheData = $this->getMachineCache();
        $this->log = Log::channel('song_offline_slot_machine');
    }

    /**
     * 获取属性（从Redis读取）
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
                    return $value;
                } catch (\Exception $e2) {
                    return 0;
                }
            }
        }
        return null;
    }

    /**
     * 设置属性（保存到Redis）
     */
    public function __set($name, $value)
    {
        $key = $this->cacheDataKey . '_' . $name;
        if (in_array($key, $this->cacheDataKeyArr)) {
            // 上分成功时更新活动时间
            if ($name === 'gaming_user_id' && !empty($value) && empty($this->gaming_user_id)) {
                Cache::set($this->cacheDataKey . '_last_play_time', time());
            }

            try {
                $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                if (!$saveResult) {
                    $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                }
            } catch (\Exception $e) {
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

            // 关键字段保存失败时抛出异常
            if (!$saveResult) {
                $mustSuccessFields = ['has_lock', 'gaming', 'gaming_user_id', 'is_login'];
                if (in_array($name, $mustSuccessFields)) {
                    \support\Log::critical('关键字段Redis保存失败，抛出异常', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'value' => $value
                    ]);
                    throw new \Exception("Redis关键字段保存失败: {$name}，请立即检查Redis服务");
                }
            }

            // 自动推送机制
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
                    'point' => $machineCacheInfo[$this->cacheDataKey . '_point'],
                    'bet' => $machineCacheInfo[$this->cacheDataKey . '_bet'],
                    'win' => $machineCacheInfo[$this->cacheDataKey . '_win'],
                    'has_lock' => $machineCacheInfo[$this->cacheDataKey . '_has_lock'],
                    'is_login' => $machineCacheInfo[$this->cacheDataKey . '_is_login'],
                ];

                $currentGamingUserId = $machineCacheInfo[$this->cacheDataKey . '_gaming_user_id'] ?? 0;
                if (in_array($name, $this->machineInfo) && !empty($currentGamingUserId)) {
                    $this->sendMachineNowInfoMessage($currentGamingUserId, $this->machine->id, $name, $info);
                }
            }
        }
    }

    /**
     * 处理消息（心跳/查询回复）
     *
     * @param string $msg 收到的消息（小写十六进制）
     * @return bool
     */
    public function handleMsg(string $msg): bool
    {
        $domain = $this->machine->domain;
        $port = $this->machine->port;

        try {
            $msg = strtolower(trim($msg));
            $len = strlen($msg);

            // 识别消息类型
            $header = substr($msg, 0, 2);

            // 开机信号
            if ($msg === self::HEARTBEAT_POWER_ON) {
                $this->log->info('[收账小卡-开机] 机版开机', [
                    'machine_code' => $this->machine->code,
                ]);
                return true;
            }

            // 错误状态
            if ($msg === self::ERROR_E1) {
                $this->log->warning('[收账小卡-错误] 记忆体异常需归0', [
                    'machine_code' => $this->machine->code,
                ]);
                $this->has_lock = 1;
                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->gaming_user_id);
                return true;
            }

            // 归0回复
            if ($msg === self::REPLY_RESET_COMPLETE || $msg === self::REPLY_RESET_CLEAR) {
                $this->log->info('[收账小卡-归0] 归0完成', [
                    'machine_code' => $this->machine->code,
                    'type' => $msg === self::REPLY_RESET_COMPLETE ? '完整归0' : '清除账目',
                ]);
                return true;
            }

            // 根据头部识别消息类型
            switch ($header) {
                case 'a6':
                    return $this->handleAccountReply($msg);
                case 'a7':
                    return $this->handleStatusReply($msg);
                case 'a5':
                    return $this->handleActionReply($msg);
                default:
                    $this->log->warning('[收账小卡] 未识别的消息类型', [
                        'machine_code' => $this->machine->code,
                        'msg' => $msg,
                        'header' => $header,
                    ]);
                    return false;
            }

        } catch (\Exception $e) {
            $this->log->error('[收账小卡] 消息处理错误', [
                'machine_code' => $this->machine->code,
                'msg' => $msg,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 处理账目查询回复（A6 C9 ... CA ... E9 ... SUM1 SUM2）
     */
    private function handleAccountReply(string $msg): bool
    {
        // A6 C9 05 14 31 0B CA 10 00 63 1E E9 00 04 03 02 00 07 28 00 SUM1 SUM2
        // 最小长度：2 + 2 + 8 + 2 + 8 + 2 + 8 + 8 + 2 + 2 = 44字符

        if (strlen($msg) < 44) {
            $this->log->error('[收账小卡] 账目回复长度不足', [
                'machine_code' => $this->machine->code,
                'msg' => $msg,
                'len' => strlen($msg),
            ]);
            return false;
        }

        // 校验SUM1和SUM2
        $dataWithoutSum = substr($msg, 0, -4);
        $receivedSum1 = substr($msg, -4, 2);
        $receivedSum2 = substr($msg, -2, 2);

        $calculatedSum1 = $this->calculateSUM1($dataWithoutSum);
        $calculatedSum2 = $this->calculateSUM2($dataWithoutSum, $calculatedSum1);

        if ($receivedSum1 !== $calculatedSum1 || $receivedSum2 !== $calculatedSum2) {
            $this->log->error('[收账小卡] 账目回复校验失败', [
                'machine_code' => $this->machine->code,
                'msg' => $msg,
                'expected_sum1' => $calculatedSum1,
                'received_sum1' => $receivedSum1,
                'expected_sum2' => $calculatedSum2,
                'received_sum2' => $receivedSum2,
            ]);
            return false;
        }

        // 解析数据
        $pos = 2; // 跳过A6

        // 开分码表标志
        $openFlag = substr($msg, $pos, 2);
        $pos += 2;

        // 开分码表（4字节）
        $openCountBytes = [
            substr($msg, $pos, 2),
            substr($msg, $pos + 2, 2),
            substr($msg, $pos + 4, 2),
            substr($msg, $pos + 6, 2),
        ];
        $openCount = $this->parseCounter($openCountBytes);
        $pos += 8;

        // 洗分码表标志
        $washFlag = substr($msg, $pos, 2);
        $pos += 2;

        // 洗分码表（4字节）
        $washCountBytes = [
            substr($msg, $pos, 2),
            substr($msg, $pos + 2, 2),
            substr($msg, $pos + 4, 2),
            substr($msg, $pos + 6, 2),
        ];
        $washCount = $this->parseCounter($washCountBytes);
        $pos += 8;

        // 开分卡分数标志
        $cardFlag = substr($msg, $pos, 2);
        $pos += 2;

        // 开分卡分数（4字节）
        $cardPointBytes = [
            substr($msg, $pos, 2),
            substr($msg, $pos + 2, 2),
            substr($msg, $pos + 4, 2),
            substr($msg, $pos + 6, 2),
        ];
        $cardPoint = $this->parseCounter($cardPointBytes);
        $pos += 8;

        // 机台分数（4字节）
        $machinePointBytes = [
            substr($msg, $pos, 2),
            substr($msg, $pos + 2, 2),
            substr($msg, $pos + 4, 2),
            substr($msg, $pos + 6, 2),
        ];
        $machinePoint = $this->parseCounter($machinePointBytes);

        // 检测开奖状态
        $isRewarding = ($openFlag === self::FLAG_OPEN_REWARD);
        $hasFault = ($cardFlag === self::FLAG_CARD_FAULT);

        $this->log->info('[收账小卡-账目] 查询回复', [
            'machine_code' => $this->machine->code,
            'open_count' => $openCount,
            'wash_count' => $washCount,
            'card_point' => $cardPoint,
            'machine_point' => $machinePoint,
            'is_rewarding' => $isRewarding,
            'has_fault' => $hasFault,
        ]);

        // 更新状态
        $this->reward_status = $isRewarding ? 1 : 0;
        $this->point = $machinePoint;
        $this->open_card_point = $cardPoint;

        // 处理故障
        if ($hasFault) {
            $this->has_lock = 1;
            sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->gaming_user_id);
        }

        // 处理外部按钮计数器变化（类似SongOfflineJackpot的B5/B7处理）
        $now = time();
        $oldOpenCount = $this->external_open_count ?? 0;
        $oldWashCount = $this->external_wash_count ?? 0;

        // 处理开分码表变化
        if ($openCount != $oldOpenCount) {
            $result = $this->processCounterChange('open', $oldOpenCount, $openCount, $now);
            if ($result['should_update']) {
                $this->external_open_count = $openCount;
            }

            if ($result['recorded']) {
                $this->log->info('[收账小卡-开分码表] 变化已记录', [
                    'machine_code' => $this->machine->code,
                    'old' => $oldOpenCount,
                    'new' => $openCount,
                    'reason' => $result['reason'] ?? '',
                ]);
            }
        }

        // 处理洗分码表变化
        if ($washCount != $oldWashCount) {
            $result = $this->processCounterChange('wash', $oldWashCount, $washCount, $now);
            if ($result['should_update']) {
                $this->external_wash_count = $washCount;
            }

            if ($result['recorded']) {
                $this->log->info('[收账小卡-洗分码表] 变化已记录', [
                    'machine_code' => $this->machine->code,
                    'old' => $oldWashCount,
                    'new' => $washCount,
                    'reason' => $result['reason'] ?? '',
                ]);
            }
        }

        return true;
    }

    /**
     * 处理状态查询回复（A7 ...）
     */
    private function handleStatusReply(string $msg): bool
    {
        $type = substr($msg, 2, 2);

        switch ($type) {
            case 'c5': // 登入回复
                $this->is_login = 1;
                $this->log->info('[收账小卡-登入] 登入成功', [
                    'machine_code' => $this->machine->code,
                ]);
                break;

            case 'c3': // 登出回复
                $this->is_login = 0;
                $this->log->info('[收账小卡-登出] 登出成功', [
                    'machine_code' => $this->machine->code,
                ]);
                break;

            case 'd8': // 查询总玩+总赢回复
                return $this->handleTotalReply($msg);

            case 'd2':
            case 'd3':
            case 'd6':
            case 'd7': // 查询机台情况回复
                return $this->handleMachineStatusReply($msg);

            default:
                $this->log->warning('[收账小卡] 未识别的A7状态回复', [
                    'machine_code' => $this->machine->code,
                    'msg' => $msg,
                    'type' => $type,
                ]);
                return false;
        }

        return true;
    }

    /**
     * 处理总玩+总赢回复（A7 D8 00 00 00 00 D9 00 00 00 00 SUM1 SUM2）
     */
    private function handleTotalReply(string $msg): bool
    {
        if (strlen($msg) < 26) {
            $this->log->error('[收账小卡] 总玩+总赢回复长度不足', [
                'machine_code' => $this->machine->code,
                'msg' => $msg,
            ]);
            return false;
        }

        // TODO: 解析总押分和总赢分

        return true;
    }

    /**
     * 处理机台情况回复（A7 D2 ... D6 ... DC ... SUM1 SUM2）
     */
    private function handleMachineStatusReply(string $msg): bool
    {
        if (strlen($msg) < 40) {
            $this->log->error('[收账小卡] 机台情况回复长度不足', [
                'machine_code' => $this->machine->code,
                'msg' => $msg,
            ]);
            return false;
        }

        // TODO: 解析开分状态、洗分状态、转数

        return true;
    }

    /**
     * 处理操作回复（A5 CA/C5 33）
     */
    private function handleActionReply(string $msg): bool
    {
        if (strlen($msg) < 6) {
            $this->log->error('[收账小卡] 操作回复长度不足', [
                'machine_code' => $this->machine->code,
                'msg' => $msg,
            ]);
            return false;
        }

        $status = substr($msg, 2, 2);
        $version = substr($msg, 4, 2);

        switch ($status) {
            case 'ca': // 操作成功
                $this->log->info('[收账小卡-操作] 操作成功', [
                    'machine_code' => $this->machine->code,
                    'version' => $version,
                ]);
                break;

            case 'c5': // 操作失败（未登入）
                $this->log->error('[收账小卡-操作] 操作失败（未登入）', [
                    'machine_code' => $this->machine->code,
                    'version' => $version,
                ]);
                $this->is_login = 0;
                break;

            default:
                $this->log->warning('[收账小卡] 未识别的A5操作回复', [
                    'machine_code' => $this->machine->code,
                    'msg' => $msg,
                    'status' => $status,
                ]);
                return false;
        }

        return true;
    }

    /**
     * 处理计数器变化（类似SongOfflineJackpot的processCounterChange）
     *
     * @param string $type 类型（open/wash）
     * @param int $oldCount 旧值
     * @param int $newCount 新值
     * @param int $timestamp 时间戳
     * @return array ['should_update' => bool, 'recorded' => bool, 'reason' => string]
     */
    private function processCounterChange(string $type, int $oldCount, int $newCount, int $timestamp): array
    {
        $cacheKey = "external_counter_{$type}_last_update_" . $this->machine->id;
        $dedupeWindow = 10; // 10秒去重窗口

        // 去重检查
        $lastUpdateTime = Cache::get($cacheKey, 0);
        if ($timestamp - $lastUpdateTime < $dedupeWindow) {
            return ['should_update' => false, 'recorded' => false, 'reason' => '去重窗口内'];
        }

        // 检测异常减少
        if ($newCount < $oldCount) {
            // 检查是否故障排除后归零
            $hasRecentCheck = Cache::get('check_flag_' . $this->machine->id);

            if ($hasRecentCheck) {
                // 正常归零
                $this->log->info("[收账小卡-{$type}码表] 故障排除后归零", [
                    'machine_code' => $this->machine->code,
                    'old' => $oldCount,
                    'new' => $newCount,
                ]);
                return ['should_update' => true, 'recorded' => false, 'reason' => '故排归零'];
            } else {
                // 异常减少
                $this->log->error("[收账小卡-{$type}码表] 异常减少", [
                    'machine_code' => $this->machine->code,
                    'old' => $oldCount,
                    'new' => $newCount,
                ]);
                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, 0);
                return ['should_update' => true, 'recorded' => false, 'reason' => '异常减少'];
            }
        }

        // 正常增加 - 记录游戏数据
        $increment = $newCount - $oldCount;

        if ($increment > 0) {
            // 更新去重时间戳
            Cache::set($cacheKey, $timestamp, $dedupeWindow * 2);

            // 记录操作
            $this->recordExternalButtonOperation($type, $increment, $timestamp);

            return ['should_update' => true, 'recorded' => true, 'reason' => '正常增加'];
        }

        return ['should_update' => true, 'recorded' => false, 'reason' => '无变化'];
    }

    /**
     * 记录外部按钮操作
     *
     * @param string $type 类型（open/wash）
     * @param int $amount 金额（线下Slot是金额，不是次数）
     * @param int $timestamp 时间戳
     */
    private function recordExternalButtonOperation(string $type, int $amount, int $timestamp): void
    {
        try {
            // ⚠️ 线下版Slot：码表记录的是金额，不是次数
            // 需要从machine_category读取turn_used_point来计算打码量

            $cateId = $this->machine->cate_id;
            $turnUsedPointCacheKey = "machine_category:{$cateId}:turn_used_point";
            $turnUsedPoint = \support\Cache::get($turnUsedPointCacheKey);

            if ($turnUsedPoint === null) {
                $turnUsedPoint = \app\model\MachineCategory::query()
                    ->where('id', $cateId)
                    ->value('turn_used_point') ?? 0;
                \support\Cache::set($turnUsedPointCacheKey, $turnUsedPoint, 3600);
            }

            if ($turnUsedPoint === null || $turnUsedPoint <= 0) {
                $this->log->warning('[收账小卡-记录] 机台类别缺少turn_used_point配置', [
                    'machine_id' => $this->machine->id,
                    'category_id' => $cateId,
                    'type' => $type,
                    'amount' => $amount,
                ]);
                return;
            }

            // 计算打码量：金额（分） × turn_used_point = 打码量（元）
            $betAmount = bcmul($amount, $turnUsedPoint, 2);

            if (bccomp($betAmount, '0', 2) <= 0) {
                return;
            }

            $this->log->info('[收账小卡-记录] 外部按钮操作', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'type' => $type,
                'amount' => $amount,
                'turn_used_point' => $turnUsedPoint,
                'bet_amount' => floatval($betAmount),
            ]);

            // 创建游戏日志
            $this->createExternalButtonGameLog($type, $amount, $betAmount, $timestamp);

        } catch (\Exception $e) {
            $this->log->error('[收账小卡-记录] 记录失败', [
                'machine_id' => $this->machine->id,
                'type' => $type,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 创建外部按钮游戏日志
     *
     * @param string $type 类型（open/wash）
     * @param int $amount 金额
     * @param float $betAmount 打码量
     * @param int $timestamp 时间戳
     */
    private function createExternalButtonGameLog(string $type, int $amount, float $betAmount, int $timestamp): void
    {
        try {
            $gameLog = new \app\model\GameLog();
            $gameLog->player_id = 0; // 外部按钮操作无玩家ID
            $gameLog->machine_id = $this->machine->id;
            $gameLog->game_type = GameType::TYPE_SLOT;
            $gameLog->action_type = $type === 'open' ? 'external_open' : 'external_wash';
            $gameLog->amount = $amount / 100; // 分转元
            $gameLog->bet_amount = $betAmount;
            $gameLog->created_at = date('Y-m-d H:i:s', $timestamp);
            $gameLog->save();

            $this->log->info('[收账小卡-日志] 游戏日志已创建', [
                'game_log_id' => $gameLog->id,
                'machine_id' => $this->machine->id,
                'type' => $type,
                'amount' => $amount,
                'bet_amount' => $betAmount,
            ]);

        } catch (\Exception $e) {
            $this->log->error('[收账小卡-日志] 创建失败', [
                'machine_id' => $this->machine->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 解析计数器（4字节BCD）
     *
     * 格式：每个字节的十六进制值转十进制后代表2位数字
     * 例：[0x05, 0x14, 0x31, 0x0B]
     *     → 5, 20, 49, 11
     *     → "05" + "20" + "49" + "11"
     *     → 5204911
     *
     * @param array $bytes 4个字节的数组（十六进制字符串）
     * @return int 计数器值
     */
    private function parseCounter(array $bytes): int
    {
        if (count($bytes) < 4) {
            return 0;
        }

        $result = 0;
        foreach ($bytes as $byte) {
            $decimal = hexdec($byte);  // 十六进制转十进制
            $result = $result * 100 + $decimal;  // 每个字节代表2位数字
        }

        return $result;
    }

    /**
     * 计数器转字节（4字节BCD）
     *
     * 例：5204911
     *     → "05204911"
     *     → [05, 20, 49, 11]
     *     → [0x05, 0x14, 0x31, 0x0B]
     *
     * @param int $count 计数器值
     * @return array 4个字节的数组（十六进制字符串）
     */
    private function counterToBytes(int $count): array
    {
        // 补齐8位
        $str = str_pad((string)$count, 8, '0', STR_PAD_LEFT);

        // 每2位转一个字节
        $bytes = [];
        for ($i = 0; $i < 8; $i += 2) {
            $twoDigits = substr($str, $i, 2);
            $decimal = (int)$twoDigits;
            $bytes[] = str_pad(dechex($decimal), 2, '0', STR_PAD_LEFT);
        }

        return $bytes;
    }

    /**
     * 计算SUM1校验（ADD累加）
     *
     * @param string $data 数据（十六进制字符串，不含SUM1和SUM2）
     * @return string 2位十六进制SUM1
     */
    private function calculateSUM1(string $data): string
    {
        $bytes = str_split($data, 2);
        $sum = 0;

        foreach ($bytes as $byte) {
            $sum += hexdec($byte);
        }

        // 取最后2位
        $result = $sum & 0xFF;
        return str_pad(dechex($result), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 计算SUM2校验（XOR异或）
     *
     * @param string $data 数据（十六进制字符串，不含SUM1和SUM2）
     * @param string $sum1 计算出的SUM1
     * @return string 2位十六进制SUM2
     */
    private function calculateSUM2(string $data, string $sum1): string
    {
        $bytes = str_split($data, 2);
        $xor = 0;

        foreach ($bytes as $byte) {
            $xor ^= hexdec($byte);
        }

        // XOR with SUM1
        $xor ^= hexdec($sum1);

        // 取最后2位
        $result = $xor & 0xFF;
        return str_pad(dechex($result), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 发送指令
     *
     * @param string $cmd 指令
     * @param int $data 数据
     * @param string $source 来源（player/admin）
     * @param int $source_id 来源ID
     * @param int $isSystem 是否系统指令
     * @return bool
     * @throws Exception
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

            // 玩家操作时更新活动时间
            if ($source == 'player') {
                $currentGamingUserId = $this->gaming_user_id;
                if (!empty($currentGamingUserId)) {
                    $this->last_play_time = time();
                }
            }

            switch ($cmd) {
                case self::LOGIN:
                case self::LOGOUT:
                case self::QUERY_ACCOUNT:
                case self::QUERY_TOTAL:
                case self::QUERY_STATUS:
                case self::CLEAR_ACCOUNT:
                case self::ALL_DOWN:       // 别名：清除历史记录
                case self::READ_BET:       // 别名：读取押分
                case self::READ_WIN:       // 别名：读取得分
                case self::WIN_NUMBER:     // 别名：查询累积转数
                    // 简单指令：直接发送
                    Gateway::sendToUid($uid, hex2bin($cmd));
                    break;

                case self::CHECK:
                case self::RESET_ZERO:
                    // 归0指令
                    $this->handleCheckCommand($uid, $source, $source_id);
                    break;

                case self::OPEN_POINT:
                    // 上分指令
                    $this->handleOpenPoint($uid, $data, $source, $source_id);
                    break;

                case self::WASH_POINT:
                    // 下分指令
                    $this->handleWashPoint($uid, $data, $source, $source_id);
                    break;

                default:
                    throw new Exception('未知指令: ' . $cmd);
            }

        } catch (Exception $e) {
            $this->log->error('[收账小卡] 发送指令失败', [
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return true;
    }

    /**
     * 处理故障排除/归0指令
     *
     * ⚠️ 会清除开分码表和洗分码表（类似SongOfflineJackpot的B5/B7）
     */
    private function handleCheckCommand(string $uid, string $source, int $source_id): void
    {
        // 设置check标记（10秒有效）
        Cache::set('check_flag_' . $this->machine->id, true, 10);

        // 清除外部按钮计数器
        $oldOpenCount = $this->external_open_count ?? 0;
        $oldWashCount = $this->external_wash_count ?? 0;

        $this->external_open_count = 0;
        $this->external_wash_count = 0;

        $this->log->info('[收账小卡-故障排除] 清除外部码表', [
            'machine_code' => $this->machine->code,
            'old_open_count' => $oldOpenCount,
            'old_wash_count' => $oldWashCount,
            'note' => '故障排除会清除开分码表和洗分码表，已设置10秒标记',
        ]);

        // 发送归0指令
        Gateway::sendToUid($uid, hex2bin(self::RESET_ZERO));

        if ($source == 'admin') {
            sendSocketMessage('private-admin-1-' . $source_id, [
                'msg_type' => 'machine_action_result',
                'id' => $this->machine->id,
                'description' => '故障排除指令已发送',
            ]);
        }
    }

    /**
     * 处理上分指令（A5 XX C0 SUM1 SUM2）
     */
    private function handleOpenPoint(string $uid, int $times, string $source, int $source_id): void
    {
        // 检查登入状态
        if ($this->is_login != 1) {
            throw new Exception('未登入，无法上分');
        }

        // 构建指令：A5 XX C0 SUM1 SUM2
        $timesHex = str_pad(dechex($times), 2, '0', STR_PAD_LEFT);
        $data = 'a5' . $timesHex . 'c0';

        $sum1 = $this->calculateSUM1($data);
        $sum2 = $this->calculateSUM2($data, $sum1);

        $fullCmd = $data . $sum1 . $sum2;

        $this->log->info('[收账小卡-上分] 发送上分指令', [
            'machine_code' => $this->machine->code,
            'times' => $times,
            'cmd' => $fullCmd,
        ]);

        Gateway::sendToUid($uid, hex2bin($fullCmd));

        if ($source == 'admin') {
            sendSocketMessage('private-admin-1-' . $source_id, [
                'msg_type' => 'machine_action_result',
                'id' => $this->machine->id,
                'description' => "上分指令已发送（{$times}次）",
            ]);
        }
    }

    /**
     * 处理下分指令（A5 00 C1 SUM1 SUM2）
     */
    private function handleWashPoint(string $uid, int $data, string $source, int $source_id): void
    {
        // 检查登入状态
        if ($this->is_login != 1) {
            throw new Exception('未登入，无法下分');
        }

        // 构建指令：A5 00 C1 SUM1 SUM2
        $cmdData = 'a500c1';

        $sum1 = $this->calculateSUM1($cmdData);
        $sum2 = $this->calculateSUM2($cmdData, $sum1);

        $fullCmd = $cmdData . $sum1 . $sum2;

        $this->log->info('[收账小卡-下分] 发送下分指令', [
            'machine_code' => $this->machine->code,
            'cmd' => $fullCmd,
        ]);

        Gateway::sendToUid($uid, hex2bin($fullCmd));

        if ($source == 'admin') {
            sendSocketMessage('private-admin-1-' . $source_id, [
                'msg_type' => 'machine_action_result',
                'id' => $this->machine->id,
                'description' => '下分指令已发送',
            ]);
        }
    }

    /**
     * 获取所有属性
     */
    public function getAllData(): iterable
    {
        return Cache::getMultiple($this->cacheDataKeyArr, 0);
    }

    /**
     * 获取指令描述
     */
    public function getDescription(string $fun = '', int $data = 0): string
    {
        locale(Str::replace('-', '_', $this->lang));

        $description = '';
        $autoStatus = $this->auto == 1 ? '启动' : '停止';
        $loginStatus = $this->is_login == 1 ? '已登入' : '未登入';

        if (empty($fun)) {
            $description .= '自动状态: ' . $autoStatus . PHP_EOL;
            $description .= '登入状态: ' . $loginStatus . PHP_EOL;
            $description .= '机台分数: ' . ($this->point ?? 0) . PHP_EOL;
            $description .= '押分: ' . ($this->bet ?? 0) . PHP_EOL;
            $description .= '得分: ' . ($this->win ?? 0) . PHP_EOL;
        } else {
            switch ($fun) {
                case self::LOGIN:
                    $description = '登入';
                    break;
                case self::LOGOUT:
                    $description = '登出';
                    break;
                case self::CHECK:
                    $description = '故障排除';
                    break;
                case self::OPEN_POINT:
                    $description = "上分（{$data}次）";
                    break;
                case self::WASH_POINT:
                    $description = '下分';
                    break;
                default:
                    $description = $fun;
            }
        }

        return $description;
    }

    /**
     * 设置操作版本号
     */
    public function setActionVersion($name): float
    {
        $version = getMillisecond();
        Cache::set($this->cacheDataKey . '_' . 'action_' . $name, $version, 60 * 60);
        return $version;
    }

    /**
     * 获取操作版本号
     */
    public function getActionVersion($name): float
    {
        return (float)Cache::get($this->cacheDataKey . '_' . 'action_' . $name);
    }
}
