<?php
/**
 * Pipeline性能基准测试
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use support\Redis;

echo "=== Redis Pipeline性能测试 ===\n\n";

$redis = Redis::connection('work');

// 准备测试数据
$testKeys = [];
echo "准备测试数据...\n";
for ($i = 1; $i <= 200; $i++) {
    $key = "test:benchmark:key:{$i}";
    $redis->hMSet($key, [
        'field1' => str_repeat('x', 100),
        'field2' => str_repeat('y', 100),
        'field3' => str_repeat('z', 100),
    ]);
    $testKeys[] = $key;
}
echo "✅ 准备完成：200个key，每个~300字节\n\n";

// 测试1：逐条读取
echo "测试1: 逐条读取（模拟原修复方案）\n";
echo str_repeat('-', 60) . "\n";

$start = microtime(true);
$results1 = [];
foreach ($testKeys as $key) {
    $results1[] = $redis->hGetAll($key);
}
$time1 = (microtime(true) - $start) * 1000;

echo "耗时: " . round($time1, 2) . " ms\n";
echo "平均每条: " . round($time1 / 200, 2) . " ms\n\n";

// 测试2：Pipeline批量读取
echo "测试2: Pipeline批量读取（当前方案）\n";
echo str_repeat('-', 60) . "\n";

$start = microtime(true);
$pipe = $redis->pipeline();
foreach ($testKeys as $key) {
    $pipe->hGetAll($key);
}
$results2 = $pipe->execute();
$time2 = (microtime(true) - $start) * 1000;

echo "耗时: " . round($time2, 2) . " ms\n";
echo "平均每条: " . round($time2 / 200, 2) . " ms\n\n";

// 性能对比
echo "性能对比:\n";
echo str_repeat('=', 60) . "\n";
$speedup = $time1 / $time2;
$saved = $time1 - $time2;

echo "逐条读取:  " . round($time1, 2) . " ms\n";
echo "Pipeline:   " . round($time2, 2) . " ms\n";
echo "节省时间:  " . round($saved, 2) . " ms\n";
echo "性能提升:  " . round($speedup, 1) . "x\n\n";

// Worker实际场景模拟
echo "实际场景模拟:\n";
echo str_repeat('=', 60) . "\n";
$workerInterval = 5000;  // 5秒
$overhead1 = ($time1 / $workerInterval) * 100;
$overhead2 = ($time2 / $workerInterval) * 100;

echo "Worker间隔: 5000 ms\n";
echo "逐条方案开销: " . round($overhead1, 2) . "%\n";
echo "Pipeline方案开销: " . round($overhead2, 2) . "%\n\n";

if ($overhead2 < 1.0) {
    echo "✅ 结论: Pipeline方案开销不到1%，性能影响可忽略！\n";
} elseif ($overhead2 < 5.0) {
    echo "✅ 结论: Pipeline方案开销小于5%，性能影响可接受！\n";
} else {
    echo "⚠️ 注意: 开销较大，考虑优化批次大小\n";
}

// 清理测试数据
echo "\n清理测试数据...\n";
foreach ($testKeys as $key) {
    $redis->del($key);
}
echo "✅ 清理完成\n";
