-- ====================================================================
-- 处理未结算记录脚本
-- ⚠️ 执行前请先运行分析部分，确认数据无误后再执行修复
-- ====================================================================

-- ====================================================================
-- 第一部分：分析未结算记录
-- ====================================================================

-- 1. 统计各平台的未结算记录（按时间段分组）
SELECT
    '========== 未结算记录统计（按平台和时间段）==========' as info;

SELECT
    p.code as platform_code,
    p.name as platform_name,
    CASE
        WHEN TIMESTAMPDIFF(MINUTE, gr.created_at, NOW()) < 5 THEN '< 5分钟'
        WHEN TIMESTAMPDIFF(MINUTE, gr.created_at, NOW()) < 60 THEN '5-60分钟'
        WHEN TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) < 24 THEN '1-24小时'
        ELSE '> 24小时'
    END as age_range,
    COUNT(*) as count,
    MIN(gr.created_at) as oldest,
    MAX(gr.created_at) as newest
FROM play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
WHERE gr.settlement_status = 0
GROUP BY p.code, p.name, age_range
ORDER BY p.code,
    FIELD(age_range, '< 5分钟', '5-60分钟', '1-24小时', '> 24小时');

-- 2. 快速结算平台的异常未结算记录（超过5分钟）
SELECT
    '========== 快速结算平台异常未结算记录（>5分钟）==========' as info;

SELECT
    p.code as platform,
    gr.order_no,
    gr.player_id,
    gr.bet,
    gr.game_code,
    gr.created_at,
    TIMESTAMPDIFF(MINUTE, gr.created_at, NOW()) as age_minutes
FROM play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
WHERE gr.settlement_status = 0
  AND TIMESTAMPDIFF(MINUTE, gr.created_at, NOW()) > 5
  AND p.code IN ('RSG', 'RSGLIVE', 'MT', 'ATG', 'O8', 'STM', 'HS', 'BTG', 'SP', 'KT', 'DG', 'SA', 'TNINE', 'TNINE_SLOT', 'QT')
ORDER BY age_minutes DESC
LIMIT 50;

-- 3. 检查是否有大量相同玩家的未结算记录（可能是卡单）
SELECT
    '========== 可能的卡单玩家（同一玩家有多条未结算）==========' as info;

SELECT
    gr.player_id,
    p.code as platform,
    COUNT(*) as unsettled_count,
    SUM(gr.bet) as total_bet,
    MIN(gr.created_at) as first_unsettled,
    MAX(gr.created_at) as last_unsettled
FROM play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
WHERE gr.settlement_status = 0
  AND TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) < 24
GROUP BY gr.player_id, p.code
HAVING unsettled_count > 5
ORDER BY unsettled_count DESC
LIMIT 20;

-- ====================================================================
-- 第二部分：修复方案（根据情况选择执行）
-- ====================================================================

-- 方案1：标记长时间未结算的记录为已取消（适用于异常订单）
-- ⚠️ 只处理超过24小时且金额为0的异常记录
-- 取消下面的注释来执行

/*
START TRANSACTION;

UPDATE play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
SET
    gr.settlement_status = 2,  -- 2 = 已取消
    gr.updated_at = NOW()
WHERE gr.settlement_status = 0
  AND gr.bet = 0  -- 只处理下注金额为0的异常记录
  AND TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) > 24  -- 超过24小时
  AND p.code IN ('RSG', 'RSGLIVE', 'MT', 'ATG', 'O8', 'STM', 'HS', 'BTG', 'SP', 'KT', 'DG', 'SA', 'TNINE', 'TNINE_SLOT', 'QT');

SELECT ROW_COUNT() as cancelled_count;

-- 确认无误后执行 COMMIT; 否则执行 ROLLBACK;
-- COMMIT;
*/

-- 方案2：对于快速结算平台超过1小时的记录，标记为结算失败并设置为0输赢
-- ⚠️ 这会将记录标记为已结算，win=0（输了）
-- 适用于确认平台侧已结算但数据库未同步的情况

/*
START TRANSACTION;

UPDATE play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
SET
    gr.settlement_status = 1,  -- 已结算
    gr.win = 0,                -- 输了（保守处理）
    gr.diff = -gr.bet,         -- diff = win - bet
    gr.updated_at = NOW(),
    gr.platform_action_at = NOW()
WHERE gr.settlement_status = 0
  AND TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) > 1  -- 超过1小时
  AND TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) < 24  -- 但不超过24小时
  AND p.code IN ('RSG', 'RSGLIVE', 'MT', 'ATG', 'O8', 'STM', 'HS', 'BTG', 'SP', 'KT', 'DG', 'SA', 'TNINE', 'TNINE_SLOT', 'QT');

SELECT ROW_COUNT() as settled_count;

-- 确认无误后执行 COMMIT; 否则执行 ROLLBACK;
-- COMMIT;
*/

-- 方案3：只删除测试数据（下注金额很小的记录）
-- ⚠️ 谨慎使用，只用于清理明显的测试数据

/*
START TRANSACTION;

DELETE gr FROM play_game_records gr
WHERE gr.settlement_status = 0
  AND gr.bet <= 0.01  -- 只删除下注金额≤0.01的记录
  AND TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) > 24;

SELECT ROW_COUNT() as deleted_count;

-- 确认无误后执行 COMMIT; 否则执行 ROLLBACK;
-- COMMIT;
*/

-- ====================================================================
-- 第三部分：验证修复结果
-- ====================================================================

SELECT
    '========== 修复后验证：剩余未结算记录统计 ==========' as info;

SELECT
    p.code as platform,
    COUNT(*) as remaining_unsettled,
    MIN(gr.created_at) as oldest,
    MAX(gr.created_at) as newest
FROM play_game_records gr
JOIN game_platforms p ON gr.platform_id = p.id
WHERE gr.settlement_status = 0
GROUP BY p.code
ORDER BY remaining_unsettled DESC;

-- ====================================================================
-- 使用说明
-- ====================================================================
/*
执行步骤：

1. 先运行【第一部分】分析脚本，了解未结算记录的情况：
   mysql -h10.59.177.7 -ujin -p < process_unsettled_records.sql

2. 根据分析结果，选择合适的修复方案：
   - 方案1：处理异常的0金额记录
   - 方案2：快速结算平台超时记录强制结算为输
   - 方案3：删除测试数据

3. 取消对应方案的注释，再次运行脚本

4. 检查 ROW_COUNT() 输出，确认影响行数合理

5. 如果合理，执行 COMMIT; 提交事务
   如果有问题，执行 ROLLBACK; 回滚事务

6. 运行【第三部分】验证修复结果

注意事项：
- 不要在生产环境高峰期执行
- 建议先在测试环境验证
- 每次只执行一个方案
- 务必先分析再修复
- 保留事务控制，随时可以回滚
*/
