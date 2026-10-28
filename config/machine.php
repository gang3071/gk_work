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

    // ========== 小松線下機台開分選項 ==========
    // 換算：player_amount = machine_score × odds_x / odds_y
    // min_player_amount：玩家單次上分最低金額（分）
    'song_offline' => [
        // key: 比值字串（玩家:機台）；value: 對應的機台積分、比值、玩家最低單次上分金額
        // min_player_amount = machine_score × odds_x / odds_y
        'open_options' => [
             '3:1'   => ['machine_score' =>  100, 'odds_x' =>  3, 'odds_y' =>  1, 'min_player_amount' =>  300],
             '5:2'   => ['machine_score' =>   40, 'odds_x' =>  5, 'odds_y' =>  2, 'min_player_amount' =>  100],
            '10:1'   => ['machine_score' =>   10, 'odds_x' => 10, 'odds_y' =>  1, 'min_player_amount' =>  100],
             '5:1'   => ['machine_score' =>   20, 'odds_x' =>  5, 'odds_y' =>  1, 'min_player_amount' =>  100],
             '4:1'   => ['machine_score' =>   25, 'odds_x' =>  4, 'odds_y' =>  1, 'min_player_amount' =>  100],
             '2:1'   => ['machine_score' =>   50, 'odds_x' =>  2, 'odds_y' =>  1, 'min_player_amount' =>  100],
             '1:1'   => ['machine_score' =>  100, 'odds_x' =>  1, 'odds_y' =>  1, 'min_player_amount' =>  100],
             '1:2'   => ['machine_score' =>  200, 'odds_x' =>  1, 'odds_y' =>  2, 'min_player_amount' =>  100],
             '1:3'   => ['machine_score' =>  300, 'odds_x' =>  1, 'odds_y' =>  3, 'min_player_amount' =>  100],
             '1:4'   => ['machine_score' =>  400, 'odds_x' =>  1, 'odds_y' =>  4, 'min_player_amount' =>  100],
             '1:5'   => ['machine_score' =>  500, 'odds_x' =>  1, 'odds_y' =>  5, 'min_player_amount' =>  100],
             '1:8'   => ['machine_score' =>  800, 'odds_x' =>  1, 'odds_y' =>  8, 'min_player_amount' =>  100],
            '1:10'   => ['machine_score' => 1000, 'odds_x' =>  1, 'odds_y' => 10, 'min_player_amount' =>  100],
        ],
    ],
];
