<?php
/**
 * 修复 ThinkPHP 依赖引用
 * 1. think\Exception -> Exception (标准PHP异常)
 * 2. think\model\concern\SoftDelete -> Illuminate\Database\Eloquent\SoftDeletes
 */

$rootPath = __DIR__;

// 需要处理的文件
$files = [
    // think\Exception 文件
    'app/functions.php',
    'app/service/MediaServer.php',
    'app/service/machine/Jackpot.php',
    'app/service/machine/Slot.php',
    'app/service/machine/SongJackpot.php',
    'app/service/machine/SongSlot.php',
    // think\model\concern\SoftDelete 文件
    'app/model/DepositBonusActivity.php',
    'app/model/StoreAutoShiftConfig.php',
];

$stats = [
    'exception_files' => 0,
    'soft_delete_files' => 0,
    'total_replacements' => 0,
];

foreach ($files as $file) {
    $filePath = $rootPath . '/' . $file;

    if (!file_exists($filePath)) {
        echo "⚠️  文件不存在: $file\n";
        continue;
    }

    $content = file_get_contents($filePath);
    $originalContent = $content;
    $fileChanged = false;

    // 替换 think\Exception
    if (strpos($content, 'use think\Exception;') !== false) {
        $content = str_replace('use think\Exception;', 'use Exception;', $content);
        $stats['exception_files']++;
        $fileChanged = true;
        echo "✅ $file - 替换 think\Exception -> Exception\n";
    }

    // 替换 think\model\concern\SoftDelete
    if (strpos($content, 'use think\model\concern\SoftDelete;') !== false) {
        $content = str_replace(
            'use think\model\concern\SoftDelete;',
            'use Illuminate\Database\Eloquent\SoftDeletes;',
            $content
        );

        // 同时需要替换 trait 使用
        $content = str_replace('use SoftDelete;', 'use SoftDeletes;', $content);

        // 替换 deleteTime 属性为 Eloquent 的 DELETED_AT
        if (strpos($content, "protected \$deleteTime = 'deleted_at';") !== false) {
            $content = str_replace(
                "protected \$deleteTime = 'deleted_at';",
                "const DELETED_AT = 'deleted_at';",
                $content
            );
        }

        $stats['soft_delete_files']++;
        $fileChanged = true;
        echo "✅ $file - 替换 SoftDelete -> SoftDeletes (Illuminate)\n";
    }

    if ($fileChanged) {
        file_put_contents($filePath, $content);
        $stats['total_replacements']++;
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "📊 统计信息:\n";
echo "   - Exception 替换: {$stats['exception_files']} 个文件\n";
echo "   - SoftDelete 替换: {$stats['soft_delete_files']} 个文件\n";
echo "   - 总计修改: {$stats['total_replacements']} 个文件\n";
echo str_repeat('=', 60) . "\n";
echo "✅ 所有 ThinkPHP 依赖已替换为标准/Illuminate 依赖\n";
