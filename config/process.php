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
use process\ClearAbnormalMachine;
use process\GamePoolSocket;
use process\GetAmsViewers;
use process\GetTencentViewers;
use process\LogClear;
use process\LotteryPoolSocket;
use process\LotteryRemind;
use process\MachineKeepOutPlayer;
use process\MediaClear;
use process\MediaRecordingClear;
use process\NationalPromoterRebate;
use process\OnlinePlayerSocket;
use process\ProfitSettlement;
use process\RechargeRemind;
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
    'RechargeRemind' => [
        'handler' => RechargeRemind::class
    ],
    'WithdrawRemind' => [
        'handler' => WithdrawRemind::class
    ],
    'ClearAbnormalMachine' => [
        'handler' => ClearAbnormalMachine::class
    ],
    'NationalPromoterRebate' => [
        'handler' => NationalPromoterRebate::class
    ],
    'MediaRecordingClear' => [
        'handler' => MediaRecordingClear::class
    ],
    'ReverseWater' => [
        'handler' => ReverseWater::class
    ],
    'TencentStream' => [
        'handler' => TencentStream::class
    ],
    'MediaClear' => [
        'handler' => MediaClear::class
    ],
    'GetAmsViewers' => [
        'handler' => GetAmsViewers::class
    ],
    'GetTencentViewers' => [
        'handler' => GetTencentViewers::class
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
