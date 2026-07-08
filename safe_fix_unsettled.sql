-- ====================================================================
-- 安全修复未结算记录 - 保守方案
-- 只处理明确可以修复的异常记录
-- ====================================================================

-- 第一步：查看需要修复的记录
SELECT
    '========== 将要修复的记录预览 ==========' as info;

-- 查找超过2小时未结算的快速结算平台记录
SELECT
    p.code as platform,
    gr.order_no,
    gr.player_id,
    gr.bet,
    gr.win,
    gr.settlement_status,
    gr.created_at,
    TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) as hours_old,
    '将标记为已结算(win=0)' as action
FROM play_game_record gr
JOIN game_platform p ON gr.platform_id = p.id
WHERE gr.settlement_status = 0
  AND TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) >= 2  -- 超过2小时
  AND TIMESTAMPDIFF(DAY, gr.created_at, NOW()) < 7   -- 但不超过7天
  AND p.code IN ('RSG', 'RSGLIVE', 'MT', 'ATG', 'O8', 'STM', 'HS', 'BTG', 'SP', 'KT', 'DG', 'SA', 'TNINE', 'TNINE_SLOT', 'QT')
ORDER BY gr.created_at
LIMIT 100;

-- 第二步：统计即将修复的记录数
SELECT
    '========== 修复统计 ==========' as info;

SELECT
    p.code as platform,
    COUNT(*) as will_fix_count,
    SUM(gr.bet) as total_bet_amount,
    MIN(gr.created_at) as oldest_record,
    MAX(gr.created_at) as newest_record
FROM play_game_record gr
JOIN game_platform p ON gr.platform_id = p.id
WHERE gr.settlement_status = 0
  AND TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) >= 2
  AND TIMESTAMPDIFF(DAY, gr.created_at, NOW()) < 7
  AND p.code IN ('RSG', 'RSGLIVE', 'MT', 'ATG', 'O8', 'STM', 'HS', 'BTG', 'SP', 'KT', 'DG', 'SA', 'TNINE', 'TNINE_SLOT', 'QT')
GROUP BY p.code
ORDER BY will_fix_count DESC;

-- 第三步：执行修复（取消下面的注释来执行）
-- ⚠️ 确认上面的预览数据无误后，再取消注释执行

/*
START TRANSACTION;

-- 修复快速结算平台超过2小时的未结算记录
UPDATE play_game_record gr
JOIN game_platform p ON gr.platform_id = p.id
SET
    gr.settlement_status = 1,           -- 标记为已结算
    gr.win = 0,                         -- 保守处理：设为输（win=0）
    gr.diff = -gr.bet,                  -- diff = win - bet = 0 - bet
    gr.updated_at = NOW(),
    gr.platform_action_at = NOW()
WHERE gr.settlement_status = 0
  AND TIMESTAMPDIFF(HOUR, gr.created_at, NOW()) >= 2
  AND TIMESTAMPDIFF(DAY, gr.created_at, NOW()) < 7
  AND p.code IN ('RSG', 'RSGLIVE', 'MT', 'ATG', 'O8', 'STM', 'HS', 'BTG', 'SP', 'KT', 'DG', 'SA', 'TNINE', 'TNINE_SLOT', 'QT');

-- 显示修复了多少条
SELECT ROW_COUNT() as fixed_count;

-- ⚠️ 检查 fixed_count，如果合理则执行 COMMIT，否则执行 ROLLBACK
-- 取消下面其中一个的注释：

-- COMMIT;     -- 确认修复，提交事务
-- ROLLBACK;   -- 取消修复，回滚事务
*/

-- 第四步：验证修复结果
SELECT
    '========== 修复后验证 ==========' as info;

-- 剩余未结算记录
SELECT
    p.code as platform,
    COUNT(*) as remaining_unsettled,
    MAX(TIMESTAMPDIFF(HOUR, gr.created_at, NOW())) as max_hours_old
FROM play_game_record gr
JOIN game_platform p ON gr.platform_id = p.id
WHERE gr.settlement_status = 0
GROUP BY p.code
ORDER BY remaining_unsettled DESC;

-- ====================================================================
-- 使用说明
-- ====================================================================
/*
安全执行步骤：

1. 运行整个脚本查看预览：
   mysql -h10.59.177.7 -ujin -p'^%q0[%Y&2_-yedt>' -Djin < safe_fix_unsettled.sql

2. 查看第一步和第二步的输出：
   - 确认要修复的订单是否合理
   - 确认金额是否在可接受范围内
   - 确认时间范围是否正确

3. 如果确认无误，取消第三步的注释，重新运行脚本

4. 查看 fixed_count 输出，确认修复数量

5. 取消 COMMIT 或 ROLLBACK 的注释：
   - 如果数量合理：取消 COMMIT 注释，重新运行
   - 如果有问题：取消 ROLLBACK 注释，重新运行

6. 查看第四步的验证结果

修复策略说明：
- 只处理快速结算平台（2小时后应该早已结算）
- 保守设置 win=0（假设玩家输了）
- 不处理超过7天的记录（太老的数据需要人工审核）
- 使用事务保护，可以随时回滚
*/
