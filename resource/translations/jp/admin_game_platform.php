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
    'atg2_platform_not_configured' => 'ATG2プラットフォームがデータベースに設定されていません。ゲームプラットフォーム管理で追加してください',
    'atg2_platform_config_missing' => 'ATG2プラットフォーム設定が見つかりません。.envファイルのATG2_*設定を確認してください',
    'atg3_platform_not_configured' => 'ATG3プラットフォームがデータベースに設定されていません。ゲームプラットフォーム管理で追加してください',
    'atg3_platform_config_missing' => 'ATG3プラットフォーム設定が見つかりません。.envファイルのATG3_*設定を確認してください',
    'platform_not_configured' => 'ゲームプラットフォームの制限グループが設定されていません。設定ファイルを使用します',
    'platform_config_incomplete' => 'ゲームプラットフォーム設定が不完全です。必須フィールドが不足しています: :fields',
];
