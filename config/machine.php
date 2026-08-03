<?php
/**
 * 实体机台配置
 */
return [
    // ========== 机台控制类型 ==========
    'control_type' => [
        'mei' => 1,   // 美商工控
        'song' => 2,  // 松岗工控
    ],

    // ========== 串口通信配置 ==========
    'serial_port' => [
        'timeout' => 3,       // 串口超时（秒）
        'baudrate' => 9600,   // 波特率
        'retry' => 3,         // 重试次数
    ],

    // ========== 机台保留配置 ==========
    'keep' => [
        'default_timeout' => 1800,    // 默认保留时长（秒），30分钟
        'pending_minutes' => 2,        // 进入保留状态的等待时间（分钟）
        'free_time_enabled' => true,   // 是否启用免扣时间段
    ],

    // ========== 踢出玩家配置 ==========
    'kick' => [
        'idle_timeout' => 300,         // 空闲超时（秒），5分钟无操作
        'check_interval' => 10,        // 检查间隔（秒），每分钟检查一次
    ],

    // ========== 机台状态同步配置 ==========
    'sync' => [
        'status_interval' => 5,        // 状态同步间隔（秒）
        'log_interval' => 10,          // 日志同步间隔（秒）
        'activity_interval' => 60,     // 活动同步间隔（秒）
    ],

    // ========== 异常机台清理配置 ==========
    'cleanup' => [
        'abnormal_timeout' => 3600,    // 异常机台超时（秒），1小时
        'cleanup_interval' => 3600,    // 清理间隔（秒），每小时清理一次
    ],

    // ========== 开分卡配置 ==========
    'open_card' => [
        'enabled' => true,             // 是否启用开分卡
        'test_timeout' => 5,           // 测试超时（秒）
    ],

    // ========== WebSocket 推送配置 ==========
    'push' => [
        'enabled' => true,             // 是否启用推送
        'channels' => [
            'machine_status' => 'machine-{machine_id}',      // 机台状态频道
            'player_machine' => 'player-{player_id}',        // 玩家机台频道
            'global_machine' => 'global-machines',            // 全局机台频道
        ],
    ],

    // ========== 日志配置 ==========
    'logging' => [
        'operation_log' => true,       // 是否记录操作日志
        'receive_log' => true,         // 是否记录接收日志
        'use_mongodb' => false,        // 是否使用 MongoDB（已禁用）
    ],

    // ========== 机台维护配置 ==========
    'maintenance' => [
        'enabled' => false,            // 是否全局维护
        'message' => '系统维护中，请稍后再试',
    ],
];
