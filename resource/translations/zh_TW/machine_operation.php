
<?php

return [
    'missing_required_params_machine_action' => '缺少必要參數: machine_id 和 action',
    'machine_not_found' => '機台不存在',
    'machine_operation_failed' => '機台操作失敗',
    'missing_required_params_machine_ids_action' => '缺少必要參數: machine_ids 和 action',
    'machine_ids_must_array' => 'machine_ids 必須是數組',
    'batch_operation_admin_only' => '批量操作僅限後台管理員',
    'batch_operation_complete' => '批量操作完成：成功 {success} 個，失敗 {fail} 個',
    'batch_operation_failed' => '批量操作失敗',
    'missing_parameter' => '缺少參數: {param}',
    'get_operations_failed' => '獲取操作列表失敗',

    // 單向指令驗證信息
    'one_way_verify_fault_normal' => '機台狀態正常，無故障',
    'one_way_verify_fault_cleared' => '故障已清除（DA正常狀態）',
    'one_way_verify_fault_not_cleared' => '故障未清除，機台仍處於故障狀態，請檢查硬體',
    'one_way_verify_fault_abnormal' => '狀態異常：原本正常但變為故障',

    'one_way_verify_external_already_zero' => '外部按鈕計數器本來就是0，無需清除',
    'one_way_verify_external_cleared' => '外部按鈕計數器已歸0（B5={open}, B7={wash}）',
    'one_way_verify_external_both_not_cleared' => 'B5和B7計數器均未歸0（B5={open}, B7={wash}）',
    'one_way_verify_external_open_not_cleared' => 'B5開分計數未歸0（當前={count}）',
    'one_way_verify_external_wash_not_cleared' => 'B7洗分計數未歸0（當前={count}）',

    'one_way_verify_score_already_zero' => '押得數值本來就是0，無需清除',
    'one_way_verify_score_cleared' => '押得數值已歸0',
    'one_way_verify_score_not_cleared' => '押得數值未歸0（當前得分={score}）',

    'command_failed' => '指令執行失敗',
];
