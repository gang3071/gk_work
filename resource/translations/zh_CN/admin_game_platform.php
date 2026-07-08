<?php

return [
    // 通用消息
    'success' => '成功',
    'system_error' => '系统错误',

    // 错误消息
    'player_info_failed' => '玩家信息获取失败，请检查 X-Player-Id header',
    'game_platform_id_required' => '游戏平台ID不能为空',
    'game_platform_not_found' => '游戏平台不存在',
    'game_platform_disabled' => '游戏平台已禁用',
    'game_id_required' => '游戏ID不能为空',
    'game_not_found' => '游戏不存在',
    'game_disabled' => '游戏已禁用',
    'game_record_id_required' => '游戏记录ID不能为空',
    'game_record_not_found' => '游戏记录不存在',
    'replay_not_supported' => '该游戏平台不支持回放功能',

    // 成功消息
    'game_list_updated' => '游戏列表已更新',

    // ATG2/ATG3 平台特定错误
    'atg2_platform_not_configured' => 'ATG2 平台未在数据库中配置，请先在游戏平台管理中添加',
    'atg2_platform_config_missing' => 'ATG2 平台配置缺失，请检查 .env 文件中的 ATG2_* 配置项',
    'atg3_platform_not_configured' => 'ATG3 平台未在数据库中配置，请先在游戏平台管理中添加',
    'atg3_platform_config_missing' => 'ATG3 平台配置缺失，请检查 .env 文件中的 ATG3_* 配置项',
    'platform_not_configured' => '游戏平台限红组未配置，已自动使用配置文件',
    'platform_config_incomplete' => '游戏平台配置不完整，缺少必需字段: :fields',
];
