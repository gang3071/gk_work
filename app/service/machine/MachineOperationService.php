<?php

namespace app\service\machine;

use app\model\GameType;
use app\model\Machine;
use app\model\Player;
use GatewayWorker\Lib\Gateway;
use support\Log;
use Exception;

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
     * 根据机台类型和控制类型选择对应的服务类：
     * - 斯洛机 + 双美 → Slot
     * - 斯洛机 + 小淞 → SongSlot
     * - 钢珠机 + 双美 → Jackpot
     * - 钢珠机 + 小淞 → SongJackpot
     */
    private function initServices(): void
    {
        if ($this->machine->type == GameType::TYPE_SLOT) {
            // 斯洛机
            $serviceClass = ($this->machine->control_type === Machine::CONTROL_TYPE_MEI)
                ? \app\service\machine\Slot::class
                : \app\service\machine\SongSlot::class;
        } else {
            // 钢珠机 (TYPE_STEEL_BALL) 或其他类型
            $serviceClass = ($this->machine->control_type === Machine::CONTROL_TYPE_MEI)
                ? \app\service\machine\Jackpot::class
                : \app\service\machine\SongJackpot::class;
        }

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
                'message' => '操作成功',
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

        throw new Exception("不支持的操作: {$action}");
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
            default:
                throw new Exception("未知的基础操作: {$action}");
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
                throw new Exception("未知的业务操作: {$action}");
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
            throw new Exception('缺少必要参数: cmd');
        }

        // 直接调用底层服务发送 TCP 指令
        $result = $this->services->sendCmd(
            $cmd,
            $data,
            $isSystem,
            $this->operatorId
        );

        return [
            'success' => $result,
            'cmd' => $cmd,
            'data' => $data,
            'machine_id' => $this->machine->id,
        ];
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
                throw new Exception("未知的斯洛机控制指令: {$action}");
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
                throw new Exception("未知的钢珠机控制指令: {$action}");
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
            throw new Exception('高级操作仅限后台管理员');
        }

        switch ($action) {
            case 'kick_player':
                return $this->kickPlayer($params);
            case 'force_kick_player':
                return $this->forceKickPlayer($params);
            case 'custom_open_score':
                return $this->customOpenScore($params);
            default:
                throw new Exception("未知的高级操作: {$action}");
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

        throw new Exception('kickPlayer 功能尚未迁移，请使用原接口');
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

        throw new Exception('forceKickPlayer 功能尚未迁移，请使用原接口');
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

        throw new Exception('customOpenScore 功能尚未迁移，请使用原接口');
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
            throw new Exception('缺少参数: player_id');
        }

        if (!isset($params['action']) || !in_array($params['action'], ['leave', 'down', 'switch'])) {
            throw new Exception('缺少或无效的参数: action (必须是 leave/down/switch)');
        }

        // 获取玩家
        // ✅ 预加载 recommend_promoter 和 national_promoter 关系，避免 N+1 查询
        $player = Player::with(['recommend_promoter', 'recommend_promoter.national_promoter'])
            ->find($params['player_id']);
        if (!$player) {
            throw new Exception('玩家不存在');
        }

        // 准备参数
        // ✅ 兼容 gk_api: 'down' 映射为 'leave'
        $action = $params['action'] === 'down' ? 'leave' : $params['action'];
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

        // 处理返回结果
        if ($result === false) {
            throw new Exception('洗分失败');
        }

        if (is_array($result)) {
            return [
                'success' => true,
                'wash_point' => $result['wash_point'] ?? 0,
                'gaming_turn_point' => $result['gaming_turn_point'] ?? 0,
                'gaming_pressure' => $result['gaming_pressure'] ?? 0,
                'gaming_score' => $result['gaming_score'] ?? 0,
            ];
        }

        // PlayerLotteryRecord 对象（中奖）
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
     */
    private function open(array $params): array
    {
        // 验证必需参数
        if (!isset($params['player_id'])) {
            throw new Exception('缺少参数: player_id');
        }

        if (!isset($params['open_score']) || $params['open_score'] <= 0) {
            throw new Exception('缺少或无效的参数: open_score (必须大于0)');
        }

        // 获取玩家
        // ✅ 预加载 recommend_promoter 和 national_promoter 关系，避免 N+1 查询
        $player = Player::with(['recommend_promoter', 'recommend_promoter.national_promoter'])
            ->find($params['player_id']);
        if (!$player) {
            throw new Exception('玩家不存在');
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
            throw new Exception('上分失败');
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