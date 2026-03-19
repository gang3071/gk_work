<?php
return [
    'default' => [
        'host' => sprintf(
            'redis://%s:%s',
            env('REDIS_HOST', '127.0.0.1'),
            env('REDIS_PORT', 6379)
        ),
        'options' => [
            'auth' => env('REDIS_PASSWORD', null),       // 密码，字符串类型，可选参数
            'db' => env('REDIS_DB', 0),                  // 数据库
            'prefix' => '',                              // key 前缀
            'max_attempts' => 5,                        // 消费失败后，重试次数
            'retry_seconds' => 5,                        // 重试间隔，单位秒
        ]
    ],
];
