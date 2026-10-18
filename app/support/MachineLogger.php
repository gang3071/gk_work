<?php

namespace app\support;

use app\model\GameType;
use app\model\Machine;
use support\Log;

/**
 * 机台工控统一日志记录器（PHP 8.0+）
 *
 * 输出标准 JSON Lines 到 machine_ctrl 通道，供 Vector 采集写入 ClickHouse。
 *
 * 日志分类：
 *   source_type   : player | heartbeat | admin | system
 *   log_status    : normal | warning | error
 *   log_direction : command（发送指令）| response（收到机台回复）| event（操作事件）
 *
 * 机台类型（machine_type_name）：
 *   slot_online | slot_offline | steel_ball_online | steel_ball_offline | fish_online | fish_offline
 *
 * 操作者字段语义：
 *   player_id / player_uuid  — 当前玩家（无玩家时为 0 / ''）
 *   operator_id / operator_name — 实际操作人（admin 事件时为管理员，system 事件时为空）
 */
class MachineLogger
{
    // source_type
    const SOURCE_PLAYER = 'player';
    const SOURCE_HEARTBEAT = 'heartbeat';
    const SOURCE_ADMIN = 'admin';
    const SOURCE_SYSTEM = 'system';

    // log_status
    const STATUS_NORMAL = 'normal';
    const STATUS_WARNING = 'warning';
    const STATUS_ERROR = 'error';

    // log_direction
    const DIR_COMMAND = 'command';   // 我方发送指令给机台
    const DIR_RESPONSE = 'response';  // 收到机台返回数据
    const DIR_EVENT = 'event';     // 玩家/系统触发的操作事件

    // action 常量（统一调用方字符串，可直接传字符串扩展）
    const ACTION_OPEN = 'open';        // 上分
    const ACTION_WASH = 'wash';        // 下分
    const ACTION_LEAVE = 'leave';       // 弃台
    const ACTION_HEARTBEAT = 'heartbeat';   // 心跳
    const ACTION_TEST = 'test';        // 测试指令（TESTING / afcc）
    const ACTION_LOCK = 'lock';        // 锁台
    const ACTION_UNLOCK = 'unlock';      // 解锁
    const ACTION_KEEP = 'keep';        // 保留台位
    const ACTION_CANCEL_KEEP = 'cancel_keep'; // 取消保留

    // 心跳去重：记录每台机台上次心跳的状态哈希（进程内持久，Workerman 常驻进程有效）
    private static array $lastHeartbeatHash = [];

    // 全静态工具类，禁止实例化
    private function __construct()
    {
    }

    /**
     * 统一写入入口
     *
     * @param array $machine 机台信息（Machine::toArray() 或手动组装）
     *                              必要字段：id, type, machine_source, code, name
     * @param string $sourceType 来源类型
     * @param string $logStatus 日志状态
     * @param string $logDirection 日志方向
     * @param string $action 操作/指令标识（建议用 ACTION_* 常量或指令名）
     * @param string $actionDesc 操作描述
     * @param array $extra 扩展字段：
     *                              player_id, player_uuid — 玩家信息
     *                              operator_id, operator_name — 操作人信息（admin 事件）
     *                              machine_data — 原始机台状态数组（必须是 Redis getAllData() 的完整结果，
     *                                             勿传部分数组，否则 open_point 等字段可能回退到语义不同的备用 key）
     *                              has_problem, problem_desc — 问题标注
     *                              raw_send, raw_recv — 协议调试原始字节
     */
    public static function log(
        array  $machine,
        string $sourceType,
        string $logStatus,
        string $logDirection,
        string $action,
        string $actionDesc = '',
        array  $extra = []
    ): void
    {
        $machineId = (int)($machine['id'] ?? 0);
        $machineType = (int)($machine['type'] ?? 0);
        $machineSource = (int)($machine['machine_source'] ?? Machine::MACHINE_SOURCE_ONLINE);

        $stateJson = json_encode(
            self::normalizeMachineState($machineId, $machineType, $machineSource, $extra['machine_data'] ?? []),
            JSON_UNESCAPED_UNICODE
        );

        $context = [
            // === 机台身份 ===
            'machine_id' => $machineId,
            'machine_code' => (string)($machine['code'] ?? ''),
            'machine_name' => (string)($machine['name'] ?? ''),
            'machine_type' => $machineType,
            'machine_type_name' => self::buildTypeName($machineType, $machineSource),
            'machine_source' => $machineSource === Machine::MACHINE_SOURCE_ONLINE ? 'online' : 'offline',
            'machine_cate' => (int)($machine['cate_id'] ?? $machine['machine_cate'] ?? 0),  // 兼容两种字段名
            'producer_id' => (int)($machine['producer_id'] ?? 0),
            'department_id' => (int)($machine['department_id'] ?? 0),

            // === 日志分类 ===
            'source_type' => $sourceType,
            'log_status' => $logStatus,
            'log_direction' => $logDirection,

            // === 操作 ===
            'action' => $action,
            'action_desc' => $actionDesc,

            // === 玩家信息（无玩家时为默认值）===
            'player_id' => (int)($extra['player_id'] ?? 0),
            'player_uuid' => (string)($extra['player_uuid'] ?? ''),

            // === 操作人信息（区分于玩家，admin 事件时有值）===
            'operator_id' => (int)($extra['operator_id'] ?? 0),
            'operator_name' => (string)($extra['operator_name'] ?? ''),

            // === 问题标注 ===
            'has_problem' => (int)($extra['has_problem'] ?? 0),
            'problem_desc' => (string)($extra['problem_desc'] ?? ''),

            // === 机台核心状态（JSON 字符串，匹配 ClickHouse String 列）===
            'machine_state' => $stateJson !== false ? $stateJson : '{}',

            // === 协议调试（可选，CRC 错误等场景传入）===
            'raw_send' => (string)($extra['raw_send'] ?? ''),  // 发送的原始 hex 字节
            'raw_recv' => (string)($extra['raw_recv'] ?? ''),  // 收到的原始 hex 字节
        ];

        match ($logStatus) {
            self::STATUS_ERROR => Log::channel('machine_ctrl')->error($action, $context),
            self::STATUS_WARNING => Log::channel('machine_ctrl')->warning($action, $context),
            default => Log::channel('machine_ctrl')->info($action, $context),
        };
    }

    // ─────────────────────────────────────────────
    // 快捷方法
    // ─────────────────────────────────────────────

    /**
     * 玩家操作事件（上分/下分/弃台）
     * 建议 action 使用 ACTION_OPEN / ACTION_WASH / ACTION_LEAVE 常量
     */
    public static function playerEvent(
        array  $machine,
        array  $player,
        string $action,
        string $actionDesc = '',
        array  $machineData = [],
        array  $problems = []
    ): void
    {
        self::log(
            $machine,
            self::SOURCE_PLAYER,
            empty($problems) ? self::STATUS_NORMAL : self::STATUS_WARNING,
            self::DIR_EVENT,
            $action,
            $actionDesc,
            [
                'player_id' => $player['id'] ?? 0,
                'player_uuid' => $player['uuid'] ?? '',
                'has_problem' => empty($problems) ? 0 : 1,
                'problem_desc' => implode(' | ', $problems),
                'machine_data' => $machineData,
            ]
        );
    }

    /**
     * 发送指令给机台（command 方向）
     * 建议 cmd 使用机台服务类的指令常量，如 SongSlot::WASH_ZERO
     *
     * @param string $rawSend 发送的原始 hex 字节串（可选，调试用）
     * @param string $sourceType 默认 system，玩家/管理员触发时传对应来源
     */
    public static function sendCommand(
        array  $machine,
        string $cmd,
        string $cmdDesc = '',
        array  $machineData = [],
        string $rawSend = '',
        string $sourceType = self::SOURCE_SYSTEM,
        array  $player = []
    ): void
    {
        self::log(
            $machine,
            $sourceType,
            self::STATUS_NORMAL,
            self::DIR_COMMAND,
            $cmd,
            $cmdDesc,
            [
                'player_id' => $player['id'] ?? 0,
                'player_uuid' => $player['uuid'] ?? '',
                'machine_data' => $machineData,
                'raw_send' => $rawSend,
            ]
        );
    }

    /**
     * 收到机台返回数据（response 方向）
     *
     * @param string $rawRecv 收到的原始 hex 字节串（可选，调试用）
     * @param array $problems 有问题时传入描述列表，自动设置 log_status=warning
     */
    public static function receiveResponse(
        array  $machine,
        string $cmd,
        string $cmdDesc = '',
        array  $machineData = [],
        string $rawRecv = '',
        array  $problems = []
    ): void
    {
        self::log(
            $machine,
            self::SOURCE_SYSTEM,
            empty($problems) ? self::STATUS_NORMAL : self::STATUS_WARNING,
            self::DIR_RESPONSE,
            $cmd,
            $cmdDesc,
            [
                'has_problem' => empty($problems) ? 0 : 1,
                'problem_desc' => implode(' | ', $problems),
                'machine_data' => $machineData,
                'raw_recv' => $rawRecv,
            ]
        );
    }

    /**
     * 心跳上报（response 方向）
     *
     * 内置去重：机台状态无变化时自动跳过，避免每秒大量冗余日志。
     * Workerman 常驻进程模式下进程内有效，重启后重置。
     *
     * @param bool $force 强制写入（忽略去重，用于调试或首次上报）
     */
    public static function heartbeat(array $machine, array $machineData = [], bool $force = false): void
    {
        $machineId = (int)($machine['id'] ?? 0);
        $machineType = (int)($machine['type'] ?? 0);
        $machineSource = (int)($machine['machine_source'] ?? Machine::MACHINE_SOURCE_ONLINE);

        // 对 normalized state 哈希，而非原始 machineData。
        // 原始数据含 action_time / last_play_time 等每次心跳都变化的 volatile 字段，
        // 若对原始数据哈希会导致去重完全失效（每秒 100 台 = 860 万条/天）。
        // normalizeMachineState 只保留业务状态字段，volatile 字段不在其中。
        $normalized = self::normalizeMachineState($machineId, $machineType, $machineSource, $machineData);
        ksort($normalized);
        $stateHash = md5(serialize($normalized));

        if (!$force
            && isset(self::$lastHeartbeatHash[$machineId])
            && self::$lastHeartbeatHash[$machineId] === $stateHash
        ) {
            return; // 业务状态无变化，跳过本次心跳日志
        }

        self::$lastHeartbeatHash[$machineId] = $stateHash;

        self::log(
            $machine,
            self::SOURCE_HEARTBEAT,
            self::STATUS_NORMAL,
            self::DIR_RESPONSE,
            self::ACTION_HEARTBEAT,
            '心跳状态变化',
            ['machine_data' => $machineData]
        );
    }

    /**
     * 管理员操作事件
     * player_id / player_uuid 保留当前机台上的玩家信息（无玩家时为 0/''）
     * operator_id / operator_name 记录实际操作的管理员
     */
    public static function adminEvent(
        array  $machine,
        array  $operator,
        string $action,
        string $actionDesc = '',
        array  $machineData = [],
        array  $player = []
    ): void
    {
        self::log(
            $machine,
            self::SOURCE_ADMIN,
            self::STATUS_NORMAL,
            self::DIR_EVENT,
            $action,
            $actionDesc,
            [
                'player_id' => $player['id'] ?? 0,
                'player_uuid' => $player['uuid'] ?? '',
                'operator_id' => $operator['id'] ?? 0,
                'operator_name' => $operator['name'] ?? $operator['username'] ?? '',
                'machine_data' => $machineData,
            ]
        );
    }

    /**
     * 系统错误
     *
     * @param string $rawRecv 收到的原始 hex 字节（协议层错误时传入）
     */
    public static function error(
        array  $machine,
        string $action,
        string $errorDesc,
        array  $machineData = [],
        string $direction = self::DIR_EVENT,
        string $rawRecv = ''
    ): void
    {
        self::log(
            $machine,
            self::SOURCE_SYSTEM,
            self::STATUS_ERROR,
            $direction,
            $action,
            $errorDesc,
            [
                'has_problem' => 1,
                'problem_desc' => $errorDesc,
                'machine_data' => $machineData,
                'raw_recv' => $rawRecv,
            ]
        );
    }

    /**
     * 系统告警（与 error 签名一致，仅 log_status 不同）
     *
     * @param string $rawRecv 收到的原始 hex 字节（可选）
     */
    public static function warning(
        array  $machine,
        string $action,
        string $warnDesc,
        array  $machineData = [],
        string $direction = self::DIR_EVENT,
        string $rawRecv = ''
    ): void
    {
        self::log(
            $machine,
            self::SOURCE_SYSTEM,
            self::STATUS_WARNING,
            $direction,
            $action,
            $warnDesc,
            [
                'has_problem' => 1,
                'problem_desc' => $warnDesc,
                'machine_data' => $machineData,
                'raw_recv' => $rawRecv,
            ]
        );
    }

    // ─────────────────────────────────────────────
    // 内部工具方法
    // ─────────────────────────────────────────────

    /**
     * 构建机台类型名称
     * 格式：{type}_{source}，如 slot_online / steel_ball_offline
     */
    private static function buildTypeName(int $type, int $source): string
    {
        $typeName = match ($type) {
            GameType::TYPE_SLOT => 'slot',
            GameType::TYPE_STEEL_BALL => 'steel_ball',
            GameType::TYPE_FISH => 'fish',
            default => 'unknown',
        };
        $sourceName = $source === Machine::MACHINE_SOURCE_ONLINE ? 'online' : 'offline';
        return "{$typeName}_{$sourceName}";
    }

    /**
     * 统一机台状态字段
     *
     * 将 Redis 原始数据（含 machine_tcp_data_cache_{id}_ 前缀）
     * 或已清洗的数组，转换为统一的 machine_state 结构。
     *
     * 通用字段（所有类型）：
     *   point, gaming, gaming_user_id, keeping, keeping_user_id,
     *   keep_seconds, has_lock, play_start_time, open_point, wash_point
     *
     * Slot 在线：bet, win, score, pressure, now_turn, auto, reward_status, pre_wash_point, player_score
     * Slot 离线：card_score, machine_score, login_status,
     *           open_table, wash_table（权威开洗分字段，open_point/wash_point 保持 0）,
     *           big_win, small_win, high_prob, external_open, external_wash,
     *           turn, now_turn, auto, reward_status, bet, win, score
     *
     * Steel Ball 通用：turn, win_number, now_turn, auto, score, player_win_number (float), reward_status, push_auto
     * Steel Ball 在线专有：rush_status（冲台），pre_wash_point（预洗分）
     * Steel Ball 离线追加：external_open_count, external_wash_count, ratio（整数，如 10=10%）
     *
     * Fish：仅通用字段
     */
    private static function normalizeMachineState(
        int   $machineId,
        int   $machineType,
        int   $machineSource,
        array $rawData
    ): array
    {
        // 去除 Redis key 前缀：machine_tcp_data_cache_{id}_
        $prefix = "machine_tcp_data_cache_{$machineId}_";
        $prefixLen = strlen($prefix);
        $data = [];
        foreach ($rawData as $key => $value) {
            $key = (string)$key;
            $cleanKey = strncmp($key, $prefix, $prefixLen) === 0
                ? substr($key, $prefixLen)
                : $key;
            $data[$cleanKey] = $value;
        }

        // ── 通用字段（所有机台类型）──
        $state = [
            'point' => (int)($data['point'] ?? 0),           // 机台当前分数
            'gaming' => (int)($data['gaming'] ?? 0),          // 是否游戏中 0/1
            'gaming_user_id' => (int)($data['gaming_user_id'] ?? 0),  // 当前游戏玩家ID
            'keeping' => (int)($data['keeping'] ?? 0),         // 是否保留 0/1
            'keeping_user_id' => (int)($data['keeping_user_id'] ?? 0), // 保留玩家ID
            'keep_seconds' => (int)($data['keep_seconds'] ?? 0),    // 保留剩余时长（秒）
            'has_lock' => (int)($data['has_lock'] ?? 0),        // 机台锁定状态 0/1
            'play_start_time' => (int)($data['play_start_time'] ?? 0), // 本局游戏开始时间戳
            'open_point' => (int)($data['player_open_point'] ?? $data['open_point'] ?? 0),
            'wash_point' => (int)($data['player_wash_point'] ?? $data['wash_point'] ?? 0),
        ];

        if ($machineType === GameType::TYPE_SLOT) {
            if ($machineSource === Machine::MACHINE_SOURCE_ONLINE) {
                // ── Slot 在线字段 ──
                $state['bet'] = (int)($data['bet'] ?? 0);
                $state['win'] = (int)($data['win'] ?? 0);
                $state['score'] = (int)($data['score'] ?? 0);
                $state['pressure'] = (float)($data['player_pressure'] ?? 0); // 压分值（在线专有）
                $state['now_turn'] = (int)($data['now_turn'] ?? 0);
                $state['auto'] = (int)($data['auto'] ?? 0);
                $state['reward_status'] = (int)($data['reward_status'] ?? 0);
                $state['pre_wash_point'] = (int)($data['pre_wash_point'] ?? 0);    // 预洗分，异常检测用
                $state['player_score'] = (int)($data['player_score'] ?? 0);      // 玩家进入时基准得分（净赢亏计算用）
            } else {
                // ── Slot 离线字段（GD 收账小卡协议）──
                // 离线版无 player_open_point/player_wash_point，common 的 open_point/wash_point 保持 0
                // 以 open_table/wash_table 作为开洗分的权威字段
                $state['bet'] = (int)($data['bet'] ?? $data['total_bet'] ?? 0);
                $state['win'] = (int)($data['win'] ?? $data['total_win'] ?? 0);
                $state['score'] = (int)($data['score'] ?? 0);
                $state['card_score'] = (int)($data['card_score'] ?? 0);     // 开分卡分数（可取回的分）
                $state['machine_score'] = (int)($data['machine_score'] ?? 0);  // 机台分数（游戏中使用）
                $state['login_status'] = (int)($data['login_status'] ?? 0);   // 登入状态 0/1
                $state['open_table'] = (int)($data['open_table'] ?? 0);     // 开分码表（外部累计）
                $state['wash_table'] = (int)($data['wash_table'] ?? 0);     // 洗分码表（外部累计）
                $state['big_win'] = (int)($data['big_win'] ?? 0);        // 大当状态 0/1
                $state['small_win'] = (int)($data['small_win'] ?? 0);      // 小当状态 0/1（BD.b2）
                $state['high_prob'] = (int)($data['high_prob'] ?? 0);      // 高确状态 0/1
                $state['external_open'] = (int)($data['external_open'] ?? 0);  // 外部开分标志（BD.b5）
                $state['external_wash'] = (int)($data['external_wash'] ?? 0);  // 外部洗分标志（BD.b4）
                $state['turn'] = (int)($data['turn'] ?? 0);           // 当前转数
                $state['now_turn'] = (int)($data['now_turn'] ?? 0);       // 累积转数
                $state['auto'] = (int)($data['auto'] ?? 0);
                $state['reward_status'] = (int)($data['reward_status'] ?? 0);
            }
        }

        if ($machineType === GameType::TYPE_STEEL_BALL) {
            // ── Steel Ball 通用字段 ──
            $state['turn'] = (int)($data['turn'] ?? 0);           // 剩余转数
            $state['win_number'] = (int)($data['win_number'] ?? 0);     // 中洞累计次数
            $state['now_turn'] = (int)($data['now_turn'] ?? 0);       // 当前累积转数
            $state['auto'] = (int)($data['auto'] ?? 0);
            $state['score'] = (int)($data['score'] ?? 0);
            // player_win_number / reward_status / push_auto 在在线/离线 Steel Ball 均有
            // player_win_number：SongOfflineJackpot @property float（可含小数转数），用 float 保留精度
            $state['player_win_number'] = (float)($data['player_win_number'] ?? 0);
            $state['reward_status'] = (int)($data['reward_status'] ?? 0);
            $state['push_auto'] = (int)($data['push_auto'] ?? 0);        // 连发AUTO状态 0/1

            if ($machineSource === Machine::MACHINE_SOURCE_ONLINE) {
                // ── Steel Ball 在线专有字段 ──
                $state['rush_status'] = (int)($data['rush_status'] ?? 0);     // 冲台模式 0/1（在线专有，影响结算）
                $state['pre_wash_point'] = (int)($data['pre_wash_point'] ?? 0);  // 预洗分，异常检测用
            }

            if ($machineSource === Machine::MACHINE_SOURCE_OFFLINE) {
                // ── Steel Ball 离线追加字段（小淞工控协议）──
                $state['external_open_count'] = (int)($data['external_open_count'] ?? 0);
                $state['external_wash_count'] = (int)($data['external_wash_count'] ?? 0);
                // ratio 存储为整数（如 10 = 10%），@property int 与协议一致
                $state['ratio'] = (int)($data['ratio'] ?? 0);
            }
        }

        // Fish 机台：仅通用字段，暂无额外状态定义

        return $state;
    }
}
