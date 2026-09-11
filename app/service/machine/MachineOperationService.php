<?php

namespace app\service\machine;

use app\model\GameType;
use app\model\Machine;
use app\model\Player;
use Exception;
use GatewayWorker\Lib\Gateway;
use support\Log;

/**
 * 机台操作统一服务类
 *
 * 职责：
 * 1. 统一处理所有机台操作（基础、控制、高级）
 * 2. 区分操作者类型（player / admin / system）
 * 3. 区分机台类型（斯洛机 / 钢珠机）
 * 4. 区分厂商类型（双美 / 小淞）
 * 5. 封装硬件指令发送逻辑
 * 6. 统一日志记录和异常处理
 *
 * @author Claude Code
 * @date 2026-07-20
 */
class MachineOperationService
{
    /**
     * 操作者类型常量
     */
    const OPERATOR_PLAYER = 'player';   // 玩家操作
    const OPERATOR_ADMIN = 'admin';     // 后台管理员
    const OPERATOR_SYSTEM = 'system';   // 系统操作

    /**
     * 操作类别常量
     */
    const CATEGORY_BASIC = 'basic';       // 基础操作（查询）
    const CATEGORY_CONTROL = 'control';   // 控制指令
    const CATEGORY_ADVANCED = 'advanced'; // 高级操作（仅后台）

    private Machine $machine;
    private string $operatorType;
    private int $operatorId;
    private string $lang;
    private $services;  // Slot / SongSlot / Jackpot / SongJackpot

    /**
     * 构造函数
     *
     * @param Machine $machine 机台对象
     * @param string $operatorType 操作者类型（player/admin/system）
     * @param int $operatorId 操作者 ID
     * @param string $lang 语言
     */
    public function __construct(
        Machine $machine,
        string $operatorType,
        int $operatorId,
        string $lang = 'zh-TW'
    ) {
        $this->machine = $machine;
        $this->operatorType = $operatorType;
        $this->operatorId = $operatorId;
        $this->lang = $lang;

        // ✅ 确保 machineCategory 关系已加载（用于 PlayerGameRecord.game_id）
        if (!$machine->relationLoaded('machineCategory')) {
            $machine->load('machineCategory');
        }

        // 初始化硬件服务类
        $this->initServices();
    }

    /**
     * 初始化硬件服务类
     *
     * 根据机台类型、控制类型和机器来源选择对应的服务类：
     * - 斯洛机 + 双美 → Slot
     * - 斯洛机 + 小淞 → SongSlot
     * - 钢珠机 + 双美 → Jackpot
     * - 钢珠机 + 小淞 + 线上 → SongJackpot
     * - 钢珠机 + 小淞 + 线下 → SongOfflineJackpot
     */
    private function initServices(): void
    {
        // 🔍 调试日志：记录机台配置
        Log::channel('machine_operations')->debug('[MachineOperationService] 初始化服务', [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'type' => $this->machine->type,
            'control_type' => $this->machine->control_type,
            'machine_source' => $this->machine->machine_source,
            'CONTROL_TYPE_MEI' => Machine::CONTROL_TYPE_MEI,
            'CONTROL_TYPE_SONG' => Machine::CONTROL_TYPE_SONG,
        ]);

        if ($this->machine->type == GameType::TYPE_SLOT) {
            // ✅ Bug #17修复：Slot机也需要区分线上/线下
            if ($this->machine->control_type === Machine::CONTROL_TYPE_MEI) {
                // 双美工控
                $serviceClass = \app\service\machine\Slot::class;
            } else {
                // 小淞工控：区分线上/线下
                $serviceClass = ($this->machine->machine_source === Machine::MACHINE_SOURCE_OFFLINE)
                    ? \app\service\machine\SongOfflineSlot::class  // ✅ 新增：线下版Slot
                    : \app\service\machine\SongSlot::class;         // 线上版Slot
            }
        } else {
            // 钢珠机 (TYPE_STEEL_BALL) 或其他类型
            if ($this->machine->control_type === Machine::CONTROL_TYPE_MEI) {
                // 双美工控
                $serviceClass = \app\service\machine\Jackpot::class;
            } else {
                // 小淞工控：区分线上/线下
                $serviceClass = ($this->machine->machine_source === Machine::MACHINE_SOURCE_OFFLINE)
                    ? \app\service\machine\SongOfflineJackpot::class
                    : \app\service\machine\SongJackpot::class;
            }
        }

        // 🔍 调试日志：记录选择的服务类
        Log::channel('machine_operations')->debug('[MachineOperationService] 服务类选择', [
            'machine_id' => $this->machine->id,
            'service_class' => $serviceClass,
        ]);

        $this->services = new $serviceClass($this->machine, $this->lang);
    }

    /**
     * 执行操作（统一入口）
     *
     * @param string $action 操作名称
     * @param array $params 操作参数
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function execute(string $action, array $params = []): array
    {
        try {
            // 记录操作开始
            $this->logOperation('start', $action, $params);

            // 根据操作类型分发
            $result = $this->dispatch($action, $params);

            // 记录操作成功
            $this->logOperation('success', $action, $params, $result);

            return [
                'success' => true,
                'message' => trans('operation_success', [], 'message', $this->lang),
                'data' => $result,
            ];

        } catch (Exception $e) {
            // 记录操作失败
            $this->logOperation('error', $action, $params, [], $e);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * 操作分发
     */
    private function dispatch(string $action, array $params): array
    {
        // 基础操作（查询）
        if ($this->isBasicOperation($action)) {
            return $this->executeBasicOperation($action, $params);
        }

        // 业务操作（洗分/上分 - 包含完整业务逻辑）
        if ($this->isBusinessOperation($action)) {
            return $this->executeBusinessOperation($action, $params);
        }

        // 控制指令
        if ($this->isControlOperation($action)) {
            return $this->executeControlOperation($action, $params);
        }

        // 高级操作（仅后台）
        if ($this->isAdvancedOperation($action)) {
            return $this->executeAdvancedOperation($action, $params);
        }

        throw new Exception(trans('unsupported_operation', ['{action}' => $action], 'message', $this->lang));
    }

    // ==================== 基础操作 ====================

    /**
     * 判断是否为基础操作
     */
    private function isBasicOperation(string $action): bool
    {
        return in_array($action, [
            'query_status',      // 查询机台状态
            'check_online',      // 检查在线状态
            'get_description',   // 获取操作描述
            'send_raw_cmd',      // 发送原始硬件指令
            'send_raw_cmd_with_reply', // 发送原始硬件指令并等待解析回复
        ]);
    }

    /**
     * 判断是否为业务操作（洗分/上分）
     */
    private function isBusinessOperation(string $action): bool
    {
        return in_array($action, [
            'wash',              // 洗分（完整业务逻辑）
            'open',              // 上分（完整业务逻辑）
        ]);
    }

    /**
     * 执行基础操作
     */
    private function executeBasicOperation(string $action, array $params): array
    {
        switch ($action) {
            case 'query_status':
                return $this->queryStatus();
            case 'check_online':
                return $this->checkOnline();
            case 'get_description':
                return $this->getDescription();
            case 'send_raw_cmd':
                return $this->sendRawCmd($params);
            case 'send_raw_cmd_with_reply':
                return $this->sendRawCmdWithReply($params);
            default:
                throw new Exception(trans('unknown_basic_operation', ['{action}' => $action], 'message', $this->lang));
        }
    }

    /**
     * 执行业务操作（洗分/上分 - 完整业务逻辑）
     */
    private function executeBusinessOperation(string $action, array $params): array
    {
        switch ($action) {
            case 'wash':
                return $this->wash($params);
            case 'open':
                return $this->open($params);
            default:
                throw new Exception(trans('unknown_business_operation', ['{action}' => $action], 'message', $this->lang));
        }
    }

    /**
     * 查询机台状态
     */
    private function queryStatus(): array
    {
        $machineInfo = [];
        foreach ($this->services->machineInfo as $key) {
            $machineInfo[$key] = $this->services->$key ?? null;
        }

        return [
            'machine_id' => $this->machine->id,
            'machine_type' => $this->machine->type,
            'control_type' => $this->machine->control_type,
            'machine_info' => $machineInfo,
            'cache_data' => $this->services->cacheData ?? [],
        ];
    }

    /**
     * 检查机台在线（统一方法）
     *
     * 区分机台类型：
     * - 钢珠机：主机台 AND 自动打卡机都在线才算在线
     * - 斯洛机/捕鱼机：只需主机台在线
     *
     * @return array ['online' => bool, 'main_online' => bool, 'auto_online' => bool]
     */
    private function checkOnline(): array
    {
        $mainUid = $this->machine->domain . ':' . $this->machine->port;

        // 检查主机台在线状态
        try {
            $mainOnline = Gateway::isUidOnline($mainUid);
        } catch (Exception $e) {
            Log::warning('检查主机台在线状态失败', [
                'machine_id' => $this->machine->id,
                'uid' => $mainUid,
                'error' => $e->getMessage(),
            ]);
            $mainOnline = false;
        }

        // 检查自动打卡机（仅 Slot 机器）
        $autoOnline = false;
        if ($this->machine->type == GameType::TYPE_SLOT) {
            if (!empty($this->machine->auto_card_domain) && !empty($this->machine->auto_card_port)) {
                $autoUid = $this->machine->auto_card_domain . ':' . $this->machine->auto_card_port;
                try {
                    $autoOnline = Gateway::isUidOnline($autoUid);
                } catch (Exception $e) {
                    Log::warning('检查自动打卡机在线状态失败', [
                        'machine_id' => $this->machine->id,
                        'uid' => $autoUid,
                        'error' => $e->getMessage(),
                    ]);
                    $autoOnline = false;
                }
            }
        }

        // 计算总在线状态
        if ($this->machine->type == GameType::TYPE_SLOT) {
            // Slot 机器：必须主机台和自动打卡机都在线
            $isOnline = $mainOnline && $autoOnline;
        } else {
            // 钢珠机/捕鱼机：只需主机台在线
            $isOnline = $mainOnline;
        }

        return [
            'machine_id' => $this->machine->id,
            'online' => $isOnline,
            'main_online' => $mainOnline,
            'auto_online' => $autoOnline,
        ];
    }

    /**
     * 静态方法：批量检查机台在线状态
     *
     * @param array $machines Machine 对象数组
     * @return array 机台ID => ['online' => bool, 'main_online' => bool, 'auto_online' => bool] 的映射
     */
    public static function batchCheckOnline(array $machines): array
    {
        $results = [];

        foreach ($machines as $machine) {
            $mainUid = $machine->domain . ':' . $machine->port;

            // 检查主机台
            try {
                $mainOnline = Gateway::isUidOnline($mainUid);
            } catch (Exception $e) {
                Log::warning('批量检查：主机台在线状态失败', [
                    'machine_id' => $machine->id,
                    'uid' => $mainUid,
                    'error' => $e->getMessage(),
                ]);
                $mainOnline = false;
            }

            // 检查自动打卡机（仅 Slot 机器）
            $autoOnline = false;
            if ($machine->type == GameType::TYPE_SLOT) {
                if (!empty($machine->auto_card_domain) && !empty($machine->auto_card_port)) {
                    $autoUid = $machine->auto_card_domain . ':' . $machine->auto_card_port;
                    try {
                        $autoOnline = Gateway::isUidOnline($autoUid);
                    } catch (Exception $e) {
                        Log::warning('批量检查：自动打卡机在线状态失败', [
                            'machine_id' => $machine->id,
                            'uid' => $autoUid,
                            'error' => $e->getMessage(),
                        ]);
                        $autoOnline = false;
                    }
                }
            }

            // 计算总在线状态
            if ($machine->type == GameType::TYPE_SLOT) {
                // Slot 机器：必须主机台和自动打卡机都在线
                $isOnline = $mainOnline && $autoOnline;
            } else {
                // 钢珠机/捕鱼机：只需主机台在线
                $isOnline = $mainOnline;
            }

            $results[$machine->id] = [
                'online' => $isOnline,
                'main_online' => $mainOnline,
                'auto_online' => $autoOnline,
            ];
        }

        return $results;
    }

    /**
     * 获取操作描述
     */
    private function getDescription(): array
    {
        return [
            'machine_id' => $this->machine->id,
            'description' => $this->services->getDescription(),
        ];
    }

    /**
     * 发送原始硬件指令（底层接口）
     *
     * 用于直接发送 TCP 指令码，不经过业务逻辑封装
     * 发送后会读取机台的最新状态数据并返回
     *
     * @param array $params ['cmd' => string, 'data' => int, 'is_system' => int]
     * @return array
     * @throws Exception
     */
    private function sendRawCmd(array $params): array
    {
        $cmd = $params['cmd'] ?? '';
        $data = (int) ($params['data'] ?? 0);
        $isSystem = (int) ($params['is_system'] ?? 0);

        if (empty($cmd)) {
            throw new Exception(trans('missing_required_parameter', ['{param}' => 'cmd'], 'message', $this->lang));
        }

        // 直接调用底层服务发送 TCP 指令
        $result = $this->services->sendCmd(
            $cmd,
            $data,
            $this->operatorType,  // source: 'player' | 'admin' | 'system'
            $this->operatorId,
            $isSystem
        );

        // 等待机台响应（给机台和Redis一点处理时间）
        usleep(800000); // 0.8秒

        // 读取机台当前状态数据（从Redis缓存中获取）
        $machineData = $this->getMachineCurrentData();

        return [
            'success' => $result,
            'cmd' => $cmd,
            'cmd_data' => $data,
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'timestamp' => date('Y-m-d H:i:s'),
            // 机台实时数据
            'machine_status' => $machineData,
        ];
    }

    /**
     * 获取机台当前状态数据
     *
     * 从Redis缓存中读取机台的实时状态
     *
     * @return array
     */
    private function getMachineCurrentData(): array
    {
        // 通过服务类对象直接读取属性值
        $service = $this->services;
        $machineType = $this->machine->type;

        if ($machineType == GameType::TYPE_SLOT) {
            // 斯洛机数据（双美/小淞）
            return [
                'login_status' => (int)($service->login_status ?? 0),
                'machine_score' => (int)($service->machine_score ?? 0),
                'point' => (int)($service->point ?? 0),              // ✅ 开分卡分数（A2 21 READ_SCORE）
                'card_score' => (int)($service->point ?? 0),         // 别名：开分卡分数
                'score' => (int)($service->score ?? 0),              // ✅ CREDIT2（A2 22 READ_CREDIT2）
                'credit2' => (int)($service->score ?? 0),            // 别名：CREDIT2
                'open_table' => (int)($service->open_table ?? $service->open_point ?? 0),
                'wash_table' => (int)($service->wash_table ?? $service->wash_point ?? 0),
                'total_bet' => (int)($service->total_bet ?? 0),
                'total_win' => (int)($service->total_win ?? 0),
                'bet' => (int)($service->bet ?? 0),
                'win' => (int)($service->win ?? 0),
                'bb' => (int)($service->bb ?? 0),
                'rb' => (int)($service->rb ?? 0),
            ];
        } else {
            // 钢珠机数据（Jackpot）
            return [
                'point' => (int)($service->point ?? 0),          // 当前分数
                'turn' => (int)($service->turn ?? 0),            // 当前转数
                'score' => (int)($service->score ?? 0),          // 当前珠数
                'win_number' => (int)($service->win_number ?? 0),// 中洞对奖次数
                'open_point' => (int)($service->open_point ?? 0),// 开分次数
                'wash_point' => (int)($service->wash_point ?? 0),// 洗分次数
                'push_auto' => (int)($service->push_auto ?? 0),  // PUSH auto状态
            ];
        }
    }

    /**
     * 发送原始硬件指令并等待解析回复（新增方法）
     *
     * 此方法会：
     * 1. 发送指令到机台
     * 2. 等待机台回复（监听Redis版本变化）
     * 3. 读取Events.php解析后的数据
     * 4. 返回解析结果
     *
     * 参考：support/test_song_offline_slot_machine.php
     *
     * @param array $params ['cmd' => string, 'data' => int, 'is_system' => int, 'timeout' => int]
     * @return array
     * @throws Exception
     */
    private function sendRawCmdWithReply(array $params): array
    {
        $cmd = $params['cmd'] ?? '';
        $data = (int)($params['data'] ?? 0);
        $isSystem = (int)($params['is_system'] ?? 0);
        $timeout = (int)($params['timeout'] ?? 5); // 默认超时5秒

        if (empty($cmd)) {
            throw new Exception(trans('missing_required_parameter', ['{param}' => 'cmd'], 'message', $this->lang));
        }

        // ✅ 统一去除空格并转大写
        $cmdNormalized = strtoupper(str_replace(' ', '', $cmd));

        // ✅ 处理双美机台（Slot/Jackpot）的A2前缀
        // 双美机台的sendCmd会自动添加A2前缀，但Redis存储的actionKey不包含A2
        // 如果$cmd已经包含A2前缀（例如 "A221" 或 "a2 21"），需要去掉
        if ($this->machine->control_type === Machine::CONTROL_TYPE_MEI) {
            // 检查是否以A2开头
            if (substr($cmdNormalized, 0, 2) === 'A2') {
                // 去掉A2前缀
                $cmdNormalized = substr($cmdNormalized, 2);
            }
        }

        // 提取 actionKey（指令的前两个字节，最多4个字符）
        // 例如："EAC3" -> "EAC3", "A500C0" -> "A500", "21" -> "21"
        $actionKey = substr($cmdNormalized, 0, min(4, strlen($cmdNormalized)));

        $machineId = $this->machine->id;

        // ✅ 使用服务类的方法获取发送前的版本号（而不是直接访问Redis）
        $beforeVersion = (int)($this->services->getActionVersion($actionKey) ?: 0);

        Log::channel('machine_operations')->info('[sendRawCmdWithReply] 准备发送指令', [
            'machine_id' => $machineId,
            'cmd' => $cmd,
            'cmd_normalized' => $cmdNormalized,
            'action_key' => $actionKey,
            'before_version' => $beforeVersion,
            'machine_type' => $this->machine->type,
            'control_type' => $this->machine->control_type,
        ]);

        // ✅ 重要：发送指令时使用处理后的指令码（去掉前缀、统一大小写）
        // 这样 Slot::sendCmd() 中的 $cmd 参数才能正确匹配常量定义
        $sendResult = $this->services->sendCmd(
            $actionKey,  // ✅ 使用 actionKey 而不是原始 $cmd
            $data,
            $this->operatorType,
            $this->operatorId,
            $isSystem
        );

        Log::channel('machine_operations')->info('[sendRawCmdWithReply] 指令已发送', [
            'machine_id' => $machineId,
            'original_cmd' => $cmd,
            'send_cmd' => $actionKey,
            'action_key' => $actionKey,
            'send_result' => $sendResult,
        ]);

        if (!$sendResult) {
            throw new Exception(trans('send_cmd_failed', [], 'message', $this->lang));
        }

        // ✅ 等待回复（监听版本变化）- 使用服务类方法而不是直接访问Redis
        $startTime = time();
        $replied = false;

        while (time() - $startTime < $timeout) {
            // 使用服务类的 getActionVersion 方法获取当前版本号
            $currentVersion = (int)($this->services->getActionVersion($actionKey) ?: 0);

            if ($currentVersion > $beforeVersion) {
                $replied = true;
                Log::channel('machine_operations')->info('[sendRawCmdWithReply] 收到回复', [
                    'machine_id' => $machineId,
                    'cmd' => $cmd,
                    'action_key' => $actionKey,
                    'before_version' => $beforeVersion,
                    'current_version' => $currentVersion,
                    'elapsed_time' => time() - $startTime,
                ]);
                break;
            }

            usleep(200000); // 等待 200ms
        }

        if (!$replied) {
            Log::channel('machine_operations')->warning('[sendRawCmdWithReply] 等待回复超时', [
                'machine_id' => $machineId,
                'cmd' => $cmd,
                'action_key' => $actionKey,
                'timeout' => $timeout,
            ]);

            return [
                'success' => false,
                'replied' => false,
                'timeout' => true,
                'cmd' => $cmd,
                'action_key' => $actionKey,
                'machine_id' => $machineId,
                'machine_code' => $this->machine->code,
                'message' => '等待机台回复超时',
            ];
        }

        // 读取解析后的数据
        $parsedData = $this->getReplyData($actionKey);

        return [
            'success' => true,
            'replied' => true,
            'timeout' => false,
            'cmd' => $cmd,
            'cmd_data' => $data,
            'action_key' => $actionKey,
            'machine_id' => $machineId,
            'machine_code' => $this->machine->code,
            'timestamp' => date('Y-m-d H:i:s'),
            'elapsed_time' => time() - $startTime,
            // 解析后的回复数据
            'reply_data' => $parsedData,
        ];
    }

    /**
     * 根据 actionKey 读取解析后的回复数据
     *
     * 不同的指令会解析出不同的数据字段
     *
     * @param string $actionKey 指令键（如 eac3, eac5, a5 等）
     * @return array
     */
    private function getReplyData(string $actionKey): array
    {
        // 通过服务类对象直接读取属性值（服务类会自动处理 Redis key）
        $service = $this->services;
        $machineType = $this->machine->type;  // ✅ 获取机台类型用于区分

        // 根据不同的指令读取不同的数据
        switch ($actionKey) {
            // ========== 小淞线下机台指令 ==========
            case 'eac3': // 登入指令
            case 'eac5': // 查询登入状态
                $loginStatus = (int)($service->login_status ?? 0);
                return [
                    'login_status' => $loginStatus,
                    'login_status_text' => $loginStatus == 1 ? '已登入' : '未登入',
                ];

            case 'eac4': // 查询详细账目
                return [
                    'open_table' => (int)($service->open_table ?? 0),
                    'wash_table' => (int)($service->wash_table ?? 0),
                    'card_score' => (int)($service->card_score ?? 0),
                    'machine_score' => (int)($service->machine_score ?? 0),
                ];

            case 'ead8': // 查询总押总赢
                return [
                    'total_bet' => (int)($service->total_bet ?? 0),
                    'total_win' => (int)($service->total_win ?? 0),
                ];

            case 'a5': // 小淞上分/下分指令
                return [
                    'machine_score' => (int)($service->machine_score ?? 0),
                    'card_score' => (int)($service->card_score ?? 0),
                    'open_table' => (int)($service->open_table ?? 0),
                    'wash_table' => (int)($service->wash_table ?? 0),
                ];

            case 'ead4': // 查询机台情况
                return $this->getMachineCurrentData();

            case 'eade': // 清除账目
                return [
                    'open_table' => (int)($service->open_table ?? 0),
                    'wash_table' => (int)($service->wash_table ?? 0),
                    'message' => '账目已清除',
                ];

            // ========== 双美机台指令（去掉A2前缀后的指令码）==========
            case '21': // A2 21 - 读取开分卡分数（READ_SCORE）
                return [
                    'point' => (int)($service->point ?? 0), // ✅ 开分卡分数存储在 point 字段
                    'card_score' => (int)($service->point ?? 0), // 别名
                ];

            case '22': // A2 22 - 读取 CREDIT2
                return [
                    'score' => (int)($service->score ?? 0), // CREDIT2 存储在 score 字段
                    'credit2' => (int)($service->score ?? 0), // 别名
                ];

            case '23': // A2 23
                if ($machineType == GameType::TYPE_SLOT) {
                    // Slot: 读取 BET（押分）
                    return [
                        'bet' => (int)($service->bet ?? 0),
                        'pressure' => (int)($service->bet ?? 0), // 别名
                    ];
                } else {
                    // Jackpot: 读取机台当前转数
                    return [
                        'turn' => (int)($service->turn ?? 0),
                    ];
                }

            case '24': // A2 24
                if ($machineType == GameType::TYPE_SLOT) {
                    // Slot: 读取 WIN
                    return [
                        'win' => (int)($service->win ?? 0),
                    ];
                } else {
                    // Jackpot: 读取中洞对奖次数
                    return [
                        'win_number' => (int)($service->win_number ?? 0),
                    ];
                }

            case '25': // A2 25
                if ($machineType == GameType::TYPE_SLOT) {
                    // Slot: 读取 BB
                    return [
                        'bb' => (int)($service->bb ?? 0),
                    ];
                } else {
                    // Jackpot: 读取总开分
                    return [
                        'open_point' => (int)($service->open_point ?? 0),
                        'total_open' => (int)($service->open_point ?? 0), // 别名
                    ];
                }

            case '26': // A2 26
                if ($machineType == GameType::TYPE_SLOT) {
                    // Slot: 读取 RB
                    return [
                        'rb' => (int)($service->rb ?? 0),
                    ];
                } else {
                    // Jackpot: 读取总下分
                    return [
                        'wash_point' => (int)($service->wash_point ?? 0),
                        'total_wash' => (int)($service->wash_point ?? 0), // 别名
                    ];
                }

            case '20': // A2 20 - 测试连接
                return [
                    'connection' => 'ok',
                    'message' => '连接测试成功',
                ];

            case '27': // A2 27 - 读取开分表（仅Slot）
                return [
                    'open_table' => (int)($service->open_point ?? 0),
                ];

            case '28': // A2 28 - 读取洗分表（仅Slot）
                return [
                    'wash_table' => (int)($service->wash_point ?? 0),
                ];

            case '2B': // A2 2B - 读取BB Rush（仅Jackpot）
                return [
                    'bb_status' => (int)($service->bb_status ?? 0),
                    'rush_status' => (int)($service->rush_status ?? 0),
                ];

            case '2D': // A2 2D - 大赏灯切换（仅Jackpot）
                return [
                    'reward_status' => (int)($service->reward_status ?? 0),
                ];

            case '2E00': // A2 2E 00 - PUSH停止
            case '2E01': // A2 2E 01 - PUSH 1下
            case '2E02': // A2 2E 02 - PUSH 2Hz
            case '2E03': // A2 2E 03 - PUSH 5Hz
                return [
                    'push_auto' => (int)($service->push_auto ?? 0),
                ];

            case '41': // A2 41 - 开分一次
            case '42': // A2 42 - 开分10次
            case '4A': // A2 4A - 开任意数
                return [
                    'point' => (int)($service->point ?? 0),
                    'card_score' => (int)($service->score ?? 0),
                ];

            case '43': // A2 43 - 洗分&清零
            case '44': // A2 44 - 洗分（Slot）/ 洗分留余数（Jackpot）
                return [
                    'point' => (int)($service->point ?? 0),
                    'wash_point' => (int)($service->wash_point ?? 0),
                ];

            case '45': // A2 45 - 移分ON（Slot）/ 自动上转（Jackpot）
                if ($machineType == GameType::TYPE_SLOT) {
                    return [
                        'move_point' => (int)($service->move_point ?? 0),
                    ];
                } else {
                    return [
                        'auto' => (int)($service->auto ?? 0),
                        'turn' => (int)($service->turn ?? 0),
                    ];
                }

            case '46': // A2 46 - 移分OFF（Slot）/ 重置预备转数（Jackpot）
                if ($machineType == GameType::TYPE_SLOT) {
                    return [
                        'move_point' => (int)($service->move_point ?? 0),
                    ];
                } else {
                    return [
                        'turn' => (int)($service->turn ?? 0),
                        'message' => '预备转数已重置',
                    ];
                }

            case '47': // A2 47 - 清除统计（Slot）/ 全部下转（Jackpot）
                if ($machineType == GameType::TYPE_SLOT) {
                    return [
                        'bet' => (int)($service->bet ?? 0),
                        'win' => (int)($service->win ?? 0),
                        'bb' => (int)($service->bb ?? 0),
                        'rb' => (int)($service->rb ?? 0),
                    ];
                } else {
                    return [
                        'turn' => (int)($service->turn ?? 0),
                        'point' => (int)($service->point ?? 0),
                    ];
                }

            case '48': // A2 48 - 转数转分数（下转一次，仅Jackpot）
                return [
                    'turn' => (int)($service->turn ?? 0),
                    'point' => (int)($service->point ?? 0),
                ];

            case '49': // A2 49
                if ($machineType == GameType::TYPE_SLOT) {
                    // Slot: 开分5次
                    return [
                        'point' => (int)($service->point ?? 0),
                    ];
                } else {
                    // Jackpot: 分数转转数（上转一次）
                    return [
                        'turn' => (int)($service->turn ?? 0),
                        'point' => (int)($service->point ?? 0),
                    ];
                }

            case '4B': // A2 4B - 得分转分数（仅Jackpot）
                return [
                    'score' => (int)($service->score ?? 0),
                    'point' => (int)($service->point ?? 0),
                ];

            case '4C': // A2 4C - 全部上转（仅Jackpot）
                return [
                    'turn' => (int)($service->turn ?? 0),
                    'point' => (int)($service->point ?? 0),
                ];

            case '4D': // A2 4D - 开保转（仅Jackpot）
                return [
                    'turn' => (int)($service->turn ?? 0),
                    'message' => 'OP_3保转已开启',
                ];

            case '4E': // A2 4E - 清除开赠要求（仅Jackpot）
                return [
                    'message' => '开赠要求已清除',
                ];

            case '4F': // A2 4F - 清除历史记录（仅Jackpot）
                return [
                    'message' => '历史记录已清除',
                ];

            default:
                // 未知指令，返回所有状态数据
                return $this->getMachineCurrentData();
        }
    }

    // ==================== 控制指令 ====================

    /**
     * 判断是否为控制指令
     */
    private function isControlOperation(string $action): bool
    {
        $slotActions = [
            'move_point_off', 'bet',  // ✅ 新增：移分OFF、押分
            'start', 'auto', 'stop_auto',
            'out_1_pulse', 'stop_1', 'stop_2', 'stop_3',
        ];

        $jackpotActions = [
            'reward_switch', 'plc_start_or_stop', 'plc_push_5hz',
            'plc_push_stop', 'plc_down_turn', 'all_down_turn',
            'plc_up_turn_100', 'all_up_turn',
        ];

        return in_array($action, array_merge($slotActions, $jackpotActions));
    }

    /**
     * 执行控制指令
     */
    private function executeControlOperation(string $action, array $params): array
    {
        if ($this->machine->type == GameType::TYPE_SLOT) {
            return $this->executeSlotControl($action, $params);
        } else {
            return $this->executeJackpotControl($action, $params);
        }
    }

    /**
     * 斯洛机控制指令
     *
     * 区分双美和小淞：
     * - start: 双美需要 MOVE_POINT_ON + PRESSURE + START，小淞只需 START
     * - auto: 双美需要 MOVE_POINT_ON + OUT_ON，小淞只需 OUT_ON
     * - out_1_pulse: 双美用 OUTPUT+U1_PULSE，小淞用 REWARD_SWITCH
     */
    private function executeSlotControl(string $action, array $params): array
    {
        $controlType = $this->machine->control_type;
        $movePoint = $params['move_point'] ?? 0;

        switch ($action) {
            case 'move_point_off':
                // 移分OFF（仅双美斯洛）
                if ($controlType === Machine::CONTROL_TYPE_MEI) {
                    $this->sendCmd($this->services::MOVE_POINT_OFF);
                    Log::channel('machine_operations')->info('[SlotControl] 发送 MOVE_POINT_OFF (双美)', [
                        'machine_id' => $this->machine->id,
                    ]);
                }
                break;

            case 'bet':
                // 押分（仅双美斯洛）
                // 先关闭移分，再发送压分指令
                if ($controlType === Machine::CONTROL_TYPE_MEI) {
                    $this->sendCmd($this->services::MOVE_POINT_OFF);
                    Log::channel('machine_operations')->info('[SlotControl] 发送 MOVE_POINT_OFF (双美)', [
                        'machine_id' => $this->machine->id,
                    ]);

                    $this->sendCmd($this->services::PRESSURE);
                    Log::channel('machine_operations')->info('[SlotControl] 发送 PRESSURE (双美)', [
                        'machine_id' => $this->machine->id,
                    ]);
                }
                break;

            case 'start':
                // 条件1: 移分开关（仅双美斯洛）
                if ($controlType === Machine::CONTROL_TYPE_MEI && $movePoint == 0) {
                    $this->sendCmd($this->services::MOVE_POINT_ON);
                    Log::channel('machine_operations')->info('[SlotControl] 发送 MOVE_POINT_ON (双美)', [
                        'machine_id' => $this->machine->id,
                    ]);
                }

                // 条件2: 压分读取（仅双美斯洛）
                if ($controlType === Machine::CONTROL_TYPE_MEI) {
                    $this->sendCmd($this->services::PRESSURE);
                    Log::channel('machine_operations')->info('[SlotControl] 发送 PRESSURE (双美)', [
                        'machine_id' => $this->machine->id,
                    ]);
                }

                // 条件3: 开始指令（所有斯洛机）
                $this->sendCmd($this->services::START);
                Log::channel('machine_operations')->info('[SlotControl] 发送 START', [
                    'machine_id' => $this->machine->id,
                    'control_type' => $controlType === Machine::CONTROL_TYPE_MEI ? '双美' : '小淞',
                ]);
                break;

            case 'auto':
                // 条件1: 移分开关（仅双美斯洛）
                if ($controlType === Machine::CONTROL_TYPE_MEI && $movePoint == 0) {
                    $this->sendCmd($this->services::MOVE_POINT_ON);
                    Log::channel('machine_operations')->info('[SlotControl] 发送 MOVE_POINT_ON (双美)', [
                        'machine_id' => $this->machine->id,
                    ]);
                }

                // 条件2: 开启自动出分（所有斯洛机）
                $this->sendCmd($this->services::OUT_ON);
                Log::channel('machine_operations')->info('[SlotControl] 发送 OUT_ON', [
                    'machine_id' => $this->machine->id,
                    'control_type' => $controlType === Machine::CONTROL_TYPE_MEI ? '双美' : '小淞',
                ]);
                break;

            case 'stop_auto':
                // 关闭自动出分（所有斯洛机）
                $this->sendCmd($this->services::OUT_OFF);
                Log::channel('machine_operations')->info('[SlotControl] 发送 OUT_OFF', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            case 'out_1_pulse':
                // 出1脉冲（厂商区分）
                if ($controlType === Machine::CONTROL_TYPE_SONG) {
                    // 小淞：REWARD_SWITCH
                    $this->sendCmd($this->services::REWARD_SWITCH);
                    Log::channel('machine_operations')->info('[SlotControl] 发送 REWARD_SWITCH (小淞)', [
                        'machine_id' => $this->machine->id,
                    ]);
                } else {
                    // 双美：OUTPUT + U1_PULSE
                    $this->sendCmd($this->services::OUTPUT . $this->services::U1_PULSE);
                    Log::channel('machine_operations')->info('[SlotControl] 发送 OUTPUT+U1_PULSE (双美)', [
                        'machine_id' => $this->machine->id,
                    ]);
                }
                break;

            case 'stop_1':
                // 停止转轴1（所有斯洛机）
                $this->sendCmd($this->services::STOP_ONE);
                Log::channel('machine_operations')->info('[SlotControl] 发送 STOP_ONE', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            case 'stop_2':
                // 停止转轴2（所有斯洛机）
                $this->sendCmd($this->services::STOP_TWO);
                Log::channel('machine_operations')->info('[SlotControl] 发送 STOP_TWO', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            case 'stop_3':
                // 停止转轴3（所有斯洛机）
                $this->sendCmd($this->services::STOP_THREE);
                Log::channel('machine_operations')->info('[SlotControl] 发送 STOP_THREE', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            default:
                throw new Exception(trans('unknown_slot_control_cmd', ['{action}' => $action], 'message', $this->lang));
        }

        return [
            'action' => $action,
            'machine_id' => $this->machine->id,
            'machine_type' => 'slot',
            'control_type' => $controlType === Machine::CONTROL_TYPE_MEI ? 'mei' : 'song',
        ];
    }

    /**
     * 钢珠机控制指令
     *
     * 钢珠机的双美和小淞指令大部分相同
     */
    private function executeJackpotControl(string $action, array $params): array
    {
        $auto = $params['auto'] ?? 0;

        switch ($action) {
            case 'reward_switch':
                $this->sendCmd($this->services::REWARD_SWITCH);
                Log::channel('machine_operations')->info('[JackpotControl] 发送 REWARD_SWITCH', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            case 'plc_start_or_stop':
                // 自动上转（开始游戏）
                $this->sendCmd($this->services::AUTO_UP_TURN);
                Log::channel('machine_operations')->info('[JackpotControl] 发送 AUTO_UP_TURN', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            case 'plc_push_5hz':
                // 连发 PUSH（5Hz）
                if ($this->machine->control_type === Machine::CONTROL_TYPE_MEI) {
                    // 双美：PUSH + PUSH_THREE
                    $this->sendCmd($this->services::PUSH . $this->services::PUSH_THREE);
                } else {
                    // 小淞：直接发送 PUSH_THREE
                    $this->sendCmd($this->services::PUSH_THREE);
                }
                Log::channel('machine_operations')->info('[JackpotControl] 发送 PUSH_THREE（连发）', [
                    'machine_id' => $this->machine->id,
                    'control_type' => $this->machine->control_type,
                ]);
                break;

            case 'plc_push_stop':
                // 停止 PUSH
                if ($this->machine->control_type === Machine::CONTROL_TYPE_MEI) {
                    // 双美：PUSH + PUSH_STOP
                    $this->sendCmd($this->services::PUSH . $this->services::PUSH_STOP);
                } else {
                    // 小淞：PUSH_ONE（单发 PUSH）
                    $this->sendCmd($this->services::PUSH_ONE);
                }
                Log::channel('machine_operations')->info('[JackpotControl] 发送 PUSH_STOP 指令', [
                    'machine_id' => $this->machine->id,
                    'control_type' => $this->machine->control_type,
                ]);
                break;

            case 'plc_down_turn':
                // 下转一次（转数转分数）
                $this->sendCmd($this->services::TURN_TO_POINT);
                Log::channel('machine_operations')->info('[JackpotControl] 发送 TURN_TO_POINT（下转一次）', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            case 'all_down_turn':
                // 全部下转
                $this->sendCmd($this->services::TURN_DOWN_ALL);
                Log::channel('machine_operations')->info('[JackpotControl] 发送 TURN_DOWN_ALL（全部下转）', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            case 'plc_up_turn_100':
                // 上转一次（分数转转数）
                $this->sendCmd($this->services::POINT_TO_TURN);
                Log::channel('machine_operations')->info('[JackpotControl] 发送 POINT_TO_TURN（上转一次）', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            case 'all_up_turn':
                // 全部上转
                $this->sendCmd($this->services::TURN_UP_ALL);
                Log::channel('machine_operations')->info('[JackpotControl] 发送 TURN_UP_ALL（全部上转）', [
                    'machine_id' => $this->machine->id,
                ]);
                break;

            default:
                throw new Exception(trans('unknown_jackpot_control_cmd', ['{action}' => $action], 'message', $this->lang));
        }

        return [
            'action' => $action,
            'machine_id' => $this->machine->id,
            'machine_type' => 'jackpot',
            'control_type' => $this->machine->control_type === Machine::CONTROL_TYPE_MEI ? 'mei' : 'song',
        ];
    }

    // ==================== 高级操作 ====================

    /**
     * 判断是否为高级操作
     */
    private function isAdvancedOperation(string $action): bool
    {
        return in_array($action, [
            'kick_player',        // 踢出玩家（洗分）
            'force_kick_player',  // 强制踢出（不返还分数）
            'custom_open_score',  // 自定义开分
            'unlock',             // ✅ 新增：解锁机台
            'reset',              // ✅ 新增：归0机板（小淞线下专用）
        ]);
    }

    /**
     * 执行高级操作
     *
     * 高级操作只允许后台管理员执行
     */
    private function executeAdvancedOperation(string $action, array $params): array
    {
        // 权限检查：高级操作只允许后台管理员
        if ($this->operatorType !== self::OPERATOR_ADMIN) {
            throw new Exception(trans('advanced_operation_admin_only', [], 'message', $this->lang));
        }

        switch ($action) {
            case 'kick_player':
                return $this->kickPlayer($params);
            case 'force_kick_player':
                return $this->forceKickPlayer($params);
            case 'custom_open_score':
                return $this->customOpenScore($params);
            case 'unlock':  // ✅ 新增：解锁机台
                return $this->unlockMachine($params);
            case 'reset':   // ✅ 新增：归0机板
                return $this->resetMachine($params);
            default:
                throw new Exception(trans('unknown_advanced_operation', ['{action}' => $action], 'message', $this->lang));
        }
    }

    /**
     * 踢出玩家（洗分）
     *
     * 注意：此方法将从 AdminMachineController::kickPlayer 迁移过来
     */
    private function kickPlayer(array $params): array
    {
        // TODO: 从 AdminMachineController 迁移实现
        Log::channel('machine_operations')->warning('[AdvancedOperation] kickPlayer 尚未实现', [
            'machine_id' => $this->machine->id,
            'params' => $params,
        ]);

        throw new Exception(trans('kick_player_not_migrated', [], 'message', $this->lang));
    }

    /**
     * 强制踢出玩家（不返还分数）
     *
     * 注意：此方法将从 AdminMachineController::forceKickPlayer 迁移过来
     */
    private function forceKickPlayer(array $params): array
    {
        // TODO: 从 AdminMachineController 迁移实现
        Log::channel('machine_operations')->warning('[AdvancedOperation] forceKickPlayer 尚未实现', [
            'machine_id' => $this->machine->id,
            'params' => $params,
        ]);

        throw new Exception(trans('force_kick_player_not_migrated', [], 'message', $this->lang));
    }

    /**
     * 自定义开分
     *
     * 注意：此方法将从 AdminMachineController::customOpenScore 迁移过来
     */
    private function customOpenScore(array $params): array
    {
        // TODO: 从 AdminMachineController 迁移实现
        Log::channel('machine_operations')->warning('[AdvancedOperation] customOpenScore 尚未实现', [
            'machine_id' => $this->machine->id,
            'params' => $params,
        ]);

        throw new Exception(trans('custom_open_score_not_migrated', [], 'message', $this->lang));
    }

    /**
     * ✅ 新增：解锁机台
     *
     * 清除锁定状态，允许玩家继续使用机台
     */
    private function unlockMachine(array $params): array
    {
        // 更新Redis缓存
        $this->services->has_lock = 0;

        // 更新数据库（只更新单个字段，减少锁竞争）
        Machine::where('id', $this->machine->id)->update(['has_lock' => 0]);

        Log::channel('machine_operations')->info('[AdvancedOperation] 机台解锁成功', [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'operator_type' => $this->operatorType,
            'operator_id' => $this->operatorId,
        ]);

        return [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'has_lock' => 0,
            'message' => '机台已解锁',
        ];
    }

    /**
     * ✅ 新增：归0机板（故障排除）
     *
     * 发送归0指令（A3 70 05 E0 F8 CE），清除机台内存错误
     *
     * ⚠️ 注意：
     * - 仅支持小淞线下版（SongOfflineSlot/SongOfflineJackpot）
     * - 会清除开分码表和洗分码表
     * - 自动解除锁定状态
     */
    private function resetMachine(array $params): array
    {
        // 检查是否支持reset操作
        $supportedClasses = [
            \app\service\machine\SongOfflineSlot::class,
            \app\service\machine\SongOfflineJackpot::class,
        ];

        if (!in_array(get_class($this->services), $supportedClasses)) {
            throw new Exception('归0操作仅支持小淞线下版机台（收账小卡协议）');
        }

        // 发送归0指令（通过sendCmd调用handleCheckCommand）
        Log::channel('machine_operations')->info('[AdvancedOperation] 准备发送归0指令', [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'operator_type' => $this->operatorType,
            'operator_id' => $this->operatorId,
            'cmd' => $this->services::RESET_BOARD,
            'has_lock' => $this->machine->has_lock,
        ]);

        $this->services->sendCmd(
            $this->services::RESET_BOARD,
            0,
            $this->operatorType,
            $this->operatorId
        );

        // 自动解锁机台
        $this->services->has_lock = 0;
        $this->machine->has_lock = 0;
        $this->machine->save();

        Log::channel('machine_operations')->info('[AdvancedOperation] 机台归0成功', [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'operator_type' => $this->operatorType,
            'operator_id' => $this->operatorId,
            'note' => '已发送归0指令并解锁机台',
        ]);

        return [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'has_lock' => 0,
            'message' => '归0指令已发送，机台已解锁',
            'command' => 'RESET_BOARD (A3 70 05 E0 F8 CE)',
        ];
    }

    // ==================== 辅助方法 ====================

    /**
     * 发送硬件指令
     *
     * 自动传递操作者信息用于日志追踪
     */
    private function sendCmd(string $cmd): void
    {
        $this->services->sendCmd(
            $cmd,
            0,
            $this->operatorType,
            $this->operatorId
        );
    }

    /**
     * 记录操作日志
     */
    private function logOperation(
        string $stage,
        string $action,
        array $params,
        array $result = [],
        ?Exception $exception = null
    ): void {
        $context = [
            'stage' => $stage,
            'machine_id' => $this->machine->id,
            'machine_type' => $this->machine->type == GameType::TYPE_SLOT ? 'slot' : 'jackpot',
            'control_type' => $this->machine->control_type === Machine::CONTROL_TYPE_MEI ? 'mei' : 'song',
            'action' => $action,
            'operator_type' => $this->operatorType,
            'operator_id' => $this->operatorId,
            'params' => $params,
        ];

        if ($exception) {
            $context['error'] = $exception->getMessage();
            $context['trace'] = $exception->getTraceAsString();
            Log::channel('machine_operations')->error('[MachineOperationService] 操作失败', $context);
        } else {
            if ($stage === 'success') {
                $context['result'] = $result;
            }
            Log::channel('machine_operations')->info("[MachineOperationService] {$stage}", $context);
        }
    }

    /**
     * 获取机台类型描述
     */
    public function getMachineTypeDescription(): string
    {
        $machineType = $this->machine->type == GameType::TYPE_SLOT ? '斯洛机' : '钢珠机';
        $controlType = $this->machine->control_type === Machine::CONTROL_TYPE_MEI ? '双美' : '小淞';

        return "{$machineType} ({$controlType})";
    }

    /**
     * 获取操作者描述
     */
    public function getOperatorDescription(): string
    {
        switch ($this->operatorType) {
            case self::OPERATOR_PLAYER:
                return "玩家 #{$this->operatorId}";
            case self::OPERATOR_ADMIN:
                return "管理员 #{$this->operatorId}";
            case self::OPERATOR_SYSTEM:
                return "系统";
            default:
                return "未知操作者";
        }
    }

    /**
     * 洗分（完整业务逻辑）
     *
     * 调用完整的 machineWash 函数处理所有业务逻辑：
     * - 硬件指令发送
     * - 数据库事务
     * - 钱包操作
     * - 游戏记录
     * - 彩金处理
     * - 活动结算
     */
    private function wash(array $params): array
    {
        // 验证必需参数
        if (!isset($params['player_id'])) {
            throw new Exception(trans('missing_parameter', ['{param}' => 'player_id'], 'message', $this->lang));
        }

        if (!isset($params['action']) || !in_array($params['action'], ['leave', 'down', 'switch'])) {
            throw new Exception(trans('invalid_wash_action', [], 'message', $this->lang));
        }

        // 获取玩家
        // ✅ 预加载 recommend_promoter 和 national_promoter 关系，避免 N+1 查询
        /** @var Player $player */
        $player = Player::with(['recommend_promoter', 'recommend_promoter.national_promoter'])
            ->find($params['player_id']);
        if (!$player) {
            throw new Exception(trans('player_not_found', [], 'message', $this->lang));
        }

        // 准备参数
        // 注意：down（下分）、leave（弃台）、switch（换台）是不同的业务逻辑
        // - down: 仅下分，不做清理操作
        // - leave: 弃台，包含下转、停push等清理操作
        // - switch: 换台，从一台机器切换到另一台
        $action = $params['action'];
        $isSystem = $params['is_system'] ?? 0;
        $hasLottery = $params['has_lottery'] ?? false;
        $adminId = $this->operatorType === self::OPERATOR_ADMIN ? $this->operatorId : 0;
        $adminUsername = $params['admin_username'] ?? '';

        // 调用完整的 machineWash 函数
        $result = \machineWash(
            $player,
            $this->machine,
            $action,
            $isSystem,
            $hasLottery,
            $adminId,
            $adminUsername
        );

        // 记录 machineWash 返回结果
        Log::channel('machine_operations')->info('[MachineOperationService::wash] machineWash 返回结果', [
            'machine_id' => $this->machine->id,
            'machine_code' => $this->machine->code,
            'player_id' => $player->id,
            'action' => $action,
            'has_lottery' => $hasLottery,
            'result_type' => gettype($result),
            'result' => $result instanceof \app\model\PlayerLotteryRecord ? $result->toArray() : $result,
        ]);

        // 处理返回结果
        if ($result === false) {
            throw new Exception(trans('wash_failed', [], 'message', $this->lang));
        }

        if (is_array($result)) {
            if(array_key_exists('has_win',$result)){
                // 彩金预检查命中（has_lottery=1 时）
                // 直接返回彩金数据给客户端，由客户端决定是否继续下分
                return [
                    'success' => true,
                    'has_win' => $result['has_win'],
                    'lottery_name' => $result['lottery_name'],
                    'amount' => $result['amount'],
                    'current_condition' => $result['current_condition'],
                    'next_lottery' => $result['next_lottery'],
                    'lottery_hint' => $result['lottery_hint'],
                ];
            }else{
                return [
                    'success' => true,
                    'wash_point' => $result['wash_point'] ?? 0,
                    'gaming_turn_point' => $result['gaming_turn_point'] ?? 0,
                    'gaming_pressure' => $result['gaming_pressure'] ?? 0,
                    'gaming_score' => $result['gaming_score'] ?? 0,
                ];
            }
        }

        // PlayerLotteryRecord 对象（中奖 - has_lottery=0 确认下分时触发）
        return [
            'success' => true,
            'has_lottery' => true,
            'lottery_record' => $result,
        ];
    }

    /**
     * 上分（完整业务逻辑）
     *
     * 调用完整的 machineOpenAnyFree 函数处理所有业务逻辑：
     * - 硬件指令发送
     * - 数据库事务
     * - 钱包操作
     * - 游戏记录
     * @throws Exception
     */
    private function open(array $params): array
    {
        // 验证必需参数
        if (!isset($params['player_id'])) {
            throw new Exception(trans('missing_parameter', ['{param}' => 'player_id'], 'message', $this->lang));
        }

        if (!isset($params['open_score']) || $params['open_score'] <= 0) {
            throw new Exception(trans('invalid_open_score', [], 'message', $this->lang));
        }

        // 获取玩家
        // ✅ 预加载 recommend_promoter 和 national_promoter 关系，避免 N+1 查询
        /** @var Player $player */
        $player = Player::with(['recommend_promoter', 'recommend_promoter.national_promoter'])
            ->find($params['player_id']);
        if (!$player) {
            throw new Exception(trans('player_not_found', [], 'message', $this->lang));
        }

        // 准备参数
        $openScore = (int) $params['open_score'];
        $giftScore = (int) ($params['gift_score'] ?? 0);
        $giveRuleId = isset($params['give_rule_id']) ? (int) $params['give_rule_id'] : null;
        $adminId = $this->operatorType === self::OPERATOR_ADMIN ? $this->operatorId : 0;
        $adminUsername = $params['admin_username'] ?? '';

        // 调用完整的 machineOpenAnyFree 函数
        $result = \machineOpenAnyFree(
            $player,
            $this->machine,
            $openScore,
            $adminId,
            $adminUsername,
            $giftScore,
            $giveRuleId
        );

        if ($result === false) {
            throw new Exception(trans('open_machine_failed', [], 'message', $this->lang));
        }

        return [
            'success' => true,
            'open_score' => $openScore,
            'machine_id' => $this->machine->id,
            'player_id' => $player->id,
        ];
    }

    /**
     * 清除 Machine 模型缓存
     *
     * 用途：在上分/下分/弃台等操作后，手动清除 Machine 缓存
     * 原因：Workerman 多进程环境下，模型事件（booted/updated）不可靠
     *       - 每个进程独立 boot 模型，boot 时机不确定
     *       - 导致 Events.php 可能获取到缓存的旧 Machine 对象
     *       - 旧对象的 gaming_user_id 可能为 0，导致心跳处理异常
     *
     * @param Machine $machine 机台对象
     * @return array 返回删除结果
     */
    public static function clearMachineCache(Machine $machine): array
    {
        $result = [
            'main_cache_key' => null,
            'main_deleted' => false,
            'auto_card_cache_key' => null,
            'auto_card_deleted' => false,
        ];

        // 生成主缓存 key
        $mainCacheKey = sprintf('machine:domain:%s:port:%s:type:%s',
            $machine->domain, $machine->port, $machine->type
        );
        $result['main_cache_key'] = $mainCacheKey;
        $result['main_deleted'] = \support\Cache::delete($mainCacheKey);

        // 如果是 Slot 机台且配置了 auto_card，也需要删除 auto_card 缓存
        if ($machine->type == GameType::TYPE_SLOT
            && !empty($machine->auto_card_domain)
            && !empty($machine->auto_card_port)
        ) {
            $autoCardCacheKey = sprintf('machine:domain:%s:port:%s:type:%s',
                $machine->auto_card_domain, $machine->auto_card_port, $machine->type
            );
            $result['auto_card_cache_key'] = $autoCardCacheKey;
            $result['auto_card_deleted'] = \support\Cache::delete($autoCardCacheKey);
        }

        // 记录日志
        Log::info('[MachineOperationService] 清除 Machine 缓存', [
            'machine_id' => $machine->id,
            'machine_code' => $machine->code,
            'machine_type' => $machine->type,
            'main_cache_key' => $result['main_cache_key'],
            'main_deleted' => $result['main_deleted'],
            'auto_card_cache_key' => $result['auto_card_cache_key'],
            'auto_card_deleted' => $result['auto_card_deleted'],
        ]);

        return $result;
    }
}