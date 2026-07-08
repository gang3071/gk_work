<?php

return [
    // Common messages
    'success' => 'Success',
    'system_error' => 'System error',

    // Error messages
    'player_info_failed' => 'Failed to get player info, please check X-Player-Id header',
    'game_platform_id_required' => 'Game platform ID is required',
    'game_platform_not_found' => 'Game platform not found',
    'game_platform_disabled' => 'Game platform is disabled',
    'game_id_required' => 'Game ID is required',
    'game_not_found' => 'Game not found',
    'game_disabled' => 'Game is disabled',
    'game_record_id_required' => 'Game record ID is required',
    'game_record_not_found' => 'Game record not found',
    'replay_not_supported' => 'This game platform does not support replay',

    // Success messages
    'game_list_updated' => 'Game list updated',

    // ATG2/ATG3 platform specific errors
    'atg2_platform_not_configured' => 'ATG2 platform not configured',
    'atg2_platform_config_missing' => 'ATG2 platform configuration file missing',
    'atg3_platform_not_configured' => 'ATG3 platform not configured',
    'atg3_platform_config_missing' => 'ATG3 platform configuration file missing',
    'platform_not_configured' => 'Game platform not configured',
    'platform_config_incomplete' => 'Game platform configuration incomplete: missing :fields',
];
