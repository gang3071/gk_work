<?php

use Webman\GatewayWorker\Gateway;

// 斯洛斯洛自动卡连接
return [
    'gateway' => [
        'handler' => Gateway::class,
        'listen' => 'tcp://0.0.0.0:' . config('gateway_worker.slot_auto_port'),
        'count' => cpu_count(),
        'reloadable' => false,
        'constructor' => ['config' => [
            'lanIp' => '127.0.0.1',
            'startPort' => 2550,
            'pingInterval' => 10,
            'pingData' => hex2bin('AA57080000004B0D'),
            'registerAddress' => '127.0.0.1:1236',
            'onConnect' => function () {
            },
        ]]
    ],
];
