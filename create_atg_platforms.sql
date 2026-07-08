-- 创建ATG_1和ATG_2独立平台
-- 每个平台有独立的回调地址和限红组配置

-- ========================================
-- 1. 获取ATG原始平台信息（用于复制配置）
-- ========================================

SELECT '========== 原始ATG平台配置 ==========' as info;
SELECT
    id,
    code,
    name,
    cate_id,
    logo,
    ratio,
    display_mode,
    has_lobby,
    sort,
    status
FROM game_platforms
WHERE code = 'ATG';

-- ========================================
-- 2. 插入ATG_1平台（运营商组2）
-- ========================================

INSERT INTO game_platforms (
    code,
    name,
    config,
    ratio,
    logo,
    cate_id,
    display_mode,
    has_lobby,
    status,
    maintenance_week,
    maintenance_start_time,
    maintenance_end_time,
    maintenance_status,
    sort,
    created_at,
    updated_at,
    picture,
    default_limit_group_id
)
SELECT
    'ATG_1' as code,
    'ATG运营商2' as name,
    config,
    ratio,
    logo,
    cate_id,
    display_mode,
    has_lobby,
    1 as status,  -- 启用
    maintenance_week,
    maintenance_start_time,
    maintenance_end_time,
    maintenance_status,
    sort + 1 as sort,
    NOW() as created_at,
    NOW() as updated_at,
    picture,
    NULL as default_limit_group_id  -- 后续配置
FROM game_platforms
WHERE code = 'ATG'
LIMIT 1;

-- ========================================
-- 3. 插入ATG_2平台（运营商组3）
-- ========================================

INSERT INTO game_platforms (
    code,
    name,
    config,
    ratio,
    logo,
    cate_id,
    display_mode,
    has_lobby,
    status,
    maintenance_week,
    maintenance_start_time,
    maintenance_end_time,
    maintenance_status,
    sort,
    created_at,
    updated_at,
    picture,
    default_limit_group_id
)
SELECT
    'ATG_2' as code,
    'ATG运营商3' as name,
    config,
    ratio,
    logo,
    cate_id,
    display_mode,
    has_lobby,
    1 as status,  -- 启用
    maintenance_week,
    maintenance_start_time,
    maintenance_end_time,
    maintenance_status,
    sort + 2 as sort,
    NOW() as created_at,
    NOW() as updated_at,
    picture,
    NULL as default_limit_group_id  -- 后续配置
FROM game_platforms
WHERE code = 'ATG'
LIMIT 1;

-- ========================================
-- 4. 验证插入结果
-- ========================================

SELECT '========== 新创建的ATG平台 ==========' as info;
SELECT
    id,
    code,
    name,
    cate_id,
    status,
    sort,
    created_at
FROM game_platforms
WHERE code IN ('ATG', 'ATG_1', 'ATG_2')
ORDER BY code;

-- ========================================
-- 5. 保存平台ID供后续使用
-- ========================================

SELECT
    CONCAT('ATG平台ID: ', id) as atg_info
FROM game_platforms
WHERE code = 'ATG';

SELECT
    CONCAT('ATG_1平台ID: ', id) as atg1_info
FROM game_platforms
WHERE code = 'ATG_1';

SELECT
    CONCAT('ATG_2平台ID: ', id) as atg2_info
FROM game_platforms
WHERE code = 'ATG_2';

-- ========================================
-- 使用说明
-- ========================================

/*
执行此SQL后：
1. 记录3个平台的ID
2. 在后台管理中为每个平台配置限红组
3. 在运营商后台配置对应的回调地址：
   - 运营商1 → /wallet/game/atg-channel/*
   - 运营商2 → /wallet/game/atg1-channel/*
   - 运营商3 → /wallet/game/atg2-channel/*

下一步：
1. 修改 .env 添加 ATG_1 和 ATG_2 配置
2. 修改 config/game_platform.php
3. 创建 Service 和 Controller 类
4. 添加路由
*/
