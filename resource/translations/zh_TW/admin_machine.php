<?php

return [
    // 通用消息
    'success' => '成功',
    'fail' => '失敗',

    // 錯誤消息
    'machine_id_required' => '機台ID不能為空',
    'cmd_required' => '指令不能為空',
    'machine_not_found' => '機台不存在',
    'field_required' => '字段名不能為空',
    'machine_ids_required' => '機台ID列表不能為空',
    'commands_required' => '指令列表不能為空',
    'data_must_be_numeric' => '數據參數必須為數字',

    // 成功消息
    'cmd_sent_success' => '指令發送成功',
    'state_updated_success' => '狀態更新成功',
    'batch_cmd_completed' => '批量指令執行完成',

    // 2026-07-24 新增
    'operation_failed_retry' => '操作失敗，請稍後重試',
    'invalid_machine_id' => '無效的機台ID',
    'field_update_not_allowed' => '字段 {field} 不允許更新',
    'invalid_player_id' => '無效的玩家ID',
    'invalid_operation_type_leave_down' => '無效的操作類型，必須是 leave 或 down',
    'player_not_found' => '玩家不存在',
    'machine_wash_undefined' => 'machineWash 函數未定義，請聯繫技術支持',
    'kick_player_success' => '踢出玩家成功',
    'reset_machine_trans_undefined' => 'resetMachineTrans 函數未定義，請聯繫技術支持',
    'force_kick_player_success' => '強制踢出玩家成功',
    'open_score_must_positive' => '開分數值必須大於0',
    'open_score_too_large' => '開分數值過大，單次最多10萬分',
    'machine_open_any_free_undefined' => 'machineOpenAnyFree 函數未定義，請聯繫技術支持',
    'custom_open_score_success' => '自定義開分成功',
    'cmd_cannot_empty' => '指令代碼不能為空',
    'cannot_create_machine_service' => '無法創建機台服務',
    'get_description_not_supported' => '機台服務不支持 getDescription 方法',
];
