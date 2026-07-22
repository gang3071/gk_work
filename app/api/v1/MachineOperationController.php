<?php

namespace app\api\v1;

use app\model\Machine;
use support\Request;
use support\Response;
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
class MachineOperationController
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
                return json(['code' => 0, 'msg' => '缺少必要参数: machine_id 和 action', 'data' => []]);
            }

            // 2. 获取机台
            $machine = Machine::find($machineId);
            if (!$machine) {
                return json(['code' => 0, 'msg' => '机台不存在', 'data' => []]);
            }

            // 3. 确定操作者类型和 ID
            // 注意：对于业务操作（wash/open），operatorId 从 params['player_id'] 获取
            $path = $request->path();
            if (strpos($path, '/api/admin/') !== false) {
                $operatorType = MachineOperationService::OPERATOR_ADMIN;
                $operatorId = 0; // 后续可从 session/header 获取
            } elseif (strpos($path, '/api/v1/') !== false && isset($params['player_id'])) {
                $operatorType = MachineOperationService::OPERATOR_PLAYER;
                $operatorId = (int) $params['player_id'];
            } else {
                $operatorType = MachineOperationService::OPERATOR_SYSTEM;
                $operatorId = 0;
            }

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
                return json(['code' => 1, 'msg' => $result['message'], 'data' => $result['data']]);
            } else {
                return json(['code' => 0, 'msg' => $result['message'], 'data' => []]);
            }

        } catch (Exception $e) {
            Log::channel('machine_operations')->error('[MachineOperationController] 机台操作失败', [
                'machine_id' => $machineId,
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return json(['code' => 0, 'msg' => '机台操作失败: ' . $e->getMessage(), 'data' => []]);
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
                return json(['code' => 0, 'msg' => '缺少必要参数: machine_ids 和 action', 'data' => []]);
            }

            if (!is_array($machineIds)) {
                return json(['code' => 0, 'msg' => 'machine_ids 必须是数组', 'data' => []]);
            }

            // 2. 确定操作者信息
            list($operatorType, $operatorId) = $this->getOperatorInfo($request);

            // 3. 只允许后台管理员批量操作
            if ($operatorType !== MachineOperationService::OPERATOR_ADMIN) {
                return json(['code' => 0, 'msg' => '批量操作仅限后台管理员', 'data' => []]);
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
            return json([
                'code' => 1,
                'msg' => "批量操作完成：成功 {$successCount} 个，失败 {$failCount} 个",
                'data' => [
                    'total' => count($machineIds),
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                    'results' => $results,
                ],
            ]);

        } catch (Exception $e) {
            Log::channel('machine_operations')->error('[MachineOperationController] 批量操作失败', [
                'action' => $action ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return json(['code' => 0, 'msg' => '批量操作失败: ' . $e->getMessage(), 'data' => []]);
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
                return json(['code' => 0, 'msg' => '缺少参数: machine_id', 'data' => []]);
            }

            $machine = Machine::find($machineId);
            if (!$machine) {
                return json(['code' => 0, 'msg' => '机台不存在', 'data' => []]);
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

            return json([
                'code' => 1,
                'msg' => 'success',
                'data' => [
                    'machine_id' => $machine->id,
                    'machine_type' => $machine->game_type == \addons\webman\model\constant\GameType::TYPE_SLOT ? 'slot' : 'jackpot',
                    'control_type' => $machine->control_type === Machine::CONTROL_TYPE_MEI ? 'mei' : 'song',
                    'operations' => $operations,
                ],
            ]);

        } catch (Exception $e) {
            Log::channel('machine_operations')->error('[MachineOperationController] 获取操作列表失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return json(['code' => 0, 'msg' => '获取操作列表失败: ' . $e->getMessage(), 'data' => []]);
        }
    }

    /**
     * 设置语言
     */
    private function setLanguage(Request $request): string
    {
        return $request->header('accept-language', 'zh_CN');
    }

    /**
     * 获取玩家（从请求中）
     */
    private function getPlayer(Request $request): ?Player
    {
        // 这里可以从 header 或 session 获取玩家信息
        // 暂时返回 null
        return null;
    }
}
