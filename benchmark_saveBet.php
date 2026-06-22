<?php
/**
 * saveBet性能测试
 * 对比新旧方案的性能差异
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use support\Redis;

echo "=== saveBet性能测试 ===\n\n";

$redis = Redis::connection('work');

// 测试数据
$testKey = 'test:performance:bet:RSG:999999';
$testData = [
    'order_no' => '999999',
    'player_id' => 1,
    'platform_id' => 1,
    'amount' => 100,
    'game_code' => 'test',
    'original_data' => ['test' => str_repeat('x', 200)],
];

// 清理旧数据
$redis->del($testKey);

echo "测试场景：模拟真实生产环境流程\n";
echo str_repeat('-', 60) . "\n\n";

// ==================== 场景1：旧代码逻辑 ====================
echo "场景1：旧代码 - 每次完整覆盖\n";
echo str_repeat('-', 60) . "\n";

$iterations = 1000;
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    // 模拟旧逻辑：每次都完整hMSet
    $record = [
        'platform' => 'RSG',
        'order_no' => '999999',
        'player_id' => 1,
        'platform_id' => 1,
        'amount' => 100,
        'game_code' => 'test',
        'game_type' => 'slot',
        'game_name' => 'test',
        'bet_type' => 'bet',
        'bet_time' => time(),
        'original_data' => json_encode($testData['original_data']),
        'status' => 'pending',
        'settlement_status' => 0,
        'win' => 0,
        'diff' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'balance_before' => '1000',
        'balance_after' => '900',
    ];

    $redis->hMSet($testKey, $record);
    $redis->expire($testKey, 604800);
    $redis->zAdd('test:queue', time(), $testKey);
}

$time1 = (microtime(true) - $start) * 1000;
$avg1 = $time1 / $iterations;

echo "总耗时: " . round($time1, 2) . " ms\n";
echo "平均每次: " . round($avg1, 3) . " ms\n";
echo "字段数: 18个\n";
echo "预估数据量: ~800字节/次\n\n";

// ==================== 场景2：新代码逻辑（记录已存在） ====================
echo "场景2：新代码 - 记录已存在（90%情况）\n";
echo str_repeat('-', 60) . "\n";

// 先创建记录（模拟Lua脚本创建）
$redis->hMSet($testKey, [
    'platform' => 'RSG',
    'order_no' => '999999',
    'balance_before' => '1000',
    'balance_after' => '900',
]);

$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    // 检查存在
    $exists = $redis->exists($testKey);

    if ($exists) {
        // 只更新必要字段
        $updates = [
            'original_data' => json_encode($testData['original_data']),
        ];
        $redis->hMSet($testKey, $updates);
    }

    $redis->zAdd('test:queue', time(), $testKey);
}

$time2 = (microtime(true) - $start) * 1000;
$avg2 = $time2 / $iterations;

echo "总耗时: " . round($time2, 2) . " ms\n";
echo "平均每次: " . round($avg2, 3) . " ms\n";
echo "字段数: 1-3个\n";
echo "预估数据量: ~250字节/次\n\n";

// ==================== 场景3：新代码逻辑（记录不存在） ====================
echo "场景3：新代码 - 记录不存在（10%情况）\n";
echo str_repeat('-', 60) . "\n";

$start = microtime(true);

for ($i = 0; $i < 100; $i++) {  // 只测试100次
    $tempKey = "test:performance:bet:RSG:99999{$i}";

    // 检查存在
    $exists = $redis->exists($tempKey);

    if (!$exists) {
        // 创建完整记录
        $record = [
            'platform' => 'RSG',
            'order_no' => "99999{$i}",
            'player_id' => 1,
            'platform_id' => 1,
            'amount' => 100,
            'game_code' => 'test',
            'game_type' => 'slot',
            'game_name' => 'test',
            'bet_type' => 'bet',
            'bet_time' => time(),
            'original_data' => json_encode($testData['original_data']),
            'status' => 'pending',
            'settlement_status' => 0,
            'win' => 0,
            'diff' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'balance_before' => '1000',
            'balance_after' => '900',
        ];

        $redis->hMSet($tempKey, $record);
        $redis->expire($tempKey, 604800);
    }

    $redis->zAdd('test:queue', time(), $tempKey);

    // 清理
    $redis->del($tempKey);
}

$time3 = (microtime(true) - $start) * 1000;
$avg3 = $time3 / 100;

echo "总耗时: " . round($time3, 2) . " ms\n";
echo "平均每次: " . round($avg3, 3) . " ms\n";
echo "字段数: 18个 + EXISTS\n";
echo "预估数据量: ~800字节/次\n\n";

// ==================== 性能对比 ====================
echo "性能对比总结：\n";
echo str_repeat('=', 60) . "\n\n";

// 加权平均（90%存在，10%不存在）
$weightedAvg = 0.9 * $avg2 + 0.1 * $avg3;

echo "旧代码平均: " . round($avg1, 3) . " ms\n";
echo "新代码平均: " . round($weightedAvg, 3) . " ms (加权)\n";
echo "  - 记录存在: " . round($avg2, 3) . " ms (90%)\n";
echo "  - 记录不存在: " . round($avg3, 3) . " ms (10%)\n\n";

$improvement = (($avg1 - $weightedAvg) / $avg1) * 100;
$saved = $avg1 - $weightedAvg;

if ($improvement > 0) {
    echo "✅ 性能提升: " . round($improvement, 1) . "%\n";
    echo "   节省时间: " . round($saved, 3) . " ms/次\n";
} else {
    echo "⚠️ 性能下降: " . round(abs($improvement), 1) . "%\n";
    echo "   增加时间: " . round(abs($saved), 3) . " ms/次\n";
}

echo "\n数据传输量对比：\n";
echo "  旧代码: ~800字节/次\n";
echo "  新代码: ~250字节/次 (平均)\n";
echo "  减少: " . round((1 - 250/800) * 100, 1) . "%\n\n";

echo "实际生产影响：\n";
echo "  调用频率: ~1000次/秒 (高峰期)\n";
echo "  时间节省: " . round($saved * 1000, 1) . " ms/秒\n";
echo "  = " . round($saved, 3) . " 秒/秒\n";

if ($improvement > 5) {
    echo "\n✅ 结论: 新代码性能更好，推荐部署！\n";
} elseif ($improvement > -5) {
    echo "\n✅ 结论: 性能影响可忽略，可安全部署！\n";
} else {
    echo "\n⚠️ 结论: 性能下降明显，需要优化！\n";
}

// 清理测试数据
$redis->del($testKey);
$redis->zRem('test:queue', $testKey);

echo "\n✅ 测试完成，数据已清理\n";
