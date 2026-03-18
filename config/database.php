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
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'read' => [
                'host' => [
                    env('DB_READ_HOST', '127.0.0.1'),
                ],
                'username' => env('DB_READ_USERNAME', 'forge'),
                'password' => env('DB_READ_PASSWORD', ''),
            ],
            // 写库配置（主库）
            'write' => [
                'host' => [
                    env('DB_WRITE_HOST', '127.0.0.1'),
                ],
                'username' => env('DB_WRITE_USERNAME', 'forge'),
                'password' => env('DB_WRITE_PASSWORD', ''),
            ],
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [
                \PDO::ATTR_TIMEOUT => 3
            ],
        ],
        'mongodb' => [
            'driver' => 'mongodb',
            'host' => env('MONGODB_HOST', '127.0.0.1'),
            'port' => env('MONGODB_PORT', 27017),
            'database' => env('MONGODB_DATABASE', 'luck3'),
            'username' => env('MONGODB_USERNAME', null),
            'password' => env('MONGODB_PASSWORD', null),
            'options' => [
                'database' => env('MONGODB_AUTH_DATABASE', 'admin'),
            ],
        ],
    ]
];