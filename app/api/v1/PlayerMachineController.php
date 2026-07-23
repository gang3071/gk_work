<?php

namespace app\api\v1;

use app\model\GameType;
use app\model\Machine;
use app\service\machine\MachineOperationService;
use Exception;
use GatewayWorker\Lib\Gateway;
use support\Log;
use support\Request;
use support\Response;

/**
 * 玩家机台操作控制器
 * 专门处理来自玩家端（gk_api）的机台查询请求
 * 使用 X-Player-Id header 传递玩家ID
 */
class PlayerMachineController
{
    /**
     * 获取所有在线状态（玩家端）
     *
     * 与 AdminMachineController::getAllOnlineStatus 功能类似，
     * 但只返回请求的机台ID，职责更聚焦
     *
     * @param Request $request
     * @return Response
     */
    public function getAllOnlineStatus(Request $request): Response
    {
        try {
            // 获取请求参数
            $machineIds = $request->post('machine_ids', []);
            $lang = $request->header('Accept-Language', 'zh_TW');

            // 参数验证
            if (!is_array($machineIds) || empty($machineIds)) {
                return $this->fail('machine_ids 参数必须是非空数组', 400);
            }

            // 查询指定的机台
            $machines = Machine::query()
                ->whereIn('id', $machineIds)
                ->where('status', 1)
                ->get(['id', 'domain', 'port', 'code', 'type', 'auto_card_domain', 'auto_card_port']);

            if ($machines->isEmpty()) {
                return $this->success([]);
            }

            // ✅ 使用统一的服务类批量检查在线状态
            $onlineStatus = MachineOperationService::batchCheckOnline($machines->all());

            // 构建返回结果
            $results = [];
            foreach ($machines as $machine) {
                $status = $onlineStatus[$machine->id] ?? ['online' => false];

                $results[] = [
                    'id' => $machine->id,
                    'code' => $machine->code,
                    'online' => $status['online'],
                    'status' => $status['online'] ? 'online' : 'offline',
                ];
            }

            Log::info('[PlayerMachineController] 批量检查机台在线状态', [
                'requested_count' => count($machineIds),
                'found_count' => count($results),
                'online_count' => count(array_filter($results, fn($r) => $r['online'])),
            ]);

            return $this->success($results);

        } catch (Exception $e) {
            Log::error('[PlayerMachineController] 批量检查机台在线状态失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->fail('批量检查机台在线状态失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 成功响应
     */
    private function success($data = [], string $message = 'success'): Response
    {
        return json([
            'code' => 200,
            'msg' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 失败响应
     */
    private function fail(string $message, int $httpCode = 400): Response
    {
        return json([
            'code' => $httpCode,
            'msg' => $message,
            'data' => [],
        ], $httpCode);
    }
}
