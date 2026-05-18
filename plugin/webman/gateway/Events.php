<?php

namespace plugin\webman\gateway;

use app\model\GameType;
use app\model\Machine;
use app\model\Notice;
use app\service\machine\MachineServices;
use Exception;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Carbon;
use support\Cache;
use support\Log;
use Webman\Push\PushException;

class Events
{
    /**
     * @param $gatewayPort
     * @param $domain
     * @param $port
     * @param $client_id
     * @return Machine|null
     */
    public static function getMachine($gatewayPort, $domain, $port, $client_id): ?Machine
    {
        if (empty($domain) || empty($port) || empty($client_id) || empty($gatewayPort)) {
            return null;
        }

        //优化为缓存处理
        $portStrategies = [
            config('gateway_worker.slot_port') => [
                'type' => GameType::TYPE_SLOT,
                'domain_field' => 'domain',
                'port_field' => 'port'
            ],
            config('gateway_worker.jackpot_port') => [
                'type' => GameType::TYPE_STEEL_BALL,
                'domain_field' => 'domain',
                'port_field' => 'port'
            ],
            config('gateway_worker.slot_auto_port') => [
                'type' => GameType::TYPE_SLOT,
                'domain_field' => 'auto_card_domain',
                'port_field' => 'auto_card_port'
            ]
        ];

        // 匹配策略
        if (!isset($portStrategies[$gatewayPort])) {
            return null;
        }
        $strategy = $portStrategies[$gatewayPort];

        // 格式化缓存key
        $cacheKey = sprintf('machine:domain:%s:port:%s:type:%s',
            $domain, $port, $strategy['type']
        );

        if (empty($jsonData = Cache::get($cacheKey))) {
            /** @var Machine $machine */
            $machine = Machine::query()->where($strategy['domain_field'], $domain)
                ->where($strategy['port_field'], $port)
                ->where('type', $strategy['type'])
                ->first();  // 返回模型对象或null

            // 优化：缩短缓存时间从 3600 秒到 300 秒（5 分钟），更快响应机台状态变更
            !is_null($machine) && Cache::set($cacheKey, $machine, 300);

            return $machine;
        }

        return $jsonData;
    }

    /**
     * 设备连接
     * @param $client_id
     * @return bool
     * @throws PushException
     */
    public static function onConnect($client_id): bool
    {
        $log = Log::channel('machine');
        $domain = $_SERVER['REMOTE_ADDR'];
        $port = $_SERVER['REMOTE_PORT'];
        $gatewayPort = $_SERVER['GATEWAY_PORT'];

        // 增强日志：记录所有连接尝试
        $log->info('机台连接尝试', [
            'client_id' => $client_id,
            'remote_addr' => $domain,
            'remote_port' => $port,
            'gateway_port' => $gatewayPort,
        ]);

        // IP 白名单验证
        if (!in_array($domain, config('gateway_worker.whitelist'))) {
            $log->warning('机台连接被拒绝：IP 不在白名单', [
                'client_id' => $client_id,
                'remote_addr' => $domain,
                'remote_port' => $port,
                'gateway_port' => $gatewayPort,
                'whitelist' => config('gateway_worker.whitelist'),
            ]);
            return Gateway::closeClient($client_id);
        }

        $machine = self::getMachine($gatewayPort, $domain, $port, $client_id);

        if (!empty($machine) && $machine->status == 1 && $machine->deleted_at == null) {
            Gateway::bindUid($client_id, $domain . ':' . $port);
            MachineServices::sendMachineNowStatusMessage($machine->id);

            // 增强日志：记录成功连接的详细信息
            $log->info('机台上线成功', [
                'client_id' => $client_id,
                'machine_id' => $machine->id,
                'code' => $machine->code,
                'name' => $machine->name ?? '',
                'type' => $machine->type,
                'remote_addr' => $domain,
                'remote_port' => $port,
                'gateway_port' => $gatewayPort,
                'bind_uid' => $domain . ':' . $port,
            ]);
        } else {
            // 增强日志：记录拒绝连接的具体原因
            $reason = '未知原因';
            if (empty($machine)) {
                $reason = '机台记录不存在';
            } elseif ($machine->status != 1) {
                $reason = '机台已禁用（status=' . $machine->status . '）';
            } elseif ($machine->deleted_at != null) {
                $reason = '机台已删除';
            }

            $log->warning('机台连接被拒绝：' . $reason, [
                'client_id' => $client_id,
                'remote_addr' => $domain,
                'remote_port' => $port,
                'gateway_port' => $gatewayPort,
                'machine_id' => $machine->id ?? null,
                'machine_status' => $machine->status ?? null,
                'machine_deleted_at' => $machine->deleted_at ?? null,
            ]);

            return Gateway::closeClient($client_id);
        }
        return true;
    }

    /**
     * 设备消息处理
     * @param $client_id
     * @param $message
     * @return bool
     * @throws \Exception
     */
    public static function onMessage($client_id, $message): bool
    {
        $log = Log::channel('machine');
        $domain = $_SERVER['REMOTE_ADDR'];
        $port = $_SERVER['REMOTE_PORT'];
        $gatewayPort = $_SERVER['GATEWAY_PORT'];

        // 增强日志：空消息检查
        if (empty($message)) {
            $log->warning('收到空消息，关闭连接', [
                'client_id' => $client_id,
                'remote_addr' => $domain,
                'remote_port' => $port,
                'gateway_port' => $gatewayPort,
            ]);
            return Gateway::closeClient($client_id);
        }

        $machine = self::getMachine($gatewayPort, $domain, $port, $client_id);

        if (empty($machine) || $machine->status == 0 || $machine->deleted_at != null) {
            // 增强日志：机台验证失败的详细原因
            $reason = empty($machine) ? '机台不存在' : ($machine->status == 0 ? '机台已禁用' : '机台已删除');
            $log->warning('消息处理失败：' . $reason, [
                'client_id' => $client_id,
                'remote_addr' => $domain,
                'remote_port' => $port,
                'gateway_port' => $gatewayPort,
                'machine_id' => $machine->id ?? null,
                'message_hex' => bin2hex($message),
            ]);
            return Gateway::closeClient($client_id);
        }

        // 增强日志：记录消息处理
        $log->debug('处理机台消息', [
            'client_id' => $client_id,
            'machine_id' => $machine->id,
            'machine_code' => $machine->code,
            'gateway_port' => $gatewayPort,
            'message_length' => strlen($message),
            'message_hex' => bin2hex($message),
        ]);

        $service = MachineServices::createServices($machine);
        switch ($gatewayPort) {
            case config('gateway_worker.slot_port'):
                switch ($machine->control_type) {
                    case Machine::CONTROL_TYPE_MEI:
                        $msg = strtoupper(bin2hex($message));
                        $chunkSize = 32;
                        for ($i = 0; $i < strlen($msg); $i += $chunkSize) {
                            $chunk = substr($msg, $i, $chunkSize);
                            $service->slotCmd($chunk);
                        }
                        return true;
                    case Machine::CONTROL_TYPE_SONG:
                        return $service->slotCmd($message);
                    default:
                        return true;
                }
            case config('gateway_worker.slot_auto_port'):
                return $service->slotAutoCmd($message);
            case config('gateway_worker.jackpot_port'):
                return $service->jackPotCmd($message);
            default:
                $log->error('未知的 Gateway 端口', [
                    'client_id' => $client_id,
                    'gateway_port' => $gatewayPort,
                    'machine_id' => $machine->id,
                ]);
                return Gateway::closeClient($client_id);
        }
    }

    /**
     * 设备断开连接
     * @return void
     * @throws PushException
     * @throws Exception
     */
    public static function onClose($client_id)
    {
        $log = Log::channel('machine');
        $domain = $_SERVER['REMOTE_ADDR'];
        $port = $_SERVER['REMOTE_PORT'];
        $gatewayPort = $_SERVER['GATEWAY_PORT'];

        // 增强日志：记录连接断开
        $log->info('机台连接断开', [
            'client_id' => $client_id,
            'remote_addr' => $domain,
            'remote_port' => $port,
            'gateway_port' => $gatewayPort,
        ]);

        // 1解绑设备
        Gateway::unbindUid($client_id, $domain . ':' . $port);

        // 2发送设备离线消息
        /** @var Machine $machine */
        $machine = self::getMachine($gatewayPort, $domain, $port, $client_id);

        if (!empty($machine)) {
            MachineServices::sendMachineNowStatusMessage($machine->id, 'offline');

            // 检查 30 分钟内的异常通知
            $count = Notice::whereBetween('created_at', [Carbon::now()->subMinutes(30), Carbon::now()])
                ->where('status', 0)
                ->where('source_id', $machine->id)
                ->where('type', Notice::TYPE_MACHINE)
                ->count();

            if ($count == 0) {
                sendMachineException($machine, Notice::TYPE_MACHINE);

                // 增强日志：记录发送异常通知
                $log->warning('机台异常通知已发送', [
                    'machine_id' => $machine->id,
                    'machine_code' => $machine->code,
                    'machine_name' => $machine->name ?? '',
                    'remote_addr' => $domain,
                    'remote_port' => $port,
                ]);
            }

            // 增强日志：记录机台离线详情
            $log->info('机台离线', [
                'machine_id' => $machine->id,
                'machine_code' => $machine->code,
                'machine_name' => $machine->name ?? '',
                'remote_addr' => $domain,
                'remote_port' => $port,
                'gateway_port' => $gatewayPort,
                'recent_exception_count' => $count,
            ]);
        } else {
            // 增强日志：未找到机台记录
            $log->warning('断开连接时未找到机台记录', [
                'client_id' => $client_id,
                'remote_addr' => $domain,
                'remote_port' => $port,
                'gateway_port' => $gatewayPort,
            ]);
        }
    }
}
