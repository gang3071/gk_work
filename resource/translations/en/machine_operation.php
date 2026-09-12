<?php

return [
    'missing_required_params_machine_action' => 'Missing required parameters: machine_id and action',
    'machine_not_found' => 'Machine not found',
    'machine_operation_failed' => 'Machine operation failed',
    'missing_required_params_machine_ids_action' => 'Missing required parameters: machine_ids and action',
    'machine_ids_must_array' => 'machine_ids must be an array',
    'batch_operation_admin_only' => 'Batch operation is only allowed for admin',
    'batch_operation_complete' => 'Batch operation complete: {success} success, {fail} failed',
    'batch_operation_failed' => 'Batch operation failed',
    'missing_parameter' => 'Missing parameter: {param}',
    'get_operations_failed' => 'Get operations list failed',
    'command_failed' => 'Command execution failed',

    // One-way command verification messages
    'one_way_verify_fault_normal' => 'Machine status is normal, no fault',
    'one_way_verify_fault_cleared' => 'Fault cleared (DA normal status)',
    'one_way_verify_fault_not_cleared' => 'Fault not cleared, machine still in fault status, please check hardware',
    'one_way_verify_fault_abnormal' => 'Status abnormal: was normal but became faulty',

    'one_way_verify_external_already_zero' => 'External button counter is already 0, no need to clear',
    'one_way_verify_external_cleared' => 'External button counter cleared to 0 (B5={open}, B7={wash})',
    'one_way_verify_external_both_not_cleared' => 'Both B5 and B7 counters not cleared (B5={open}, B7={wash})',
    'one_way_verify_external_open_not_cleared' => 'B5 open counter not cleared (current={count})',
    'one_way_verify_external_wash_not_cleared' => 'B7 wash counter not cleared (current={count})',

    'one_way_verify_score_already_zero' => 'Bet value is already 0, no need to clear',
    'one_way_verify_score_cleared' => 'Bet value cleared to 0',
    'one_way_verify_score_not_cleared' => 'Bet value not cleared (current score={score})',
];
