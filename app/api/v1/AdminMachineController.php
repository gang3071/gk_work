<?php

namespace app\api\v1;

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
     * 成功响应
     */
    private function success($data = [], string $message = 'success'): Response
    {
        return json([
            'code' => 200,
            'msg' => $message,
            'data' => $data
        ]);
    }

    /**
     * 失败响应
     */
    private function fail(string $message = 'fail', int $code = 400, $data = []): Response
    {
        return json([
            'code' => $code,
            'msg' => $message,
            'data' => $data
        ]);
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
            $adminId = $this->getAdminId($request);
            $machineId = $request->post('machine_id');
            $cmd = $request->post('cmd');
            $data = $request->post('data', 0);
            $lang = $request->post('lang', 'zh_CN');

            if (empty($machineId)) {
                return $this->fail('机台ID不能为空');
            }

            if (empty($cmd)) {
                return $this->fail('指令不能为空');
            }

            // 查询机台
            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在');
            }

            // 创建机台服务
            $services = MachineServices::createServices($machine, $lang);

            // 发送指令
            $result = $services->sendCmd($cmd, (int)$data, 'admin', $adminId);

            Log::info('Admin send machine cmd', [
                'admin_id' => $adminId,
                'machine_id' => $machineId,
                'cmd' => $cmd,
                'data' => $data,
                'result' => $result
            ]);

            return $this->success([
                'result' => $result,
                'cmd' => $cmd,
                'machine_id' => $machineId
            ], '指令发送成功');

        } catch (Exception $e) {
            Log::error('Admin send machine cmd failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->fail($e->getMessage());
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
            $machineId = $request->post('machine_id');
            $lang = $request->post('lang', 'zh_CN');

            if (empty($machineId)) {
                return $this->fail('机台ID不能为空');
            }

            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在');
            }

            $services = MachineServices::createServices($machine, $lang);

            // 获取机台信息
            $machineInfo = [];
            foreach ($services->machineInfo as $key) {
                $machineInfo[$key] = $services->$key ?? null;
            }

            return $this->success([
                'machine_id' => $machineId,
                'machine_info' => $machineInfo,
                'cache_data' => $services->cacheData ?? []
            ]);

        } catch (Exception $e) {
            Log::error('Get machine status failed', [
                'error' => $e->getMessage(),
                'machine_id' => $machineId ?? null
            ]);
            return $this->fail($e->getMessage());
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
            $machineId = $request->post('machine_id');

            if (empty($machineId)) {
                return $this->fail('机台ID不能为空');
            }

            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在');
            }

            // 检查主连接是否在线
            $mainUid = $machine->domain . ':' . $machine->port;
            $mainOnline = Gateway::isUidOnline($mainUid);

            // 检查自动卡是否在线（如果有）
            $autoOnline = false;
            if (!empty($machine->auto_card_domain) && !empty($machine->auto_card_port)) {
                $autoUid = $machine->auto_card_domain . ':' . $machine->auto_card_port;
                $autoOnline = Gateway::isUidOnline($autoUid);
            }

            return $this->success([
                'machine_id' => $machineId,
                'main_online' => $mainOnline,
                'auto_online' => $autoOnline,
                'online' => $mainOnline, // 主连接在线即认为在线
            ]);

        } catch (Exception $e) {
            Log::error('Check machine online failed', [
                'error' => $e->getMessage(),
                'machine_id' => $machineId ?? null
            ]);
            return $this->fail($e->getMessage());
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
            $machineIds = $request->post('machine_ids', []);

            if (empty($machineIds) || !is_array($machineIds)) {
                return $this->fail('机台ID列表不能为空');
            }

            $machines = Machine::whereIn('id', $machineIds)->get();
            $results = [];

            foreach ($machines as $machine) {
                $mainUid = $machine->domain . ':' . $machine->port;
                $mainOnline = Gateway::isUidOnline($mainUid);

                $autoOnline = false;
                if (!empty($machine->auto_card_domain) && !empty($machine->auto_card_port)) {
                    $autoUid = $machine->auto_card_domain . ':' . $machine->auto_card_port;
                    $autoOnline = Gateway::isUidOnline($autoUid);
                }

                $results[] = [
                    'machine_id' => $machine->id,
                    'main_online' => $mainOnline,
                    'auto_online' => $autoOnline,
                    'online' => $mainOnline,
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
            $machineId = $request->post('machine_id');
            $fun = $request->post('fun', '');
            $data = $request->post('data', 0);
            $lang = $request->post('lang', 'zh_CN');

            if (empty($machineId)) {
                return $this->fail('机台ID不能为空');
            }

            $machine = Machine::find($machineId);
            if (!$machine) {
                return $this->fail('机台不存在');
            }

            $services = MachineServices::createServices($machine, $lang);
            $description = $services->getDescription($fun, (int)$data);

            return $this->success([
                'description' => $description
            ]);

        } catch (Exception $e) {
            Log::error('Get machine description failed', [
                'error' => $e->getMessage()
            ]);
            return $this->fail($e->getMessage());
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
                $mainOnline = Gateway::isUidOnline($mainUid);

                $autoOnline = false;
                if (!empty($machine->auto_card_domain) && !empty($machine->auto_card_port)) {
                    $autoUid = $machine->auto_card_domain . ':' . $machine->auto_card_port;
                    $autoOnline = Gateway::isUidOnline($autoUid);
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
            Log::error('Get all machine online status failed', [
                'error' => $e->getMessage()
            ]);
            return $this->fail($e->getMessage());
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
            $machines = Machine::query()->where('status', 1)->get(['id', 'domain', 'port', 'type']);

            $statistics = [
                'total' => $machines->count(),
                'online' => 0,
                'offline' => 0,
                'by_type' => [],
            ];

            foreach ($machines as $machine) {
                $uid = $machine->domain . ':' . $machine->port;
                $isOnline = Gateway::isUidOnline($uid);

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
            Log::error('Get machine online statistics failed', [
                'error' => $e->getMessage()
            ]);
            return $this->fail($e->getMessage());
        }
    }
}
