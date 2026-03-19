<?php

/**
 * 设备管理配置
 *
 * 安全建议：
 * 1. 修改 secret_key 为随机字符串（64位以上）
 * 2. 验证服务器部署在独立IP/端口
 * 3. 配置防火墙限制访问
 * 4. 启用HTTPS
 */

return [
    // ============================
    // 密钥配置（务必修改）
    // ============================

    /**
     * 系统密钥（用于生成设备密钥和JWT签名）
     *
     * 生成方法：php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
     *
     * ⚠️ 重要：生产环境必须修改此值！
     */
    'secret_key' => env('DEVICE_SECRET_KEY', 'change_this_to_random_string_1234567890abcdef'),

    // ============================
    // 验证服务器配置
    // ============================

    'auth_server' => [
        /**
         * 是否启用独立验证服务器
         */
        'enabled' => env('DEVICE_AUTH_ENABLED', true),

        /**
         * 验证服务器地址（建议使用内网IP）
         */
        'host' => env('DEVICE_AUTH_HOST', '10.0.0.100'),

        /**
         * 验证服务器端口（建议使用非标准端口）
         */
        'port' => env('DEVICE_AUTH_PORT', 9501),

        /**
         * 协议（生产环境必须使用 https）
         */
        'protocol' => env('DEVICE_AUTH_PROTOCOL', 'https'),

        /**
         * 完整URL（自动拼接）
         */
        'url' => function () {
            $config = config('device.auth_server');
            return sprintf(
                '%s://%s:%d/api/device/auth',
                $config['protocol'],
                $config['host'],
                $config['port']
            );
        },
    ],

    // ============================
    // 令牌配置
    // ============================

    'token' => [
        /**
         * 令牌有效期（秒）
         * 默认：1800秒（30分钟）
         */
        'ttl' => env('DEVICE_TOKEN_TTL', 1800),

        /**
         * JWT算法
         * 支持：HS256, HS384, HS512
         */
        'algorithm' => env('DEVICE_TOKEN_ALGORITHM', 'HS256'),

        /**
         * JWT颁发者标识
         */
        'issuer' => env('DEVICE_TOKEN_ISSUER', 'device-auth'),

        /**
         * 令牌自动续期阈值（秒）
         * 当令牌剩余时间少于此值时自动续期
         */
        'refresh_threshold' => env('DEVICE_TOKEN_REFRESH_THRESHOLD', 600), // 10分钟
    ],

    // ============================
    // 安全配置
    // ============================

    /**
     * 时间戳容忍度（秒）
     * 防止重放攻击，请求时间戳超出此范围将被拒绝
     */
    'timestamp_tolerance' => env('DEVICE_TIMESTAMP_TOLERANCE', 300), // 5分钟

    /**
     * 是否启用请求签名验证（额外安全层）
     *
     * 启用后，所有请求都需要携带 X-Timestamp 和 X-Signature 头
     * signature = HMAC-SHA256(device_no|timestamp|body, device_secret)
     */
    'enable_request_signature' => env('DEVICE_ENABLE_REQUEST_SIGNATURE', false),

    /**
     * 允许访问验证服务器的IP白名单（CIDR格式）
     *
     * 防止验证服务器被扫描和暴力破解
     * 空数组表示不限制
     */
    'allowed_ips' => [
        '10.0.0.0/8',       // 内网A类
        '172.16.0.0/12',    // 内网B类
        '192.168.0.0/16',   // 内网C类
        // '203.0.113.0/24', // 示例：特定公网IP段
    ],

    // ============================
    // 监控和限流配置
    // ============================

    /**
     * 认证失败限制
     *
     * 在指定时间窗口内，认证失败次数超过此值将触发IP封禁
     */
    'auth_fail_limit' => [
        'max_attempts' => env('DEVICE_AUTH_MAX_ATTEMPTS', 10),  // 最大失败次数
        'window' => env('DEVICE_AUTH_FAIL_WINDOW', 3600),       // 时间窗口（秒）
        'ban_duration' => env('DEVICE_AUTH_BAN_DURATION', 86400), // 封禁时长（秒）
    ],

    /**
     * 是否启用异常检测
     */
    'enable_anomaly_detection' => env('DEVICE_ENABLE_ANOMALY_DETECTION', true),

    // ============================
    // Redis配置
    // ============================

    /**
     * Redis键前缀
     */
    'redis_prefix' => env('DEVICE_REDIS_PREFIX', 'device:'),

    /**
     * Redis键名模板
     */
    'redis_keys' => [
        'token' => 'device_token:{device_no}:{ip}',
        'auth_fail' => 'auth_fail:{ip}',
        'banned_ip' => 'banned_ip:{ip}',
        'token_jti' => 'token_jti:{jti}',
    ],

    // ============================
    // 日志配置
    // ============================

    /**
     * 是否记录详细日志
     */
    'enable_verbose_logging' => env('DEVICE_ENABLE_VERBOSE_LOGGING', false),

    /**
     * 日志保留天数
     */
    'log_retention_days' => env('DEVICE_LOG_RETENTION_DAYS', 30),

    // ============================
    // 告警配置
    // ============================

    /**
     * 告警方式
     * 支持：log, email, dingtalk, wechat
     */
    'alert_channels' => explode(',', env('DEVICE_ALERT_CHANNELS', 'log')),

    /**
     * 钉钉机器人Webhook（可选）
     */
    'dingtalk_webhook' => env('DEVICE_DINGTALK_WEBHOOK', ''),

    /**
     * 企业微信机器人Webhook（可选）
     */
    'wechat_webhook' => env('DEVICE_WECHAT_WEBHOOK', ''),

    /**
     * 告警邮件地址（可选）
     */
    'alert_email' => env('DEVICE_ALERT_EMAIL', ''),
];
