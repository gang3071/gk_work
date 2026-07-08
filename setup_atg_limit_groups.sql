-- ATG三运营商限红组配置SQL
-- 用途：为ATG平台创建3个限红组，每个对应一个运营商账号

-- ========================================
-- 1. 插入3个限红组定义
-- ========================================

-- 限红组1：ATG运营商1（现有）
INSERT INTO platform_limit_group (code, name, department_id, status, created_at, updated_at)
VALUES ('ATG_OP1', 'ATG运营商1', 34, 1, NOW(), NOW());

-- 限红组2：ATG运营商2
INSERT INTO platform_limit_group (code, name, department_id, status, created_at, updated_at)
VALUES ('ATG_OP2', 'ATG运营商2', 34, 1, NOW(), NOW());

-- 限红组3：ATG运营商3
INSERT INTO platform_limit_group (code, name, department_id, status, created_at, updated_at)
VALUES ('ATG_OP3', 'ATG运营商3', 34, 1, NOW(), NOW());

-- ========================================
-- 2. 获取刚插入的限红组ID和ATG平台ID
-- ========================================

SET @atg_platform_id = (SELECT id FROM game_platforms WHERE code = 'ATG' LIMIT 1);
SET @limit_group_op1_id = (SELECT id FROM platform_limit_group WHERE code = 'ATG_OP1' LIMIT 1);
SET @limit_group_op2_id = (SELECT id FROM platform_limit_group WHERE code = 'ATG_OP2' LIMIT 1);
SET @limit_group_op3_id = (SELECT id FROM platform_limit_group WHERE code = 'ATG_OP3' LIMIT 1);

-- ========================================
-- 3. 为每个限红组创建ATG平台配置
-- ========================================

-- 运营商1配置（现有jinzun账号）
INSERT INTO platform_limit_group_config (
    limit_group_id,
    platform_id,
    config_data,
    status,
    created_at,
    updated_at
)
VALUES (
    @limit_group_op1_id,
    @atg_platform_id,
    JSON_OBJECT(
        'operator', 'jinzun',
        'key', '59eceb441f2b41f18035b7065e59920b',
        'providerId', '4'
    ),
    1,
    NOW(),
    NOW()
);

-- 运营商2配置（待填写真实凭据）
INSERT INTO platform_limit_group_config (
    limit_group_id,
    platform_id,
    config_data,
    status,
    created_at,
    updated_at
)
VALUES (
    @limit_group_op2_id,
    @atg_platform_id,
    JSON_OBJECT(
        'operator', 'operator2_name',           -- 待替换
        'key', 'your_operator2_key_here',       -- 待替换
        'providerId', '5'                        -- 待替换
    ),
    1,
    NOW(),
    NOW()
);

-- 运营商3配置（待填写真实凭据）
INSERT INTO platform_limit_group_config (
    limit_group_id,
    platform_id,
    config_data,
    status,
    created_at,
    updated_at
)
VALUES (
    @limit_group_op3_id,
    @atg_platform_id,
    JSON_OBJECT(
        'operator', 'operator3_name',           -- 待替换
        'key', 'your_operator3_key_here',       -- 待替换
        'providerId', '6'                        -- 待替换
    ),
    1,
    NOW(),
    NOW()
);

-- ========================================
-- 4. 验证插入结果
-- ========================================

SELECT '========== 限红组列表 ==========' as info;
SELECT
    id,
    code,
    name,
    department_id,
    status,
    created_at
FROM platform_limit_group
WHERE code IN ('ATG_OP1', 'ATG_OP2', 'ATG_OP3')
ORDER BY code;

SELECT '========== 限红组配置 ==========' as info;
SELECT
    plgc.id,
    plg.code as limit_group_code,
    plg.name as limit_group_name,
    gp.code as platform_code,
    plgc.config_data,
    plgc.status
FROM platform_limit_group_config plgc
JOIN platform_limit_group plg ON plgc.limit_group_id = plg.id
JOIN game_platforms gp ON plgc.platform_id = gp.id
WHERE plg.code IN ('ATG_OP1', 'ATG_OP2', 'ATG_OP3')
ORDER BY plg.code;

-- ========================================
-- 使用说明
-- ========================================

/*
1. 修改运营商2和运营商3的配置：
   - operator: ATG运营商账号名称
   - key: API密钥
   - providerId: 供应商ID

2. 执行SQL创建限红组

3. 在后台管理系统中：
   - 进入"限红组管理"
   - 为不同店家分配对应的限红组
   - 或设置平台默认限红组

4. 玩家进入ATG游戏时：
   - 系统自动根据店家的限红组选择运营商
   - 在对应运营商账号下注册/游戏
   - PlayerGamePlatform表的operator字段记录玩家所属运营商
*/
