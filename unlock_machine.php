#!/usr/bin/env php
<?php
/**
 * 机台解锁工具
 * 用法: php unlock_machine.php <machine_code>
 * 示例: php unlock_machine.php S386
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use app\model\Machine;
use support\Redis;

if ($argc < 2) {
    echo "用法: php unlock_machine.php <machine_code>\n";
    echo "示例: php unlock_machine.php S386\n";
    exit(1);
}

$machineCode = $argv[1];

try {
    // 查找机台
    $machine = Machine::query()->where('code', $machineCode)->first();

    if (!$machine) {
        echo "❌ 错误: 机台 $machineCode 不存在\n";
        exit(1);
    }

    echo "===========================================\n";
    echo "机台解锁工具\n";
    echo "===========================================\n";
    echo "机台编号: {$machine->code}\n";
    echo "机台名称: {$machine->name}\n";
    echo "机台ID: {$machine->id}\n";
    echo "机台类型: " . ($machine->type == 1 ? '斯洛' : '钢珠') . "\n";
    echo "-------------------------------------------\n";

    // 检查 Redis 中的机台状态
    $cacheKey = 'machine_tcp_data_cache_' . $machine->id;
    $hasLockKey = $cacheKey . '_has_lock';

    $redis = Redis::connection('default')->client();
    $hasLock = $redis->hGet($hasLockKey, 'has_lock');

    echo "当前锁定状态: " . ($hasLock == 1 ? '🔒 已锁定' : '🔓 未锁定') . "\n";

    if ($hasLock != 1) {
        echo "\n✅ 机台未被锁定，无需解锁\n";
        exit(0);
    }

    // 显示机台当前状态
    echo "\n当前机台状态:\n";
    $machineData = [
        'point' => $redis->hGet($cacheKey . '_point', 'point'),
        'bet' => $redis->hGet($cacheKey . '_bet', 'bet'),
        'win' => $redis->hGet($cacheKey . '_win', 'win'),
        'auto' => $redis->hGet($cacheKey . '_auto', 'auto'),
        'gaming_user_id' => $redis->hGet($cacheKey . '_gaming_user_id', 'gaming_user_id'),
    ];

    foreach ($machineData as $key => $value) {
        echo "  {$key}: {$value}\n";
    }

    // 查询最近的锁机日志
    echo "\n查询最近的锁机日志...\n";
    $logFile = __DIR__ . '/runtime/logs/machine_operations.log';
    if (file_exists($logFile)) {
        $logContent = shell_exec("grep 'MachineLock.*{$machineCode}' {$logFile} | tail -3");
        if ($logContent) {
            echo "-------------------------------------------\n";
            echo $logContent;
            echo "-------------------------------------------\n";
        } else {
            echo "未找到锁机日志\n";
        }
    }

    // 确认解锁
    echo "\n是否确认解锁机台 {$machineCode}? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);

    if (strtolower($line) !== 'yes') {
        echo "已取消解锁\n";
        exit(0);
    }

    // 执行解锁
    echo "\n正在解锁...\n";
    $result = $redis->hSet($hasLockKey, 'has_lock', 0);

    if ($result !== false) {
        echo "✅ 解锁成功！\n";

        // 记录解锁日志
        \support\Log::channel('machine_operations')->info('[Manual-Unlock] 手动解锁机台', [
            'machine_id' => $machine->id,
            'machine_code' => $machine->code,
            'machine_name' => $machine->name,
            'operator' => 'cli',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        echo "\n机台 {$machineCode} 已成功解锁\n";
    } else {
        echo "❌ 解锁失败\n";
        exit(1);
    }

    echo "===========================================\n";

} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
