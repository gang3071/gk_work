-- 检查所有平台的结算状态统计
-- 找出有大量未结算订单的平台

SELECT
    p.code as platform_code,
    p.name as platform_name,
    COUNT(*) as total_records,
    SUM(CASE WHEN gr.settlement_status = 0 THEN 1 ELSE 0 END) as unsettled_count,
    SUM(CASE WHEN gr.settlement_status = 1 THEN 1 ELSE 0 END) as settled_count,
    ROUND(SUM(CASE WHEN gr.settlement_status = 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as unsettled_percent,
    -- 平均结算时间（秒）
    ROUND(AVG(CASE
        WHEN gr.settlement_status = 1
        THEN TIMESTAMPDIFF(SECOND, gr.created_at, gr.updated_at)
        ELSE NULL
    END), 2) as avg_settle_seconds,
    -- 未结算订单的平均年龄（秒）
    ROUND(AVG(CASE
        WHEN gr.settlement_status = 0
        THEN TIMESTAMPDIFF(SECOND, gr.created_at, NOW())
        ELSE NULL
    END), 2) as avg_unsettled_age_seconds,
    -- 最老的未结算订单年龄（秒）
    MAX(CASE
        WHEN gr.settlement_status = 0
        THEN TIMESTAMPDIFF(SECOND, gr.created_at, NOW())
        ELSE NULL
    END) as oldest_unsettled_seconds
FROM play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
WHERE gr.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY gr.platform_id, p.code, p.name
HAVING unsettled_count > 0  -- 只显示有未结算订单的平台
ORDER BY unsettled_count DESC, unsettled_percent DESC;

-- 分隔线
SELECT '========== 快速结算平台候选（平均结算时间<10秒且未结算率>5%）==========' as separator;

-- 找出需要加入快速结算列表的平台
SELECT
    p.code as platform_code,
    p.name as platform_name,
    COUNT(*) as total_records,
    SUM(CASE WHEN gr.settlement_status = 0 THEN 1 ELSE 0 END) as unsettled_count,
    ROUND(SUM(CASE WHEN gr.settlement_status = 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as unsettled_percent,
    ROUND(AVG(CASE
        WHEN gr.settlement_status = 1
        THEN TIMESTAMPDIFF(SECOND, gr.created_at, gr.updated_at)
        ELSE NULL
    END), 2) as avg_settle_seconds,
    '需要加入快速结算列表' as recommendation
FROM play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
WHERE gr.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY gr.platform_id, p.code, p.name
HAVING
    avg_settle_seconds < 10  -- 平均结算时间小于10秒
    AND unsettled_percent > 5  -- 未结算率超过5%
    AND unsettled_count > 10  -- 至少有10条未结算记录
ORDER BY unsettled_count DESC;

-- 分隔线
SELECT '========== 各平台详细未结算订单样本 ==========' as separator;

-- 每个有未结算订单的平台显示3条样本
SELECT * FROM (
    SELECT
        p.code as platform_code,
        gr.order_no,
        gr.bet,
        gr.game_code,
        gr.created_at,
        TIMESTAMPDIFF(SECOND, gr.created_at, NOW()) as age_seconds,
        ROW_NUMBER() OVER (PARTITION BY p.code ORDER BY gr.created_at DESC) as rn
    FROM play_game_records gr
    JOIN game_platforms p ON gr.platform_id = p.id
    WHERE gr.settlement_status = 0
      AND gr.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
) as ranked
WHERE rn <= 3
ORDER BY platform_code, rn;
