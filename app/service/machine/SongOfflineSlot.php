<?php

namespace app\service\machine;

use app\model\AdminUser;
use app\model\Machine;
use app\model\Notice;
use Exception;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Str;
use support\Cache;
use support\Log;
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
    const LOGIN = 'eac3';              // 登入（原始协议：後台發送"EA C3"，機板回傳 A7 C3 (表示登入中)）
    const CHECK_LOGIN = 'eac5';        // 查询登入状态（原始协议：後台發送"EA C5"，機板回傳 A7 C5 (表示登出中)）

    // ==================== 资金操作 ====================
    const OPEN_POINT = 'a5';           // 上分（需拼接次数和校验）
    const WASH_POINT = 'a5';           // 下分（需拼接参数和校验）

    // ==================== 管理指令 ====================
    const CLEAR_ACCOUNT = 'eade';      // 清除开洗分账+回补数
    const RESET_MEMORY = 'eae7';       // 清除账目（需指拨1=ON）
    const RESET_BOARD = 'a37005e0f8ce'; // 归0机板（固定指令，不可修改）⚠️ 新增
    const CHECK = 'eae7';              // 故障排除（同RESET_MEMORY）

    // ==================== 心跳 ====================
    const HEARTBEAT = 'b7';            // 心跳标识 ⚠️ 新增

    // ==================== 回复标识（已在后面定义，这里注释掉避免重复）====================
    // const REPLY_DETAIL = 'a6';      // 详细账目回复（见后面REPLY_ACCOUNT）
    // const REPLY_BET_WIN = 'a7';     // 总押总赢回复（见后面REPLY_TOTAL）
    // const REPLY_STATUS = 'a7';      // 机台情况回复（见后面）
    // const REPLY_UP_DOWN = 'a5';     // 上下分回复（见后面REPLY_OPEN/WASH_SUCCESS）
    // const REPLY_LOGIN = 'a7';       // 登入回复（见后面）
    // const REPLY_RESET = 'a3';       // 归0回复（见后面REPLY_RESET_COMPLETE）

    // ==================== 状态标识 ====================
    const STATUS_NORMAL = 'c9';        // 正常开分 ⚠️ 新增
    const STATUS_DRAWING = 'cb';       // 开奖中 ⚠️ 新增
    const STATUS_CARD_OK = 'e9';       // 开分卡正常 ⚠️ 新增
    const STATUS_CARD_ERROR = 'ee';    // Smart卡故障 ⚠️ 新增
    const STATUS_UP_OK = 'ca';         // 上分成功 ⚠️ 新增
    const STATUS_UP_FAIL = 'c5';       // 上分失败（未登入）⚠️ 新增
    const STATUS_RESET_OK = 'f8';      // 归0完成 ⚠️ 新增

    // ==================== 开机标识 ====================
    const BOOT_FLAG = 'fa';            // 机板开机标识（FAH）⚠️ 新增

    // ==================== 业务限制 ====================
    const MAX_SCORE = 99999999;        // 最大分数（4字节BCD）⚠️ 新增

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
    const REPLY_LOGIN_SUCCESS = 'a7c3';   // ⚠️ 修复：登入成功回复（EA C3 → A7 C3）
    const REPLY_LOGIN_QUERY = 'a7c5';     // ⚠️ 修复：查询登入状态回复（EA C5 → A7 C5）
    // 注意：协议没有登出指令，登出状态通过心跳状态字节bit7检测

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
            // 基础字段
            $this->cacheDataKey . '_gaming_user_id',
            $this->cacheDataKey . '_gaming',
            $this->cacheDataKey . '_last_play_time',
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_has_lock',

            // ✅ Bug #15修复：添加推送机制需要的关键字段
            $this->cacheDataKey . '_auto',                 // 自动状态（必须，推送需要）
            $this->cacheDataKey . '_reward_status',        // 开奖状态（必须，推送需要）
            $this->cacheDataKey . '_bet',                  // 当前押分（必须，推送需要）
            $this->cacheDataKey . '_win',                  // 总赢分数（必须，推送需要）

            // ⚠️ 新协议字段（GD 2026 07/30）
            $this->cacheDataKey . '_login_status',         // 登入状态（新命名）
            $this->cacheDataKey . '_card_score',           // 开分卡分数（新命名）
            $this->cacheDataKey . '_machine_score',        // 机台分数（新增）⚠️
            $this->cacheDataKey . '_total_bet',            // 总押分
            $this->cacheDataKey . '_total_win',            // 总得分
            $this->cacheDataKey . '_open_table',           // 开分码表（新命名）
            $this->cacheDataKey . '_wash_table',           // 洗分码表（新命名）

            // ⚠️ 新增：大当/高确/小当状态（心跳状态字节）
            $this->cacheDataKey . '_big_win',              // 大当状态
            $this->cacheDataKey . '_high_prob',            // 高确状态
            $this->cacheDataKey . '_small_win',            // 小当状态

            // ⚠️ 新增：现场跳码表检测（心跳状态字节）
            $this->cacheDataKey . '_external_open',        // 现场跳开分表
            $this->cacheDataKey . '_external_wash',        // 现场跳洗分表

            // 其他字段
            $this->cacheDataKey . '_turn',                 // 转数
            $this->cacheDataKey . '_return_count',         // 回补次数
            $this->cacheDataKey . '_table_miss',           // 码表少跳数

            // ⚠️ 兼容性保留（逐步移除）- 旧字段映射
            $this->cacheDataKey . '_is_login',             // 旧→login_status
            $this->cacheDataKey . '_external_open_count',  // 旧→open_table
            $this->cacheDataKey . '_external_wash_count',  // 旧→wash_table
            $this->cacheDataKey . '_open_card_point',      // 旧→card_score
            $this->cacheDataKey . '_point',                // 旧→machine_score
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
                // ✅ Bug #15修复：所有字段添加默认值，防止Undefined array key错误
                $info = [
                    'id' => $this->machine->id,
                    'last_game_at' => $this->machine->last_game_at,
                    'type' => $this->machine->type,
                    'gaming_user_id' => $machineCacheInfo[$this->cacheDataKey . '_gaming_user_id'] ?? 0,
                    'gaming' => $machineCacheInfo[$this->cacheDataKey . '_gaming'] ?? 0,
                    'auto' => $machineCacheInfo[$this->cacheDataKey . '_auto'] ?? 0,
                    'reward_status' => $machineCacheInfo[$this->cacheDataKey . '_reward_status'] ?? 0,
                    'point' => $machineCacheInfo[$this->cacheDataKey . '_point'] ?? 0,
                    'bet' => $machineCacheInfo[$this->cacheDataKey . '_bet'] ?? 0,
                    'win' => $machineCacheInfo[$this->cacheDataKey . '_win'] ?? 0,
                    'has_lock' => $machineCacheInfo[$this->cacheDataKey . '_has_lock'] ?? 0,
                    'is_login' => $machineCacheInfo[$this->cacheDataKey . '_is_login'] ?? 0,
                ];

                $currentGamingUserId = $machineCacheInfo[$this->cacheDataKey . '_gaming_user_id'] ?? 0;
                if (in_array($name, $this->machineInfo) && !empty($currentGamingUserId)) {
                    $this->sendMachineNowInfoMessage($currentGamingUserId, $this->machine->id, $name, $info);
                }
            }
        }
    }

    /**
     * Gateway Worker 入口方法
     *
     * Events.php 会调用此方法处理来自机台的消息
     *
     * @param string $msg 收到的消息（十六进制）
     * @return bool
     */
    public function slotCmd(string $msg): bool
    {
        // 转换为小写并调用 handleMsg
        $this->log->info('[收账小卡-开机] 收到指令', [
            'msg' => $msg,
        ]);
        return $this->handleMsg(strtolower($msg));
    }

    /**
     * 处理消息（心跳/查询回复）
     *
     * @param string $msg 收到的消息（小写十六进制）
     * @return bool
     */
    public function handleMsg(string $msg): bool
    {

        try {
            $msg = strtolower(trim($msg));
            // ⚠️ 第一步：检查机板开机标识（FAH）
            // ⚠️ 修复：直接检查，不调用不存在的checkBootFlag方法
            if (substr($msg, 0, 2) === self::BOOT_FLAG || $msg === self::HEARTBEAT_POWER_ON) {
                $this->log->info('[收账小卡-开机] 机版开机', [
                    'machine_code' => $this->machine->code,
                    'msg' => strtoupper($msg),
                ]);
                // 开机后应该重新登入
                $this->is_login = 0;
                $this->login_status = 0;
                return true;
            }

            // ⚠️ 第二步：判断并处理心跳消息（B7前缀）
            if ($this->isHeartbeat($msg)) {
                return $this->handleHeartbeat($msg);
            }

            // 识别消息类型
            $header = substr($msg, 0, 2);

            // ✅ Bug #16修复：E1错误识别（可能是连续的E1，如e1e1e1e1e1e1）
            if (preg_match('/^(e1)+$/i', $msg)) {
                $this->log->warning('[收账小卡-错误] 记忆体异常需归0', [
                    'machine_code' => $this->machine->code,
                    'msg' => strtoupper($msg),
                    'e1_count' => strlen($msg) / 2,
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

        // 解析数据（使用parseScore4Byte简化）⚠️ 已优化
        $pos = 2; // 跳过A6

        // 开分码表标志
        $openFlag = substr($msg, $pos, 2);
        $pos += 2;

        // 开分码表（4字节）⚠️ 使用parseScore4Byte
        $openTable = $this->parseScore4Byte(substr($msg, $pos, 8));
        $pos += 8;

        // 洗分码表标志
        $washFlag = substr($msg, $pos, 2);
        $pos += 2;

        // 洗分码表（4字节）⚠️ 使用parseScore4Byte
        $washTable = $this->parseScore4Byte(substr($msg, $pos, 8));
        $pos += 8;

        // 开分卡分数标志
        $cardFlag = substr($msg, $pos, 2);
        $pos += 2;

        // 开分卡分数（4字节）⚠️ 使用parseScore4Byte
        $cardScore = $this->parseScore4Byte(substr($msg, $pos, 8));
        $pos += 8;

        // 机台分数（4字节）⚠️ 使用parseScore4Byte
        $machineScore = $this->parseScore4Byte(substr($msg, $pos, 8));

        // 检测开奖状态
        $isRewarding = ($openFlag === self::FLAG_OPEN_REWARD);
        $hasFault = ($cardFlag === self::FLAG_CARD_FAULT);

        $this->log->info('[收账小卡-账目] 查询回复', [
            'machine_code' => $this->machine->code,
            'open_table' => $openTable,      // ⚠️ 新字段名
            'wash_table' => $washTable,      // ⚠️ 新字段名
            'card_score' => $cardScore,      // ⚠️ 新字段名
            'machine_score' => $machineScore, // ⚠️ 新字段名
            'is_rewarding' => $isRewarding,
            'has_fault' => $hasFault,
        ]);

        // 更新状态（同时更新新旧字段，保持兼容性）⚠️ 已优化
        $this->reward_status = $isRewarding ? 1 : 0;

        // ⚠️ 修复：先读取旧值，再处理变化检测
        // 不能在这里直接更新open_table和wash_table，否则下面的变化检测永远是false
        $this->card_score = $cardScore;
        $this->machine_score = $machineScore;

        // 旧字段（兼容性）
        $this->point = $machineScore;              // 旧名
        $this->open_card_point = $cardScore;       // 旧名

        // 处理故障
        if ($hasFault) {
            $this->has_lock = 1;
            sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->gaming_user_id);
        }

        // 处理外部按钮计数器变化（类似SongOfflineJackpot的B5/B7处理）
        $now = time();
        $oldOpenTable = $this->open_table ?? 0;  // ⚠️ 读取Redis旧值
        $oldWashTable = $this->wash_table ?? 0;  // ⚠️ 读取Redis旧值

        // 处理开分码表变化
        if ($openTable != $oldOpenTable) {
            $result = $this->processCounterChange('open', $oldOpenTable, $openTable, $now);
            if ($result['should_update']) {
                $this->open_table = $openTable;              // ⚠️ 新字段
                $this->external_open_count = $openTable;     // 旧字段（兼容性）
            }

            if ($result['recorded']) {
                $this->log->info('[收账小卡-开分码表] 变化已记录', [
                    'machine_code' => $this->machine->code,
                    'old' => $oldOpenTable,
                    'new' => $openTable,
                    'reason' => $result['reason'] ?? '',
                ]);
            }
        }

        // 处理洗分码表变化
        if ($washTable != $oldWashTable) {
            $result = $this->processCounterChange('wash', $oldWashTable, $washTable, $now);
            if ($result['should_update']) {
                $this->wash_table = $washTable;              // ⚠️ 新字段
                $this->external_wash_count = $washTable;     // 旧字段（兼容性）
            }

            if ($result['recorded']) {
                $this->log->info('[收账小卡-洗分码表] 变化已记录', [
                    'machine_code' => $this->machine->code,
                    'old' => $oldWashTable,
                    'new' => $washTable,
                    'reason' => $result['reason'] ?? '',
                ]);
            }
        }

        // ✅ 更新版本号，表示收到回复
        $this->setActionVersion(self::QUERY_ACCOUNT);

        return true;
    }

    /**
     * 处理状态查询回复（A7 ...）
     */
    private function handleStatusReply(string $msg): bool
    {
        $type = substr($msg, 2, 2);

        switch ($type) {
            case 'c3': // A7 C3 回复
                // ✅ 登入成功（原始协议：後台發送"EA C3"，機板回傳 A7 C3 (表示登入中)）
                $this->is_login = 1;
                $this->login_status = 1;
                $this->log->info('[收账小卡-登入] 登入成功（A7 C3）', [
                    'machine_code' => $this->machine->code,
                ]);
                $this->setActionVersion(self::LOGIN);
                break;

            case 'c5': // A7 C5 回复
                // ✅ 修复Bug #9：查询到登出状态（原始协议：後台發送"EA C5"，機板回傳 A7 C5 (表示登出中)）
                // ⚠️ 原始代码错误地设置is_login=1，应该是0
                $this->is_login = 0;
                $this->login_status = 0;
                $this->log->info('[收账小卡-查询] 机台处于登出状态（A7 C5）', [
                    'machine_code' => $this->machine->code,
                ]);
                $this->setActionVersion(self::CHECK_LOGIN);
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

        // ✅ 解析总押分和总赢分
        // 格式：A7 D8 [总押4B] D9 [总赢4B] SUM1 SUM2
        $pos = 4; // 跳过 A7 D8

        $totalBet = $this->parseScore4Byte(substr($msg, $pos, 8));
        $pos += 8;

        $pos += 2; // 跳过 D9

        $totalWin = $this->parseScore4Byte(substr($msg, $pos, 8));

        $this->log->info('[收账小卡-总押总赢] 查询回复', [
            'machine_code' => $this->machine->code,
            'total_bet' => $totalBet,
            'total_win' => $totalWin,
        ]);

        // 更新数据
        $this->total_bet = $totalBet;
        $this->total_win = $totalWin;

        // ✅ 更新版本号，表示收到回复
        $this->setActionVersion(self::QUERY_TOTAL);

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

        // ✅ 解析开分状态、洗分状态、转数
        // 格式：A7 D2/D3/D6/D7 ... SUM1 SUM2
        $statusType = substr($msg, 2, 2);

        $this->log->info('[收账小卡-机台情况] 查询回复', [
            'machine_code' => $this->machine->code,
            'status_type' => $statusType,
            'msg' => $msg,
        ]);

        // ⚠️ 具体解析逻辑根据协议文档实现（当前简化处理）

        // ✅ 更新版本号，表示收到回复
        $this->setActionVersion(self::QUERY_STATUS);

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
                $this->login_status = 0;  // 新字段
                break;

            default:
                $this->log->warning('[收账小卡] 未识别的A5操作回复', [
                    'machine_code' => $this->machine->code,
                    'msg' => $msg,
                    'status' => $status,
                ]);
                return false;
        }

        // ✅ 更新版本号，表示收到回复（A5用于上分和下分）
        $this->setActionVersion('a5');

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

            // 计算打码量：金额（分）÷ 100 × turn_used_point = 打码量（元）
            // ⚠️ 修复：$amount是"分"，需要先转成"元"再乘turn_used_point
            $betAmount = bcmul(bcdiv($amount, 100, 2), $turnUsedPoint, 2);

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
        // ✅ Bug #8修复：添加事务保护和完整字段（对比钢珠机实现）
        \support\Db::beginTransaction();

        try {
            $playerGameLog = new \app\model\PlayerGameLog();

            // 玩家信息（外部按钮固定为0）
            $playerGameLog->player_id = 0;
            $playerGameLog->parent_player_id = 0;

            // ✅ 修复：从机台绑定获取渠道信息（外部按钮无玩家，但需要渠道/门店信息）
            $channelMachine = \app\model\ChannelMachine::where('machine_id', $this->machine->id)->first();
            if ($channelMachine) {
                $playerGameLog->department_id = $channelMachine->department_id;
                $playerGameLog->store_id = $channelMachine->store_admin_id;

                // 门店代理ID：从门店的上级获取
                if ($channelMachine->store_admin_id) {
                    $storeAdmin = AdminUser::find($channelMachine->store_admin_id);
                    // ✅ Bug #13修复：检查AdminUser是否存在，避免访问null的属性
                    if ($storeAdmin) {
                        $playerGameLog->store_agent_id = $storeAdmin->parent_admin_id ?? null;
                    }
                }
            }

            // 代理信息（外部按钮无代理）
            $playerGameLog->agent_player_id = 0;

            // 机台信息
            $playerGameLog->game_id = $this->machine->machineCategory?->game_id ?? 0;
            $playerGameLog->machine_id = $this->machine->id;
            $playerGameLog->type = $this->machine->type;
            $playerGameLog->odds = $this->machine->odds_x . ':' . $this->machine->odds_y;
            $playerGameLog->control_open_point = $this->machine->control_open_point ?? 0;

            // 操作类型和分数
            $playerGameLog->action = $type === 'open' ?
                \app\model\PlayerGameLog::ACTION_OPEN :
                \app\model\PlayerGameLog::ACTION_DOWN;
            $playerGameLog->open_point = $type === 'open' ? $amount : 0;
            $playerGameLog->wash_point = $type === 'wash' ? $amount : 0;

            // 金额信息
            $playerGameLog->gift_point = 0;
            $playerGameLog->game_amount = $amount / 100;
            $playerGameLog->before_game_amount = 0;  // 外部按钮不涉及玩家余额
            $playerGameLog->after_game_amount = 0;

            // 其他信息
            $playerGameLog->source_type = \app\model\PlayerGameLog::SOURCE_TYPE_OFFLINE_BUTTON;
            $playerGameLog->is_test = 0;
            $playerGameLog->created_at = date('Y-m-d H:i:s', $timestamp);

            $playerGameLog->save();
            \support\Db::commit();

            $this->log->info('[收账小卡-日志] 游戏日志已创建', [
                'game_log_id' => $playerGameLog->id,
                'machine_id' => $this->machine->id,
                'type' => $type,
                'amount' => $amount,
                'bet_amount' => $betAmount,
                'department_id' => $playerGameLog->department_id ?? 'NULL',
                'store_id' => $playerGameLog->store_id ?? 'NULL',
            ]);

        } catch (\Exception $e) {
            \support\Db::rollBack();
            $this->log->error('[收账小卡-日志] 创建失败', [
                'machine_id' => $this->machine->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
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
                case self::LOGIN:          // 登入（EA C3）
                case self::CHECK_LOGIN:    // 查询登入状态（EA C5）
                case self::QUERY_ACCOUNT:  // 查询账目（EA C4）
                case self::QUERY_TOTAL:    // 查询总玩+总赢（EA D8）
                case self::QUERY_STATUS:   // 查询机台情况（EA D4）
                case self::CLEAR_ACCOUNT:  // 清除开洗分账（EA DE）
                case self::ALL_DOWN:       // 别名：清除历史记录
                case self::READ_BET:       // 别名：读取押分
                case self::READ_WIN:       // 别名：读取得分
                case self::WIN_NUMBER:     // 别名：查询累积转数
                // ✅ 修复：使用 createCmd 添加校验和
                $fullCmd = $this->createCmd($cmd);
                $this->log->info('[收账小卡] 发送简单指令', [
                    'machine_code' => $this->machine->code,
                    'cmd' => strtoupper($cmd),
                    'full_cmd' => strtoupper($fullCmd),
                ]);
                Gateway::sendToUid($uid, hex2bin($fullCmd));
                    break;

                case self::CHECK:
                case self::RESET_MEMORY:  // ⚠️ 修复：清除账目（同CHECK）
                case self::RESET_BOARD:   // ⚠️ 修复：归0机板（RESET_ZERO已废弃）
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

        // 清除外部按钮计数器（同步清除新旧字段）
        $oldOpenCount = $this->external_open_count ?? 0;
        $oldWashCount = $this->external_wash_count ?? 0;

        // ✅ Bug #12修复：同步清除新旧字段，确保一致性
        $this->open_table = 0;           // 新字段
        $this->external_open_count = 0;  // 旧字段（兼容性）
        $this->wash_table = 0;           // 新字段
        $this->external_wash_count = 0;  // 旧字段（兼容性）

        $this->log->info('[收账小卡-故障排除] 清除外部码表', [
            'machine_code' => $this->machine->code,
            'old_open_count' => $oldOpenCount,
            'old_wash_count' => $oldWashCount,
            'note' => '故障排除会清除开分码表和洗分码表，已设置10秒标记',
        ]);

        // 发送归0机板指令（固定指令：A3 70 05 E0 F8 CE）
        // ⚠️ 修复：RESET_ZERO已废弃，使用RESET_BOARD
        Gateway::sendToUid($uid, hex2bin(self::RESET_BOARD));

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
     *
     * ⚠️ 小淞线下Slot特殊规则：
     * 1. 固定100分为单位
     * 2. 玩家开分1000需要发送开分指令10次（1000÷100=10次）
     * 3. $data参数是机台分数（不是玩家钱包金额）
     */
    private function handleOpenPoint(string $uid, int $data, string $source, int $source_id): void
    {
        // ⚠️ 确保已登入（自动登入）
        $this->ensureLoggedIn();

        // ⚠️ 特殊逻辑：机台分数转换成次数（100分为单位）
        if ($data % 100 != 0) {
            throw new Exception('开分金额必须是100的倍数，当前：' . $data);
        }

        $times = intval($data / 100);

        if ($times <= 0 || $times > 255) {
            throw new Exception('开分次数超出范围（1-255），当前：' . $times);
        }

        // ✅ 优化：构建指令 A5 XX C0，使用统一方法添加校验和
        $timesHex = str_pad(dechex($times), 2, '0', STR_PAD_LEFT);
        $cmdData = 'a5' . $timesHex . 'c0';

        // 手动计算校验和（因为指令格式特殊，不能直接用 createCmd）
        $sum1 = $this->calculateSUM1($cmdData);
        $sum2 = $this->calculateSUM2($cmdData, $sum1);

        $fullCmd = $cmdData . $sum1 . $sum2;

        $this->log->info('[收账小卡-上分] 发送上分指令', [
            'machine_code' => $this->machine->code,
            'machine_score' => $data,
            'times' => $times,
            'unit' => 100,
            'cmd' => $fullCmd,
        ]);

        Gateway::sendToUid($uid, hex2bin($fullCmd));

        if ($source == 'admin') {
            sendSocketMessage('private-admin-1-' . $source_id, [
                'msg_type' => 'machine_action_result',
                'id' => $this->machine->id,
                'description' => "上分指令已发送（{$times}次×100分={$data}分）",
            ]);
        }
    }

    /**
     * 处理下分指令（A5 00 C1 SUM1 SUM2）
     *
     * ⚠️ 小淞线下Slot特殊规则：
     * 1. 固定100分为单位
     * 2. 不能全部洗分（根据用户说明）
     * 3. 洗分需要根据洗分的次数换算成对应的玩家分数
     *
     * ⚠️ 协议说明：
     * - A5 00 C1：固定指令，表示全部洗分
     * - 洗分后，机台会返回洗了多少分
     * - 需要将机台分数换算成玩家钱包金额
     */
    private function handleWashPoint(string $uid, int $data, string $source, int $source_id): void
    {
        // ⚠️ 确保已登入（自动登入）
        $this->ensureLoggedIn();

        // ⚠️ 协议：A5 00 C1（固定00表示全部洗分）
        // 注意：$data参数在当前协议中不使用，因为是全部洗分
        // 如果协议支持按次数洗分，需要类似开分的逻辑

        $cmdData = 'a500c1';

        $sum1 = $this->calculateSUM1($cmdData);
        $sum2 = $this->calculateSUM2($cmdData, $sum1);

        $fullCmd = $cmdData . $sum1 . $sum2;

        $currentMachineScore = $this->machine_score ?? $this->point ?? 0;

        $this->log->info('[收账小卡-下分] 发送下分指令', [
            'machine_code' => $this->machine->code,
            'current_machine_score' => $currentMachineScore,
            'cmd' => $fullCmd,
            'note' => 'A5 00 C1 = 全部洗分',
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
                case self::CHECK_LOGIN:
                    $description = '查询登入状态';
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

    /**
     * 判断是否是心跳消息（B7前缀）
     *
     * ⚠️ 心跳总长度：46个hex字符（23字节）
     * B7(1B) B1(1B) 卡分(3B) B2(1B) 机分(3B) BA(1B) 押分(4B) BB(1B) 得分(4B) BD(1B) 状态(1B) SUM1(1B) SUM2(1B)
     */
    private function isHeartbeat(string $msg): bool
    {
        return substr(strtolower($msg), 0, 2) === self::HEARTBEAT && strlen($msg) >= 46;
    }

    /**
     * 处理心跳消息
     *
     * ⚠️ 格式：B7 B1 [卡分3B] B2 [机分3B] BA [押分4B] BB [得分4B] BD [状态1B] S1 S2
     *
     * 注意：心跳中开分卡和机台分数是3字节，不是4字节！
     */
    private function handleHeartbeat(string $msg): bool
    {
        try {
            // 验证校验和
            if (!$this->validateChecksum($msg)) {
                $this->log->error('[心跳] 校验失败', [
                    'machine_code' => $this->machine->code,
                    'msg' => strtoupper($msg),
                ]);
                return false;
            }

            // 解析心跳数据
            [$cardScore, $machineScore, $totalBet, $totalWin, $statusByte] =
                $this->parseHeartbeatData($msg);

            // 更新Redis
            $this->card_score = $cardScore;
            $this->machine_score = $machineScore;
            $this->total_bet = $totalBet;
            $this->total_win = $totalWin;

            // 解析状态字节
            $status = $this->parseStatusByte($statusByte);

            // ✅ Bug #11修复：同步更新is_login和login_status
            $loginValue = $status['logged_out'] ? 0 : 1;
            $this->login_status = $loginValue;
            $this->is_login = $loginValue;  // 同步更新，确保前端显示和后台判断一致

            $this->big_win = $status['big_win'] ? 1 : 0;
            $this->high_prob = $status['high_prob'] ? 1 : 0;
            $this->small_win = $status['small_win'] ? 1 : 0;

            // 检测现场跳码表
            if ($status['external_open'] || $status['external_wash']) {
                $this->handleExternalButton($status);
            }

            $this->log->info('[心跳] 机台状态更新', [
                'machine_code' => $this->machine->code,
                'card_score' => $cardScore,
                'machine_score' => $machineScore,
                'total_bet' => $totalBet,
                'total_win' => $totalWin,
                'status' => $status,
            ]);

            return true;

        } catch (Exception $e) {
            $this->log->error('[心跳] 处理失败', [
                'error' => $e->getMessage(),
                'msg' => strtoupper($msg),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * 解析心跳数据
     *
     * ⚠️ 格式：B7 B1 [卡分3B] B2 [机分3B] BA [押分4B] BB [得分4B] BD [状态1B] S1 S2
     *
     * 位置计算（hex字符位置）：
     * - B7: 0-2
     * - B1: 2-4, 开分卡分数: 4-10 (3字节=6hex)
     * - B2: 10-12, 机台分数: 12-18 (3字节=6hex)
     * - BA: 18-20, 总押分: 20-28 (4字节=8hex)
     * - BB: 28-30, 总得分: 30-38 (4字节=8hex)
     * - BD: 38-40, 状态字节: 40-42 (1字节=2hex)
     * - SUM1: 42-44, SUM2: 44-46
     */
    private function parseHeartbeatData(string $msg): array
    {
        try {
            // ✅ 修复：开分卡和机台分数是3字节，不是4字节
            $cardScore = $this->parseScore3Byte(substr($msg, 4, 6));      // B1后3字节
            $machineScore = $this->parseScore3Byte(substr($msg, 12, 6));  // B2后3字节
            $totalBet = $this->parseScore4Byte(substr($msg, 20, 8));      // BA后4字节
            $totalWin = $this->parseScore4Byte(substr($msg, 30, 8));      // BB后4字节
            $statusByte = substr($msg, 40, 2);                            // BD后1字节

            return [$cardScore, $machineScore, $totalBet, $totalWin, $statusByte];

        } catch (Exception $e) {
            $this->log->error('[心跳] 数据解析失败', [
                'error' => $e->getMessage(),
                'msg' => strtoupper($msg),
            ]);
            throw $e;
        }
    }

    /**
     * 解析3字节BCD分数（心跳专用）
     *
     * ⚠️ 心跳中的开分卡分数和机台分数是3字节BCD格式
     *
     * 示例：
     * 0B 14 1E → 0B=11万位 14=20千位 1E=30百位十位个位
     * 转换：11 20 30 → 112030
     *
     * @param string $scoreSection 6个hex字符（3字节）
     * @return int 分数值
     */
    private function parseScore3Byte(string $scoreSection): int
    {
        if (strlen($scoreSection) !== 6) {
            throw new Exception('3字节BCD格式错误，期望6个hex字符，实际：' . strlen($scoreSection));
        }

        $bytes = str_split($scoreSection, 2);

        // 每个字节转换为2位十进制数
        $byte1 = hexdec($bytes[0]);  // 十万、万
        $byte2 = hexdec($bytes[1]);  // 千、百
        $byte3 = hexdec($bytes[2]);  // 十、个

        // 组合成最终数值
        return ($byte1 * 10000) + ($byte2 * 100) + $byte3;
    }

    /**
     * 解析状态字节
     * bit7: 登出（1=登出，0=登入）
     * bit5: 现场跳开分表
     * bit4: 现场跳洗分表
     * bit2: 小当
     * bit1: 高确
     * bit0: 大当
     */
    private function parseStatusByte(string $byte): array
    {
        $val = hexdec($byte);

        return [
            'logged_out' => ($val & 0x80) > 0,      // bit7
            'external_open' => ($val & 0x20) > 0,   // bit5
            'external_wash' => ($val & 0x10) > 0,   // bit4
            'small_win' => ($val & 0x04) > 0,       // bit2
            'high_prob' => ($val & 0x02) > 0,       // bit1
            'big_win' => ($val & 0x01) > 0,         // bit0
        ];
    }

    /**
     * 处理外部按钮（现场跳码表）
     */
    private function handleExternalButton(array $status): void
    {
        $lockKey = "external_button_slot_{$this->machine->id}";
        $lock = Locker::lock($lockKey, 10);

        try {
            if (!$lock->acquire()) {
                $this->log->warning('[外部按钮] 获取锁失败', [
                    'machine_code' => $this->machine->code,
                ]);
                return;
            }

            $gamingUserId = $this->gaming_user_id ?? 0;

            // 检测开分表
            if ($status['external_open']) {
                $this->external_open = 1;
                $this->processExternalTable('open', $gamingUserId);
            }

            // 检测洗分表
            if ($status['external_wash']) {
                $this->external_wash = 1;
                $this->processExternalTable('wash', $gamingUserId);
            }

        } finally {
            $lock->release();
        }
    }

    /**
     * 处理外部码表（查询并记录）
     *
     * ⚠️ 修复Bug #7：统一调用processCounterChange，避免与路径1（EA C4）逻辑不一致
     */
    private function processExternalTable(string $type, int $userId): void
    {
        try {
            // 查询详细账目获取码表数值
            $detail = $this->queryDetailSync();

            $fieldName = $type === 'open' ? 'open_table' : 'wash_table';
            $newTable = $detail[$fieldName] ?? 0;
            $oldTable = $this->$fieldName ?? 0;

            // ✅ 修复：调用processCounterChange，统一两条路径的逻辑
            // 这样可以：
            // 1. 避免重复记录（10秒去重窗口）
            // 2. 正确处理故障排除后的归零（check_flag检查）
            // 3. 检测异常减少（告警和锁定机台）
            // 4. 正确计算打码量（betAmount字段）
            $timestamp = time();
            $result = $this->processCounterChange($type, $oldTable, $newTable, $timestamp);

            if ($result['should_update']) {
                $this->$fieldName = $newTable;
            }

            if ($result['recorded']) {
                $this->log->warning('[外部按钮-心跳触发] 检测到现场跳码表', [
                    'machine_code' => $this->machine->code,
                    'type' => $type,
                    'old_table' => $oldTable,
                    'new_table' => $newTable,
                    'increment' => $newTable - $oldTable,
                    'path' => 'B7 heartbeat',
                ]);
            }

        } catch (Exception $e) {
            $this->log->error('[外部按钮] 查询码表失败', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ⚠️ 已删除：recordExternalButtonLog() 方法
    // 原因：该方法与recordExternalButtonOperation()逻辑不一致，导致Bug #7
    // 修复：processExternalTable()现在统一调用processCounterChange()
    //      → recordExternalButtonOperation() → createExternalButtonGameLog()
    // 这样两条路径（EA C4和B7心跳）的逻辑完全一致

    /**
     * 解析4字节BCD分数
     * 格式: AA BB CC DD
     * 值 = AA×1000000 + BB×10000 + CC×100 + DD
     */
    private function parseScore4Byte(string $scoreSection): int
    {
        $bytes = str_split($scoreSection, 2);
        if (count($bytes) !== 4) {
            throw new Exception('4字节BCD分数格式错误: ' . $scoreSection);
        }

        return (hexdec($bytes[0]) * 1000000)
            + (hexdec($bytes[1]) * 10000)
            + (hexdec($bytes[2]) * 100)
            + hexdec($bytes[3]);
    }

    /**
     * 分数转4字节BCD
     */
    public static function scoreToBytes4(int $score): array
    {
        $score = max(0, min(self::MAX_SCORE, $score));

        $tenMillions = intval($score / 1000000);
        $tenThousands = intval(($score % 1000000) / 10000);
        $hundreds = intval(($score % 10000) / 100);
        $ones = $score % 100;

        return [$tenMillions, $tenThousands, $hundreds, $ones];
    }

    /**
     * 验证SUM1/SUM2校验和
     */
    private function validateChecksum(string $data): bool
    {
        if (strlen($data) < 4) {
            return false;
        }

        $sum1 = substr($data, -4, 2);
        $sum2 = substr($data, -2, 2);
        $payload = substr($data, 0, -4);

        $calculatedSUM1 = $this->calculateSUM1($payload);
        $calculatedSUM2 = $this->calculateSUM2($payload, $calculatedSUM1);

        return strtolower($sum1) == strtolower($calculatedSUM1)
            && strtolower($sum2) == strtolower($calculatedSUM2);
    }

    /**
     * 查询详细账目（同步方法）
     */
    private function queryDetailSync(): array
    {
        $uid = $this->machine->domain . ':' . $this->machine->port;
        $cmd = $this->createCmd(self::QUERY_ACCOUNT);

        $beforeTime = $this->setActionVersion(self::QUERY_ACCOUNT);
        Gateway::sendToUid($uid, hex2bin($cmd));

        $timeout = 1000000;  // 1秒
        $sleep = 50000;      // 50ms
        $elapsed = 0;

        while ($elapsed < $timeout) {
            $actionTime = $this->getActionVersion(self::QUERY_ACCOUNT);
            if ($actionTime > $beforeTime) {
                return [
                    'open_table' => $this->open_table ?? 0,
                    'wash_table' => $this->wash_table ?? 0,
                    'card_score' => $this->card_score ?? 0,
                    'machine_score' => $this->machine_score ?? 0,
                ];
            }

            usleep($sleep);
            $elapsed += $sleep;
        }

        throw new Exception('查询详细账目超时');
    }

    /**
     * 检查登入状态
     */
    private function checkLoginStatus(): bool
    {
        return ($this->login_status ?? 0) === 1;
    }

    /**
     * 登入机台
     */
    private function loginMachine(): bool
    {
        $uid = $this->machine->domain . ':' . $this->machine->port;

        // 发送登入指令
        $cmd = $this->createCmd(self::LOGIN);
        Gateway::sendToUid($uid, hex2bin($cmd));

        // 等待回复
        usleep(100000);  // 等待100ms

        // 验证登入成功
        return $this->checkLoginStatus();
    }

    /**
     * 确保已登入（发送指令前调用）
     */
    private function ensureLoggedIn(): void
    {
        if (!$this->checkLoginStatus()) {
            $this->log->warning('[登入检查] 机台未登入，尝试自动登入', [
                'machine_code' => $this->machine->code,
            ]);

            $success = $this->loginMachine();
            if (!$success) {
                throw new Exception('机台登入失败，请检查机台状态');
            }

            $this->log->info('[登入检查] 自动登入成功', [
                'machine_code' => $this->machine->code,
            ]);
        }
    }

    /**
     * 创建指令（添加SUM1/SUM2校验）
     */
    private function createCmd(string $cmd, int $data = 0): string
    {
        $hexString = '';
        if (!empty($data)) {
            $bytes = self::scoreToBytes4($data);
            $hexString = $this->toHexString($bytes);
        }

        $cmd .= $hexString;
        $sum1 = $this->calculateSUM1($cmd);
        $sum2 = $this->calculateSUM2($cmd, $sum1);

        $this->log->debug('[创建指令]', [
            'cmd' => strtoupper($cmd . $sum1 . $sum2)
        ]);

        return $cmd . $sum1 . $sum2;
    }

    /**
     * 字节数组转十六进制字符串
     */
    private function toHexString(array $bytes): string
    {
        return implode('', array_map(function ($b) {
            return strtoupper(str_pad(dechex($b), 2, '0', STR_PAD_LEFT));
        }, $bytes));
    }
}
