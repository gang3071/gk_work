<?php
/**
 * 福利卷/体验卷配置文件
 */

return [
    // 钱包锁定时，出票所需最低分数
    'issue_threshold' => 5000,

    // 使用福利卷/体验卷开分时，钱包余额上限（低于此值才能开分）
    'open_score_limit' => 100,

    // 福利卷/体验卷出票后有效小时数
    'expire_hours' => 24,

    // 活动结束时间（Y-m-d H:i:s），空字符串表示不限制
    'activity_end_time' => '',
];
