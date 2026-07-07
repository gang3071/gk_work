-- 修复历史未结算订单
-- ⚠️ 注意：此脚本会批量更新数据库，执行前请先备份！

-- 1. 首先检查有多少订单需要修复
SELECT
    '需要修复的订单统计' as info,
    COUNT(*) as total_count,
    MIN(created_at) as oldest,
    MAX(created_at) as newest
FROM play_game_records
WHERE settlement_status = 1  -- Redis中已标记为已结算
  AND status = 0  -- 但数据库中仍是未分佣
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);

-- 2. 按平台分组统计
SELECT
    p.code as platform_code,
    p.name as platform_name,
    COUNT(*) as need_fix_count
FROM play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
WHERE gr.settlement_status = 1
  AND gr.status = 0
  AND gr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY gr.platform_id, p.code, p.name
ORDER BY need_fix_count DESC;

-- 3. 显示示例（不执行更新）
SELECT
    '以下是需要修复的示例订单（前20条）' as info;

SELECT
    p.code as platform,
    gr.order_no,
    gr.bet,
    gr.win,
    gr.settlement_status,
    gr.status,
    gr.created_at
FROM play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
WHERE gr.settlement_status = 1
  AND gr.status = 0
  AND gr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY gr.created_at DESC
LIMIT 20;

-- 4. ⚠️⚠️⚠️ 执行修复（取消下面的注释来执行）⚠️⚠️⚠️
-- 将已结算但status=0的记录更新为status=1

/*
START TRANSACTION;

UPDATE play_game_records
SET status = 1,  -- 更新为已分佣
    updated_at = NOW()
WHERE settlement_status = 1  -- 已结算
  AND status = 0  -- 但仍是未分佣
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);  -- 最近7天的记录

-- 显示更新了多少条
SELECT ROW_COUNT() as updated_count;

-- 如果确认无误，执行 COMMIT; 提交事务
-- 如果有问题，执行 ROLLBACK; 回滚
COMMIT;
*/

-- 5. 验证修复结果
SELECT
    '修复后验证：还有多少未分佣的已结算订单' as info,
    COUNT(*) as remaining_count
FROM play_game_records
WHERE settlement_status = 1
  AND status = 0
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);
