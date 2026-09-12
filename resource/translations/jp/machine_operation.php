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

    // 単方向コマンド検証メッセージ
    'one_way_verify_fault_normal' => '機台は正常、故障なし',
    'one_way_verify_fault_cleared' => '故障が解除されました（DA正常状態）',
    'one_way_verify_fault_not_cleared' => '故障が解除されていません、機台が故障状態にあります、ハードウェアを確認してください',
    'one_way_verify_fault_abnormal' => '状態異常：正常だったのに故障になりました',

    'one_way_verify_external_already_zero' => '外部ボタンカウンタは既に0です、クリア不要',
    'one_way_verify_external_cleared' => '外部ボタンカウンタが0にクリアされました（B5={open}, B7={wash}）',
    'one_way_verify_external_both_not_cleared' => 'B5とB7カウンタが共にクリアされていません（B5={open}, B7={wash}）',
    'one_way_verify_external_open_not_cleared' => 'B5開分カウントがクリアされていません（現在={count}）',
    'one_way_verify_external_wash_not_cleared' => 'B7洗分カウントがクリアされていません（現在={count}）',

    'one_way_verify_score_already_zero' => 'ベット値は既に0です、クリア不要',
    'one_way_verify_score_cleared' => 'ベット値が0にクリアされました',
    'one_way_verify_score_not_cleared' => 'ベット値がクリアされていません（現在スコア={score}）',
];
