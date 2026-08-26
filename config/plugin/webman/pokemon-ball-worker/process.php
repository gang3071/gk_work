<?php

use Webman\GatewayWorker\BusinessWorker;
use Webman\GatewayWorker\Gateway;

// 精灵球机台连接
// Register 已在 jackpot-worker 中定义，所有 Gateway/Worker 共享同一个 Register
return [
    'gateway' => [
        'handler' => Gateway::class,
        'listen' => 'tcp://0.0.0.0:' . config('gateway_worker.pokemon_ball_port'),  // 监听所有网卡，允许外部连接
        'count' => cpu_count(),
        'reloadable' => false,
        'constructor' => ['config' => [
            'lanIp' => '127.0.0.1',  // 自动获取局域网 IP
            'startPort' => 2700,
            'pingInterval' => 10,
            'pingData' => hex2bin('FAEA0102000306FBEB'),  // 精灵球心跳包
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
            'name' => 'pokemonBallBusinessWorker',
            'registerAddress' => '127.0.0.1:1236',
        ]]
    ],
];
