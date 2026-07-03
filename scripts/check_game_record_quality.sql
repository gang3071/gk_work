-- ============================================
-- 电子游戏记录数据质量检查脚本
-- 用途：检测时序竞争导致的数据异常
-- 使用：在MySQL客户端执行此脚本
-- ============================================

-- 1. 检查各平台的异常记录统计（最近24小时）
-- 异常定义：bet>0 但 win=0, diff=0, settlement_status=0
SELECT
    platform,
    COUNT(*) as total_records,
    SUM(CASE WHEN settlement_status = 0 THEN 1 ELSE 0 END) as unsettled_count,
    SUM(CASE WHEN bet > 0 AND win = 0 AND diff = 0 AND settlement_status = 0 AND updated_at > created_at THEN 1 ELSE 0 END) as suspicious_count,
    ROUND(SUM(CASE WHEN bet > 0 AND win = 0 AND diff = 0 AND settlement_status = 0 AND updated_at > created_at THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as suspicious_ratio
FROM play_game_record
WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
  AND bet > 0
GROUP BY platform
ORDER BY suspicious_count DESC;

-- 2. 列出具体的异常订单（最近1小时）
-- 帮助定位受影响的玩家和订单
SELECT
    order_no,
    platform,
    player_id,
    bet,
    win,
    diff,
    settlement_status,
    created_at,
    updated_at,
    TIMESTAMPDIFF(SECOND, created_at, updated_at) as update_delay_seconds
FROM play_game_record
WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
  AND bet > 0
  AND win = 0
  AND diff = 0
  AND settlement_status = 0
  AND updated_at > created_at  -- 有过更新但还是0
ORDER BY created_at DESC
LIMIT 50;

-- 3. 检查长时间未结算的订单（>1小时）
-- 可能是长周期游戏或异常订单
SELECT
    platform,
    COUNT(*) as count,
    MIN(created_at) as oldest_time,
    TIMESTAMPDIFF(MINUTE, MIN(created_at), NOW()) as oldest_age_minutes
FROM play_game_record
WHERE settlement_status = 0
  AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY platform
ORDER BY count DESC;

-- 4. 检查快速结算平台的未结算比例
-- 快速结算平台的未结算比例应该<1%
SELECT
    platform,
    COUNT(*) as total,
    SUM(CASE WHEN settlement_status = 0 THEN 1 ELSE 0 END) as unsettled,
    ROUND(SUM(CASE WHEN settlement_status = 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as unsettled_ratio
FROM play_game_record
WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
  AND platform IN ('RSG', 'RSGLIVE', 'MT', 'BTG', 'SP', 'SPSDY', 'KT', 'JDB', 'KY', 'DG')
GROUP BY platform
HAVING unsettled_ratio > 1  -- 超过1%视为异常
ORDER BY unsettled_ratio DESC;

-- 5. 检查balance_before/after的一致性
-- balance_after应该 = balance_before - bet + win
SELECT
    order_no,
    platform,
    bet,
    win,
    diff,
    balance_before,
    balance_after,
    ROUND(balance_before - bet + win, 2) as expected_balance_after,
    ROUND(balance_after - (balance_before - bet + win), 2) as balance_diff
FROM play_game_record
WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
  AND settlement_status = 1
  AND balance_before IS NOT NULL
  AND balance_after IS NOT NULL
  AND ABS(balance_after - (balance_before - bet + win)) > 0.01  -- 允许0.01的浮点误差
LIMIT 20;

-- 6. 检查单位转换异常（金额×100或÷100）
-- 检测win或diff明显不合理的记录
SELECT
    order_no,
    platform,
    bet,
    win,
    diff,
    CASE
        WHEN win > 0 AND win = bet * 100 THEN 'win可能×100'
        WHEN win > 0 AND bet = win * 100 THEN 'win可能÷100'
        WHEN ABS(diff) > 0 AND ABS(diff) = bet * 100 THEN 'diff可能×100'
        WHEN ABS(diff) > 0 AND bet = ABS(diff) * 100 THEN 'diff可能÷100'
        ELSE '其他异常'
    END as issue_type,
    created_at
FROM play_game_record
WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
  AND (
    (win > 0 AND (win = bet * 100 OR bet = win * 100))
    OR
    (ABS(diff) > 0 AND (ABS(diff) = bet * 100 OR bet = ABS(diff) * 100))
  )
ORDER BY created_at DESC
LIMIT 20;

-- 7. 汇总报告：今天的整体数据质量
SELECT
    '今日记录总数' as metric,
    COUNT(*) as value
FROM play_game_record
WHERE DATE(created_at) = CURDATE()
UNION ALL
SELECT
    '已结算记录数',
    COUNT(*)
FROM play_game_record
WHERE DATE(created_at) = CURDATE()
  AND settlement_status = 1
UNION ALL
SELECT
    '未结算记录数',
    COUNT(*)
FROM play_game_record
WHERE DATE(created_at) = CURDATE()
  AND settlement_status = 0
UNION ALL
SELECT
    '异常记录数（win=0且有更新）',
    COUNT(*)
FROM play_game_record
WHERE DATE(created_at) = CURDATE()
  AND bet > 0
  AND win = 0
  AND diff = 0
  AND settlement_status = 0
  AND updated_at > created_at;
