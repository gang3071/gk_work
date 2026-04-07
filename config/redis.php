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
    'default' => [
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
];
