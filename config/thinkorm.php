<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            // 数据库类型
            'type' => 'mysql',
            // 服务器地址
            'hostname' => env('DB_WRITE_HOST', '127.0.0.1'),
            // 数据库名
            'database' => env('DB_DATABASE', 'forge'),
            // 数据库用户名
            'username' => env('DB_WRITE_USERNAME', 'forge'),
            // 数据库密码
            'password' => env('DB_WRITE_PASSWORD', ''),
            // 数据库连接端口
            'hostport' => env('DB_PORT', '3306'),
            'slave' => [
                [
                    'hostname' => env('DB_READ_HOST', '127.0.0.1'),
                    'database' => env('DB_DATABASE', 'forge'),
                    'username' => env('DB_READ_USERNAME', 'forge'),
                    'password' => env('DB_READ_PASSWORD', ''),
                    'hostport' => env('DB_PORT', '3306'),
                    'weight' => 1, // 权重
                ]
            ],

            // 分布式配置
            'deploy' => 1, // 启用分布式
            'rw_separate' => false, // 读写分离
            'master_num' => 1, // 主库数量

            // 数据库连接参数
            'params' => [
                // 连接超时3秒
                \PDO::ATTR_TIMEOUT => 3,
            ],
            'charset' => 'utf8mb4',
            // 数据库表前缀
            'prefix' => '',
            // 断线重连
            'break_reconnect' => true,
            // 关闭SQL监听日志
            'trigger_sql' => true,
            // 自定义分页类
            'bootstrap' => ''
        ],
    ],
];
