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
    // 🎯 iGaming 核心系统：结算、返点、流水处理
    // 特点：大量 Lua 脚本、高并发写入、单一钱包架构
    'work' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),

        // ✅ 针对 iGaming 结算任务的加固配置
        'timeout' => 5.0,              // 🔧 调高到5秒：容忍结算高峰时的连接排队
        'read_timeout' => 5.0,         // 🔧 调高到5秒：容忍复杂 Lua 脚本执行时间
        'persistent' => true,          // ✅ 持久连接（每进程1个，54进程=54个稳定连接）
        'retry_interval' => 100,       // 连接失败后100ms重试

        // 连接优化配置
        'options' => [
            'prefix' => env('REDIS_PREFIX', ''),
            'parameters' => [
                'tcp_nodelay' => true,  // ✅ 禁用Nagle算法，降低延迟
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
