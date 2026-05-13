<?php

/**
 * 高分广播配置初始化脚本
 *
 * 功能：为所有渠道初始化高分广播配置
 * 用法：php init_high_score_broadcast.php
 */

require_once __DIR__ . '/vendor/autoload.php';

echo "=== 高分广播配置初始化 ===\n\n";

// 初始化数据库
$capsule = new \Illuminate\Database\Capsule\Manager;
$config = require __DIR__ . '/config/database.php';
$capsule->addConnection($config['connections'][$config['default']]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// 获取后管的 SystemSetting 模型（需要跨项目访问）
// 如果在 gk_work 中无法直接访问 gk_admin 的模型，需要使用原始 SQL

$tableName = 'system_setting'; // 根据实际表名调整

echo "📝 步骤1：查询所有渠道\n";

$channels = \Illuminate\Database\Capsule\Manager::table('channel')
    ->whereNull('deleted_at')
    ->select(['department_id', 'name'])
    ->orderBy('department_id')
    ->get();

if ($channels->isEmpty()) {
    echo "   ❌ 未找到任何渠道\n";
    exit(1);
}

echo "   ✅ 找到 " . $channels->count() . " 个渠道\n\n";

echo "📝 步骤2：初始化配置记录\n";

$createdCount = 0;
$existsCount = 0;
$defaultThreshold = 5000; // 默认阈值 5000 分
$defaultStatus = 0; // 默认禁用

foreach ($channels as $channel) {
    // 检查是否已存在配置
    $exists = \Illuminate\Database\Capsule\Manager::table($tableName)
        ->where('department_id', $channel->department_id)
        ->where('feature', 'high_score_broadcast_threshold')
        ->exists();

    if ($exists) {
        echo "   ⏭ 渠道 {$channel->department_id} ({$channel->name}) - 配置已存在，跳过\n";
        $existsCount++;
        continue;
    }

    // 创建配置记录
    \Illuminate\Database\Capsule\Manager::table($tableName)->insert([
        'department_id' => $channel->department_id,
        'feature' => 'high_score_broadcast_threshold',
        'num' => $defaultThreshold,
        'content' => '',
        'date_start' => null,
        'date_end' => null,
        'status' => $defaultStatus,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    echo "   ✅ 渠道 {$channel->department_id} ({$channel->name}) - 已创建（阈值: {$defaultThreshold} 分，状态: " . ($defaultStatus ? '启用' : '禁用') . "）\n";
    $createdCount++;
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "=== 初始化完成 ===\n";
echo str_repeat('=', 60) . "\n\n";

echo "统计:\n";
echo "  - 总渠道数: " . $channels->count() . "\n";
echo "  - 新创建配置: {$createdCount} 个\n";
echo "  - 已存在配置: {$existsCount} 个\n\n";

echo "下一步:\n";
echo "  1. 访问后管系统设置页面\n";
echo "  2. 找到【高分广播阈值】配置项\n";
echo "  3. 为需要的渠道启用并设置阈值\n";
echo "  4. 修改阈值：直接在后管界面编辑\n";
echo "  5. 启用/禁用：切换状态开关\n\n";

echo "⚠️ 注意:\n";
echo "  - 默认状态为禁用（status = 0）\n";
echo "  - 默认阈值为 {$defaultThreshold} 分\n";
echo "  - 需要在后管手动启用才会生效\n\n";

echo "=== 脚本执行完成 ===\n";
