<?php

return [
    // Common messages
    'success' => '成功',
    'system_error' => 'システムエラー',

    // Error messages
    'player_info_failed' => 'プレイヤー情報の取得に失敗しました。X-Player-Id headerを確認してください',
    'game_platform_id_required' => 'ゲームプラットフォームIDは必須です',
    'game_platform_not_found' => 'ゲームプラットフォームが見つかりません',
    'game_platform_disabled' => 'ゲームプラットフォームは無効です',
    'game_id_required' => 'ゲームIDは必須です',
    'game_not_found' => 'ゲームが見つかりません',
    'game_disabled' => 'ゲームは無効です',
    'game_record_id_required' => 'ゲーム記録IDは必須です',
    'game_record_not_found' => 'ゲーム記録が見つかりません',
    'replay_not_supported' => 'このゲームプラットフォームはリプレイ機能をサポートしていません',

    // Success messages
    'game_list_updated' => 'ゲームリスト更新済み',

    // ATG2/ATG3 platform specific errors
    'atg2_platform_not_configured' => 'ATG2プラットフォームが設定されていません',
    'atg2_platform_config_missing' => 'ATG2プラットフォーム設定ファイルが見つかりません',
    'atg3_platform_not_configured' => 'ATG3プラットフォームが設定されていません',
    'atg3_platform_config_missing' => 'ATG3プラットフォーム設定ファイルが見つかりません',
    'platform_not_configured' => 'ゲームプラットフォームが設定されていません',
    'platform_config_incomplete' => 'ゲームプラットフォーム設定が不完全です: :fieldsが不足しています',
];
