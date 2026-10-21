<?php

return [
    'missing_required_params_machine_action' => '缺少必要参数: machine_id 和 action',
    'machine_not_found' => '机台不存在',
    'machine_operation_failed' => '机台操作失败',
    'missing_required_params_machine_ids_action' => '缺少必要参数: machine_ids 和 action',
    'machine_ids_must_array' => 'machine_ids 必须是数组',
    'batch_operation_admin_only' => '批量操作仅限后台管理员',
    'batch_operation_complete' => '批量操作完成：成功 {success} 个，失败 {fail} 个',
    'batch_operation_failed' => '批量操作失败',
    'missing_parameter' => '缺少参数: {param}',
    'get_operations_failed' => '获取操作列表失败',
    'command_failed' => '指令执行失败',

    // 单向指令验证信息
    'one_way_verify_fault_normal' => '机台状态正常，无故障',
    'one_way_verify_fault_cleared' => '故障已清除（DA正常状态）',
    'one_way_verify_fault_not_cleared' => '故障未清除，机台仍处于故障状态，请检查硬件',
    'one_way_verify_fault_abnormal' => '状态异常：原本正常但变为故障',

    'one_way_verify_external_already_zero' => '外部按钮计数器本来就是0，无需清除',
    'one_way_verify_external_cleared' => '外部按钮计数器已归0（B5={open}, B7={wash}）',
    'one_way_verify_external_both_not_cleared' => 'B5和B7计数器均未归0（B5={open}, B7={wash}）',
    'one_way_verify_external_open_not_cleared' => 'B5开分计数未归0（当前={count}）',
    'one_way_verify_external_wash_not_cleared' => 'B7洗分计数未归0（当前={count}）',

    'one_way_verify_score_already_zero' => '押得数值本来就是0，无需清除',
    'one_way_verify_score_cleared' => '押得数值已归0',
    'one_way_verify_score_not_cleared' => '押得数值未归0（当前得分={score}）',
];
