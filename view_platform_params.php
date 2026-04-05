#!/usr/bin/env php
<?php
/**
 * 游戏平台参数查看工具
 *
 * 使用方法：
 *   php view_platform_params.php list                    # 列出所有收集的文件
 *   php view_platform_params.php view rsg_bet_20260405   # 查看具体文件
 *   php view_platform_params.php report RSG bet          # 生成分析报告
 *   php view_platform_params.php cleanup 7               # 清理 7 天前的数据
 */

require_once __DIR__ . '/vendor/autoload.php';

use app\service\PlatformParamsCollector;

// 颜色输出
function colorText($text, $color = 'white')
{
    $colors = [
            'black' => '0;30',
            'red' => '0;31',
            'green' => '0;32',
            'yellow' => '0;33',
            'blue' => '0;34',
            'magenta' => '0;35',
            'cyan' => '0;36',
            'white' => '0;37',
            'bright_red' => '1;31',
            'bright_green' => '1;32',
            'bright_yellow' => '1;33',
            'bright_blue' => '1;34',
    ];

    $code = $colors[$color] ?? $colors['white'];
    return "\033[{$code}m{$text}\033[0m";
}

function printHeader($text)
{
    echo "\n" . colorText(str_repeat('=', 60), 'cyan') . "\n";
    echo colorText($text, 'bright_yellow') . "\n";
    echo colorText(str_repeat('=', 60), 'cyan') . "\n\n";
}

function printSuccess($text)
{
    echo colorText('✓ ', 'green') . $text . "\n";
}

function printError($text)
{
    echo colorText('✗ ', 'red') . $text . "\n";
}

function printInfo($text)
{
    echo colorText('ℹ ', 'blue') . $text . "\n";
}

// 解析命令行参数
$command = $argv[1] ?? 'help';

switch ($command) {
    case 'list':
        // 列出所有收集的文件
        printHeader('收集的参数文件列表');

        $files = PlatformParamsCollector::getCollectedFiles();

        if (empty($files)) {
            printInfo('暂无收集的参数文件');
            break;
        }

        echo sprintf(
                "%-10s %-10s %-12s %-25s %10s\n",
                colorText('平台', 'bright_green'),
                colorText('操作', 'bright_green'),
                colorText('日期', 'bright_green'),
                colorText('文件名', 'bright_green'),
                colorText('大小', 'bright_green')
        );
        echo str_repeat('-', 80) . "\n";

        foreach ($files as $file) {
            echo sprintf(
                    "%-10s %-10s %-12s %-25s %7s KB\n",
                    colorText($file['platform'], 'cyan'),
                    $file['action'],
                    $file['date'],
                    $file['filename'],
                    number_format($file['size'] / 1024, 2)
            );
        }

        echo "\n" . colorText('总计: ' . count($files) . ' 个文件', 'bright_yellow') . "\n";
        break;

    case 'view':
        // 查看具体文件
        $filename = $argv[2] ?? '';

        if (empty($filename)) {
            printError('请指定文件名');
            echo "用法: php view_platform_params.php view <filename>\n";
            echo "示例: php view_platform_params.php view rsg_bet_20260405.json\n";
            break;
        }

        // 允许不带 .json 后缀
        if (!str_ends_with($filename, '.json')) {
            $filename .= '.json';
        }

        printHeader("查看参数文件: {$filename}");

        $limit = (int)($argv[3] ?? 10);
        $data = PlatformParamsCollector::read($filename, $limit);

        if (empty($data)) {
            printError("文件不存在或没有数据: {$filename}");
            break;
        }

        echo colorText("显示最新 " . count($data) . " 条记录\n\n", 'bright_blue');

        foreach ($data as $index => $record) {
            echo colorText("记录 #" . ($index + 1), 'bright_green') . "\n";
            echo colorText('时间: ', 'yellow') . $record['timestamp'] . "\n";
            echo colorText('平台: ', 'yellow') . $record['platform'] . "\n";
            echo colorText('操作: ', 'yellow') . $record['action'] . "\n";
            echo colorText('参数: ', 'yellow') . "\n";
            echo json_encode($record['params'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

            if (!empty($record['context'])) {
                echo colorText('上下文: ', 'yellow') . "\n";
                echo json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            }

            echo str_repeat('-', 60) . "\n\n";
        }
        break;

    case 'report':
        // 生成分析报告
        $platform = strtoupper($argv[2] ?? '');
        $action = $argv[3] ?? '';

        if (empty($platform) || empty($action)) {
            printError('请指定平台和操作类型');
            echo "用法: php view_platform_params.php report <platform> <action>\n";
            echo "示例: php view_platform_params.php report RSG bet\n";
            break;
        }

        printHeader("{$platform} 平台 {$action} 操作参数分析报告");

        $report = PlatformParamsCollector::generateReport($platform, $action);

        if ($report['total_samples'] == 0) {
            printError("没有找到 {$platform} 平台 {$action} 操作的数据");
            break;
        }

        echo colorText("样本数量: ", 'bright_green') . $report['total_samples'] . "\n";
        echo colorText("字段统计: ", 'bright_green') . count($report['fields']) . " 个字段\n\n";

        echo sprintf(
                "%-25s %10s %30s %s\n",
                colorText('字段名', 'bright_green'),
                colorText('出现次数', 'bright_green'),
                colorText('类型分布', 'bright_green'),
                colorText('示例值', 'bright_green')
        );
        echo str_repeat('-', 120) . "\n";

        foreach ($report['fields'] as $field) {
            $types = [];
            foreach ($field['types'] as $type => $count) {
                $percentage = round($count / $field['count'] * 100);
                $types[] = "{$type}({$percentage}%)";
            }
            $typesStr = implode(', ', $types);

            $examples = array_slice($field['examples'], 0, 3);
            $examplesStr = implode(', ', $examples);
            if (strlen($examplesStr) > 40) {
                $examplesStr = substr($examplesStr, 0, 37) . '...';
            }

            echo sprintf(
                    "%-25s %10d %30s %s\n",
                    colorText($field['name'], 'cyan'),
                    $field['count'],
                    $typesStr,
                    $examplesStr
            );
        }

        echo "\n" . colorText('最新样本:', 'bright_yellow') . "\n";
        echo json_encode($report['latest_sample']['params'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        break;

    case 'cleanup':
        // 清理旧数据
        $days = (int)($argv[2] ?? 7);

        printHeader("清理 {$days} 天前的数据");

        $deleted = PlatformParamsCollector::cleanup($days);

        if ($deleted > 0) {
            printSuccess("已删除 {$deleted} 个文件");
        } else {
            printInfo("没有需要清理的文件");
        }
        break;

    case 'help':
    default:
        // 显示帮助
        printHeader('游戏平台参数查看工具');

        echo colorText('用法:', 'bright_green') . "\n";
        echo "  php view_platform_params.php <command> [options]\n\n";

        echo colorText('命令:', 'bright_green') . "\n";
        echo "  " . colorText('list', 'cyan') . "                          列出所有收集的文件\n";
        echo "  " . colorText('view <filename> [limit]', 'cyan') . "       查看具体文件（默认显示最新 10 条）\n";
        echo "  " . colorText('report <platform> <action>', 'cyan') . "    生成参数分析报告\n";
        echo "  " . colorText('cleanup [days]', 'cyan') . "                清理旧数据（默认 7 天）\n";
        echo "  " . colorText('help', 'cyan') . "                          显示此帮助信息\n\n";

        echo colorText('示例:', 'bright_green') . "\n";
        echo "  php view_platform_params.php list\n";
        echo "  php view_platform_params.php view rsg_bet_20260405\n";
        echo "  php view_platform_params.php view rsg_bet_20260405 20\n";
        echo "  php view_platform_params.php report RSG bet\n";
        echo "  php view_platform_params.php cleanup 7\n\n";

        echo colorText('文件位置:', 'bright_green') . "\n";
        echo "  runtime/platform_params/\n\n";

        break;
}

echo "\n";
