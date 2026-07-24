<?php

return [
    // 通用消息
    'success' => '成功',
    'fail' => '失败',

    // 错误消息
    'machine_id_required' => '机台ID不能为空',
    'cmd_required' => '指令不能为空',
    'machine_not_found' => '机台不存在',
    'field_required' => '字段名不能为空',
    'machine_ids_required' => '机台ID列表不能为空',
    'commands_required' => '指令列表不能为空',
    'data_must_be_numeric' => '数据参数必须为数字',

    // 成功消息
    'cmd_sent_success' => '指令发送成功',
    'state_updated_success' => '状态更新成功',
    'batch_cmd_completed' => '批量指令执行完成',

    // 2026-07-24 新增
    'operation_failed_retry' => '操作失败，请稍后重试',
    'invalid_machine_id' => '无效的机台ID',
    'field_update_not_allowed' => '字段 {field} 不允许更新',
    'invalid_player_id' => '无效的玩家ID',
    'invalid_operation_type_leave_down' => '无效的操作类型，必须是 leave 或 down',
    'player_not_found' => '玩家不存在',
    'machine_wash_undefined' => 'machineWash 函数未定义，请联系技术支持',
    'kick_player_success' => '踢出玩家成功',
    'reset_machine_trans_undefined' => 'resetMachineTrans 函数未定义，请联系技术支持',
    'force_kick_player_success' => '强制踢出玩家成功',
    'open_score_must_positive' => '开分数值必须大于0',
    'open_score_too_large' => '开分数值过大，单次最多10万分',
    'machine_open_any_free_undefined' => 'machineOpenAnyFree 函数未定义，请联系技术支持',
    'custom_open_score_success' => '自定义开分成功',
    'cmd_cannot_empty' => '指令代码不能为空',
    'cannot_create_machine_service' => '无法创建机台服务',
    'get_description_not_supported' => '机台服务不支持 getDescription 方法',
];
