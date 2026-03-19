<?php

use Webman\GatewayWorker\Gateway;

// 斯洛开分卡连接
return [
    'gateway' => [
        'handler' => Gateway::class,
        'listen' => 'tcp://0.0.0.0:' . config('gateway_worker.slot_port'),
        'count' => cpu_count(),
        'reloadable' => false,
        'constructor' => ['config' => [
            'lanIp' => '127.0.0.1',
            'startPort' => 2500,
            'pingInterval' => 10,
            'pingData' => hex2bin('A220000000000000000005050000030C'),
            'registerAddress' => '127.0.0.1:1236',
            'onConnect' => function () {
            },
        ]]
    ],
];
