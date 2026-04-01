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
    'listen' => env('APP_LISTEN', 'http://0.0.0.0:8788'),
    'transport' => 'tcp',
    'context' => [],
    'name' => 'webman',
    // 修改 1: 降低进程数，减少 CPU 切换压力
    'count' => cpu_count() * 2,
    'user' => '',
    'group' => '',
    'reusePort' => false,
    'event_loop' => '',
    // 修改 2: 给长耗时请求留出更多退场时间
    'stop_timeout' => 10,
    // 修改 3: 显式设置 max_request 为 0，彻底禁用由于请求数达到上限引起的重启
    'max_request' => 0,
    'pid_file' => runtime_path() . '/webman.pid',
    'status_file' => runtime_path() . '/webman.status',
    'stdout_file' => runtime_path() . '/logs/stdout.log',
    'log_file' => runtime_path() . '/logs/workerman.log',
    'max_package_size' => 10 * 1024 * 1024
];
