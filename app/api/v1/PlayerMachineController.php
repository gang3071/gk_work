<?php

namespace app\api\v1;

use app\exception\BusinessException;
use app\model\GameType;
use app\model\Machine;
use app\model\Player;
use app\service\machine\MachineServices;
use Exception;
use GatewayWorker\Lib\Gateway;
use support\Log;
use support\Request;
use support\Response;
use Tinywan\Jwt\JwtToken;

/**
 * 玩家机台操作控制器
 * 专门处理来自玩家端（gk_api）的机台操作请求
 * 使用 JWT Token 或 X-Player-Id header 传递玩家ID
 */
class PlayerMachineController
{
    /**
     * 从请求中获取玩家信息
     * @param Request $request
     * @return Player|null
     */
    private function getPlayer(Request $request): ?Player
    {
        try {
            // 优先从 X-Player-Id header 获取（来自 gk_api 代理）
            $playerIdRaw = $request->header('X-Player-Id', '');

            // 修复 HTTP Keep-Alive 导致的 header 累积问题
            // 如果是逗号分隔的字符串，取第一个值
            if (strpos($playerIdRaw, ',') !== false) {
                $playerIdArray = explode(',', $playerIdRaw);
                $playerId = trim($playerIdArray[0]);
            } else {
                $playerId = $playerIdRaw;
            }

            // 明确判断：非空字符串且转为整数后大于0
            if ($playerId !== '' && $playerId !== null) {
                $playerIdInt = (int)$playerId;

                // 只接受正整数的玩家ID（拒绝0和负数）
                if ($playerIdInt > 0) {
                    $player = Player::query()->where('id', $playerIdInt)->first();

                    if ($player) {
                        return $player;
                    }
                }
            }

            // 降级方案：尝试解析 JWT token（直接访问时）
            $authorization = $request->header('Authorization', '');
            if ($authorization === '' || $authorization === null) {
                return null;
            }

            // 提取 token
            $token = str_replace('Bearer ', '', $authorization);
            if ($token === '' || $token === null) {
                return null;
            }

            // 解析 token 获取用户 ID
            $id = JwtToken::getCurrentId();
            if (!$id || $id <= 0) {
                return null;
            }

            // 查询玩家
            $player = Player::query()->where('id', $id)->first();

            return $player;

        } catch (Exception $e) {
            Log::error('Get player from token failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
        $message = $message ?? trans('success', [], 'player_machine');
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
        $message = $message ?? trans('fail', [], 'player_machine');
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
     * 发送机台指令（玩家操作）
     * POST /api/v1/machine/send-cmd
     *
     * @param Request $request
     * @return Response
     */
    public function sendCmd(Request $request): Response
    {
        $player = null;  // 初始化变量，避免catch块中未定义
        $cmdId = uniqid('cmd_', true);  // 生成唯一指令ID用于追踪
        $startTime = microtime(true);

        // 提取追踪上下文（来自 gk_api）
        $traceContext = [
            'batch_id' => $request->header('X-Batch-Id', ''),
            'command_index' => $request->header('X-Command-Index', ''),
            'command_id' => $request->header('X-Command-Id', ''),
            'wash_id' => $request->header('X-Wash-Id', ''),
        ];

        // 过滤空值
        $traceContext = array_filter($traceContext, function ($value) {
            return $value !== '' && $value !== null;
        });

        try {
            $lang = $this->setLanguage($request);
            $player = $this->getPlayer($request);

            if (!$player) {
                Log::warning('[PlayerMachine-SendCmd] 玩家信息获取失败', [
                    'cmd_id' => $cmdId,
                    'trace_context' => $traceContext,
                    'player_id_header' => $request->header('X-Player-Id', ''),
                    'ip' => $request->getRealIp(),
                ]);
                return $this->fail('玩家信息获取失败', 401);
            }

            // 获取并验证参数
            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'player_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $cmd = $request->post('cmd');
            if ($cmd === null || $cmd === '') {
                return $this->fail(trans('cmd_required', [], 'player_machine'));
            }

            $data = $request->post('data', 0);
            if (!is_numeric($data)) {
                return $this->fail(trans('data_must_be_numeric', [], 'player_machine'));
            }
            $dataInt = (int)$data;

            // 查询机台
            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                Log::warning('[PlayerMachine-SendCmd] 机台不存在', [
                    'cmd_id' => $cmdId,
                    'machine_id' => $machineIdInt,
                    'player_id' => $player->id,
                ]);
                return $this->fail(trans('machine_not_found', [], 'player_machine'));
            }

            // 创建机台服务
            $services = MachineServices::createServices($machine, $lang);

            // 记录指令执行前的状态
            Log::info('[PlayerMachine-SendCmd] 准备执行指令', [
                'cmd_id' => $cmdId,
                'trace_context' => $traceContext,  // 关键：包含 batch_id, wash_id 等
                'operator_type' => 'player',
                'player_id' => $player->id,
                'player_name' => $player->name,
                'machine_id' => $machineIdInt,
                'machine_code' => $machine->code,
                'machine_type' => $machine->type,
                'cmd' => $cmd,
                'data' => $dataInt,
                'lang' => $lang,
                'ip' => $request->getRealIp(),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            // 发送指令 - 标记为玩家操作
            $execStartTime = microtime(true);
            $result = $services->sendCmd($cmd, $dataInt, 'player', $player->id);
            $execDuration = round((microtime(true) - $execStartTime) * 1000, 2);

            $totalDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('[PlayerMachine-SendCmd] 指令执行完成', [
                'cmd_id' => $cmdId,
                'trace_context' => $traceContext,  // 关键：包含 batch_id, wash_id 等
                'operator_type' => 'player',
                'player_id' => $player->id,
                'player_name' => $player->name,
                'machine_id' => $machineIdInt,
                'machine_code' => $machine->code,
                'cmd' => $cmd,
                'data' => $dataInt,
                'result' => $result,
                'exec_duration_ms' => $execDuration,
                'total_duration_ms' => $totalDuration,
                'success' => true,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            // 性能警告（超过500ms）
            if ($totalDuration > 500) {
                Log::warning('[PlayerMachine-SendCmd] 指令执行耗时过长', [
                    'cmd_id' => $cmdId,
                    'machine_id' => $machineIdInt,
                    'cmd' => $cmd,
                    'duration_ms' => $totalDuration,
                    'threshold_ms' => 500,
                ]);
            }

            return $this->success([
                'result' => $result,
                'cmd' => $cmd,
                'machine_id' => $machineIdInt
            ], trans('cmd_sent_success', [], 'player_machine'));

        } catch (Exception $e) {
            $failDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('[PlayerMachine-SendCmd] 指令执行失败', [
                'cmd_id' => $cmdId,
                'trace_context' => $traceContext,  // 关键：包含 batch_id, wash_id 等
                'operator_type' => 'player',
                'player_id' => isset($player) && $player ? $player->id : null,
                'player_name' => isset($player) && $player ? $player->name : null,
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null,
                'cmd' => $cmd ?? null,
                'data' => isset($dataInt) ? $dataInt : null,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'duration_ms' => $failDuration,
                'trace' => $e->getTraceAsString(),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            return $this->handleException($e, '【玩家操作】机台指令失败', [
                'operator_type' => 'player',
                'player_id' => isset($player) && $player ? $player->id : null,
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null,
                'cmd' => $cmd ?? null
            ]);
        }
    }

    /**
     * 获取机台状态（玩家查询）
     * POST /api/v1/machine/status
     *
     * 注意：这是只读查询操作，不强制要求玩家验证
     *
     * @param Request $request
     * @return Response
     */
    public function getMachineStatus(Request $request): Response
    {
        $player = null;  // 初始化变量

        try {
            $lang = $this->setLanguage($request);

            // 尝试获取玩家信息（用于日志），但不强制要求
            $player = $this->getPlayer($request);

            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'player_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                return $this->fail(trans('machine_not_found', [], 'player_machine'));
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
            return $this->handleException($e, '【玩家查询】获取机台状态失败', [
                'operator_type' => 'player',
                'player_id' => isset($player) && $player ? $player->id : null,
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null
            ]);
        }
    }

    /**
     * 检查机台是否在线
     * POST /api/v1/machine/check-online
     *
     * 注意：这是只读查询操作，不强制要求玩家验证
     * 会尝试获取玩家信息用于日志记录，但验证失败不会阻止查询
     *
     * @param Request $request
     * @return Response
     */
    public function checkOnline(Request $request): Response
    {
        $player = null;  // 初始化变量

        try {
            $this->setLanguage($request);

            // 尝试获取玩家信息（用于日志），但不强制要求
            $player = $this->getPlayer($request);

            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'player_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                return $this->fail(trans('machine_not_found', [], 'player_machine'));
            }

            // 检查主连接是否在线（带异常处理）
            $mainUid = $machine->domain . ':' . $machine->port;
            try {
                $mainOnline = Gateway::isUidOnline($mainUid);
            } catch (Exception $e) {
                Log::warning('Gateway isUidOnline failed for main connection', [
                    'machine_id' => $machineId,
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
                        'machine_id' => $machineId,
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
            return $this->handleException($e, '【玩家查询】检查机台在线失败', [
                'operator_type' => 'player',
                'player_id' => isset($player) && $player ? $player->id : null,
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null
            ]);
        }
    }

    /**
     * 获取机台操作描述
     * POST /api/v1/machine/get-description
     *
     * 注意：这是只读查询操作，不强制要求玩家验证
     *
     * @param Request $request
     * @return Response
     */
    public function getDescription(Request $request): Response
    {
        $player = null;  // 初始化变量

        try {
            $lang = $this->setLanguage($request);

            // 尝试获取玩家信息（用于日志），但不强制要求
            $player = $this->getPlayer($request);

            $machineId = $request->post('machine_id');
            if ($machineId === null || $machineId === '') {
                return $this->fail(trans('machine_id_required', [], 'player_machine'));
            }

            $machineIdInt = (int)$machineId;
            if ($machineIdInt <= 0) {
                return $this->fail('无效的机台ID', 400);
            }

            $fun = $request->post('fun', '');
            $data = $request->post('data', 0);

            $machine = Machine::find($machineIdInt);
            if (!$machine) {
                return $this->fail(trans('machine_not_found', [], 'player_machine'));
            }

            $services = MachineServices::createServices($machine, $lang);
            $description = $services->getDescription($fun, (int)$data);

            return $this->success([
                'description' => $description
            ]);

        } catch (Exception $e) {
            return $this->handleException($e, '【玩家查询】获取机台描述失败', [
                'operator_type' => 'player',
                'player_id' => isset($player) && $player ? $player->id : null,
                'machine_id' => isset($machineIdInt) ? $machineIdInt : null
            ]);
        }
    }

    /**
     * 批量检查机台在线状态（玩家端）
     * POST /api/v1/machine/batch-check-online
     *
     * 注意：这是只读查询操作，不强制要求玩家验证
     * 会尝试获取玩家信息用于日志记录，但验证失败不会阻止查询
     *
     * @param Request $request
     * @return Response
     */
    public function batchCheckOnline(Request $request): Response
    {
        $player = null;  // 初始化变量

        try {
            $this->setLanguage($request);

            // 尝试获取玩家信息（用于日志），但不强制要求
            $player = $this->getPlayer($request);

            $machineIds = $request->post('machine_ids', []);

            if (empty($machineIds) || !is_array($machineIds)) {
                return $this->fail(trans('machine_ids_required', [], 'player_machine'));
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
                    $mainOnline = false;
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
                        $autoOnline = false;
                    }
                }

                // 根据机型计算在线状态
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
            return $this->handleException($e, '【玩家查询】批量检查机台在线失败', [
                'operator_type' => 'player',
                'player_id' => isset($player) && $player ? $player->id : null,
                'machine_count' => isset($validIds) ? count($validIds) : 0
            ]);
        }
    }
}
