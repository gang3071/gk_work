-- ============================================================
-- MT平台玩家数据清理脚本
-- ============================================================
-- 用途：清除所有MT平台玩家绑定，强制重新注册
-- 原因：MT平台user_id从"uuid"改为"system_code_uuid"格式
-- 影响：所有玩家下次进入MT游戏时会自动重新注册
-- ============================================================

-- 1. 查看当前MT平台玩家数量
SELECT
    COUNT(*) as total_players,
    COUNT(DISTINCT player_id) as unique_players
FROM player_game_platform
WHERE platform_id = (SELECT id FROM game_platform WHERE code = 'MT');

-- 2. 查看具体玩家信息（确认影响范围）
SELECT
    pgp.id,
    pgp.player_id,
    pgp.player_name,
    pgp.player_code as old_user_id,
    p.uuid as player_uuid,
    CONCAT('yjbmt_', p.uuid) as new_user_id  -- 新格式预览
FROM player_game_platform pgp
JOIN gaming_user p ON p.id = pgp.player_id
WHERE pgp.platform_id = (SELECT id FROM game_platform WHERE code = 'MT')
ORDER BY pgp.player_id
LIMIT 20;

-- 3. 【慎重操作】删除所有MT平台玩家绑定
-- ⚠️ 执行前请备份数据库！
-- ⚠️ 执行后玩家会自动重新注册，MT余额会保留（MT平台端存储）
/*
DELETE FROM player_game_platform
WHERE platform_id = (SELECT id FROM game_platform WHERE code = 'MT');
*/

-- 4. 验证删除结果
SELECT COUNT(*) as remaining_mt_players
FROM player_game_platform
WHERE platform_id = (SELECT id FROM game_platform WHERE code = 'MT');

-- ============================================================
-- 使用说明
-- ============================================================
-- 步骤1: 执行查询1和2，确认影响范围
-- 步骤2: 备份数据库
--   mysqldump -u root -p gaming_db player_game_platform > backup_pgp.sql
-- 步骤3: 取消步骤3的注释，执行删除
-- 步骤4: 执行查询4，验证删除成功（应返回0）
-- 步骤5: 部署代码到生产环境
-- 步骤6: 让玩家重新进入MT游戏（会自动重新注册）
-- ============================================================

-- ============================================================
-- 可选：只删除特定玩家（如遇到冲突的玩家）
-- ============================================================
/*
DELETE FROM player_game_platform
WHERE platform_id = (SELECT id FROM game_platform WHERE code = 'MT')
AND player_id IN (29, 100, 200);  -- 替换为实际冲突的玩家ID
*/

-- ============================================================
-- MT平台余额说明
-- ============================================================
-- MT平台余额存储在MT服务器端，不在我们的数据库
-- 删除player_game_platform记录不会影响MT平台余额
-- 重新注册时MT会识别为新玩家，余额为0
-- 如果需要保留余额，需要：
--   1. 先让玩家下分（Withdraw）到主钱包
--   2. 删除绑定记录
--   3. 重新注册后再上分（Deposit）
-- ============================================================
