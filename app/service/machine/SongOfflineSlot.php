<?php

namespace app\service\machine;

use app\model\AdminUser;
use app\model\Machine;
use app\model\Notice;
use app\model\PlayerGameRecord;
use Exception;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Str;
use support\Cache;
use support\Log;
use Workerman\Timer;
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
 * - 读取分数: EA C4 → 回复开分码表、洗分码表、开分卡分数、机台分数
 * - 读取押分: EA D8 → 回复总押分数、总赢分数
 * - 读取状态: EA D4 → 回复开分状态、洗分状态、转数
 * - 登入: EA C3 → 回复A7 C3（表示登入中，必须登入才能上下分）
 * - 登出: EA C5 → 回复A7 C5（表示登出中）
 * - 上分: A5 XX C0 SUM1 SUM2（XX=开分次数，固定100分为单位）
 * - 下分: A5 00 C1 SUM1 SUM2（固定00表示全部洗分）
 * - 清除历史记录: EA DE
 * - 故排: A3 70 05 E0 F8 CE → 回复A3 F8 33 → EF/EE
 * - SSR讯号10秒: EA EC（预留给smart-slot移出按钮用）
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
 * ========== 基础字段（与线上版一致） ==========
 * @property int $auto 自动状态（0=停止 1=启动）
 * @property int $reward_status 开奖状态（0=未开奖 1=开奖中）
 * @property int $play_start_time 开始游戏时间
 * @property int $gaming_user_id 游戏中玩家ID
 * @property int $gaming 是否游戏中
 * @property int $point 当前分数（= machine_score，机台上面的分数）
 * @property int $score 当前得分（= card_score，开分卡上的分数）
 * @property int $bet 当前押分
 * @property int $win 总赢分数（= total_win）
 * @property int $last_play_time 最后游戏时间
 * @property int $action_time 操作时间
 * @property int $now_turn 当前累积转数
 * @property int $has_lock 机台锁定状态
 *
 * ========== 线下版特有字段（GD收账小卡协议） ==========
 * @property int $login_status 登入状态（0=未登入 1=已登入，心跳BD.b7取反）
 * @property int $card_score 开分卡分数（心跳B1字段，3字节BCD）
 * @property int $machine_score 机台分数（心跳B2字段，3字节BCD）
 * @property int $last_card_score 上一次心跳的开分卡分数（用于计算线下洗分金额）
 * @property int $total_bet 总押分数（心跳BA字段，4字节BCD）
 * @property int $total_win 总得分数（心跳BB字段，4字节BCD）
 * @property int $open_table 开分码表（外部开分累计金额，查询账目EA C4回复）
 * @property int $wash_table 洗分码表（外部洗分累计金额，查询账目EA C4回复）
 * @property int $big_win 大当状态（心跳BD.b0）
 * @property int $high_prob 高确状态（心跳BD.b1）
 * @property int $small_win 小当状态（心跳BD.b2）
 * @property int $external_open 现场跳开分表（心跳BD.b5）
 * @property int $external_wash 现场跳洗分表（心跳BD.b4）
 * @property int $turn 当前转数（查询机台情况EA D4回复）
 * @property int $return_count 回补次数（查询机台情况EA D4回复）
 * @property int $table_miss 码表少跳数（查询机台情况EA D4回复）
 *
 * ========== 兼容性字段（已废弃，保留映射） ==========
 * @property int $is_login （废弃→login_status）
 * @property int $external_open_count （废弃→open_table）
 * @property int $external_wash_count （废弃→wash_table）
 * @property int $open_card_point （废弃→card_score）
 *
 * @package app\service\machine
 * @author Claude Code
 * @date 2026-09-05
 */
class SongOfflineSlot extends MachineServices implements BaseMachine
{
    // ========================================
    // 查询指令（统一命名规范：READ_*）
    // ========================================
    const READ_SCORE = 'eac4';              // 读取分数（查询账目：开分码表、洗分码表、开分卡分数、机台分数）
    const READ_BET = 'ead8';                // 读取押分（查询总押分+总得分）
    const READ_STATUS = 'ead4';             // 读取状态（查询机台情况：开分状态、洗分状态、转数）

    // ========================================
    // 登入/登出指令（线下版特有）
    // ========================================
    const LOGIN = 'eac3';                   // 登入（機板回傳 A7 C3 表示登入中）
    const LOGOUT = 'eac5';                  // 登出（機板回傳 A7 C5 表示登出中）

    // ========================================
    // 资金操作指令
    // ========================================
    const OPEN_POINT = 'a5';                // 上分前缀（需拼接次数：A5 XX C0 SUM1 SUM2）
    const OPEN_ANY_POINT = 'a5';            // 开任意分数（别名，兼容gk_api/gk_admin统一调用）
    const WASH_POINT = 'a500c1';            // 下分（全部洗分：A5 00 C1 SUM1 SUM2）

    // ========================================
    // 管理指令（统一命名：ALL_DOWN/CHECK）
    // ========================================
    const ALL_DOWN = 'eade';                // 清除历史记录（清除开洗分账+回补数）
    const CHECK = 'a37005e0f8ce';           // 故排（归0机板，固定指令）
    const SSR_SIGNAL = 'eaec';              // SSR讯号10秒（线下特有：预留给smart-slot移出按钮）

    // ========================================
    // 心跳和开机（线下版特有）
    // ========================================
    const TESTING = 'b7';                   // 心跳标识
    const BOOT = 'fa';                      // 开机标识
    const POWER_ON = 'fah';                 // 开机信号（机版主动发送）

    // ========================================
    // 回复头部（内部识别用）
    // ========================================
    const REPLY_A3 = 'a3';                  // 归0回复头部
    const REPLY_A5 = 'a5';                  // 操作回复头部
    const REPLY_A6 = 'a6';                  // 账目回复头部
    const REPLY_A7 = 'a7';                  // 状态回复头部

    // ========================================
    // 回复状态
    // ========================================
    const RESET_COMPLETE = 'ef';            // 完整归0完成（E1时发归0指令）
    const RESET_CLEAR = 'ee';               // 清除账目完成（未有E1时发归0指令）

    // ========================================
    // 状态标志（异常状态检测用）
    // ========================================
    const FLAG_REWARDING = 'cb';            // 开奖中（开分码表异常，正常时为C9）
    const FLAG_FAULT = 'ee';                // 故障（开分卡分数异常，正常时为E9）

    // ========================================
    // 业务限制常量
    // ========================================
    const MAX_SCORE = 99999999;             // 最大分数（4字节BCD = 99,999,999）
    const OPEN_UNIT = 100;                  // 上分单位（固定100分）
    const MAX_OPEN_TIMES = 255;             // 最大开分次数（1字节 = 0-255）

    public $cacheData = [];
    public $expirationTime = 5000000;  // 5秒超时
    public $log = null;

    // ✅ 指令测试支持：记录原始指令码（用于设置正确的 actionVersion）
    private $originalCmd = null;

    // ✅ TCP分包处理：消息缓冲区（静态，所有实例共享）
    private static $msgBuffer = [];
    private static $bufferLastClearTime = [];

    public function __construct(Machine $machine, $lang = 'zh_CN')
    {
        $this->machine = $machine;
        $this->cacheKey = self::CACHE_PREFIX . $this->machine->id;
        $this->cacheDataKey = self::MACHINE_DATA_PREFIX . $this->machine->id;

        // Redis缓存字段列表
        $this->cacheDataKeyArr = [
            // ========== 基础字段（与线上版一致） ==========
            $this->cacheDataKey . '_auto',                 // 自动状态
            $this->cacheDataKey . '_reward_status',        // 开奖状态
            $this->cacheDataKey . '_play_start_time',      // 开始游戏时间
            $this->cacheDataKey . '_gaming_user_id',       // 游戏中玩家ID
            $this->cacheDataKey . '_gaming',               // 是否游戏中
            $this->cacheDataKey . '_point',                // 当前分数（= machine_score）
            $this->cacheDataKey . '_score',                // 当前得分（= card_score）
            $this->cacheDataKey . '_bet',                  // 当前押分
            $this->cacheDataKey . '_win',                  // 总赢分数（= total_win）
            $this->cacheDataKey . '_last_play_time',       // 最后游戏时间
            $this->cacheDataKey . '_action_time',          // 操作时间
            $this->cacheDataKey . '_now_turn',             // 当前累积转数（= turn）
            $this->cacheDataKey . '_has_lock',             // 机台锁定状态

            // ========== 线下版特有字段（GD收账小卡协议） ==========
            $this->cacheDataKey . '_login_status',         // 登入状态（心跳BD.b7取反）
            $this->cacheDataKey . '_card_score',           // 开分卡分数（心跳B1字段）
            $this->cacheDataKey . '_last_card_score',      // 上一次心跳的开分卡分数（用于计算线下洗分金额）
            $this->cacheDataKey . '_machine_score',        // 机台分数（心跳B2字段）
            $this->cacheDataKey . '_total_bet',            // 总押分数（心跳BA字段）
            $this->cacheDataKey . '_total_win',            // 总得分数（心跳BB字段）
            $this->cacheDataKey . '_open_table',           // 开分码表（查询账目回复）
            $this->cacheDataKey . '_wash_table',           // 洗分码表（查询账目回复）
            $this->cacheDataKey . '_big_win',              // 大当状态（心跳BD.b0）
            $this->cacheDataKey . '_high_prob',            // 高确状态（心跳BD.b1）
            $this->cacheDataKey . '_small_win',            // 小当状态（心跳BD.b2）
            $this->cacheDataKey . '_external_open',        // 现场跳开分表（心跳BD.b5）
            $this->cacheDataKey . '_external_wash',        // 现场跳洗分表（心跳BD.b4）
            $this->cacheDataKey . '_turn',                 // 转数（查询机台情况回复）
            $this->cacheDataKey . '_return_count',         // 回补次数（查询机台情况回复）
            $this->cacheDataKey . '_table_miss',           // 码表少跳数（查询机台情况回复）
        ];

        // 推送到前端的关键字段（WebSocket实时同步）
        $this->machineInfo = [
            'auto',                // 自动状态
            'reward_status',       // 开奖状态
            'point',               // 当前分数（= machine_score）
            'bet',                 // 当前押分
            'win',                 // 总赢分数（= total_win）
            'has_lock',            // 机台锁定状态
            'is_login',            // 登入状态（兼容旧字段，推荐使用login_status）
            'login_status',        // 登入状态（新字段）
            'big_win',             // 大当状态（线下版特有）
            'high_prob',           // 高确状态（线下版特有）
            'small_win',           // 小当状态（线下版特有）
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
     * ✅ 支持TCP分包：消息可能被拆分成多个TCP包，需要缓冲拼接
     *
     * @param string $msg 收到的消息（小写十六进制）
     * @return bool
     */
    public function handleMsg(string $msg): bool
    {

        try {
            $msg = strtolower(trim($msg));
            $machineId = $this->machine->id;

            // ✅ 步骤0：TCP分包处理 - 拼接到缓冲区
            if (!isset(self::$msgBuffer[$machineId])) {
                self::$msgBuffer[$machineId] = '';
                self::$bufferLastClearTime[$machineId] = time();
            }
            self::$msgBuffer[$machineId] .= $msg;
            $buffer = self::$msgBuffer[$machineId];

            // ✅ 尝试从缓冲区提取并处理完整消息
            $processed = false;

            // ⚠️ 第一步：检查机板开机标识（FAH）
            // ✅ 修复：fah 在前（长的优先匹配），避免 "fah" 被识别为 "fa"
            if (preg_match('/^(fah|fa)/', $buffer, $matches)) {
                $bootMsg = $matches[0];
                $this->log->info('[收账小卡-开机] 机版开机', [
                    'machine_code' => $this->machine->code,
                    'msg' => strtoupper($bootMsg),
                ]);
                // 开机后应该重新登入
                $this->is_login = 0;
                $this->login_status = 0;

                // ✅ 修复：检查残留数据合法性，防止脏数据污染
                $remaining = substr($buffer, strlen($bootMsg));
                if (strlen($remaining) > 0) {
                    $remainingHeader = substr($remaining, 0, 2);
                    // 检查是否是合法的消息头
                    if (!in_array($remainingHeader, ['a3', 'a5', 'a6', 'a7', 'b7', 'fa', 'e1'])) {
                        // 非法消息头 → 可能是脏数据 → 清空
                        $this->log->warning('[TCP分包] 清除非法残留数据', [
                            'machine_code' => $this->machine->code,
                            'remaining' => strtoupper($remaining),
                            'remaining_size' => strlen($remaining),
                        ]);
                        $remaining = '';
                    }
                }
                self::$msgBuffer[$machineId] = $remaining;
                return true;
            }

            // ⚠️ 第二步：判断并处理心跳消息（B7前缀，46字符）
            if (preg_match('/^(b7[0-9a-f]{44})/', $buffer, $matches)) {
                $heartbeat = $matches[0];
                // ✅ 直接处理，删除冗余的 isHeartbeat() 检查（消除死锁风险）
                $processed = $this->handleHeartbeat($heartbeat);

                // ✅ 只有处理成功才更新缓冲区（防止消息丢失）
                if ($processed) {
                    // ✅ P0修复：检查残留数据合法性，防止脏数据污染
                    $remaining = substr($buffer, 46);
                    if (strlen($remaining) > 0) {
                        $remainingHeader = substr($remaining, 0, 2);
                        // 检查是否是合法的消息头
                        if (!in_array($remainingHeader, ['a3', 'a5', 'a6', 'a7', 'b7', 'fa', 'e1'])) {
                            // 非法消息头 → 可能是脏数据 → 清空
                            $this->log->warning('[TCP分包] 清除非法残留数据', [
                                'machine_code' => $this->machine->code,
                                'remaining' => strtoupper($remaining),
                                'remaining_size' => strlen($remaining),
                            ]);
                            $remaining = '';
                        }
                    }
                    self::$msgBuffer[$machineId] = $remaining;
                } else {
                    // ⚠️ 处理失败，保留消息在缓冲区待重试
                    $this->log->warning('[TCP分包] 心跳处理失败，保留在缓冲区', [
                        'machine_code' => $this->machine->code,
                        'heartbeat' => strtoupper(substr($heartbeat, 0, 20)) . '...',
                    ]);
                }
                return $processed;
            }

            // ⚠️ 第2.5步：判断并处理账目查询回复（A6前缀，44字符）
            if (preg_match('/^(a6[0-9a-f]{42})/', $buffer, $matches)) {
                $accountMsg = $matches[0];
                $processed = $this->handleAccountReply($accountMsg);

                // ✅ 只有处理成功才更新缓冲区（防止消息丢失）
                if ($processed) {
                    // ✅ P1修复：检查残留数据合法性
                    $remaining = substr($buffer, 44);
                    if (strlen($remaining) > 0) {
                        $remainingHeader = substr($remaining, 0, 2);
                        if (!in_array($remainingHeader, ['a3', 'a5', 'a6', 'a7', 'b7', 'fa', 'e1'])) {
                            $this->log->warning('[TCP分包] 清除非法残留数据', [
                                'machine_code' => $this->machine->code,
                                'remaining' => strtoupper($remaining),
                                'remaining_size' => strlen($remaining),
                            ]);
                            $remaining = '';
                        }
                    }
                    self::$msgBuffer[$machineId] = $remaining;
                } else {
                    // ⚠️ 处理失败，保留消息在缓冲区待重试
                    $this->log->warning('[TCP分包] 账目查询回复处理失败，保留在缓冲区', [
                        'machine_code' => $this->machine->code,
                        'account_msg' => strtoupper(substr($accountMsg, 0, 20)) . '...',
                    ]);
                }
                return $processed;
            }

            // ⚠️ 第三步：如果缓冲区不是心跳或A6，尝试处理其他消息
            // 如果缓冲区开头不是B7或A6，说明可能是其他回复消息
            if (substr($buffer, 0, 2) !== 'b7' && substr($buffer, 0, 2) !== 'a6') {
                // ✅ P2修复：检测消息长度，处理粘包
                $header = substr($buffer, 0, 2);
                $msgLength = $this->getMessageLength($header, $buffer);

                if ($msgLength > 0 && strlen($buffer) >= $msgLength) {
                    // 提取完整消息（暂不更新缓冲区）
                    $msg = substr($buffer, 0, $msgLength);
                } else {
                    // 不完整，等待更多数据
                    return false;
                }
            } else {
                // 等待更多数据（心跳或A6不完整）
                return false;
            }

            // 识别消息类型
            $header = substr($msg, 0, 2);
            $processed = false;

            // ✅ Bug #16修复：E1错误识别（可能是连续的E1，如e1e1e1e1e1e1）
            if (preg_match('/^(e1)+$/i', $msg)) {
                $this->log->error('[收账小卡-锁定] 记忆体异常需归0，机台已锁定', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'msg' => strtoupper($msg),
                    'e1_count' => strlen($msg) / 2,
                    'old_has_lock' => $this->has_lock ?? 0,
                    'new_has_lock' => 1,
                    'gaming_user_id' => $this->gaming_user_id ?? null,
                    'reason' => 'E1错误-记忆体异常',
                ]);
                $this->has_lock = 1;
                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->gaming_user_id);
                $processed = true;
            }
            // 归0回复（EF=完整归0，EE=清除账目）
            elseif ($msg === self::RESET_COMPLETE || $msg === self::RESET_CLEAR) {
                $resetType = $msg === self::RESET_COMPLETE ? '完整归0' : '清除账目';
                $oldHasLock = $this->has_lock ?? 0;

                // ✅ 归0成功后自动解锁机台
                if ($oldHasLock == 1) {
                    $this->has_lock = 0;
                    $this->log->info('[收账小卡-解锁] 归0成功，机台已自动解锁', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'reset_type' => $resetType,
                        'old_has_lock' => $oldHasLock,
                        'new_has_lock' => 0,
                        'msg' => strtoupper($msg),
                    ]);
                } else {
                    $this->log->info('[收账小卡-归0] 归0完成', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'reset_type' => $resetType,
                        'has_lock' => $oldHasLock,
                        'msg' => strtoupper($msg),
                    ]);
                }

                $processed = true;
            }
            // 根据头部识别消息类型
            else {
                switch ($header) {
                    case self::REPLY_A6:
                        $processed = $this->handleAccountReply($msg);
                        break;
                    case self::REPLY_A7:
                        $processed = $this->handleStatusReply($msg);
                        break;
                    case self::REPLY_A5:
                        $processed = $this->handleActionReply($msg);
                        break;
                    case self::REPLY_A3:
                        $processed = $this->handleResetReply($msg);
                        break;
                    default:
                        $this->log->warning('[收账小卡] 未识别的消息类型', [
                            'machine_code' => $this->machine->code,
                            'msg' => $msg,
                            'header' => $header,
                        ]);
                        $processed = false;
                        break;
                }
            }

            // ✅ 只有处理成功才更新缓冲区（防止消息丢失）
            if ($processed) {
                $remaining = substr($buffer, $msgLength);
                // 检查残留数据合法性
                if (strlen($remaining) > 0) {
                    $remainingHeader = substr($remaining, 0, 2);
                    if (!in_array($remainingHeader, ['a3', 'a5', 'a6', 'a7', 'b7', 'fa', 'e1'])) {
                        $this->log->warning('[TCP分包] 清除非法残留数据', [
                            'machine_code' => $this->machine->code,
                            'remaining' => strtoupper($remaining),
                            'remaining_size' => strlen($remaining),
                        ]);
                        $remaining = '';
                    }
                }
                self::$msgBuffer[$machineId] = $remaining;
            } else {
                // ⚠️ 处理失败，保留消息在缓冲区待重试
                $this->log->warning('[TCP分包] 消息处理失败，保留在缓冲区', [
                    'machine_code' => $this->machine->code,
                    'msg_type' => strtoupper($header),
                    'msg' => strtoupper(substr($msg, 0, 20)) . '...',
                ]);
            }

            return $processed;

        } catch (\Exception $e) {
            $this->log->error('[收账小卡] 消息处理错误', [
                'machine_code' => $this->machine->code,
                'msg' => $msg ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return false;
        } finally {
            // ✅ 定期清理缓冲区（防止内存泄漏）
            if (isset(self::$msgBuffer[$machineId])) {
                $now = time();
                $bufferSize = strlen(self::$msgBuffer[$machineId]);

                // 条件1：缓冲区超过200字符（异常情况）
                if ($bufferSize > 200) {
                    $this->log->warning('[TCP分包] 缓冲区过大，清空', [
                        'machine_code' => $this->machine->code,
                        'buffer_size' => $bufferSize,
                        'buffer_content' => strtoupper(substr(self::$msgBuffer[$machineId], 0, 50)) . '...',
                    ]);
                    self::$msgBuffer[$machineId] = '';
                    self::$bufferLastClearTime[$machineId] = $now;
                }

                // 条件2：超过10秒未清理（可能有残留数据）
                if (isset(self::$bufferLastClearTime[$machineId])
                    && ($now - self::$bufferLastClearTime[$machineId]) > 10) {
                    if ($bufferSize > 0) {
                        $this->log->debug('[TCP分包] 定期清理缓冲区', [
                            'machine_code' => $this->machine->code,
                            'buffer_size' => $bufferSize,
                        ]);
                    }
                    self::$msgBuffer[$machineId] = '';
                    self::$bufferLastClearTime[$machineId] = $now;
                }
            }
        }
    }

    /**
     * 处理账目查询回复（A6 C9 ... CA ... E9 ... SUM1 SUM2）
     */
    private function handleAccountReply(string $msg): bool
    {
        // ✅ 入口日志
        $this->log->info('[账目查询] 处理开始', [
            'machine_code' => $this->machine->code,
            'msg_len' => strlen($msg),
        ]);

        // A6 C9 05 14 31 0B CA 10 00 63 1E E9 00 04 03 02 00 07 28 00 SUM1 SUM2
        // 最小长度：2 + 2 + 8 + 2 + 8 + 2 + 8 + 8 + 2 + 2 = 44字符

        if (strlen($msg) < 44) {
            $this->log->error('[账目查询] 回复长度不足', [
                'machine_code' => $this->machine->code,
                'msg' => strtoupper($msg),
                'len' => strlen($msg),
                'expected_min' => 44,
            ]);
            return false;
        }

        // ✅ 使用公共方法校验和验证
        if (!$this->validateMessageChecksum($msg, '账目查询')) {
            return false;
        }

        // ✅ 使用提取的方法解析数据
        $data = $this->parseAccountData($msg);

        // ✅ 使用提取的方法检测标志
        $flags = $this->detectAccountFlags($data['open_flag'], $data['card_flag']);

        // 记录解析结果
        $this->log->info('[收账小卡-账目] 查询回复', [
            'machine_code' => $this->machine->code,
            'open_table' => $data['open_table'],
            'wash_table' => $data['wash_table'],
            'card_score' => $data['card_score'],
            'machine_score' => $data['machine_score'],
            'is_rewarding' => $flags['is_rewarding'],
            'has_fault' => $flags['has_fault'],
            'card_flag' => $data['card_flag'],
        ]);

        // ✅ 检测Smart卡通讯故障（card_flag="EE"）
        if ($flags['has_fault']) {
            return $this->handleSmartCardCommunicationFault($data);
        }

        // ✅ 通讯正常，清除重试计数
        $retryKey = $this->cacheDataKey . '_account_retry_count';
        Cache::delete($retryKey);

        // ✅ 使用提取的方法更新状态
        $this->updateAccountData($data, $flags);

        // 处理外部按钮计数器变化（类似SongOfflineJackpot的B5/B7处理）
        $now = time();
        $oldOpenTable = $this->open_table ?? 0;  // ⚠️ 读取Redis旧值
        $oldWashTable = $this->wash_table ?? 0;  // ⚠️ 读取Redis旧值

        // 处理开分码表变化
        if ($data['open_table'] != $oldOpenTable) {
            $result = $this->processCounterChange('open', $oldOpenTable, $data['open_table'], $now);
            if ($result['should_update']) {
                $this->open_table = $data['open_table'];              // ⚠️ 新字段
                $this->external_open_count = $data['open_table'];     // 旧字段（兼容性）
            }

            if ($result['recorded']) {
                $this->log->info('[收账小卡-开分码表] 变化已记录', [
                    'machine_code' => $this->machine->code,
                    'old' => $oldOpenTable,
                    'new' => $data['open_table'],
                    'reason' => $result['reason'] ?? '',
                ]);
            }
        }

        // 处理洗分码表变化
        if ($data['wash_table'] != $oldWashTable) {
            $result = $this->processCounterChange('wash', $oldWashTable, $data['wash_table'], $now);
            if ($result['should_update']) {
                $this->wash_table = $data['wash_table'];              // ⚠️ 新字段
                $this->external_wash_count = $data['wash_table'];     // 旧字段（兼容性）
            }

            if ($result['recorded']) {
                $this->log->info('[收账小卡-洗分码表] 变化已记录', [
                    'machine_code' => $this->machine->code,
                    'old' => $oldWashTable,
                    'new' => $data['wash_table'],
                    'reason' => $result['reason'] ?? '',
                ]);
            }
        }

        // ✅ 更新版本号，表示收到回复
        $this->setActionVersion(self::READ_SCORE);

        return true;
    }

    /**
     * 处理状态查询回复（A7 ...）
     */
    private function handleStatusReply(string $msg): bool
    {
        // ✅ 入口日志
        $this->log->info('[状态查询] 处理开始', [
            'machine_code' => $this->machine->code,
            'msg_len' => strlen($msg),
        ]);

        // ✅ 修复：先检查最小长度（A7 + 类型 = 4字符）
        if (strlen($msg) < 4) {
            $this->log->error('[状态查询] 回复长度不足', [
                'machine_code' => $this->machine->code,
                'msg' => strtoupper($msg),
                'len' => strlen($msg),
                'expected_min' => 4,
            ]);
            return false;
        }

        $type = substr($msg, 2, 2);

        switch ($type) {
            case 'c3': // A7 C3 登入回复（简短回复，无校验和，只有4字节）
            case 'c5': // A7 C5 登出回复（简短回复，无校验和，只有4字节）
                // ✅ 修复：登入/登出回复只有 4 字节，不包含 SUM1/SUM2
                // 协议说明：EA C3 → 回复 A7 C3（表示登入中）
                if (strlen($msg) !== 4) {
                    $this->log->error('[登入登出] 回复长度异常', [
                        'machine_code' => $this->machine->code,
                        'msg' => strtoupper($msg),
                        'len' => strlen($msg),
                        'expected' => 4,
                    ]);
                    return false;
                }

                // 更新状态
                if ($type === 'c3') {
                    // 登入成功
                    $oldLoginStatus = $this->login_status ?? 0;
                    $this->logFieldChange('登入', 'login_status', $oldLoginStatus, 1, '登入成功');

                    $this->is_login = 1;
                    $this->login_status = 1;

                    $this->logOperation('登入', '登入操作', ['result' => 'A7 C3'], true);
                    $this->setActionVersion(self::LOGIN);
                } else {
                    // 登出成功
                    $oldLoginStatus = $this->login_status ?? 1;
                    $this->logFieldChange('登出', 'login_status', $oldLoginStatus, 0, '登出成功');

                    $this->is_login = 0;
                    $this->login_status = 0;

                    $this->logOperation('登出', '登出操作', ['result' => 'A7 C5'], true);
                    $this->log->info('[收账小卡-登出] 登出成功（A7 C5）', [
                        'machine_code' => $this->machine->code,
                    ]);
                    $this->setActionVersion(self::LOGOUT);
                }
                break;

            case 'd8': // 读取押分回复
                return $this->handleTotalReply($msg);

            case 'd2':  // 开分完成
            case 'd3':  // 开分中
            case 'd6':  // 洗分完成
            case 'd7':  // 洗分中
                // 读取状态回复（⚠️ 当前简化处理，未解析具体状态）
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
     * 处理归0回复（A3 F8 33）
     */
    private function handleResetReply(string $msg): bool
    {
        if (strlen($msg) < 6) {
            $this->log->error('[收账小卡] 归0回复长度不足', [
                'machine_code' => $this->machine->code,
                'msg' => $msg,
            ]);
            return false;
        }

        $status = substr($msg, 2, 2);
        $version = substr($msg, 4, 2);

        if ($status === 'f8') {
            $this->log->info('[收账小卡-归0] 归0指令已收到', [
                'machine_code' => $this->machine->code,
                'version' => $version,
            ]);

            // ✅ 更新版本号，表示收到回复
            $this->setActionVersion(self::CHECK);
            return true;
        }

        $this->log->warning('[收账小卡] 未识别的A3归0回复', [
            'machine_code' => $this->machine->code,
            'msg' => $msg,
            'status' => $status,
        ]);
        return false;
    }

    /**
     * 处理总玩+总赢回复（A7 D8 00 00 00 00 D9 00 00 00 00 SUM1 SUM2）
     *
     * ⚠️ 协议说明：
     * - D8 = 一般状态的总押分
     * - D7 = 开奖中状态的总押分
     */
    private function handleTotalReply(string $msg): bool
    {
        // A7 D8 00 00 00 00 D9 00 00 00 00 SUM1 SUM2
        // 最小长度：2 + 2 + 8 + 2 + 8 + 2 + 2 = 26字符

        if (strlen($msg) < 26) {
            $this->log->error('[收账小卡] 总押总赢回复长度不足', [
                'machine_code' => $this->machine->code,
                'msg' => $msg,
                'len' => strlen($msg),
            ]);
            return false;
        }

        // ✅ 修复：添加校验SUM1和SUM2
        $dataWithoutSum = substr($msg, 0, -4);
        $receivedSum1 = substr($msg, -4, 2);
        $receivedSum2 = substr($msg, -2, 2);

        $calculatedSum1 = $this->calculateSUM1($dataWithoutSum);
        $calculatedSum2 = $this->calculateSUM2($dataWithoutSum, $calculatedSum1);

        if ($receivedSum1 !== $calculatedSum1 || $receivedSum2 !== $calculatedSum2) {
            $this->log->error('[收账小卡] 总押总赢回复校验失败', [
                'machine_code' => $this->machine->code,
                'msg' => $msg,
                'expected_sum1' => $calculatedSum1,
                'received_sum1' => $receivedSum1,
                'expected_sum2' => $calculatedSum2,
                'received_sum2' => $receivedSum2,
            ]);
            return false;
        }

        // ✅ 修复：检查总押分标志（D8一般/D7开奖中）
        $betFlag = substr($msg, 2, 2);
        $isRewarding = ($betFlag === 'd7');  // D7表示开奖中

        // 解析数据
        // 格式：A7 D8/D7 [总押4B] D9 [总赢4B] SUM1 SUM2
        $pos = 4; // 跳过 A7 D8/D7

        $totalBet = $this->parseScore4Byte(substr($msg, $pos, 8));
        $pos += 8;

        $pos += 2; // 跳过 D9

        $totalWin = $this->parseScore4Byte(substr($msg, $pos, 8));

        $this->log->info('[收账小卡-总押总赢] 查询回复', [
            'machine_code' => $this->machine->code,
            'total_bet' => $totalBet,
            'total_win' => $totalWin,
            'is_rewarding' => $isRewarding,
            'bet_flag' => strtoupper($betFlag),
        ]);

        // 更新数据
        $this->total_bet = $totalBet;
        $this->total_win = $totalWin;

        // ✅ 修复：更新开奖状态（与READ_SCORE保持一致）
        if ($isRewarding) {
            $this->reward_status = 1;
        }

        // ✅ 更新版本号，表示收到回复
        $this->setActionVersion(self::READ_BET);

        return true;
    }

    /**
     * 处理机台情况回复（A7 D2 ... D6 ... DC ... SUM1 SUM2）
     *
     * ⚠️ 完整格式（44字符）：
     * A7 D2 00 01 00 00 D6 10 00 63 1E DC 1E 14 00 00 AC xx xx xx SUM1 SUM2
     *
     * 字段说明：
     * - D2/D3: 开分状态（D2=完成，D3=开分中）
     * - 回补次数: 开分失败次数
     * - 码表少跳: 高位+低位（正常00 00）
     * - D6/D7: 洗分状态（D6=完成，D7=洗分中）
     * - 洗分数据: 4字节BCD
     * - 转数: 2字节BCD
     * - 状态字节: 8x=登出状态
     */
    private function handleMachineStatusReply(string $msg): bool
    {
        // ✅ 入口日志
        $this->log->info('[机台情况] 处理开始', [
            'machine_code' => $this->machine->code,
            'msg_len' => strlen($msg),
        ]);

        // A7 D2 00 01 00 00 D6 10 00 63 1E DC 1E 14 00 00 AC xx xx xx SUM1 SUM2
        // 最小长度：44字符（22字节）
        if (strlen($msg) < 44) {
            $this->log->error('[机台情况] 回复长度不足', [
                'machine_code' => $this->machine->code,
                'msg' => strtoupper($msg),
                'len' => strlen($msg),
                'expected_min' => 44,
            ]);
            return false;
        }

        // ✅ 使用公共方法校验和验证
        if (!$this->validateMessageChecksum($msg, '机台情况')) {
            return false;
        }

        // ✅ 使用提取的方法解析字段
        $fields = $this->parseMachineStatusFields($msg);

        // ✅ 使用提取的方法检测标志
        $flags = $this->detectMachineStatusFlags($fields['status_value']);

        // 记录解析结果
        $this->log->info('[收账小卡-机台情况] 查询回复', [
            'machine_code' => $this->machine->code,
            'open_status' => strtoupper($fields['open_status']),
            'open_ing' => ($fields['open_status'] === 'd3'),
            'retry_count' => $fields['retry_count'],
            'counter_skip' => strtoupper($fields['counter_skip_high'] . $fields['counter_skip_low']),
            'wash_status' => strtoupper($fields['wash_status']),
            'wash_ing' => ($fields['wash_status'] === 'd7'),
            'wash_score' => $fields['wash_score'],
            'turn_count' => $fields['turn_count'],
            'status_byte' => strtoupper($fields['status_byte']),
            'is_logout' => $flags['is_logout'],
            'has_fault1' => $flags['has_fault1'],
            'has_fault2' => $flags['has_fault2'],
        ]);

        // ✅ 使用提取的方法更新状态
        $this->updateMachineStatus($fields, $flags);

        // ✅ 更新版本号，表示收到回复
        $this->setActionVersion(self::READ_STATUS);

        return true;
    }

    /**
     * 处理回补（开分失败时退款给玩家）
     *
     * ⚠️ 回补机制说明：
     * - 当开分指令发送后，机台可能因为到达上限或其他原因导致部分开分失败
     * - READ_STATUS查询时会返回"回补次数"，表示有多少次开分失败
     * - 需要将失败的金额（回补次数 × 100分）退回给玩家钱包
     *
     * @param int $retryCount 回补次数（开分失败次数）
     * @return void 是否处理成功
     */
    private function handleRetryRefund(int $retryCount): void
    {
        // 1. 检查是否有玩家在使用机台
        $gamingUserId = $this->gaming_user_id ?? 0;

        if ($gamingUserId <= 0) {
            // 没有玩家在使用，不需要退款（可能是管理员测试）
            $this->log->info('[收账小卡-回补] 检测到回补次数，但无玩家使用，跳过退款', [
                'machine_code' => $this->machine->code,
                'retry_count' => $retryCount,
                'refund_amount' => $retryCount * self::OPEN_UNIT,
            ]);
            return;
        }

        // 2. 计算需要退款的金额（回补次数 × 100分）
        $refundAmount = $retryCount * self::OPEN_UNIT;

        $this->log->warning('[收账小卡-回补] 检测到开分失败，需要退款', [
            'machine_code' => $this->machine->code,
            'player_id' => $gamingUserId,
            'retry_count' => $retryCount,
            'refund_amount' => $refundAmount,
            'unit' => self::OPEN_UNIT,
        ]);

        // 3. TODO: 调用钱包API退款
        // ⚠️ 预留接口，当有玩家实际使用时再实现
        //
        // 实现思路：
        // - 调用玩家钱包服务，将 $refundAmount 退回到玩家账户
        // - 记录退款日志到数据库（player_game_log 或 player_wallet_log）
        // - 可能需要发送WebSocket推送通知玩家
        //
        // 示例代码（需要根据实际钱包API调整）：
        // try {
        //     // 调用钱包API
        //     $result = PlayerWalletService::refund([
        //         'player_id' => $gamingUserId,
        //         'amount' => $refundAmount,
        //         'reason' => '机台开分失败回补',
        //         'machine_id' => $this->machine->id,
        //         'machine_code' => $this->machine->code,
        //         'retry_count' => $retryCount,
        //     ]);
        //
        //     if ($result['success']) {
        //         $this->log->info('[收账小卡-回补] 退款成功', [
        //             'machine_code' => $this->machine->code,
        //             'player_id' => $gamingUserId,
        //             'refund_amount' => $refundAmount,
        //             'result' => $result,
        //         ]);
        //         return true;
        //     } else {
        //         $this->log->error('[收账小卡-回补] 退款失败', [
        //             'machine_code' => $this->machine->code,
        //             'player_id' => $gamingUserId,
        //             'refund_amount' => $refundAmount,
        //             'error' => $result['error'] ?? '未知错误',
        //         ]);
        //         return false;
        //     }
        // } catch (Exception $e) {
        //     $this->log->error('[收账小卡-回补] 退款异常', [
        //         'machine_code' => $this->machine->code,
        //         'player_id' => $gamingUserId,
        //         'refund_amount' => $refundAmount,
        //         'error' => $e->getMessage(),
        //     ]);
        //     return false;
        // }

        // ⚠️ 当前仅记录日志，实际退款逻辑需要根据钱包API实现
        $this->log->warning('[收账小卡-回补] 退款接口未实现，仅记录日志', [
            'machine_code' => $this->machine->code,
            'player_id' => $gamingUserId,
            'refund_amount' => $refundAmount,
            'note' => '需要根据实际钱包API实现退款逻辑',
        ]);

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

        // ✅ 指令测试支持：如果有原始指令码，也为其设置版本号
        // 这样前端发送 'a500c0' 时，等待的 actionKey 也能收到版本更新
        // ⚠️ 跨请求场景：从 Redis 读取原始指令（因为 sendCmd 和 handleMsg 是不同对象实例）
        if (!$this->originalCmd) {
            $this->originalCmd = Cache::get($this->cacheDataKey . '_pending_cmd');
        }

        if ($this->originalCmd) {
            $this->setActionVersion($this->originalCmd);
            // 删除临时数据（已使用）
            Cache::delete($this->cacheDataKey . '_pending_cmd');

            $this->log->info('[收账小卡-操作] 同时更新原始指令版本号', [
                'machine_code' => $this->machine->code,
                'original_cmd' => $this->originalCmd,
            ]);
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
        // ✅ 去重检查
        if ($this->shouldSkipCounterChange($type, $timestamp)) {
            return ['should_update' => false, 'recorded' => false, 'reason' => '去重窗口内'];
        }

        // ✅ 检测异常（减少情况）
        $anomaly = $this->detectCounterAnomaly($type, $oldCount, $newCount);

        // 如果是故障排除后归零，更新计数但不记录
        if ($anomaly['is_check_reset']) {
            return ['should_update' => true, 'recorded' => false, 'reason' => $anomaly['reason']];
        }

        // 如果有异常减少，更新计数但不记录（已发送异常通知）
        if ($anomaly['has_anomaly']) {
            return ['should_update' => true, 'recorded' => false, 'reason' => $anomaly['reason']];
        }

        // ✅ 正常增加 - 记录游戏数据
        $increment = $newCount - $oldCount;

        if ($increment > 0) {
            $recorded = $this->decideCounterRecord($type, $increment, $timestamp);
            return ['should_update' => true, 'recorded' => $recorded, 'reason' => '正常增加'];
        }

        // 无变化
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
     * @throws Exception
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
            $this->log->warning('[收账小卡-发送] 发送指令', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'has_lock' => $this->has_lock,
                'cmd' => $cmd,
            ]);
            // ✅ 修复：归0指令允许在机台锁定时执行（其他指令需要检查锁定状态）
            if ($this->has_lock == 1 && $cmd !== self::CHECK) {
                throw new Exception(trans('machine_lock', ['{code}' => $this->machine->code], 'message'));
            }

            // 玩家操作时更新活动时间
            if ($source == 'player') {
                $currentGamingUserId = $this->gaming_user_id;
                if (!empty($currentGamingUserId)) {
                    $this->last_play_time = time();
                }
            }

            // ✅ 指令测试支持：识别特殊格式的上分/下分指令
            // 前端可能发送 'a5xxc0'（上分）或 'a500c1'（下分）
            $this->originalCmd = null;  // 重置
            if (preg_match('/^a5[0-9a-f]{2}c0$/i', $cmd)) {
                // 上分指令格式：a5xxc0
                $this->originalCmd = strtolower($cmd);  // 保存原始指令（小写）
                $cmd = self::OPEN_POINT;

                // ✅ 保存到 Redis（跨请求传递，60秒过期）
                Cache::set($this->cacheDataKey . '_pending_cmd', $this->originalCmd, 60);
            } elseif (preg_match('/^a500c1$/i', $cmd)) {
                // 下分指令格式：a500c1（固定00）
                $this->originalCmd = strtolower($cmd);  // 保存原始指令（小写）
                $cmd = self::WASH_POINT;

                // ✅ 保存到 Redis（跨请求传递，60秒过期）
                Cache::set($this->cacheDataKey . '_pending_cmd', $this->originalCmd, 60);
            }

            switch ($cmd) {
                case self::LOGIN:           // 登入（EA C3）
                case self::LOGOUT:          // 登出（EA C5）
                case self::READ_SCORE:      // 读取分数（EA C4）
                case self::READ_BET:        // 读取押分（EA D8）
                case self::READ_STATUS:     // 读取状态（EA D4）
                case self::ALL_DOWN:        // 清除历史记录（EA DE）
                case self::SSR_SIGNAL:      // SSR讯号（EA EC）
                // ✅ 使用 createCmd 添加校验和
                    $fullCmd = $this->createCmd($cmd);
                    $this->log->info('[收账小卡] 发送指令', [
                        'machine_code' => $this->machine->code,
                        'cmd' => strtoupper($cmd),
                        'full_cmd' => strtoupper($fullCmd),
                    ]);
                    Gateway::sendToUid($uid, hex2bin($fullCmd));
                    break;

                case self::CHECK:           // 故排（A3 70 05 E0 F8 CE）
                    $this->handleCheckCommand($uid, $source, $source_id);
                    break;

                case self::OPEN_POINT:      // 上分（需拼接次数）
                    $this->handleOpenPoint($uid, $data, $source, $source_id);
                    break;

                case self::WASH_POINT:      // 下分（全部洗分）
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
        $this->log->info('[收账小卡-开机] 发送指令', [
            'machine_code' => $this->machine->code,
            'msg' => strtoupper($cmd),
        ]);
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
        $this->external_open_count = 0;  // 旧字段（兼容性）
        $this->external_wash_count = 0;  // 旧字段（兼容性）

        $this->log->info('[收账小卡-故障排除] 清除外部码表', [
            'machine_code' => $this->machine->code,
            'old_open_count' => $oldOpenCount,
            'old_wash_count' => $oldWashCount,
            'note' => '故障排除会清除开分码表和洗分码表，已设置10秒标记',
        ]);

        // 发送故排指令（固定指令：A3 70 05 E0 F8 CE）
        Gateway::sendToUid($uid, hex2bin(self::CHECK));

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
     * 1. 固定100分为单位（self::OPEN_UNIT）
     * 2. 玩家开分1000需要发送开分指令10次（1000÷100=10次）
     * 3. $data参数是机台分数（不是玩家钱包金额）
     */
    private function handleOpenPoint(string $uid, int $data, string $source, int $source_id): void
    {
        // ⚠️ 确保已登入（自动登入）
        $this->ensureLoggedIn();

        // ⚠️ 特殊逻辑：机台分数转换成次数（100分为单位）
        if ($data % self::OPEN_UNIT != 0) {
            throw new Exception('开分金额必须是' . self::OPEN_UNIT . '的倍数，当前：' . $data);
        }

        $times = intval($data / self::OPEN_UNIT);

        if ($times <= 0 || $times > self::MAX_OPEN_TIMES) {
            throw new Exception('开分次数超出范围（1-' . self::MAX_OPEN_TIMES . '），当前：' . $times);
        }

        // ✅ 优化：构建指令 A5 XX C0，使用统一方法添加校验和
        $timesHex = str_pad(dechex($times), 2, '0', STR_PAD_LEFT);
        $cmdData = self::OPEN_POINT . $timesHex . 'c0';

        // 手动计算校验和（因为指令格式特殊，不能直接用 createCmd）
        $sum1 = $this->calculateSUM1($cmdData);
        $sum2 = $this->calculateSUM2($cmdData, $sum1);

        $fullCmd = $cmdData . $sum1 . $sum2;

        $this->log->info('[收账小卡-上分] 发送上分指令', [
            'machine_code' => $this->machine->code,
            'machine_score' => $data,
            'times' => $times,
            'unit' => self::OPEN_UNIT,
            'cmd' => strtoupper($fullCmd),

            // ✅ 诊断日志：记录当前分数，用于对比
            'current_card_score' => $this->card_score ?? 0,
            'current_machine_score' => $this->machine_score ?? 0,
            'expected_card_score_increase' => $data,
            'note' => '期望卡分增加' . $data . '分，请关注后续心跳/账目查询是否符合预期',
        ]);

        Gateway::sendToUid($uid, hex2bin($fullCmd));

        if ($source == 'admin') {
            sendSocketMessage('private-admin-1-' . $source_id, [
                'msg_type' => 'machine_action_result',
                'id' => $this->machine->id,
                'description' => "上分指令已发送（{$times}次×" . self::OPEN_UNIT . "分={$data}分）",
            ]);
        }
    }

    /**
     * 处理下分指令（A5 00 C1 SUM1 SUM2）
     *
     * ⚠️ 小淞线下Slot特殊规则：
     * 1. 固定100分为单位（self::OPEN_UNIT）
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

        $cmdData = self::WASH_POINT;

        $sum1 = $this->calculateSUM1($cmdData);
        $sum2 = $this->calculateSUM2($cmdData, $sum1);

        $fullCmd = $cmdData . $sum1 . $sum2;

        $currentMachineScore = $this->machine_score ?? $this->point ?? 0;

        $this->log->info('[收账小卡-下分] 发送下分指令', [
            'machine_code' => $this->machine->code,
            'current_machine_score' => $currentMachineScore,
            'cmd' => strtoupper($fullCmd),
            'note' => 'A5 00 C1 = 全部洗分',

            // ✅ 诊断日志：记录当前分数，用于对比
            'current_card_score' => $this->card_score ?? 0,
            'expected_card_score_after_wash' => 0,
            'note2' => '期望洗分后卡分为0，请关注后续心跳/账目查询是否符合预期',
        ]);

        Gateway::sendToUid($uid, hex2bin($fullCmd));

        if ($source == 'admin') {
            sendSocketMessage('private-admin-1-' . $source_id, [
                'msg_type' => 'machine_action_result',
                'id' => $this->machine->id,
                'description' => '下分指令已发送（全部洗分）',
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
                case self::READ_SCORE:
                    $description = '读取分数';
                    break;
                case self::READ_BET:
                    $description = '读取押分';
                    break;
                case self::READ_STATUS:
                    $description = '读取状态';
                    break;
                case self::ALL_DOWN:
                    $description = '清除历史记录';
                    break;
                case self::CHECK:
                    $description = '故排';
                    break;
                case self::SSR_SIGNAL:
                    $description = '给SSR讯号10秒';
                    break;
                case self::OPEN_POINT:
                    $description = "上分（{$data}分）";
                    break;
                case self::WASH_POINT:
                    $description = '下分（全部洗分）';
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
        return substr(strtolower($msg), 0, 2) === self::TESTING && strlen($msg) >= 46;
    }

    /**
     * 处理心跳消息（约1秒传一次）
     *
     * ⚠️ 格式：B7 B1 [开分卡分数3B] B2 [机台分数3B] BA [总押分4B] BB [总得分4B] BD [状态1B] S1 S2
     *
     * 示例：B7 B1 0B1416 B2 02151F BA 00050B0C BB 00033305 BD xx S1(XOR) S2(ADD)
     *       - B1: 开分卡分数旗标
     *       - 0B1416: 开分卡分数 = 112030分
     *       - B2: 机台分数旗标
     *       - 02151F: 机台分数 = 22131分
     *       - BA: 总押分旗标
     *       - 00050B0C: 总押分 = 51112分
     *       - BB: 总得分旗标
     *       - 00033305: 总得分 = 35105分
     *       - BD: 状态字节（详见下方）
     *       - S1: XOR异或校验
     *       - S2: ADD累加校验（包含S1）
     *
     * BD状态字节（1字节，8位）：
     *   b7 = 0: 登入中      | b7 = 1: 登出中（无法开洗分）
     *   b6: 预留
     *   b5 = 1: 现场跳开分表（未透过后台开分）
     *   b4 = 1: 现场跳洗分表（未透过后台洗分）
     *   b3: 空置预留
     *   b2 = 1: 小当
     *   b1 = 1: 高确
     *   b0 = 1: 大当
     *
     * 示例：BD80 = 10000000（二进制）= 登出中
     *
     * ⚠️ 校验算法：S1 = XOR, S2 = ADD + S1（与收账小卡其他指令的SUM1/SUM2顺序相反）
     * ⚠️ 注意：心跳中开分卡和机台分数是3字节，总押分和总得分是4字节！
     */
    private function handleHeartbeat(string $msg): bool
    {
        try {
            // ✅ 入口日志（DEBUG级别，避免日志过多）
            $this->log->debug('[心跳] 处理开始', [
                'machine_code' => $this->machine->code,
                'msg_len' => strlen($msg),
            ]);

            // ✅ B7心跳使用特殊的S1/S2校验算法（不是收账小卡标准的SUM1/SUM2）
            if (!$this->validateHeartbeatChecksum($msg)) {
                $this->log->error('[心跳] 校验失败', [
                    'machine_code' => $this->machine->code,
                    'msg' => strtoupper($msg),
                ]);
                return false;
            }

            // 解析心跳数据
            [$cardScore, $machineScore, $totalBet, $totalWin, $statusByte] =
                $this->parseHeartbeatData($msg);

            // ========== 更新线下版特有字段（GD收账小卡协议） ==========
            // ✅ 记录分数变化（只在有显著变化时记录，避免心跳日志过多）
            $oldCardScore = $this->card_score ?? 0;
            $oldMachineScore = $this->machine_score ?? 0;

            // ✅ 诊断日志：详细记录分数变化原因，帮助排查锁机台问题
            if (abs($cardScore - $oldCardScore) > 0) {
                $cardScoreChange = $cardScore - $oldCardScore;
                $changeReason = '未知';

                if ($cardScoreChange > 0) {
                    $changeReason = '上分操作（期望增加：查看上分日志）';
                } elseif ($cardScoreChange < 0) {
                    if ($cardScore === 0) {
                        $changeReason = '洗分操作（全部洗分，期望变为0）';
                    } else {
                        $changeReason = '下分操作（部分洗分）或游戏消耗';
                    }
                }

                $this->logFieldChange('心跳', 'card_score', $oldCardScore, $cardScore, $changeReason);

                $this->log->info('[心跳-分数诊断] 卡分变化', [
                    'machine_code' => $this->machine->code,
                    'old_card_score' => $oldCardScore,
                    'new_card_score' => $cardScore,
                    'change' => $cardScoreChange,
                    'reason' => $changeReason,
                    'note' => '如果此变化后出现锁机台，说明分数变化不符合预期',
                ]);
            }

            if (abs($machineScore - $oldMachineScore) > 0) {
                $machineScoreChange = $machineScore - $oldMachineScore;
                $changeReason = '游戏进行中的分数变化';

                $this->logFieldChange('心跳', 'machine_score', $oldMachineScore, $machineScore, $changeReason);
            }

            // ✅ 保存上一次心跳的开分卡分数（用于计算线下洗分金额）
            if ($oldCardScore > 0) {
                $this->last_card_score = $oldCardScore;
            }

            $this->card_score = $cardScore;           // 开分卡分数（心跳B1字段）
            $this->machine_score = $machineScore;     // 机台分数（心跳B2字段）
            $this->total_bet = $totalBet;             // 总押分数（心跳BA字段）
            $this->total_win = $totalWin;             // 总得分数（心跳BB字段）

            // ========== 同步更新兼容字段（与线上版保持一致） ==========
            $this->score = $cardScore;                // score = card_score（兼容线上版）
            $this->point = $machineScore;             // point = machine_score（兼容线上版）
            $this->win = $totalWin;                   // win = total_win（兼容线上版）

            // ✅ 优化：心跳更新数据时同步更新 actionVersion，避免查询指令等待超时
            // 场景：发送查询指令后，心跳先到达并包含最新数据，此时应解除等待
            $this->setActionVersion(self::READ_SCORE);  // card_score, machine_score 已更新
            $this->setActionVersion(self::READ_BET);    // total_bet, total_win 已更新

            // 解析状态字节
            $status = $this->parseStatusByte($statusByte);

            // ========== 登入状态（心跳BD.b7） ==========
            $oldLoginStatus = $this->login_status ?? 0;
            $loginValue = $status['logged_out'] ? 0 : 1;
            $this->logFieldChange('心跳', 'login_status', $oldLoginStatus, $loginValue, '心跳检测');

            $this->login_status = $loginValue;        // 新字段
            $this->is_login = $loginValue;            // 兼容旧字段

            // ✅ 优化：登入状态变化时更新 actionVersion
            if ($oldLoginStatus !== $loginValue) {
                if ($loginValue === 1) {
                    $this->setActionVersion(self::LOGIN);   // 登入成功
                } else {
                    $this->setActionVersion(self::LOGOUT);  // 登出成功
                }
            }

            // ========== 游戏状态（心跳BD.b0/b1/b2） ==========
            $oldBigWin = $this->big_win ?? 0;
            $oldHighProb = $this->high_prob ?? 0;
            $oldSmallWin = $this->small_win ?? 0;

            $newBigWin = $status['big_win'] ? 1 : 0;
            $newHighProb = $status['high_prob'] ? 1 : 0;
            $newSmallWin = $status['small_win'] ? 1 : 0;

            $this->logFieldChange('心跳', 'big_win', $oldBigWin, $newBigWin, '大当状态');
            $this->logFieldChange('心跳', 'high_prob', $oldHighProb, $newHighProb, '高确状态');
            $this->logFieldChange('心跳', 'small_win', $oldSmallWin, $newSmallWin, '小当状态');

            $this->big_win = $newBigWin;       // 大当状态
            $this->high_prob = $newHighProb;   // 高确状态
            $this->small_win = $newSmallWin;   // 小当状态

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
     * 解析BD状态字节
     *
     * BD状态字节（1字节8位）各位含义：
     *   b7: 登入状态
     *       - 0 = 登入中（可以开洗分）
     *       - 1 = 登出中（无法开洗分）
     *   b6: 预留
     *   b5: 现场跳开分表（外部按钮开分，未透过后台）
     *       - 0 = 正常
     *       - 1 = 检测到现场跳开分表
     *   b4: 现场跳洗分表（外部按钮洗分，未透过后台）
     *       - 0 = 正常
     *       - 1 = 检测到现场跳洗分表
     *   b3: 空置预留
     *   b2: 小当状态
     *       - 0 = 无小当
     *       - 1 = 小当中
     *   b1: 高确状态
     *       - 0 = 无高确
     *       - 1 = 高确中
     *   b0: 大当状态
     *       - 0 = 无大当
     *       - 1 = 大当中
     *
     * 示例：
     *   BD80 = 10000000（二进制）= 登出中，其他状态正常
     *   BD00 = 00000000（二进制）= 登入中，所有状态正常
     *   BD01 = 00000001（二进制）= 登入中，大当中
     *   BD07 = 00000111（二进制）= 登入中，大当+高确+小当
     *
     * @param string $byte 状态字节（2个hex字符）
     * @return array 解析后的状态数组
     */
    private function parseStatusByte(string $byte): array
    {
        $val = hexdec($byte);

        return [
            'logged_out' => ($val & 0x80) > 0,      // bit7: 登出状态
            'external_open' => ($val & 0x20) > 0,   // bit5: 现场跳开分表
            'external_wash' => ($val & 0x10) > 0,   // bit4: 现场跳洗分表
            'small_win' => ($val & 0x04) > 0,       // bit2: 小当
            'high_prob' => ($val & 0x02) > 0,       // bit1: 高确
            'big_win' => ($val & 0x01) > 0,         // bit0: 大当
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
                // ✅ 使用开分码表差值记录线下开分
                $this->processExternalOpen($gamingUserId);
            }

            // 检测洗分表
            if ($status['external_wash']) {
                $this->external_wash = 1;
                // ✅ 使用开分卡分数差值计算洗分金额
                $this->processExternalWash($gamingUserId);
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

    /**
     * 处理线下洗分（通过开分卡分数差值计算）
     *
     * ✅ 新逻辑：根据上一次心跳的开分卡分数作为洗分金额
     * - 洗分前：last_card_score（上一次心跳）
     * - 洗分后：card_score（当前心跳）
     * - 洗分金额 = last_card_score - card_score
     *
     * @param int $gamingUserId 当前游戏玩家ID（0=无玩家）
     */
    private function processExternalWash(int $gamingUserId): void
    {
        try {
            // 1. 获取洗分前后的开分卡分数
            $cardScoreBefore = $this->last_card_score ?? 0;
            $cardScoreAfter = $this->card_score ?? 0;

            // 2. 计算实际洗掉的分数
            $washedScore = $cardScoreBefore - $cardScoreAfter;

            // 3. 验证洗分金额
            if ($washedScore <= 0) {
                $this->log->warning('[线下洗分] 开分卡分数未减少，跳过处理', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'card_score_before' => $cardScoreBefore,
                    'card_score_after' => $cardScoreAfter,
                    'gaming_user_id' => $gamingUserId,
                ]);
                return;
            }

            // 4. 转换成玩家余额（使用机台比值）
            // 公式：game_amount = floor(wash_point * odds_x / odds_y)
            $washedAmount = floor(
                bcmul(
                    bcdiv($washedScore, $this->machine->odds_y ?? 1, 4),
                    $this->machine->odds_x ?? 1,
                    2
                )
            );

            $this->log->info('[线下洗分] 检测到开分卡分数减少', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'card_score_before' => $cardScoreBefore,
                'card_score_after' => $cardScoreAfter,
                'washed_score' => $washedScore,
                'washed_amount' => $washedAmount,
                'odds_x' => $this->machine->odds_x,
                'odds_y' => $this->machine->odds_y,
                'gaming_user_id' => $gamingUserId,
            ]);

            // 5. 处理玩家余额
            if ($gamingUserId > 0) {
                // 有玩家：返回余额到钱包
                $this->returnBalanceToPlayer($gamingUserId, $washedScore, $washedAmount);
            } else {
                // 无玩家：只记录操作
                $this->recordExternalWashWithoutPlayer($washedScore, $washedAmount);
            }

            // 6. ✅ 自动发送清除故障指令（清除 b4 标志）
            try {
                $this->sendCmd(self::CHECK, 0, 'system');
                $this->log->info('[线下洗分] 自动发送 CHECK 指令清除 b4 标志', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                ]);
            } catch (Exception $e) {
                $this->log->error('[线下洗分] 发送 CHECK 指令失败', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'error' => $e->getMessage(),
                ]);
            }

        } catch (Exception $e) {
            $this->log->error('[线下洗分] 处理失败', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 处理线下开分（通过开分码表差值记录）
     *
     * ✅ 新逻辑：根据开分码表差值记录线下开分
     * - 查询：总账（EA C4）- 网络账（EA C7）= 线下账
     * - 记录：创建开分记录（PlayerGameLog）
     * - 注意：不扣减玩家余额（线下现金投币）
     *
     * @param int $gamingUserId 当前游戏玩家ID（0=无玩家）
     */
    private function processExternalOpen(int $gamingUserId): void
    {
        try {
            // 1. 查询开分码表差值
            $detail = $this->queryDetailSync();
            $newOpenTable = $detail['open_table'] ?? 0;
            $oldOpenTable = $this->open_table ?? 0;

            // 2. 计算开分增量
            $openIncrement = $newOpenTable - $oldOpenTable;

            // 3. 验证开分金额
            if ($openIncrement <= 0) {
                $this->log->warning('[线下开分] 开分码表未增加，跳过处理', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'old_open_table' => $oldOpenTable,
                    'new_open_table' => $newOpenTable,
                    'gaming_user_id' => $gamingUserId,
                ]);
                return;
            }

            // 4. 转换成金额（用于记录，不扣减余额）
            $openAmount = floor(
                bcmul(
                    bcdiv($openIncrement, $this->machine->odds_y ?? 1, 4),
                    $this->machine->odds_x ?? 1,
                    2
                )
            );

            $this->log->info('[线下开分] 检测到开分码表增加', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'old_open_table' => $oldOpenTable,
                'new_open_table' => $newOpenTable,
                'open_increment' => $openIncrement,
                'open_amount' => $openAmount,
                'odds_x' => $this->machine->odds_x,
                'odds_y' => $this->machine->odds_y,
                'gaming_user_id' => $gamingUserId,
            ]);

            // 5. 记录开分操作（不扣减余额）
            if ($gamingUserId > 0) {
                // 有玩家：记录玩家开分
                $this->recordExternalOpenForPlayer($gamingUserId, $openIncrement, $openAmount);
            } else {
                // 无玩家：记录系统开分
                $this->recordExternalOpenWithoutPlayer($openIncrement, $openAmount);
            }

            // 6. 更新开分码表
            $this->open_table = $newOpenTable;

            // 7. ✅ 自动发送清除故障指令（清除 b5 标志）
            try {
                $this->sendCmd(self::CHECK, 0, 'system');
                $this->log->info('[线下开分] 自动发送 CHECK 指令清除 b5 标志', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                ]);
            } catch (Exception $e) {
                $this->log->error('[线下开分] 发送 CHECK 指令失败', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'error' => $e->getMessage(),
                ]);
            }

        } catch (Exception $e) {
            $this->log->error('[线下开分] 处理失败', [
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 记录玩家的线下开分操作
     *
     * 流程：
     * 1. 创建开分记录（PlayerGameLog）
     * 2. 创建金流记录（PlayerDeliveryRecord）
     * 3. 更新游戏记录（PlayerGameRecord）
     * 4. 不扣减玩家余额（线下现金投币）
     */
    private function recordExternalOpenForPlayer(int $playerId, int $openedScore, string $openAmount): void
    {
        DB::beginTransaction();

        try {
            $player = \app\model\Player::find($playerId);
            if (!$player) {
                throw new Exception("玩家不存在: {$playerId}");
            }

            // 1. 获取游戏记录
            $gameRecord = \app\model\PlayerGameRecord::query()
                ->where('machine_id', $this->machine->id)
                ->where('player_id', $playerId)
                ->where('status', \app\model\PlayerGameRecord::STATUS_START)
                ->orderBy('created_at', 'desc')
                ->first();

            // 2. 读取玩家余额（记录用，不扣减）
            $balance = \app\service\WalletService::getBalance($playerId);

            // 3. 创建开分记录（PlayerGameLog）
            $playerGameLog = addPlayerGameLog(
                $player,
                $this->machine,
                $gameRecord,
                $this->machine->control_open_point ?? 100
            );
            $playerGameLog->open_point = $openedScore;
            $playerGameLog->game_amount = $openAmount;
            $playerGameLog->before_game_amount = $balance;
            $playerGameLog->after_game_amount = $balance;  // 余额不变
            $playerGameLog->action = \app\model\PlayerGameLog::ACTION_OPEN;
            $playerGameLog->chip_amount = 0;
            $playerGameLog->is_system = 0;
            $playerGameLog->remark = '线下实体按键开分（现金投币）';
            $playerGameLog->save();

            // 5. 更新游戏记录
            if ($gameRecord) {
                $gameRecord->open_point = bcadd($gameRecord->open_point, $openedScore, 2);
                $gameRecord->open_amount = bcadd($gameRecord->open_amount, $openAmount, 2);
                $gameRecord->save();
            }

            // 6. 更新 Redis 记录
            $this->last_point_at = time();
            $this->player_open_point = bcadd($this->player_open_point ?? '0', $openedScore, 2);

            DB::commit();

            $this->log->info('[线下开分] 玩家开分记录创建成功', [
                'player_id' => $playerId,
                'opened_score' => $openedScore,
                'open_amount' => $openAmount,
                'machine_id' => $this->machine->id,
                'balance_unchanged' => $balance,
                'player_game_log_id' => $playerGameLog->id,
            ]);

        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 记录无玩家的线下开分操作
     *
     * 仅创建开分记录，标记为线下按键操作
     */
    private function recordExternalOpenWithoutPlayer(int $openedScore, string $openAmount): void
    {
        DB::beginTransaction();

        try {
            // 创建系统开分记录（player_id = 0）
            $playerGameLog = new \app\model\PlayerGameLog();
            $playerGameLog->player_id = 0;  // 系统记录
            $playerGameLog->department_id = $this->machine->department_id ?? 0;
            $playerGameLog->machine_id = $this->machine->id;
            $playerGameLog->open_point = $openedScore;
            $playerGameLog->game_amount = $openAmount;
            $playerGameLog->before_game_amount = 0;
            $playerGameLog->after_game_amount = 0;
            $playerGameLog->action = \app\model\PlayerGameLog::ACTION_OPEN;
            $playerGameLog->chip_amount = 0;
            $playerGameLog->is_system = 1;
            $playerGameLog->remark = '线下实体按键开分（无玩家，现金投币）';
            $playerGameLog->save();

            DB::commit();

            $this->log->info('[线下开分] 无玩家游戏，已记录开分操作', [
                'opened_score' => $openedScore,
                'open_amount' => $openAmount,
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'player_game_log_id' => $playerGameLog->id,
            ]);

        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 返回余额给玩家（线下洗分）
     *
     * 流程：
     * 1. 创建下分记录（PlayerGameLog）
     * 2. 创建金流记录（PlayerDeliveryRecord）
     * 3. 更新游戏记录（PlayerGameRecord）
     * 4. 钱包加款（WalletService）
     * 5. 推送余额变化（BalancePushService）
     */
    private function returnBalanceToPlayer(int $playerId, int $washedScore, string $washedAmount): void
    {
        DB::beginTransaction();

        try {
            $player = \app\model\Player::find($playerId);
            if (!$player) {
                throw new Exception("玩家不存在: {$playerId}");
            }

            // 1. 获取游戏记录
            $gameRecord = \app\model\PlayerGameRecord::query()
                ->where('machine_id', $this->machine->id)
                ->where('player_id', $playerId)
                ->where('status', \app\model\PlayerGameRecord::STATUS_START)
                ->orderBy('created_at', 'desc')
                ->first();

            // 2. 读取玩家余额
            $beforeBalance = \app\service\WalletService::getBalance($playerId);
            $afterBalance = bcadd($beforeBalance, $washedAmount, 2);

            // 2.5 ✅ 计算玩家游戏期间的打码量
            $playerPressure = $this->player_pressure ?? 0;  // 玩家进入时的押分
            $playerScore = $this->player_score ?? 0;        // 玩家进入时的得分
            $totalBet = $this->total_bet ?? 0;              // 当前总押分
            $totalWin = $this->total_win ?? 0;              // 当前总得分

            // 计算游戏期间的押分和得分
            $gamingPressure = max(0, $totalBet - $playerPressure);
            $gamingScore = max(0, $totalWin - $playerScore);

            // 计算打码量：押分 * 比值
            $ratio = bcdiv($this->machine->odds_x ?? 1, $this->machine->odds_y ?? 1, 4);
            $chipAmount = bcmul($gamingPressure, $ratio, 2);

            $this->log->info('[线下洗分] 计算打码量', [
                'player_id' => $playerId,
                'player_pressure' => $playerPressure,
                'total_bet' => $totalBet,
                'gaming_pressure' => $gamingPressure,
                'gaming_score' => $gamingScore,
                'ratio' => floatval($ratio),
                'chip_amount' => floatval($chipAmount),
            ]);

            // 3. 创建下分记录（PlayerGameLog）
            /** @var PlayerGameRecord $gameRecord */
            $playerGameLog = addPlayerGameLog(
                $player,
                $this->machine,
                $gameRecord,
                $this->machine->control_open_point ?? 100
            );
            $playerGameLog->wash_point = $washedScore;
            $playerGameLog->game_amount = $washedAmount;
            $playerGameLog->before_game_amount = $beforeBalance;
            $playerGameLog->after_game_amount = $afterBalance;
            $playerGameLog->action = \app\model\PlayerGameLog::ACTION_DOWN;
            $playerGameLog->chip_amount = floatval($chipAmount);  // ✅ 记录打码量
            $playerGameLog->pressure = $gamingPressure;           // ✅ 记录游戏期间押分
            $playerGameLog->score = $gamingScore;                 // ✅ 记录游戏期间得分
            $playerGameLog->turn_point = 0;                       // Slot机台无转数
            $playerGameLog->is_system = 0;
            $playerGameLog->remark = '线下实体按键洗分';
            $playerGameLog->save();

            // 4. 创建金流记录（PlayerDeliveryRecord）
            $playerDeliveryRecord = new \app\model\PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $playerId;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerGameLog->getTable();
            $playerDeliveryRecord->target_id = $playerGameLog->id;
            $playerDeliveryRecord->machine_id = $this->machine->id;
            $playerDeliveryRecord->machine_name = $this->machine->name;
            $playerDeliveryRecord->machine_type = $this->machine->type;
            $playerDeliveryRecord->code = $this->machine->code;
            $playerDeliveryRecord->type = \app\model\PlayerDeliveryRecord::TYPE_MACHINE_DOWN;
            $playerDeliveryRecord->source = 'external_button';  // 标记为外部按键
            $playerDeliveryRecord->amount = $washedAmount;
            $playerDeliveryRecord->amount_before = $beforeBalance;
            $playerDeliveryRecord->amount_after = $afterBalance;
            $playerDeliveryRecord->tradeno = $playerGameLog->tradeno ?? '';
            $playerDeliveryRecord->remark = '线下实体按键洗分';
            $playerDeliveryRecord->save();

            // 5. 更新游戏记录
            if ($gameRecord) {
                $gameRecord->wash_point = bcadd($gameRecord->wash_point, $washedScore, 2);
                $gameRecord->wash_amount = bcadd($gameRecord->wash_amount, $washedAmount, 2);
                $gameRecord->after_game_amount = $afterBalance;
                $gameRecord->save();
            }

            // 6. 更新 Redis 记录
            $this->last_point_at = time();
            $this->player_wash_point = bcadd($this->player_wash_point ?? '0', $washedScore, 2);

            DB::commit();

            // 6.5 ✅ 清理玩家游戏数据（洗分后归零）
            $this->player_pressure = 0;  // 清零玩家押分
            $this->player_score = 0;     // 清零玩家得分
            $this->bet = 0;              // 清零当前押分

            $this->log->info('[线下洗分] 清理玩家游戏数据', [
                'player_id' => $playerId,
                'machine_id' => $this->machine->id,
                'player_pressure' => 0,
                'player_score' => 0,
                'bet' => 0,
            ]);

            // 7. 钱包加款（在事务外执行）
            try {
                $addResult = \app\service\WalletService::add($playerId, $washedAmount);

                $this->log->info('[线下洗分] 钱包加款成功', [
                    'player_id' => $playerId,
                    'washed_score' => $washedScore,
                    'washed_amount' => $washedAmount,
                    'before_balance' => $beforeBalance,
                    'after_balance' => $addResult['balance'],
                    'machine_id' => $this->machine->id,
                ]);

                // 8. 推送余额变化
                \app\service\BalancePushService::pushBalanceChange(
                    $playerId,
                    $beforeBalance,
                    $addResult['balance'],
                    'settle',
                    [
                        'platform' => $this->machine->name ?? $this->machine->code,
                        'machine_id' => $this->machine->id,
                        'type' => 'external_wash',
                    ]
                );

            } catch (Exception $walletError) {
                // 钱包加款失败，发送告警
                $this->log->critical('[线下洗分] 钱包加款失败，需要人工介入', [
                    'player_id' => $playerId,
                    'amount' => $washedAmount,
                    'error' => $walletError->getMessage(),
                    'action' => '已记录下分成功，但钱包未加款，请立即手动给玩家加款',
                ]);

                // 发送 Telegram 告警
                try {
                    $telegramConfig = config('telegram');
                    if ($telegramConfig && !empty($telegramConfig['bot_token']) && !empty($telegramConfig['chat_id'])) {
                        $telegram = new \app\service\TelegramService($telegramConfig['bot_token'], $telegramConfig['chat_id']);
                        $telegram->sendAlert([
                            'datetime' => new \DateTime(),
                            'level_name' => 'CRITICAL',
                            'message' => '线下洗分成功但钱包加款失败',
                            'context' => [
                                'player_id' => $playerId,
                                'machine_id' => $this->machine->id,
                                'amount' => $washedAmount,
                                'action' => '请立即手动给玩家加款',
                            ],
                        ]);
                    }
                } catch (Exception $e) {
                    // 忽略 Telegram 告警失败
                }
            }

        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 记录无玩家的线下洗分操作
     *
     * 仅创建下分记录，不涉及钱包操作
     */
    private function recordExternalWashWithoutPlayer(int $washedScore, string $washedAmount): void
    {
        DB::beginTransaction();

        try {
            // 创建系统下分记录（player_id = 0）
            $playerGameLog = new \app\model\PlayerGameLog();
            $playerGameLog->player_id = 0;  // 系统记录
            $playerGameLog->department_id = $this->machine->department_id ?? 0;
            $playerGameLog->machine_id = $this->machine->id;
            $playerGameLog->wash_point = $washedScore;
            $playerGameLog->game_amount = $washedAmount;
            $playerGameLog->before_game_amount = 0;
            $playerGameLog->after_game_amount = 0;
            $playerGameLog->action = \app\model\PlayerGameLog::ACTION_DOWN;
            $playerGameLog->chip_amount = 0;
            $playerGameLog->is_system = 1;
            $playerGameLog->remark = '线下实体按键洗分（无玩家）';
            $playerGameLog->save();

            // 创建金流记录
            $playerDeliveryRecord = new \app\model\PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = 0;
            $playerDeliveryRecord->department_id = $this->machine->department_id ?? 0;
            $playerDeliveryRecord->target = $playerGameLog->getTable();
            $playerDeliveryRecord->target_id = $playerGameLog->id;
            $playerDeliveryRecord->machine_id = $this->machine->id;
            $playerDeliveryRecord->machine_name = $this->machine->name;
            $playerDeliveryRecord->machine_type = $this->machine->type;
            $playerDeliveryRecord->code = $this->machine->code;
            $playerDeliveryRecord->type = \app\model\PlayerDeliveryRecord::TYPE_MACHINE_DOWN;
            $playerDeliveryRecord->source = 'external_button';
            $playerDeliveryRecord->amount = $washedAmount;
            $playerDeliveryRecord->amount_before = 0;
            $playerDeliveryRecord->amount_after = 0;
            $playerDeliveryRecord->tradeno = $playerGameLog->tradeno ?? '';
            $playerDeliveryRecord->remark = '线下实体按键洗分（无玩家）';
            $playerDeliveryRecord->save();

            DB::commit();

            $this->log->info('[线下洗分] 无玩家游戏，已记录下分操作', [
                'washed_score' => $washedScore,
                'washed_amount' => $washedAmount,
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'player_game_log_id' => $playerGameLog->id,
            ]);

        } catch (Exception $e) {
            DB::rollback();
            throw $e;
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
     * 解析2字节BCD（用于转数等）
     *
     * @param string $scoreSection 2字节hex字符串（4个字符）
     * @return int 解析后的数值
     *
     * 示例：'1e14' → 0x1E=30, 0x14=20 → 30*100 + 20 = 3020
     */
    private function parseScore2Byte(string $scoreSection): int
    {
        $bytes = str_split($scoreSection, 2);
        if (count($bytes) !== 2) {
            throw new Exception('2字节BCD格式错误: ' . $scoreSection);
        }

        return (hexdec($bytes[0]) * 100)
            + hexdec($bytes[1]);
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
     * 验证B7心跳校验和（特殊的S1/S2算法）
     *
     * ⚠️ 收账小卡的B7心跳使用特殊的校验算法，与其他指令（EA/A5/A6/A7等）不同：
     *
     * B7心跳：
     * - S1 = XOR（异或）
     * - S2 = ADD + S1（累加，包含S1）
     *
     * 其他指令（EA/A5/A6/A7）：
     * - SUM1 = ADD（累加）
     * - SUM2 = XOR ^ SUM1（异或）
     *
     * 算法顺序完全相反！
     */
    private function validateHeartbeatChecksum(string $data): bool
    {
        if (strlen($data) < 4) {
            return false;
        }

        $s1 = substr($data, -4, 2);
        $s2 = substr($data, -2, 2);
        $payload = substr($data, 0, -4);

        // S1 = XOR异或
        $bytes = str_split($payload, 2);
        $xor = 0;
        foreach ($bytes as $byte) {
            $xor ^= hexdec($byte);
        }
        $calculatedS1 = str_pad(dechex($xor), 2, '0', STR_PAD_LEFT);

        // S2 = ADD累加 + S1
        $add = 0;
        foreach ($bytes as $byte) {
            $add += hexdec($byte);
        }
        $add += hexdec($calculatedS1);
        $calculatedS2 = str_pad(dechex($add & 0xFF), 2, '0', STR_PAD_LEFT);

        return strtolower($s1) == strtolower($calculatedS1)
            && strtolower($s2) == strtolower($calculatedS2);
    }

    /**
     * 查询详细账目（同步方法）
     */
    private function queryDetailSync(): array
    {
        $uid = $this->machine->domain . ':' . $this->machine->port;
        $cmd = $this->createCmd(self::READ_SCORE);

        $beforeTime = $this->setActionVersion(self::READ_SCORE);
        Gateway::sendToUid($uid, hex2bin($cmd));

        $timeout = 1000000;  // 1秒
        $sleep = 50000;      // 50ms
        $elapsed = 0;

        while ($elapsed < $timeout) {
            $actionTime = $this->getActionVersion(self::READ_SCORE);
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
        $beforeTime = $this->setActionVersion(self::LOGIN);
        Gateway::sendToUid($uid, hex2bin($cmd));

        $this->log->info('[登入] 发送登入指令', [
            'machine_code' => $this->machine->code,
        ]);

        // ✅ 改进：带超时的等待回复
        $timeout = 1000000;  // 1秒
        $sleep = 50000;      // 50ms
        $elapsed = 0;

        while ($elapsed < $timeout) {
            $actionTime = $this->getActionVersion(self::LOGIN);
            if ($actionTime > $beforeTime) {
                // 收到回复，检查登入状态
                $status = $this->checkLoginStatus();
                $this->log->info('[登入] 登入' . ($status ? '成功' : '失败'), [
                    'machine_code' => $this->machine->code,
                    'login_status' => $status,
                ]);
                return $status;
            }

            usleep($sleep);
            $elapsed += $sleep;
        }

        // 超时
        $this->log->error('[登入] 登入超时，未收到回复', [
            'machine_code' => $this->machine->code,
            'timeout' => $timeout / 1000 . 'ms',
        ]);
        return false;
    }

    /**
     * 确保已登入（发送指令前调用）
     * @throws Exception
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

    // ========== 🆕 重构新增：公共辅助方法 ==========

    /**
     * 解析账目查询数据（A6回复）
     *
     * @param string $msg 完整消息
     * @return array ['open_table', 'wash_table', 'card_score', 'machine_score', 'open_flag', 'wash_flag', 'card_flag']
     */
    private function parseAccountData(string $msg): array
    {
        $pos = 2; // 跳过A6

        // 开分码表标志
        $openFlag = substr($msg, $pos, 2);
        $pos += 2;

        // 开分码表（4字节）
        $openTable = $this->parseScore4Byte(substr($msg, $pos, 8));
        $pos += 8;

        // 洗分码表标志
        $washFlag = substr($msg, $pos, 2);
        $pos += 2;

        // 洗分码表（4字节）
        $washTable = $this->parseScore4Byte(substr($msg, $pos, 8));
        $pos += 8;

        // 开分卡分数标志
        $cardFlag = substr($msg, $pos, 2);
        $pos += 2;

        // 开分卡分数（4字节）
        $cardScore = $this->parseScore4Byte(substr($msg, $pos, 8));
        $pos += 8;

        // 机台分数（4字节）
        $machineScore = $this->parseScore4Byte(substr($msg, $pos, 8));

        return [
            'open_table' => $openTable,
            'wash_table' => $washTable,
            'card_score' => $cardScore,
            'machine_score' => $machineScore,
            'open_flag' => $openFlag,
            'wash_flag' => $washFlag,
            'card_flag' => $cardFlag,
        ];
    }

    /**
     * 检测账目标志状态
     *
     * @param string $openFlag 开分标志
     * @param string $cardFlag 开分卡标志
     * @return array ['is_rewarding', 'has_fault']
     */
    private function detectAccountFlags(string $openFlag, string $cardFlag): array
    {
        return [
            'is_rewarding' => ($openFlag === self::FLAG_REWARDING),
            'has_fault' => ($cardFlag === self::FLAG_FAULT),
        ];
    }

    /**
     * 处理Smart卡通讯故障（card_flag="EE"）
     *
     * @param array $data 解析后的数据
     * @return bool true表示消息已处理（从缓冲区移除），数据已丢弃
     */
    private function handleSmartCardCommunicationFault(array $data): bool
    {
        // 获取重试计数
        $retryKey = $this->cacheDataKey . '_account_retry_count';
        $retryCount = (int) Cache::get($retryKey, 0);
        $maxRetries = 3;  // 最大重试3次

        if ($retryCount < $maxRetries) {
            // 未达到最大重试次数，增加计数并延迟1秒后重试
            $retryCount++;
            Cache::set($retryKey, $retryCount, 60);  // 60秒过期

            $this->log->warning('[收账小卡-通讯故障] Smart卡通讯超时（EE），丢弃数据并延迟1秒后重试', [
                'machine_code' => $this->machine->code,
                'card_flag' => $data['card_flag'],
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
                'retry_delay' => '1秒',
                'reason' => 'Smart卡1秒内未发送完整信号（可能是RS232线路接触不良或信号干扰）',
                'action' => '丢弃当前数据，1秒后重新查询最新数据',
                'data_status' => '维持上一个有效值，不会丢失分数',
                'note' => 'Smart卡大约1秒更新一次，延迟1秒确保机台有足够时间获取新数据',
            ]);

            // 使用Workerman定时器，1秒后发送重试查询
            $uid = $this->machine->domain . ':' . $this->machine->port;
            $machineCode = $this->machine->code;
            $log = $this->log;
            $cmd = $this->createCmd(self::READ_SCORE);  // 在闭包外生成指令

            Timer::add(1, function() use ($uid, $machineCode, $retryCount, $log, $cmd) {
                Gateway::sendToUid($uid, hex2bin($cmd));

                $log->info('[收账小卡] 发送指令', [
                    'machine_code' => $machineCode,
                    'cmd' => strtoupper($cmd),
                    'reason' => '延迟1秒后重试（第' . $retryCount . '次）',
                ]);
            }, null, false);  // false表示只执行一次

            // 返回true，表示消息已处理（从缓冲区移除），但数据已丢弃（不更新Redis）
            return true;
        }

        // 达到最大重试次数，锁定机台
        $oldHasLock = $this->has_lock ?? 0;
        $this->logFieldChange('账目查询', 'has_lock', $oldHasLock, 1, 'Smart卡通讯故障持续');

        $this->log->error('[收账小卡-锁定] Smart卡通讯故障持续（EE），机台已锁定', [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'card_flag' => $data['card_flag'],
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'reason' => 'Smart卡通讯故障，' . $maxRetries . '次重试后仍未恢复',
            'diagnosis' => 'card_flag=EE 表示账务小卡1秒内未收到Smart卡完整信号',
            'possible_causes' => [
                '1. RS232线路接触不良（账务小卡上的4P 2线）',
                '2. 信号干扰/杂讯导致数据不完整',
                '3. Smart卡硬件故障',
                '4. 账务小卡读卡器故障',
            ],
            'next_steps' => [
                '1. 检查RS232线路连接',
                '2. 重新插拔4P 2线',
                '3. 如果频繁出现，考虑更换线材或检查硬件',
            ],
        ]);

        $this->has_lock = 1;
        sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->gaming_user_id);

        // 返回true，表示消息已处理（从缓冲区移除），但数据已丢弃（不更新Redis）
        return true;
    }

    /**
     * 更新账目数据到Redis
     *
     * @param array $data 解析后的数据
     * @param array $flags 检测到的标志
     */
    private function updateAccountData(array $data, array $flags): void
    {
        // ✅ 记录开奖状态变化
        $oldRewardStatus = $this->reward_status ?? 0;
        $newRewardStatus = $flags['is_rewarding'] ? 1 : 0;
        $this->logFieldChange('账目查询', 'reward_status', $oldRewardStatus, $newRewardStatus, '开奖状态');
        $this->reward_status = $newRewardStatus;

        // ✅ 记录分数变化（保存旧值用于诊断）
        $oldCardScore = $this->card_score ?? 0;
        $oldMachineScore = $this->machine_score ?? 0;
        $this->logFieldChange('账目查询', 'card_score', $oldCardScore, $data['card_score'], '开分卡分数');
        $this->logFieldChange('账目查询', 'machine_score', $oldMachineScore, $data['machine_score'], '机台分数');

        $this->card_score = $data['card_score'];
        $this->machine_score = $data['machine_score'];

        // 旧字段（兼容性）
        $this->point = $data['machine_score'];              // 旧名
        $this->open_card_point = $data['card_score'];       // 旧名

        // ✅ 注意：FLAG_FAULT（card_flag="EE"）的处理已移至handleSmartCardCommunicationFault方法
        // 此方法只会在card_flag="E9"（正常）时被调用，因此不需要处理故障情况
    }

    /**
     * 解析机台状态查询数据（A7 D2 回复）
     *
     * @param string $msg 完整消息
     * @return array 解析后的字段数组
     */
    private function parseMachineStatusFields(string $msg): array
    {
        $pos = 2;  // 跳过 A7

        // 1. 开分状态（D2=完成，D3=开分中）
        $openStatus = substr($msg, $pos, 2);
        $pos += 2;

        // 2. 空置
        $pos += 2;

        // 3. 回补次数（开分失败次数）
        $retryCount = hexdec(substr($msg, $pos, 2));
        $pos += 2;

        // 4. 码表少跳（高位+低位）
        $counterSkipHigh = substr($msg, $pos, 2);
        $counterSkipLow = substr($msg, $pos + 2, 2);
        $pos += 4;

        // 5. 洗分状态（D6=完成，D7=洗分中）
        $washStatus = substr($msg, $pos, 2);
        $pos += 2;

        // 6. 洗分数据（4字节BCD）
        $washScore = $this->parseScore4Byte(substr($msg, $pos, 8));
        $pos += 8;

        // 7. 跳过 DC 固定标志
        $pos += 2;

        // 8. 转数（2字节BCD）
        $turnCount = $this->parseScore2Byte(substr($msg, $pos, 4));
        $pos += 4;

        // 9. 累计连庄数（不使用）
        $pos += 2;

        // 10. 状态字节
        $statusByte = substr($msg, $pos, 2);
        $statusValue = hexdec($statusByte);

        return [
            'open_status' => $openStatus,
            'retry_count' => $retryCount,
            'counter_skip_high' => $counterSkipHigh,
            'counter_skip_low' => $counterSkipLow,
            'wash_status' => $washStatus,
            'wash_score' => $washScore,
            'turn_count' => $turnCount,
            'status_byte' => $statusByte,
            'status_value' => $statusValue,
        ];
    }

    /**
     * 检测机台状态标志
     *
     * @param int $statusValue 状态字节值
     * @return array 检测结果
     */
    private function detectMachineStatusFlags(int $statusValue): array
    {
        return [
            'is_logout' => ($statusValue & 0x80) !== 0,  // 检查最高位（bit 7）
            'has_fault1' => ($statusValue & 0x10) !== 0,  // 得分线故障1
            'has_fault2' => ($statusValue & 0x20) !== 0,  // 故障2（网路未开分，却跳开洗分表）
        ];
    }

    /**
     * 更新机台状态数据
     *
     * @param array $fields 解析后的字段
     * @param array $flags 检测到的标志
     */
    private function updateMachineStatus(array $fields, array $flags): void
    {
        // 如果检测到登出状态，更新登入状态
        if ($flags['is_logout']) {
            $oldLoginStatus = $this->login_status ?? 1;
            $this->logFieldChange('机台情况', 'login_status', $oldLoginStatus, 0, '检测到登出状态');

            $this->is_login = 0;
            $this->login_status = 0;

            $this->log->warning('[收账小卡-机台情况] 检测到登出状态（8x）', [
                'machine_code' => $this->machine->code,
                'status_byte' => strtoupper($fields['status_byte']),
            ]);
        }

        // 如果有故障，记录警告
        if ($flags['has_fault1'] || $flags['has_fault2']) {
            $this->log->warning('[收账小卡-机台情况] 检测到故障状态', [
                'machine_code' => $this->machine->code,
                'fault1' => $flags['has_fault1'] ? '得分线故障' : null,
                'fault2' => $flags['has_fault2'] ? '网路未开分却跳开洗分表' : null,
            ]);
        }

        // 处理回补（开分失败时退款给玩家）
        if ($fields['retry_count'] > 0) {
            $this->handleRetryRefund($fields['retry_count']);
        }

        // 更新回补次数到缓存
        $oldReturnCount = $this->return_count ?? 0;
        $this->logFieldChange('机台情况', 'return_count', $oldReturnCount, $fields['retry_count'], '回补次数');
        $this->return_count = $fields['retry_count'];
    }

    /**
     * 验证消息校验和（SUM1 + SUM2）
     *
     * @param string $msg 完整消息（包含校验和）
     * @param string $context 上下文名称（用于日志）
     * @return bool
     */
    private function validateMessageChecksum(string $msg, string $context = ''): bool
    {
        $dataWithoutSum = substr($msg, 0, -4);
        $receivedSum1 = substr($msg, -4, 2);
        $receivedSum2 = substr($msg, -2, 2);

        $calculatedSum1 = $this->calculateSUM1($dataWithoutSum);
        $calculatedSum2 = $this->calculateSUM2($dataWithoutSum, $calculatedSum1);

        if ($receivedSum1 !== $calculatedSum1 || $receivedSum2 !== $calculatedSum2) {
            $this->log->error("[{$context}] 校验和验证失败", [
                'machine_code' => $this->machine->code,
                'msg' => strtoupper($msg),
                'expected_sum1' => $calculatedSum1,
                'received_sum1' => $receivedSum1,
                'expected_sum2' => $calculatedSum2,
                'received_sum2' => $receivedSum2,
            ]);
            return false;
        }

        // ✅ 校验成功时也记录（DEBUG级别）
        $this->log->debug("[{$context}] 校验和验证成功", [
            'machine_code' => $this->machine->code,
            'sum1' => $calculatedSum1,
            'sum2' => $calculatedSum2,
        ]);

        return true;
    }

    /**
     * 检查计数器变化是否应该跳过（去重检查）
     *
     * @param string $type 类型（open/wash）
     * @param int $timestamp 当前时间戳
     * @return bool true=应该跳过，false=继续处理
     */
    private function shouldSkipCounterChange(string $type, int $timestamp): bool
    {
        $cacheKey = "external_counter_{$type}_last_update_" . $this->machine->id;
        $dedupeWindow = 10; // 10秒去重窗口

        $lastUpdateTime = Cache::get($cacheKey, 0);
        return ($timestamp - $lastUpdateTime < $dedupeWindow);
    }

    /**
     * 检测计数器异常（减少情况）
     *
     * @param string $type 类型（open/wash）
     * @param int $oldCount 旧计数
     * @param int $newCount 新计数
     * @return array ['has_anomaly' => bool, 'is_check_reset' => bool, 'reason' => string]
     */
    private function detectCounterAnomaly(string $type, int $oldCount, int $newCount): array
    {
        // 没有减少，无异常
        if ($newCount >= $oldCount) {
            return [
                'has_anomaly' => false,
                'is_check_reset' => false,
                'reason' => '',
            ];
        }

        // 检查是否故障排除后归零
        $hasRecentCheck = Cache::get('check_flag_' . $this->machine->id);

        if ($hasRecentCheck) {
            // 正常归零（故障排除）
            $this->log->info("[收账小卡-{$type}码表] 故障排除后归零", [
                'machine_code' => $this->machine->code,
                'old' => $oldCount,
                'new' => $newCount,
            ]);

            return [
                'has_anomaly' => false,
                'is_check_reset' => true,
                'reason' => '故排归零',
            ];
        } else {
            // 异常减少
            $this->log->error("[收账小卡-{$type}码表] 异常减少", [
                'machine_code' => $this->machine->code,
                'old' => $oldCount,
                'new' => $newCount,
            ]);
            sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, 0);

            return [
                'has_anomaly' => true,
                'is_check_reset' => false,
                'reason' => '异常减少',
            ];
        }
    }

    /**
     * 决定是否记录计数器变化
     *
     * @param string $type 类型（open/wash）
     * @param int $increment 增量
     * @param int $timestamp 时间戳
     * @return bool true=已记录，false=未记录
     */
    private function decideCounterRecord(string $type, int $increment, int $timestamp): bool
    {
        if ($increment <= 0) {
            return false;
        }

        $cacheKey = "external_counter_{$type}_last_update_" . $this->machine->id;
        $dedupeWindow = 10;

        // 更新去重时间戳
        Cache::set($cacheKey, $timestamp, $dedupeWindow * 2);

        // 记录操作
        $this->recordExternalButtonOperation($type, $increment, $timestamp);

        return true;
    }

    /**
     * 记录字段状态变化
     *
     * @param string $context 上下文名称
     * @param string $field 字段名
     * @param mixed $oldValue 旧值
     * @param mixed $newValue 新值
     * @param string $reason 变化原因（可选）
     */
    private function logFieldChange(string $context, string $field, $oldValue, $newValue, string $reason = ''): void
    {
        if ($oldValue !== $newValue) {
            $logData = [
                'machine_code' => $this->machine->code,
                'field' => $field,
                'old' => $oldValue,
                'new' => $newValue,
            ];

            if ($reason) {
                $logData['reason'] = $reason;
            }

            $this->log->info("[{$context}] 字段更新", $logData);
        }
    }

    /**
     * 记录业务操作
     *
     * @param string $context 上下文名称
     * @param string $operation 操作名称
     * @param array $data 操作数据
     * @param bool $success 是否成功
     */
    private function logOperation(string $context, string $operation, array $data = [], bool $success = true): void
    {
        $level = $success ? 'info' : 'error';
        $status = $success ? '成功' : '失败';

        $this->log->{$level}("[{$context}] {$operation}{$status}", array_merge([
            'machine_code' => $this->machine->code,
        ], $data));
    }

    /**
     * 获取消息的完整长度（用于TCP分包处理）
     *
     * @param string $header 消息头（2字符）
     * @param string $buffer 缓冲区数据
     * @return int 消息长度，0表示无法确定
     */
    private function getMessageLength(string $header, string $buffer): int
    {
        switch ($header) {
            case 'a7':
                // A7 系列消息需要根据子类型判断长度
                if (strlen($buffer) < 4) {
                    return 0; // 长度不足，无法判断子类型
                }
                $subType = substr($buffer, 2, 2);
                if ($subType === 'c3' || $subType === 'c5') {
                    return 4;  // 登入/登出回复
                }
                if ($subType === 'd8') {
                    return 26; // 总押分回复
                }
                if (in_array($subType, ['d2', 'd3', 'd6', 'd7'])) {
                    return 44; // 机台状态回复
                }

                // ✅ 优化：未知 A7 子类型，记录警告便于发现新协议
                $this->log->warning('[TCP分包] 未知的A7子类型', [
                    'machine_code' => $this->machine->code,
                    'buffer' => strtoupper(substr($buffer, 0, min(20, strlen($buffer)))),
                    'sub_type' => strtoupper($subType),
                ]);
                return 0;

            case 'a6':
                return 44; // 账目查询回复

            case 'a5':
                return 6;  // 操作回复（A5 + 状态2字符 + 校验和2字符）

            case 'a3':
                // A3 归0回复
                if (strlen($buffer) >= 4) {
                    $status = substr($buffer, 2, 2);
                    if ($status === 'ee' || $status === 'ef') {
                        return 4; // 归0完成回复
                    }
                }
                if (strlen($buffer) >= 4 && substr($buffer, 0, 4) === 'a370') {
                    return 12; // 归0指令回复（A3 70 05 E0 F8 CE）
                }
                return 0;

            case 'b7':
                return 46; // 心跳

            case 'fa':
                if (strlen($buffer) >= 3 && substr($buffer, 0, 3) === 'fah') {
                    return 3; // FAH 开机信号
                }
                return 2; // FA 开机信号

            case 'e1':
                // E1 可能重复多次（e1e1e1...）
                if (preg_match('/^(e1)+/', $buffer, $matches)) {
                    return strlen($matches[0]);
                }
                return 0;

            default:
                return 0; // 未知消息类型
        }
    }
}
