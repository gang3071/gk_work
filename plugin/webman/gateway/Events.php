<?php

namespace plugin\webman\gateway;

use app\model\GameType;
use app\model\Machine;
use app\service\machine\MachineServices;
use GatewayWorker\Lib\Gateway;
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

            !is_null($machine) && Cache::set($cacheKey, $machine, 3600);

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
        $log->info('机台上线', [
            'remote_addr' => $domain,
            'remote_port' => $port,
            'gateway_port' => $_SERVER['GATEWAY_PORT'],
        ]);
        if (!in_array($domain, config('gateway_worker.whitelist'))) {
            return Gateway::closeClient($client_id);
        }
        $machine = self::getMachine($_SERVER['GATEWAY_PORT'], $domain, $port, $client_id);
        if (!empty($machine) && $machine->status == 1 && $machine->deleted_at == null) {
            Gateway::bindUid($client_id, $domain . ':' . $port);
            MachineServices::sendMachineNowStatusMessage($machine->id);
        } else {
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

        if (empty($message)) {
            return Gateway::closeClient($client_id);
        }
        $machine = self::getMachine($gatewayPort, $domain, $port, $client_id);
        if (empty($machine) || $machine->status == 0 || $machine->deleted_at != null) {
            $log->warning('onMessage无效机台关闭', [
                'domain' => $domain,
                'port' => $port,
                'machine_null' => empty($machine),
                'status' => $machine?->status,
                'deleted_at' => $machine?->deleted_at,
                'code' => $machine?->code,
                'control_type' => $machine?->control_type,
            ]);
            return Gateway::closeClient($client_id);
        }
        try {
            $service = MachineServices::createServices($machine);
        } catch (\Throwable $e) {
            $log->error('onMessage createServices失败', [
                'code' => $machine->code,
                'type' => $machine->type,
                'control_type' => $machine->control_type,
                'domain' => $domain,
                'port' => $port,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
        $isOffline = ($machine->machine_source == Machine::MACHINE_SOURCE_OFFLINE);
        switch ($gatewayPort) {
            case config('gateway_worker.slot_port'):
                switch ($machine->control_type) {
                    case Machine::CONTROL_TYPE_MEI:
                        $msg = strtoupper(bin2hex($message));
                        Log::channel('slot_machine')->info('收到双美Slot消息', [
                            'code' => $machine->code,
                            'remote_addr' => $domain,
                            'remote_port' => $port,
                            'hex' => $msg,
                        ]);
                        $chunkSize = 32;
                        for ($i = 0; $i < strlen($msg); $i += $chunkSize) {
                            $chunk = substr($msg, $i, $chunkSize);
                            $service->slotCmd($chunk);
                        }
                        return true;
                    case Machine::CONTROL_TYPE_SONG:
                        $hex = bin2hex($message);
                        $channel = $isOffline ? 'song_offline_slot_machine' : 'song_slot_machine';
                        Log::channel($channel)->info($isOffline ? '收到小淞线下Slot消息' : '收到小淞Slot消息', [
                            'code' => $machine->code,
                            'remote_addr' => $domain,
                            'remote_port' => $port,
                            'hex' => $hex,
                        ]);
                        return $service->slotCmd($hex);
                    default:
                        return true;
                }
            case config('gateway_worker.slot_auto_port'):
                $hexAuto = strtoupper(bin2hex($message));
                Log::channel('slot_machine')->info('收到双美Slot自动机消息', [
                    'code' => $machine->code,
                    'remote_addr' => $domain,
                    'remote_port' => $port,
                    'hex' => $hexAuto,
                ]);
                return $service->slotAutoCmd($hexAuto);
            case config('gateway_worker.jackpot_port'):
                switch ($machine->control_type) {
                    case Machine::CONTROL_TYPE_MEI:
                        $hexMei = strtoupper(bin2hex($message));
                        Log::channel('jackpot_machine')->info('收到双美钢珠消息', [
                            'code' => $machine->code,
                            'remote_addr' => $domain,
                            'remote_port' => $port,
                            'hex' => $hexMei,
                        ]);
                        return $service->jackPotCmd($hexMei);
                    case Machine::CONTROL_TYPE_SONG:
                        $hexSong = bin2hex($message);
                        $channel = $isOffline ? 'song_offline_jackpot_machine' : 'song_jackpot_machine';
                        Log::channel($channel)->info($isOffline ? '收到小淞线下钢珠消息' : '收到小淞钢珠消息', [
                            'code' => $machine->code,
                            'remote_addr' => $domain,
                            'remote_port' => $port,
                            'hex' => $hexSong,
                        ]);
                        return $service->jackPotCmd($hexSong);
                    default:
                        $log->warning('jackpot_port未知control_type', [
                            'code' => $machine->code,
                            'control_type' => $machine->control_type,
                            'domain' => $domain,
                            'port' => $port,
                        ]);
                        return true;
                }
            default:
                return Gateway::closeClient($client_id);
        }
    }

    /**
     * 设备断开连接
     * @return void
     */
    public static function onClose($client_id)
    {
        $domain = $_SERVER['REMOTE_ADDR'];
        $port = $_SERVER['REMOTE_PORT'];
        // 1解绑设备
        Gateway::unbindUid($client_id, $domain . ':' . $port);
    }
}
