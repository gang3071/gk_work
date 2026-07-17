<?php

namespace app\api\v1;

use app\exception\BusinessException;
use app\model\GameType;
use app\model\Machine;
use app\service\machine\MachineServices;
use Exception;
use GatewayWorker\Lib\Gateway;
use support\Log;
use support\Request;
use support\Response;

/**
 * 管理后台机台操作控制器
 * 专门处理来自管理后台（gk_admin）的机台操作请求
 * 使用 X-Admin-Id header 传递管理员ID
 */
class AdminMachineController
{
    /**
     * 从请求中获取管理员ID
     * @param Request $request
     * @return int
     */
    private function getAdminId(Request $request): int
    {
        $adminId = $request->header('X-Admin-Id', 0);
        return (int)$adminId;
    }

    /**
     * 设置语言环境
     * @param Request $request
     * @return string
     */
    private function setLanguage(Request $request): string
    {
        $lang = $request->post('lang', $request->header('Accept-Language', 'zh_TW'));
        locale($lang);
        return $lang;
    }

    /**
     * 成功响应
     */
    private function success($data = [], string $message = null): Response
    {
        $message = $message ?? trans('success', [], 'admin_machine');
        return json([
            'code' => 200,
            'msg' => $message,
            'data' => $data
        ]);
    }

    /**
     * 失败响应
     */
    private function fail(string $message = null, int $code = 400, $data = []): Response
    {
        $message = $message ?? trans('fail', [], 'admin_machine');
        return json([
            'code' => $code,
            'msg' => $message,
            'data' => $data
        ]);
    }

    /**
     * 处理异常并返回安全的错误响应
     *
     * @param Exception $e 异常对象
     * @param string $context 上下文描述（用于日志）
     * @param array $logData 额外的日志数据
     * @return Response
     */
    private function handleException(Exception $e, string $context, array $logData = []): Response
    {
        // 记录完整错误到日志（包含敏感信息）
        Log::error($context, array_merge([
            'error_class' => get_class($e),
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], $logData));

        // 返回给客户端的消息（安全的）
        if ($e instanceof BusinessException) {
            // 业务异常：消息是安全的，可以直接返回
            return $this->fail($e->getMessage(), $e->getErrorCode(), $e->getData());
        }

        // 系统异常：根据环境决定返回内容
        if (config('app.debug', false)) {
            // 开发环境：返回详细错误信息
            return $this->fail($e->getMessage(), 500, [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        } else {
            // 生产环境：返回通用错误消息
            return $this->fail('操作失败，请稍后重试', 500);
        }
    }

    /**
     * 发送机台指令
     * POST /api/admin/machine/send-cmd
     *
     * @param Request $request
     * @return Response
     */
    public function sendCmd(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $adminId = $this->getAdminId($request);

            // 获取并验证参数
            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'admin_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $cmd = $request->post('cmd');
            if ($cmd === null || $cmd === '') {
                return $this->fail(trans('cmd_required', [], 'admin_machine'));
            }

            $data = $request->post('data', 0);
            if (!is_numeric($data)) {
                return $this->fail(trans('data_must_be_numeric', [], 'admin_machine'));
            }
            $dataInt = (int)$data;

            // 查询机台
            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                return $this->fail(trans('machine_not_found', [], 'admin_machine'));
            }

            // 创建机台服务
            $services = MachineServices::createServices($machine, $lang);

            // 发送指令
            $result = $services->sendCmd($cmd, $dataInt, 'admin', $adminId);

            Log::info('【管理员操作】机台指令', [
                'operator_type' => 'admin',
                'admin_id' => $adminId,
                'machine_id' => $machineIdInt,
                'machine_code' => $machine->code,
                'cmd' => $cmd,
                'data' => $dataInt,
                'result' => $result
            ]);

            return $this->success([
                'result' => $result,
                'cmd' => $cmd,
                'machine_id' => $machineIdInt
            ], trans('cmd_sent_success', [], 'admin_machine'));

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】机台指令失败', [
                'operator_type' => 'admin',
                'admin_id' => $adminId ?? 0,
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null,
                'cmd' => $cmd ?? null
            ]);
        }
    }

    /**
     * 获取机台状态
     * POST /api/admin/machine/status
     *
     * @param Request $request
     * @return Response
     */
    public function getMachineStatus(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);

            // 获取并验证参数
            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'admin_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                return $this->fail(trans('machine_not_found', [], 'admin_machine'));
            }

            $services = MachineServices::createServices($machine, $lang);

            // 获取机台信息
            $machineInfo = [];
            foreach ($services->machineInfo as $key) {
                $machineInfo[$key] = $services->$key ?? null;
            }

            return $this->success([
                'machine_id' => $machineIdInt,
                'machine_info' => $machineInfo,
                'cache_data' => $services->cacheData ?? []
            ]);

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】获取机台状态失败', [
                'operator_type' => 'admin',
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null
            ]);
        }
    }

    /**
     * 检查机台是否在线
     * POST /api/admin/machine/check-online
     *
     * @param Request $request
     * @return Response
     */
    public function checkOnline(Request $request): Response
    {
        try {
            $this->setLanguage($request);

            // 获取并验证参数
            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'admin_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                return $this->fail(trans('machine_not_found', [], 'admin_machine'));
            }

            // 检查主连接是否在线（带异常处理）
            $mainUid = $machine->domain . ':' . $machine->port;
            try {
                $mainOnline = Gateway::isUidOnline($mainUid);
            } catch (Exception $e) {
                Log::warning('Gateway isUidOnline failed for main connection', [
                    'machine_id' => $machineIdInt,
                    'uid' => $mainUid,
                    'error' => $e->getMessage()
                ]);
                $mainOnline = false; // 优雅降级
            }

            // 检查自动卡是否在线（如果有）
            $autoOnline = false;
            if (!empty($machine->auto_card_domain) && !empty($machine->auto_card_port)) {
                $autoUid = $machine->auto_card_domain . ':' . $machine->auto_card_port;
                try {
                    $autoOnline = Gateway::isUidOnline($autoUid);
                } catch (Exception $e) {
                    Log::warning('Gateway isUidOnline failed for auto card', [
                        'machine_id' => $machineIdInt,
                        'uid' => $autoUid,
                        'error' => $e->getMessage()
                    ]);
                    $autoOnline = false; // 优雅降级
                }
            }

            // 根据机型计算在线状态
            // Slot老虎机：需要 main 和 auto 两个连接都在线
            // 钢珠机：只需要 main 连接在线
            if ($machine->type === GameType::TYPE_SLOT) {
                $online = $mainOnline && $autoOnline;
            } else {
                $online = $mainOnline;
            }

            return $this->success([
                'machine_id' => $machineIdInt,
                'type' => $machine->type,
                'main_online' => $mainOnline,
                'auto_online' => $autoOnline,
                'online' => $online,
            ]);

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】检查机台在线失败', [
                'operator_type' => 'admin',
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null
            ]);
        }
    }

    /**
     * 批量检查机台在线状态
     * POST /api/admin/machine/batch-check-online
     *
     * @param Request $request
     * @return Response
     */
    public function batchCheckOnline(Request $request): Response
    {
        try {
            $this->setLanguage($request);
            $machineIds = $request->post('machine_ids', []);

            if (empty($machineIds) || !is_array($machineIds)) {
                return $this->fail(trans('machine_ids_required', [], 'admin_machine'));
            }

            // 验证并过滤数组元素
            $validIds = [];
            foreach ($machineIds as $id) {
                $idInt = (int)$id;
                if ($idInt > 0) {
                    $validIds[] = $idInt;
                }
            }

            if (empty($validIds)) {
                return $this->fail('没有有效的机台ID', 400);
            }

            $machines = Machine::whereIn('id', $validIds)->get();
            $results = [];

            foreach ($machines as $machine) {
                $mainUid = $machine->domain . ':' . $machine->port;
                try {
                    $mainOnline = Gateway::isUidOnline($mainUid);
                } catch (Exception $e) {
                    Log::warning('Gateway isUidOnline failed in batch check', [
                        'machine_id' => $machine->id,
                        'uid' => $mainUid,
                        'error' => $e->getMessage()
                    ]);
                    $mainOnline = false; // 优雅降级
                }

                $autoOnline = false;
                if (!empty($machine->auto_card_domain) && !empty($machine->auto_card_port)) {
                    $autoUid = $machine->auto_card_domain . ':' . $machine->auto_card_port;
                    try {
                        $autoOnline = Gateway::isUidOnline($autoUid);
                    } catch (Exception $e) {
                        Log::warning('Gateway isUidOnline failed for auto card in batch check', [
                            'machine_id' => $machine->id,
                            'uid' => $autoUid,
                            'error' => $e->getMessage()
                        ]);
                        $autoOnline = false; // 优雅降级
                    }
                }

                // 根据机型计算在线状态
                // Slot老虎机：需要 main 和 auto 两个连接都在线
                // 钢珠机：只需要 main 连接在线
                if ($machine->type === GameType::TYPE_SLOT) {
                    $online = $mainOnline && $autoOnline;
                } else {
                    $online = $mainOnline;
                }

                $results[] = [
                    'machine_id' => $machine->id,
                    'type' => $machine->type,
                    'main_online' => $mainOnline,
                    'auto_online' => $autoOnline,
                    'online' => $online,
                ];
            }

            return $this->success($results);

        } catch (Exception $e) {
            Log::error('Batch check machine online failed', [
                'error' => $e->getMessage()
            ]);
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 获取机台操作描述
     * POST /api/admin/machine/get-description
     *
     * @param Request $request
     * @return Response
     */
    public function getDescription(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);

            // 获取并验证参数
            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'admin_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $fun = $request->post('fun', '');
            $data = $request->post('data', 0);

            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                return $this->fail(trans('machine_not_found', [], 'admin_machine'));
            }

            $services = MachineServices::createServices($machine, $lang);
            $description = $services->getDescription($fun, (int)$data);

            return $this->success([
                'description' => $description
            ]);

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】获取机台描述失败', [
                'operator_type' => 'admin',
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null
            ]);
        }
    }

    /**
     * 获取Gateway注册地址（用于调试）
     * GET /api/admin/machine/gateway-info
     *
     * @param Request $request
     * @return Response
     */
    public function getGatewayInfo(Request $request): Response
    {
        return $this->success([
            'register_address' => Gateway::$registerAddress ?? 'not set',
        ]);
    }

    /**
     * 获取所有机台的实时在线状态
     * POST /api/admin/machine/all-online-status
     *
     * @param Request $request
     * @return Response
     */
    public function getAllOnlineStatus(Request $request): Response
    {
        try {
            $this->setLanguage($request);
            $departmentId = $request->post('department_id', 0);
            $type = $request->post('type'); // slot, steel_ball, fish

            // 构建查询
            $query = Machine::query()->where('status', 1);

            if ($departmentId) {
                // 如果需要按部门过滤，这里添加逻辑
                // $query->where('department_id', $departmentId);
            }

            if ($type) {
                $typeMap = [
                    'slot' => GameType::TYPE_SLOT,
                    'steel_ball' => GameType::TYPE_STEEL_BALL,
                    'fish' => GameType::TYPE_FISH,
                ];
                if (isset($typeMap[$type])) {
                    $query->where('type', $typeMap[$type]);
                }
            }

            $machines = $query->get(['id', 'domain', 'port', 'auto_card_domain', 'auto_card_port', 'type', 'code', 'name']);

            $results = [];
            foreach ($machines as $machine) {
                $mainUid = $machine->domain . ':' . $machine->port;
                try {
                    $mainOnline = Gateway::isUidOnline($mainUid);
                } catch (Exception $e) {
                    Log::warning('Gateway isUidOnline failed in getAllOnlineStatus', [
                        'machine_id' => $machine->id,
                        'uid' => $mainUid,
                        'error' => $e->getMessage()
                    ]);
                    $mainOnline = false; // 优雅降级
                }

                $autoOnline = false;
                if (!empty($machine->auto_card_domain) && !empty($machine->auto_card_port)) {
                    $autoUid = $machine->auto_card_domain . ':' . $machine->auto_card_port;
                    try {
                        $autoOnline = Gateway::isUidOnline($autoUid);
                    } catch (Exception $e) {
                        Log::warning('Gateway isUidOnline failed for auto card in getAllOnlineStatus', [
                            'machine_id' => $machine->id,
                            'uid' => $autoUid,
                            'error' => $e->getMessage()
                        ]);
                        $autoOnline = false; // 优雅降级
                    }
                }

                $results[] = [
                    'id' => $machine->id,
                    'code' => $machine->code,
                    'name' => $machine->name,
                    'type' => $machine->type,
                    'main_online' => $mainOnline,
                    'auto_online' => $autoOnline,
                    'online' => $mainOnline,
                    'status' => $mainOnline ? 'online' : 'offline',
                ];
            }

            return $this->success($results);

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】获取所有在线状态失败', [
                'operator_type' => 'admin'
            ]);
        }
    }

    /**
     * 获取机台在线统计
     * GET /api/admin/machine/online-statistics
     *
     * @param Request $request
     * @return Response
     */
    public function getOnlineStatistics(Request $request): Response
    {
        try {
            $this->setLanguage($request);
            $machines = Machine::query()->where('status', 1)->get(['id', 'domain', 'port', 'type']);

            $statistics = [
                'total' => $machines->count(),
                'online' => 0,
                'offline' => 0,
                'by_type' => [],
            ];

            foreach ($machines as $machine) {
                $uid = $machine->domain . ':' . $machine->port;
                try {
                    $isOnline = Gateway::isUidOnline($uid);
                } catch (Exception $e) {
                    Log::warning('Gateway isUidOnline failed in getOnlineStatistics', [
                        'machine_id' => $machine->id,
                        'uid' => $uid,
                        'error' => $e->getMessage()
                    ]);
                    $isOnline = false; // 优雅降级
                }

                if ($isOnline) {
                    $statistics['online']++;
                } else {
                    $statistics['offline']++;
                }

                // 按类型统计
                $typeName = match ($machine->type) {
                    GameType::TYPE_SLOT => 'slot',
                    GameType::TYPE_STEEL_BALL => 'steel_ball',
                    GameType::TYPE_FISH => 'fish',
                    default => 'unknown',
                };

                if (!isset($statistics['by_type'][$typeName])) {
                    $statistics['by_type'][$typeName] = [
                        'total' => 0,
                        'online' => 0,
                        'offline' => 0,
                    ];
                }

                $statistics['by_type'][$typeName]['total']++;
                if ($isOnline) {
                    $statistics['by_type'][$typeName]['online']++;
                } else {
                    $statistics['by_type'][$typeName]['offline']++;
                }
            }

            return $this->success($statistics);

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】获取在线统计失败', [
                'operator_type' => 'admin'
            ]);
        }
    }

    /**
     * 批量获取机台状态
     * POST /api/admin/machine/batch-status
     *
     * @param Request $request
     * @return Response
     */
    public function batchGetMachineStatus(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $machineIds = $request->post('machine_ids', []);

            if (empty($machineIds) || !is_array($machineIds)) {
                return $this->fail(trans('machine_ids_required', [], 'admin_machine'));
            }

            // 验证并过滤数组元素
            $validIds = [];
            foreach ($machineIds as $id) {
                $idInt = (int)$id;
                if ($idInt > 0) {
                    $validIds[] = $idInt;
                }
            }

            if (empty($validIds)) {
                return $this->fail('没有有效的机台ID', 400);
            }

            $machines = Machine::whereIn('id', $validIds)->get();
            $results = [];

            foreach ($machines as $machine) {
                $services = MachineServices::createServices($machine, $lang);

                // 获取机台信息
                $machineInfo = [];
                foreach ($services->machineInfo as $key) {
                    $machineInfo[$key] = $services->$key ?? null;
                }

                $results[] = [
                    'machine_id' => $machine->id,
                    'machine_info' => $machineInfo,
                    'cache_data' => $services->cacheData ?? []
                ];
            }

            return $this->success($results);

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】批量获取机台状态失败', [
                'operator_type' => 'admin',
                'machine_count' => isset($validIds) ? count($validIds) : 0
            ]);
        }
    }

    /**
     * 更新机台状态（直接修改 Redis 缓存）
     * POST /api/admin/machine/update-state
     *
     * @param Request $request
     * @return Response
     */
    public function updateMachineState(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);

            // 获取并验证参数
            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'admin_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $field = $request->post('field');
            if ($field === null || $field === '') {
                return $this->fail(trans('field_required', [], 'admin_machine'));
            }

            $value = $request->post('value');

            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                return $this->fail(trans('machine_not_found', [], 'admin_machine'));
            }

            // 创建机台服务
            $services = MachineServices::createServices($machine, $lang);

            // 更新字段值
            $services->$field = $value;

            Log::info('Admin update machine state', [
                'machine_id' => $machineIdInt,
                'field' => $field,
                'value' => $value
            ]);

            return $this->success([
                'machine_id' => $machineIdInt,
                'field' => $field,
                'value' => $value
            ], trans('state_updated_success', [], 'admin_machine'));

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】更新机台状态失败', [
                'operator_type' => 'admin',
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null,
                'field' => $field ?? null
            ]);
        }
    }

    /**
     * 批量发送机台指令
     * POST /api/admin/machine/batch-send-cmd
     *
     * @param Request $request
     * @return Response
     */
    public function batchSendCmd(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $adminId = $this->getAdminId($request);

            // 获取并验证参数
            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'admin_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $commands = $request->post('commands', []);
            if (empty($commands) || !is_array($commands)) {
                return $this->fail(trans('commands_required', [], 'admin_machine'));
            }

            // 查询机台
            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                return $this->fail(trans('machine_not_found', [], 'admin_machine'));
            }

            // 创建机台服务
            $services = MachineServices::createServices($machine, $lang);

            // 执行批量指令
            $results = [];
            $successCount = 0;
            $failedCount = 0;

            foreach ($commands as $index => $command) {
                try {
                    $cmd = $command['cmd'] ?? '';
                    $data = (int)($command['data'] ?? 0);

                    // 验证cmd
                    if ($cmd === null || $cmd === '') {
                        $results[] = [
                            'index' => $index,
                            'cmd' => $cmd,
                            'success' => false,
                            'message' => trans('cmd_required', [], 'admin_machine')
                        ];
                        $failedCount++;
                        continue;
                    }

                    // 发送指令
                    $result = $services->sendCmd($cmd, $data, 'admin', $adminId);

                    $results[] = [
                        'index' => $index,
                        'cmd' => $cmd,
                        'data' => $data,
                        'success' => true,
                        'result' => $result
                    ];
                    $successCount++;

                } catch (Exception $e) {
                    $results[] = [
                        'index' => $index,
                        'cmd' => $command['cmd'] ?? '',
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                    $failedCount++;

                    Log::error('Batch send cmd item failed', [
                        'machine_id' => $machineId,
                        'index' => $index,
                        'command' => $command,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('【管理员操作】批量机台指令', [
                'operator_type' => 'admin',
                'admin_id' => $adminId,
                'machine_id' => $machineId,
                'total_count' => count($commands),
                'success_count' => $successCount,
                'failed_count' => $failedCount
            ]);

            return $this->success([
                'machine_id' => $machineId,
                'total_count' => count($commands),
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'results' => $results
            ], trans('batch_cmd_completed', [], 'admin_machine'));

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】批量机台指令失败', [
                'operator_type' => 'admin',
                'admin_id' => $adminId ?? 0,
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null,
                'commands_count' => isset($commands) ? count($commands) : 0
            ]);
        }
    }

    /**
     * 踢出玩家（洗分）
     * POST /api/admin/machine/kick-player
     *
     * @param Request $request
     * @return Response
     */
    public function kickPlayer(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $adminId = $this->getAdminId($request);

            // 验证参数
            $machineId = (int)$request->post('machine_id');
            $playerId = (int)$request->post('player_id');
            $path = $request->post('path', 'leave'); // leave 或 down

            if ($machineId <= 0) {
                return $this->fail('无效的机台ID', 400);
            }
            if ($playerId <= 0) {
                return $this->fail('无效的玩家ID', 400);
            }

            // 查询机台和玩家
            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在', 404);
            }

            $player = \app\model\Player::find($playerId);
            if (!$player) {
                return $this->fail('玩家不存在', 404);
            }

            // 调用洗分函数
            // 注意：这里需要确保 gk_work 中也有 machineWash 函数
            // 如果没有，需要从 gk_admin 迁移过来
            if (!function_exists('machineWash')) {
                return $this->fail('machineWash 函数未定义，请联系技术支持', 500);
            }

            machineWash($player, $machine, $path);

            Log::info('【管理员操作】踢出玩家', [
                'operator_type' => 'admin',
                'admin_id' => $adminId,
                'machine_id' => $machineId,
                'player_id' => $playerId,
                'path' => $path
            ]);

            return $this->success([], '踢出玩家成功');

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】踢出玩家失败', [
                'operator_type' => 'admin',
                'admin_id' => $adminId ?? 0,
                'machine_id' => $machineId ?? null,
                'player_id' => $playerId ?? null
            ]);
        }
    }

    /**
     * 强制踢出玩家（不返还分数）
     * POST /api/admin/machine/force-kick-player
     *
     * @param Request $request
     * @return Response
     */
    public function forceKickPlayer(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $adminId = $this->getAdminId($request);

            // 验证参数
            $machineId = (int)$request->post('machine_id');
            $playerId = (int)$request->post('player_id');

            if ($machineId <= 0) {
                return $this->fail('无效的机台ID', 400);
            }
            if ($playerId <= 0) {
                return $this->fail('无效的玩家ID', 400);
            }

            // 查询机台和玩家
            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在', 404);
            }

            $player = \app\model\Player::find($playerId);
            if (!$player) {
                return $this->fail('玩家不存在', 404);
            }

            // 调用强制重置函数
            if (!function_exists('resetMachineTrans')) {
                return $this->fail('resetMachineTrans 函数未定义，请联系技术支持', 500);
            }

            resetMachineTrans($machine, $player);

            Log::info('【管理员操作】强制踢出玩家', [
                'operator_type' => 'admin',
                'admin_id' => $adminId,
                'machine_id' => $machineId,
                'player_id' => $playerId
            ]);

            return $this->success([], '强制踢出玩家成功');

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】强制踢出玩家失败', [
                'operator_type' => 'admin',
                'admin_id' => $adminId ?? 0,
                'machine_id' => $machineId ?? null,
                'player_id' => $playerId ?? null
            ]);
        }
    }

    /**
     * 自定义开分
     * POST /api/admin/machine/custom-open-score
     *
     * @param Request $request
     * @return Response
     */
    public function customOpenScore(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $adminId = $this->getAdminId($request);

            // 验证参数
            $machineId = (int)$request->post('machine_id');
            $playerId = (int)$request->post('player_id');
            $openScore = (int)$request->post('open_score');

            if ($machineId <= 0) {
                return $this->fail('无效的机台ID', 400);
            }
            if ($playerId <= 0) {
                return $this->fail('无效的玩家ID', 400);
            }
            if ($openScore <= 0) {
                return $this->fail('开分数值必须大于0', 400);
            }

            // 查询机台和玩家
            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在', 404);
            }

            $player = \app\model\Player::find($playerId);
            if (!$player) {
                return $this->fail('玩家不存在', 404);
            }

            // 调用自定义开分函数
            if (!function_exists('machineOpenAnyFree')) {
                return $this->fail('machineOpenAnyFree 函数未定义，请联系技术支持', 500);
            }

            machineOpenAnyFree($player, $machine, $openScore);

            Log::info('【管理员操作】自定义开分', [
                'operator_type' => 'admin',
                'admin_id' => $adminId,
                'machine_id' => $machineId,
                'player_id' => $playerId,
                'open_score' => $openScore
            ]);

            return $this->success([], '自定义开分成功');

        } catch (Exception $e) {
            return $this->handleException($e, '【管理员操作】自定义开分失败', [
                'operator_type' => 'admin',
                'admin_id' => $adminId ?? 0,
                'machine_id' => $machineId ?? null,
                'player_id' => $playerId ?? null,
                'open_score' => $openScore ?? null
            ]);
        }
    }
}
