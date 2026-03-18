<?php
/**
 * 机台任务进程配置
 */

use process\ClearAbnormalMachine;
use process\GetAmsViewers;
use process\GetTencentViewers;
use process\MachineKeepOutPlayer;
use process\MediaClear;
use process\MediaRecordingClear;
use process\Monitor;
use process\SyncMachineActivity;
use process\TencentStream;
use Workerman\Worker;

return [
    'monitor' => [
        'handler' => Monitor::class,
        'reloadable' => false,
        'constructor' => [
            'monitorDir' => [
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/.env',
            ],
            'monitorExtensions' => ['php', 'env'],
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
    'ClearAbnormalMachine' => [
        'handler' => ClearAbnormalMachine::class
    ],
    'TencentStream' => [
        'handler' => TencentStream::class
    ],
    'MediaClear' => [
        'handler' => MediaClear::class
    ],
    'MediaRecordingClear' => [
        'handler' => MediaRecordingClear::class
    ],
    'GetAmsViewers' => [
        'handler' => GetAmsViewers::class
    ],
    'GetTencentViewers' => [
        'handler' => GetTencentViewers::class
    ],
];
