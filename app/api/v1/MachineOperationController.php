<?php

namespace app\api\v1;

use support\Request;
use support\Response;
use addons\webman\model\Machine;
use addons\webman\model\Player;
use app\service\machine\MachineOperationService;
use support\Log;
use Exception;

/**
 * 机台操作统一控制器
 *
 * 职责：
 * 1. 接收来自 gk_api（玩家端）和 gk_admin（后台）的请求
 * 2. 验证请求参数
 * 3. 确定操作者类型（player / admin / system）
 * 4. 调用 MachineOperationService 统一处理
 * 5. 返回标准响应
 *
 * 路由：
 * - POST /api/v1/machine/execute (玩家端)
 * - POST /api/admin/machine/execute (后台)
 *
 * @author Claude Code
 * @date 2026-07-20
 */
class MachineOperationController extends BaseController
{
    /**
     * 执行机台操作（统一入口）
     *
     * POST /api/v1/machine/execute (玩家端)
     * POST /api/admin/machine/execute (后台)
     *
     * 请求参数：
     * - machine_id: int 机台 ID（必填）
     * - action: string 操作名称（必填）
     * - params: array 操作参数（选填）
     *
     * @param Request $request
     * @return Response
     */
    public function execute(Request $request): Response
    {
        $machineId = null;
        $action = null;

        try {
            // 1. 验证基础参数
            $machineId = $request->post('machine_id');
            $action = $request->post('action');
            $params = $request->post('params', []);

            if (!$machineId || !$action) {
                return $this->fail('缺少必要参数: machine_id 和 action');
            }

            // 2. 获取机台
            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在');
            }

            // 3. 确定操作者类型和 ID
            list($operatorType, $operatorId) = $this->getOperatorInfo($request);

            // 4. 获取语言
            $lang = $this->setLanguage($request);

            // 5. 记录请求日志
            Log::channel('machine_operations')->info('[MachineOperationController] 收到请求', [
                'machine_id' => $machineId,
                'action' => $action,
                'operator_type' => $operatorType,
                'operator_id' => $operatorId,
                'params' => $params,
                'route' => $request->path(),
            ]);

            // 6. 创建服务实例
            $service = new MachineOperationService(
                $machine,
                $operatorType,
                $operatorId,
                $lang
            );

            // 7. 执行操作
            $result = $service->execute($action, $params);

            // 8. 返回结果
            if ($result['success']) {
                return $this->success($result['data'], $result['message']);
            } else {
                return $this->fail($result['message']);
            }

        } catch (Exception $e) {
            return $this->handleException($e, '机台操作失败', [
                'machine_id' => $machineId,
                'action' => $action,
            ]);
        }
    }

    /**
     * 获取操作者信息
     *
     * 返回 [operatorType, operatorId]
     *
     * 判断逻辑：
     * 1. 如果路由包含 /api/admin/ → 后台管理员
     * 2. 如果路由包含 /api/v1/ 且有玩家信息 → 玩家
     * 3. 否则 → 系统
     *
     * @param Request $request
     * @return array [string $operatorType, int $operatorId]
     */
    private function getOperatorInfo(Request $request): array
    {
        $path = $request->path();

        // 1. 后台管理员
        if (strpos($path, '/api/admin/') !== false) {
            // 后台操作（暂时使用 0，后续可以从 session 或 header 获取管理员 ID）
            return [MachineOperationService::OPERATOR_ADMIN, 0];
        }

        // 2. 玩家操作
        if (strpos($path, '/api/v1/') !== false) {
            try {
                $player = $this->getPlayer($request);
                if ($player) {
                    return [MachineOperationService::OPERATOR_PLAYER, $player->id];
                }
            } catch (Exception $e) {
                // 获取玩家信息失败，继续判断为系统操作
                Log::channel('machine_operations')->warning('[MachineOperationController] 获取玩家信息失败', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. 系统操作
        return [MachineOperationService::OPERATOR_SYSTEM, 0];
    }

    /**
     * 批量执行操作（用于后台批量操作）
     *
     * POST /api/admin/machine/batch-execute
     *
     * 请求参数：
     * - machine_ids: array 机台 ID 数组（必填）
     * - action: string 操作名称（必填）
     * - params: array 操作参数（选填）
     *
     * @param Request $request
     * @return Response
     */
    public function batchExecute(Request $request): Response
    {
        try {
            // 1. 验证参数
            $machineIds = $request->post('machine_ids', []);
            $action = $request->post('action');
            $params = $request->post('params', []);

            if (empty($machineIds) || !$action) {
                return $this->fail('缺少必要参数: machine_ids 和 action');
            }

            if (!is_array($machineIds)) {
                return $this->fail('machine_ids 必须是数组');
            }

            // 2. 确定操作者信息
            list($operatorType, $operatorId) = $this->getOperatorInfo($request);

            // 3. 只允许后台管理员批量操作
            if ($operatorType !== MachineOperationService::OPERATOR_ADMIN) {
                return $this->fail('批量操作仅限后台管理员');
            }

            // 4. 获取语言
            $lang = $this->setLanguage($request);

            // 5. 批量执行
            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($machineIds as $machineId) {
                try {
                    $machine = Machine::find($machineId);
                    if (!$machine) {
                        $results[] = [
                            'machine_id' => $machineId,
                            'success' => false,
                            'message' => '机台不存在',
                        ];
                        $failCount++;
                        continue;
                    }

                    $service = new MachineOperationService(
                        $machine,
                        $operatorType,
                        $operatorId,
                        $lang
                    );

                    $result = $service->execute($action, $params);

                    $results[] = [
                        'machine_id' => $machineId,
                        'success' => $result['success'],
                        'message' => $result['message'],
                        'data' => $result['data'],
                    ];

                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }

                } catch (Exception $e) {
                    $results[] = [
                        'machine_id' => $machineId,
                        'success' => false,
                        'message' => $e->getMessage(),
                    ];
                    $failCount++;
                }
            }

            // 6. 返回汇总结果
            return $this->success([
                'total' => count($machineIds),
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results,
            ], "批量操作完成：成功 {$successCount} 个，失败 {$failCount} 个");

        } catch (Exception $e) {
            return $this->handleException($e, '批量操作失败', [
                'action' => $action ?? null,
            ]);
        }
    }

    /**
     * 获取支持的操作列表
     *
     * GET /api/v1/machine/operations
     * GET /api/admin/machine/operations
     *
     * @param Request $request
     * @return Response
     */
    public function getOperations(Request $request): Response
    {
        try {
            $machineId = $request->get('machine_id');

            if (!$machineId) {
                return $this->fail('缺少参数: machine_id');
            }

            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在');
            }

            // 根据机台类型返回支持的操作
            if ($machine->game_type == \addons\webman\model\constant\GameType::TYPE_SLOT) {
                // 斯洛机操作
                $operations = [
                    'basic' => [
                        'query_status' => '查询状态',
                        'check_online' => '检查在线',
                        'get_description' => '获取描述',
                    ],
                    'control' => [
                        'start' => '开始游戏',
                        'auto' => '开启自动',
                        'stop_auto' => '关闭自动',
                        'out_1_pulse' => '出1脉冲',
                        'stop_1' => '停止转轴1',
                        'stop_2' => '停止转轴2',
                        'stop_3' => '停止转轴3',
                    ],
                ];
            } else {
                // 钢珠机操作
                $operations = [
                    'basic' => [
                        'query_status' => '查询状态',
                        'check_online' => '检查在线',
                        'get_description' => '获取描述',
                    ],
                    'control' => [
                        'reward_switch' => '奖励开关',
                        'plc_start_or_stop' => '开始/停止',
                        'plc_push_5hz' => '推球5Hz',
                        'plc_push_stop' => '停止推球',
                        'plc_down_turn' => '下转',
                        'all_down_turn' => '全部下转',
                        'plc_up_turn_100' => '上转100',
                        'all_up_turn' => '全部上转',
                    ],
                ];
            }

            // 后台管理员额外支持高级操作
            $path = $request->path();
            if (strpos($path, '/api/admin/') !== false) {
                $operations['advanced'] = [
                    'kick_player' => '踢出玩家',
                    'force_kick_player' => '强制踢出',
                    'custom_open_score' => '自定义开分',
                ];
            }

            return $this->success([
                'machine_id' => $machine->id,
                'machine_type' => $machine->game_type == \addons\webman\model\constant\GameType::TYPE_SLOT ? 'slot' : 'jackpot',
                'control_type' => $machine->control_type === Machine::CONTROL_TYPE_MEI ? 'mei' : 'song',
                'operations' => $operations,
            ]);

        } catch (Exception $e) {
            return $this->handleException($e, '获取操作列表失败');
        }
    }

    /**
     * 机台洗分（只执行硬件操作）
     *
     * POST /api/v1/machine/wash-point
     *
     * 请求参数：
     * - machine_id: int 机台 ID（必填）
     * - player_id: int 玩家 ID（必填）
     * - action: string 操作类型 (leave/down)（必填）
     * - machine_context: array 机台上下文信息（必填）
     * - wash_id: string 洗分唯一ID（必填）
     *
     * 职责：只负责硬件层操作
     * 1. 发送机台指令
     * 2. 读取机台状态
     * 3. 返回洗分数量
     *
     * 不负责：
     * - 数据库事务（由 gk_api 处理）
     * - 钱包操作（由 gk_api 处理）
     * - 游戏记录（由 gk_api 处理）
     *
     * @param Request $request
     * @return Response
     */
    public function washPoint(Request $request): Response
    {
        $washId = null;
        try {
            // 1. 获取请求参数
            $machineId = $request->post('machine_id');
            $playerId = $request->post('player_id');
            $action = $request->post('action', 'leave');
            $machineContext = $request->post('machine_context', []);
            $washId = $request->post('wash_id');
            $lang = $request->post('lang', 'zh_TW');

            locale($lang);

            // 2. 参数验证
            if (empty($machineId) || empty($playerId) || empty($washId)) {
                return $this->fail('缺少必填参数', 400);
            }

            Log::channel('machine_operations')->info('[WashPoint] 收到洗分请求', [
                'wash_id' => $washId,
                'machine_id' => $machineId,
                'player_id' => $playerId,
                'action' => $action,
            ]);

            // 3. 查询机台和玩家
            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在', 404);
            }

            $player = Player::find($playerId);
            if (!$player) {
                return $this->fail('玩家不存在', 404);
            }

            // 4. 创建机台服务（用于发送指令）
            $services = \app\service\machine\MachineServices::createServices($machine, $lang);

            // 5. 根据机台类型执行不同的洗分指令
            $washPoint = 0;
            $gamingTurnPoint = 0;
            $gamingPressure = 0;
            $gamingScore = 0;

            switch ($machine->type) {
                case \app\model\GameType::TYPE_STEEL_BALL:
                    // 钢珠机洗分
                    $washPoint = $this->washSteelBall($machine, $services, $action, $playerId);
                    $gamingTurnPoint = $services->player_win_number;
                    break;

                case \app\model\GameType::TYPE_SLOT:
                    // 斯洛机洗分
                    $result = $this->washSlot($machine, $services, $playerId);
                    $washPoint = $result['wash_point'];
                    $gamingPressure = $result['pressure'];
                    $gamingScore = $result['score'];
                    break;

                default:
                    return $this->fail('不支持的机台类型', 400);
            }

            Log::channel('machine_operations')->info('[WashPoint] 洗分完成', [
                'wash_id' => $washId,
                'machine_id' => $machineId,
                'wash_point' => $washPoint,
                'gaming_turn_point' => $gamingTurnPoint,
                'gaming_pressure' => $gamingPressure,
                'gaming_score' => $gamingScore,
            ]);

            // 6. 返回洗分结果
            return $this->success([
                'wash_point' => $washPoint,
                'gaming_turn_point' => $gamingTurnPoint,
                'gaming_pressure' => $gamingPressure,
                'gaming_score' => $gamingScore,
            ]);

        } catch (Exception $e) {
            Log::channel('machine_operations')->error('[WashPoint] 洗分失败', [
                'wash_id' => $washId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->handleException($e, '机台洗分失败');
        }
    }

    /**
     * 钢珠机洗分（只执行硬件操作）
     *
     * @param Machine $machine
     * @param \app\service\machine\MachineServices $services
     * @param string $action
     * @param int $playerId
     * @return int
     * @throws Exception
     */
    private function washSteelBall(
        Machine $machine,
        \app\service\machine\MachineServices $services,
        string $action,
        int $playerId
    ): int {
        // 弃台需要下转,下珠
        if ($action == 'leave') {
            if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
                // 双美：停止推珠 + 自动上转 + 得分转分 + 全部下转
                $services->sendCmd($services::PUSH . $services::PUSH_STOP, 0, 'player', $playerId);
                if ($services->auto == 1) {
                    $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $playerId);
                }
                if ($services->score > 0) {
                    $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $playerId);
                }
                if ($services->turn > 0) {
                    $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $playerId);
                }
            }

            if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
                // 小淞：自动上转 + 读取转数 + 读取得分 + 得分转分 + 全部下转
                if ($services->auto == 1) {
                    $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $playerId);
                }
                $services->sendCmd($services::MACHINE_TURN, 0, 'player', $playerId);
                $services->sendCmd($services::MACHINE_SCORE, 0, 'player', $playerId);
                if ($services->score > 0) {
                    $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $playerId);
                }
                if ($services->turn > 0) {
                    $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $playerId);
                }
            }
        }

        // 读取机台分数和中奖数
        $services->sendCmd($services::MACHINE_POINT, 0, 'player', $playerId);
        $services->sendCmd($services::WIN_NUMBER, 0, 'player', $playerId);

        // 返回洗分数量（不扣除赠点，由 gk_api 处理）
        return $services->point;
    }

    /**
     * 斯洛机洗分（只执行硬件操作）
     *
     * @param Machine $machine
     * @param \app\service\machine\MachineServices $services
     * @param int $playerId
     * @return array
     * @throws Exception
     */
    private function washSlot(
        Machine $machine,
        \app\service\machine\MachineServices $services,
        int $playerId
    ): array {
        // 关闭移点（双美）
        if ($services->move_point == 1 && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
            $services->sendCmd($services::MOVE_POINT_OFF, 0, 'player', $playerId);
        }

        // 关闭自动
        if ($services->auto == 1) {
            $services->sendCmd($services::OUT_OFF, 0, 'player', $playerId);
        }

        // 停止所有轴
        $services->sendCmd($services::STOP_ONE, 0, 'player', $playerId);
        $services->sendCmd($services::STOP_TWO, 0, 'player', $playerId);
        $services->sendCmd($services::STOP_THREE, 0, 'player', $playerId);

        // 读取得分
        $services->sendCmd($services::READ_SCORE, 0, 'player', $playerId);

        // 读取押分
        $services->sendCmd($services::READ_BET, 0, 'player', $playerId);

        // 计算压分和得分（不扣除 player_pressure 和 player_score，由 gk_api 处理）
        $gamingPressure = bcsub($services->bet, $services->player_pressure ?? 0);
        $gamingScore = bcsub($services->win, $services->player_score ?? 0);

        Log::channel('slot_machine')->info('[WashSlot] 斯洛机洗分', [
            'machine_code' => $machine->code,
            'point' => $services->point,
            'bet' => $services->bet,
            'win' => $services->win,
            'gaming_pressure' => $gamingPressure,
            'gaming_score' => $gamingScore,
        ]);

        return [
            'wash_point' => $services->point,
            'pressure' => max($gamingPressure, 0),
            'score' => max($gamingScore, 0),
        ];
    }

    /**
     * 检查机台在线状态
     *
     * POST /api/v1/machine/check-online
     *
     * 请求参数：
     * - machine_id: int 机台ID（必填）
     *
     * @param Request $request
     * @return Response
     */
    public function checkOnline(Request $request): Response
    {
        try {
            $machineId = $request->post('machine_id');
            if (empty($machineId)) {
                return $this->fail('缺少机台ID', 400);
            }

            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在', 404);
            }

            // 检查主连接是否在线
            $mainUid = $machine->domain . ':' . $machine->port;
            try {
                $mainOnline = \GatewayWorker\Lib\Gateway::isUidOnline($mainUid);
            } catch (Exception $e) {
                Log::warning('[CheckOnline] Gateway 检查主连接失败', [
                    'machine_id' => $machineId,
                    'main_uid' => $mainUid,
                    'error' => $e->getMessage(),
                ]);
                $mainOnline = false;
            }

            // 检查从连接是否在线（双连接机台）
            $slaveOnline = false;
            if (!empty($machine->slave_domain) && !empty($machine->slave_port)) {
                $slaveUid = $machine->slave_domain . ':' . $machine->slave_port;
                try {
                    $slaveOnline = \GatewayWorker\Lib\Gateway::isUidOnline($slaveUid);
                } catch (Exception $e) {
                    Log::warning('[CheckOnline] Gateway 检查从连接失败', [
                        'machine_id' => $machineId,
                        'slave_uid' => $slaveUid,
                        'error' => $e->getMessage(),
                    ]);
                    $slaveOnline = false;
                }
            }

            return $this->success([
                'machine_id' => $machine->id,
                'main_online' => $mainOnline,
                'slave_online' => $slaveOnline,
                'is_online' => $mainOnline, // 主连接在线即认为机台在线
            ]);

        } catch (Exception $e) {
            return $this->handleException($e, '检查机台在线状态失败');
        }
    }

    /**
     * 批量检查机台在线状态
     *
     * POST /api/v1/machine/batch-check-online
     *
     * 请求参数：
     * - machine_ids: array 机台ID数组（必填）
     *
     * @param Request $request
     * @return Response
     */
    public function batchCheckOnline(Request $request): Response
    {
        try {
            $machineIds = $request->post('machine_ids', []);
            if (empty($machineIds) || !is_array($machineIds)) {
                return $this->fail('缺少机台ID数组', 400);
            }

            $results = [];
            $machines = Machine::whereIn('id', $machineIds)->get();

            foreach ($machines as $machine) {
                // 检查主连接
                $mainUid = $machine->domain . ':' . $machine->port;
                try {
                    $mainOnline = \GatewayWorker\Lib\Gateway::isUidOnline($mainUid);
                } catch (Exception $e) {
                    $mainOnline = false;
                }

                // 检查从连接（如果有）
                $slaveOnline = false;
                if (!empty($machine->slave_domain) && !empty($machine->slave_port)) {
                    $slaveUid = $machine->slave_domain . ':' . $machine->slave_port;
                    try {
                        $slaveOnline = \GatewayWorker\Lib\Gateway::isUidOnline($slaveUid);
                    } catch (Exception $e) {
                        $slaveOnline = false;
                    }
                }

                $results[] = [
                    'machine_id' => $machine->id,
                    'main_online' => $mainOnline,
                    'slave_online' => $slaveOnline,
                    'is_online' => $mainOnline,
                ];
            }

            return $this->success([
                'total' => count($results),
                'machines' => $results,
            ]);

        } catch (Exception $e) {
            return $this->handleException($e, '批量检查机台在线状态失败');
        }
    }

    /**
     * 机台上分（只执行硬件操作）
     *
     * POST /api/v1/machine/open-point
     *
     * 请求参数：
     * - machine_id: int 机台ID（必填）
     * - player_id: int 玩家ID（必填）
     * - open_score: float 上分数量（必填）
     * - machine_context: array 机台上下文信息（必填）
     * - pre_clear_commands: array 预清空指令（选填）
     *
     * 职责：只负责硬件层操作
     * 1. 执行预清空指令（如果有）
     * 2. 发送上分指令
     * 3. 返回成功状态
     *
     * 不负责：
     * - 数据库事务（由 gk_api 处理）
     * - 钱包操作（由 gk_api 处理）
     * - 游戏记录（由 gk_api 处理）
     *
     * @param Request $request
     * @return Response
     */
    public function openPoint(Request $request): Response
    {
        try {
            // 1. 获取请求参数
            $machineId = $request->post('machine_id');
            $playerId = $request->post('player_id');
            $openScore = $request->post('open_score');
            $machineContext = $request->post('machine_context', []);
            $preClearCommands = $request->post('pre_clear_commands', []);
            $lang = $request->post('lang', 'zh_TW');

            locale($lang);

            // 2. 参数验证
            if (empty($machineId) || empty($playerId) || $openScore === null) {
                return $this->fail('缺少必填参数', 400);
            }

            if ($openScore <= 0) {
                return $this->fail('上分数量必须大于0', 400);
            }

            Log::channel('machine_operations')->info('[OpenPoint] 收到上分请求', [
                'machine_id' => $machineId,
                'player_id' => $playerId,
                'open_score' => $openScore,
            ]);

            // 3. 查询机台
            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在', 404);
            }

            // 4. 创建机台服务
            $services = \app\service\machine\MachineServices::createServices($machine, $lang);

            // 5. 执行预清空指令（如果有）
            if (!empty($preClearCommands) && is_array($preClearCommands)) {
                foreach ($preClearCommands as $cmd) {
                    if (isset($cmd['command']) && isset($cmd['value'])) {
                        Log::channel('machine_operations')->info('[OpenPoint] 执行预清空指令', [
                            'machine_id' => $machineId,
                            'command' => $cmd['command'],
                            'value' => $cmd['value'],
                        ]);
                        $services->sendCmd($cmd['command'], $cmd['value'], 'player', $playerId);
                    }
                }
            }

            // 6. 首次上分特殊处理
            if ($machine->gaming_user_id == 0) {
                // 斯洛机 + 双美：关闭移点
                if ($machine->type == \app\model\GameType::TYPE_SLOT
                    && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    Log::channel('machine_operations')->info('[OpenPoint] 首次上分 - 关闭移点', [
                        'machine_id' => $machineId,
                    ]);
                    $services->sendCmd($services::MOVE_POINT_OFF, 0, 'player', $playerId);
                }
            }

            // 7. 发送上分指令
            $services->sendCmd($services::OPEN_ANY_POINT, $openScore, 'player', $playerId);

            Log::channel('machine_operations')->info('[OpenPoint] 上分完成', [
                'machine_id' => $machineId,
                'player_id' => $playerId,
                'open_score' => $openScore,
            ]);

            // 8. 返回成功
            return $this->success([
                'machine_id' => $machine->id,
                'open_score' => $openScore,
                'success' => true,
            ]);

        } catch (Exception $e) {
            Log::channel('machine_operations')->error('[OpenPoint] 上分失败', [
                'machine_id' => $machineId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->handleException($e, '机台上分失败');
        }
    }
}
