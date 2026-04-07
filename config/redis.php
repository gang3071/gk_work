<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    // ========== gk_work 业务连接池 ==========
    // 用于：余额查询、缓存、Lua原子操作等
    'work' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),

        // ✅ 连接管理配置（防止僵死连接和连接耗尽）
        'timeout' => 2.5,              // 连接超时2.5秒（防止无限等待）
        'read_timeout' => 2.5,         // 读取超时2.5秒（防止读操作阻塞）
        'persistent' => true,          // 持久连接（复用连接，减少连接数）
        'retry_interval' => 100,       // 连接失败后100ms重试

        // 连接池配置（可选，predis支持）
        'options' => [
            'prefix' => env('REDIS_PREFIX', ''),
            'parameters' => [
                'tcp_nodelay' => true,  // 禁用Nagle算法，降低延迟
            ],
        ],
    ],

    // ========== gk_work 队列操作连接池 ==========
    // 🎯 用于业务代码中的队列操作（非 redis-queue 插件）
    // 注意：redis-queue 插件使用独立配置，不使用此连接
    'queue' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),

        // 🚨 关键区别：队列需要长时间阻塞
        'timeout' => 2.5,              // 连接建立超时
        'read_timeout' => 0,           // 💡 0 = 无限等待（队列 BRPOP 需要）
        'persistent' => true,          // 持久连接
        'retry_interval' => 100,

        'options' => [
            'prefix' => env('REDIS_PREFIX', ''),
            'parameters' => [
                'tcp_nodelay' => true,
            ],
        ],
    ],

    // ========== 向后兼容：default 指向 work ==========
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),
        'timeout' => 2.5,
        'read_timeout' => 2.5,
        'persistent' => true,
        'retry_interval' => 100,
        'options' => [
            'prefix' => env('REDIS_PREFIX', ''),
            'parameters' => [
                'tcp_nodelay' => true,
            ],
        ],
    ],
];
