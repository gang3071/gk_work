<?php

use Webman\GatewayWorker\BusinessWorker;
use Webman\GatewayWorker\Gateway;
use Webman\GatewayWorker\Register;

// 钢珠卡连接
return [
    'gateway' => [
        'handler' => Gateway::class,
        'listen' => 'tcp://0.0.0.0:' . config('gateway_worker.jackpot_port'),
        'count' => cpu_count(),
        'reloadable' => false,
        'constructor' => ['config' => [
            'lanIp' => '127.0.0.1',
            'startPort' => 2300,
            'pingInterval' => 10,
            'pingData' => hex2bin('A22000000000005500000000000082DD'),
            'registerAddress' => '127.0.0.1:1236',
            'onConnect' => function () {
            },
        ]]
    ],
    'worker' => [
        'handler' => BusinessWorker::class,
        'count' => cpu_count() * 2,
        'constructor' => ['config' => [
            'eventHandler' => plugin\webman\gateway\Events::class,
            'name' => 'jackpotBusinessWorker',
            'registerAddress' => '127.0.0.1:1236',
        ]]
    ],
    'register' => [
        'handler' => Register::class,
        'listen' => 'text://127.0.0.1:1236',
        'count' => 1, // Must be 1
        'constructor' => []
    ],
];
