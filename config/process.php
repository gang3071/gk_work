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

use process\BurstCleaner;
use process\ChannelSettlement;
use process\GamePoolSocket;
use process\LogClear;
use process\LotteryPoolSocket;
use process\LotteryRemind;
use process\MachineKeepOutPlayer;
use process\NationalPromoterRebate;
use process\OnlinePlayerSocket;
use process\ProfitSettlement;
use process\ReverseWater;
use process\SyncMachineActivity;
use process\TencentStream;
use process\WithdrawRemind;
use Workerman\Worker;

return [
    // File update detection and automatic reload
    'monitor' => [
        'handler' => process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/addons',
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/plugin',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                'enable_file_monitor' => !Worker::$daemonize && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ],
    'MachineKeepOutPlayer' => [
        'handler' => MachineKeepOutPlayer::class
    ],
    'SyncMachineActivity' => [
        'handler' => SyncMachineActivity::class
    ],
    'ProfitSettlement' => [
        'handler' => ProfitSettlement::class
    ],
    'LotteryPoolSocket' => [
        'handler' => LotteryPoolSocket::class
    ],
    'OnlinePlayerSocket' => [
        'handler' => OnlinePlayerSocket::class
    ],
    'LogClear' => [
        'handler' => LogClear::class
    ],
    'LotteryRemind' => [
        'handler' => LotteryRemind::class
    ],
    'WithdrawRemind' => [
        'handler' => WithdrawRemind::class
    ],
    'NationalPromoterRebate' => [
        'handler' => NationalPromoterRebate::class
    ],
    'ReverseWater' => [
        'handler' => ReverseWater::class
    ],
    'TencentStream' => [
        'handler' => TencentStream::class
    ],
    'ChannelSettlement' => [
        'handler' => ChannelSettlement::class
    ],
    'GamePoolSocket' => [
        'handler' => GamePoolSocket::class
    ],
    'BurstCleaner' => [
        'handler' => BurstCleaner::class
    ],
];
