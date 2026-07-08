<?php

return [
    // 通用消息
    'success' => '成功',
    'system_error' => '系統錯誤',

    // 錯誤消息
    'player_info_failed' => '玩家信息獲取失敗，請檢查 X-Player-Id header',
    'game_platform_id_required' => '遊戲平台ID不能為空',
    'game_platform_not_found' => '遊戲平台不存在',
    'game_platform_disabled' => '遊戲平台已禁用',
    'game_id_required' => '遊戲ID不能為空',
    'game_not_found' => '遊戲不存在',
    'game_disabled' => '遊戲已禁用',
    'game_record_id_required' => '遊戲記錄ID不能為空',
    'game_record_not_found' => '遊戲記錄不存在',
    'replay_not_supported' => '該遊戲平台不支持回放功能',

    // 成功消息
    'game_list_updated' => '遊戲列表已更新',

    // ATG2/ATG3 平台特定錯誤
    'atg2_platform_not_configured' => 'ATG2 平台未在數據庫中配置，請先在遊戲平台管理中添加',
    'atg2_platform_config_missing' => 'ATG2 平台配置缺失，請檢查 .env 文件中的 ATG2_* 配置項',
    'atg3_platform_not_configured' => 'ATG3 平台未在數據庫中配置，請先在遊戲平台管理中添加',
    'atg3_platform_config_missing' => 'ATG3 平台配置缺失，請檢查 .env 文件中的 ATG3_* 配置項',
    'platform_not_configured' => '遊戲平台限紅組未配置，已自動使用配置文件',
    'platform_config_incomplete' => '遊戲平台配置不完整，缺少必需字段: :fields',
];
