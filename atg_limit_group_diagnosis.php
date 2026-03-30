<?php
/**
 * ATG限红组诊断脚本
 * 用于排查ATG限红组配置问题
 */

require_once __DIR__ . '/vendor/autoload.php';

// 初始化 Webman 配置和数据库
\Webman\Config::load(config_path(), ['route', 'container']);

use app\model\Player;
use app\model\GamePlatform;
use app\model\AdminUserLimitGroup;
use app\model\PlatformLimitGroupConfig;
use app\model\PlatformLimitGroup;

echo "\n========================================\n";
echo "ATG限红组诊断工具\n";
echo "========================================\n\n";

// 1. 查询ATG平台信息
echo "【步骤1】查询ATG平台信息...\n";
$atgPlatform = GamePlatform::query()->where('code', 'ATG')->first();

if (!$atgPlatform) {
    echo "❌ 错误：找不到ATG平台，请检查 game_platform 表\n";
    exit(1);
}

echo "✅ ATG平台信息：\n";
echo "   - ID: {$atgPlatform->id}\n";
echo "   - 代码: {$atgPlatform->code}\n";
echo "   - 名称: {$atgPlatform->name}\n";
echo "   - 默认限红组ID: " . ($atgPlatform->default_limit_group_id ?? '未设置') . "\n\n";

// 2. 查询所有限红组
echo "【步骤2】查询所有限红组...\n";
$limitGroups = PlatformLimitGroup::query()
    ->where('status', 1)
    ->orderBy('sort')
    ->get();

if ($limitGroups->isEmpty()) {
    echo "⚠️ 警告：没有找到任何限红组\n\n";
} else {
    echo "✅ 找到 {$limitGroups->count()} 个限红组：\n";
    foreach ($limitGroups as $group) {
        echo "   - ID:{$group->id} 代码:{$group->code} 名称:{$group->name}\n";
    }
    echo "\n";
}

// 3. 查询ATG平台的限红组配置
echo "【步骤3】查询ATG平台的限红组配置...\n";
$atgConfigs = PlatformLimitGroupConfig::query()
    ->where('platform_id', $atgPlatform->id)
    ->where('status', 1)
    ->get();

if ($atgConfigs->isEmpty()) {
    echo "❌ 错误：没有找到ATG平台的限红组配置\n";
    echo "   请在 platform_limit_group_config 表中添加ATG配置\n\n";
} else {
    echo "✅ 找到 {$atgConfigs->count()} 个ATG限红组配置：\n";
    foreach ($atgConfigs as $config) {
        $groupName = $config->limitGroup->name ?? '未知';
        echo "   - 限红组: {$groupName} (ID:{$config->limit_group_id})\n";
        echo "     配置数据: " . json_encode($config->config_data, JSON_UNESCAPED_UNICODE) . "\n";

        // 检查配置数据完整性
        $configData = $config->config_data;
        $hasOperator = !empty($configData['operator']);
        $hasKey = !empty($configData['key']);
        $hasProviderId = !empty($configData['providerId']);

        echo "     检查: operator[" . ($hasOperator ? '✓' : '✗') . "] ";
        echo "key[" . ($hasKey ? '✓' : '✗') . "] ";
        echo "providerId[" . ($hasProviderId ? '✓' : '✗') . "]\n";

        if (!$hasOperator || !$hasKey) {
            echo "     ⚠️ 警告：配置不完整，至少需要 operator 和 key\n";
        }
    }
    echo "\n";
}

// 4. 查询店家限红组分配
echo "【步骤4】查询店家限红组分配...\n";
$adminLimitGroups = AdminUserLimitGroup::query()
    ->where('platform_id', $atgPlatform->id)
    ->where('status', 1)
    ->get();

if ($adminLimitGroups->isEmpty()) {
    echo "⚠️ 警告：没有店家被分配到ATG限红组\n\n";
} else {
    echo "✅ 找到 {$adminLimitGroups->count()} 个店家限红组分配：\n";
    foreach ($adminLimitGroups as $assignment) {
        $groupName = $assignment->limitGroup->name ?? '未知';
        $adminUser = \addons\webman\model\AdminUsers::find($assignment->admin_user_id);
        $adminName = $adminUser->username ?? '未知';

        echo "   - 店家: {$adminName} (ID:{$assignment->admin_user_id})\n";
        echo "     限红组: {$groupName} (ID:{$assignment->limit_group_id})\n";

        // 检查该限红组是否有ATG配置
        $hasConfig = PlatformLimitGroupConfig::query()
            ->where('limit_group_id', $assignment->limit_group_id)
            ->where('platform_id', $atgPlatform->id)
            ->where('status', 1)
            ->exists();

        echo "     ATG配置: " . ($hasConfig ? '✓ 已配置' : '✗ 未配置') . "\n";
    }
    echo "\n";
}

// 5. 测试具体玩家的限红组配置
echo "【步骤5】测试玩家限红组配置...\n";
echo "请输入要测试的玩家ID（留空跳过）: ";
$playerId = trim(fgets(STDIN));

if (!empty($playerId)) {
    $player = Player::find($playerId);

    if (!$player) {
        echo "❌ 错误：找不到玩家 ID: {$playerId}\n\n";
    } else {
        echo "✅ 玩家信息：\n";
        echo "   - ID: {$player->id}\n";
        echo "   - UUID: {$player->uuid}\n";
        echo "   - 店家ID: " . ($player->store_admin_id ?? '未设置') . "\n";

        if (empty($player->store_admin_id)) {
            echo "   ⚠️ 警告：该玩家没有店家ID，无法使用限红组功能\n\n";
        } else {
            // 查询店家的限红组分配
            $adminLimitGroup = AdminUserLimitGroup::query()
                ->where('admin_user_id', $player->store_admin_id)
                ->where('platform_id', $atgPlatform->id)
                ->where('status', 1)
                ->first();

            if (!$adminLimitGroup) {
                echo "   ⚠️ 警告：店家(ID:{$player->store_admin_id})未分配到ATG限红组\n\n";
            } else {
                $groupName = $adminLimitGroup->limitGroup->name ?? '未知';
                echo "   ✅ 店家限红组: {$groupName} (ID:{$adminLimitGroup->limit_group_id})\n";

                // 查询限红组的ATG配置
                $limitGroupConfig = PlatformLimitGroupConfig::query()
                    ->where('limit_group_id', $adminLimitGroup->limit_group_id)
                    ->where('platform_id', $atgPlatform->id)
                    ->where('status', 1)
                    ->first();

                if (!$limitGroupConfig) {
                    echo "   ❌ 错误：限红组没有ATG配置\n\n";
                } else {
                    echo "   ✅ ATG配置：\n";
                    $configData = $limitGroupConfig->config_data;
                    echo "      - operator: " . ($configData['operator'] ?? '未设置') . "\n";
                    echo "      - key: " . (isset($configData['key']) ? '已设置(***隐藏***)' : '未设置') . "\n";
                    echo "      - providerId: " . ($configData['providerId'] ?? '未设置') . "\n";
                    echo "      - api_domain: " . ($configData['api_domain'] ?? '使用默认') . "\n";

                    // 最终结论
                    if (!empty($configData['operator']) && !empty($configData['key'])) {
                        echo "\n   🎉 该玩家的限红组配置完整，应该可以正常使用！\n";
                        echo "   📝 如果仍然没有生效，请检查日志文件：storage/logs/atg_server.log\n\n";
                    } else {
                        echo "\n   ❌ 配置不完整，缺少必要的 operator 或 key\n\n";
                    }
                }
            }
        }
    }
} else {
    echo "跳过玩家测试\n\n";
}

// 6. 生成配置示例SQL
echo "【步骤6】生成配置示例SQL...\n";
echo "如果需要添加ATG限红组配置，可以参考以下SQL：\n\n";

echo "-- 1. 创建限红组\n";
echo "INSERT INTO platform_limit_group (department_id, code, name, description, status, sort, created_at, updated_at)\n";
echo "VALUES (1, 'ATG_A', 'ATG高额组', '高额限红配置', 1, 1, NOW(), NOW());\n\n";

echo "-- 2. 为限红组配置ATG平台参数（假设限红组ID=1，ATG平台ID={$atgPlatform->id}）\n";
echo "INSERT INTO platform_limit_group_config (limit_group_id, platform_id, platform_code, config_data, status, created_at, updated_at)\n";
echo "VALUES (\n";
echo "    1,\n";
echo "    {$atgPlatform->id},\n";
echo "    'ATG',\n";
echo "    JSON_OBJECT(\n";
echo "        'operator', 'your_operator_name',\n";
echo "        'key', 'your_operator_key',\n";
echo "        'providerId', '4'\n";
echo "    ),\n";
echo "    1,\n";
echo "    NOW(),\n";
echo "    NOW()\n";
echo ");\n\n";

echo "-- 3. 为店家分配限红组（假设店家ID=100，限红组ID=1）\n";
echo "INSERT INTO admin_user_limit_group (admin_user_id, limit_group_id, platform_id, platform_code, assigned_by, assigned_at, status, created_at, updated_at)\n";
echo "VALUES (\n";
echo "    100,\n";
echo "    1,\n";
echo "    {$atgPlatform->id},\n";
echo "    'ATG',\n";
echo "    1,\n";
echo "    NOW(),\n";
echo "    1,\n";
echo "    NOW(),\n";
echo "    NOW()\n";
echo ");\n\n";

echo "========================================\n";
echo "诊断完成！\n";
echo "========================================\n\n";

echo "📋 检查清单：\n";
echo "  □ ATG平台存在于 game_platform 表\n";
echo "  □ 已创建限红组（platform_limit_group）\n";
echo "  □ 限红组已配置ATG参数（platform_limit_group_config）\n";
echo "  □ 配置数据包含 operator 和 key\n";
echo "  □ 店家已分配到限红组（admin_user_limit_group）\n";
echo "  □ 玩家的 store_admin_id 已设置\n\n";

echo "💡 提示：\n";
echo "  1. 修改配置后，请查看日志文件确认是否生效\n";
echo "  2. 日志路径：storage/logs/atg_server.log\n";
echo "  3. 查找关键字：'ATG使用限红组营运账号' 或 'ATG使用默认配置'\n\n";
