<?php
// 生产服务器日志分析脚本

$host = '34.80.234.173';
$port = 22;
$user = 'root';
$pass = 'gang3071';
$logDir = '/www/wwwroot/admin.supergames9.com/runtime/logs';

echo "=== 连接到生产服务器 $host ===\n\n";

// 检查是否安装了ssh2扩展
if (!function_exists('ssh2_connect')) {
    echo "错误: PHP ssh2扩展未安装\n";
    echo "使用替代方法: plink (PuTTY)\n\n";

    // 使用plink作为替代
    $commands = [
        "列出日志文件" => "ls -lh $logDir | head -30",
        "GameRecordSyncWorker日志" => "tail -200 $logDir/GameRecordSyncWorker.log 2>/dev/null || echo '日志文件不存在'",
        "检查队列积压" => "redis-cli -h 127.0.0.1 -p 6379 ZCARD game:sync:queue",
        "检查duplicate错误" => "grep -i 'duplicate' $logDir/*.log 2>/dev/null | tail -50",
        "检查RSG平台" => "grep 'RSG' $logDir/GameRecordSyncWorker.log 2>/dev/null | tail -30",
        "检查T9SLOT平台" => "grep 'T9SLOT' $logDir/GameRecordSyncWorker.log 2>/dev/null | tail -30",
        "检查DG平台" => "grep 'DG' $logDir/GameRecordSyncWorker.log 2>/dev/null | tail -30",
    ];

    foreach ($commands as $desc => $cmd) {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "[$desc]\n";
        echo str_repeat('=', 60) . "\n";

        $fullCmd = "echo gang3071 | plink -ssh -batch -pw gang3071 root@$host \"$cmd\"";
        echo "执行: $cmd\n\n";

        $output = shell_exec($fullCmd);
        echo $output ?: "(无输出)\n";
    }

    exit(0);
}

// 使用ssh2扩展
$conn = ssh2_connect($host, $port);
if (!$conn) {
    die("无法连接到服务器\n");
}

if (!ssh2_auth_password($conn, $user, $pass)) {
    die("认证失败\n");
}

echo "✅ 连接成功\n\n";

function execCommand($conn, $cmd) {
    $stream = ssh2_exec($conn, $cmd);
    stream_set_blocking($stream, true);
    $output = stream_get_contents($stream);
    fclose($stream);
    return $output;
}

$commands = [
    "列出日志文件" => "ls -lh $logDir | head -30",
    "GameRecordSyncWorker日志" => "tail -200 $logDir/GameRecordSyncWorker.log 2>/dev/null || echo '日志文件不存在'",
    "检查队列积压" => "redis-cli -h 127.0.0.1 -p 6379 ZCARD game:sync:queue",
    "检查duplicate错误" => "grep -i 'duplicate' $logDir/*.log 2>/dev/null | tail -50",
    "检查各平台记录" => "grep -E '(RSG|T9SLOT|DG|SA|QT|KT)' $logDir/GameRecordSyncWorker.log 2>/dev/null | tail -50",
];

foreach ($commands as $desc => $cmd) {
    echo str_repeat('=', 60) . "\n";
    echo "[$desc]\n";
    echo str_repeat('=', 60) . "\n";
    $output = execCommand($conn, $cmd);
    echo $output ?: "(无输出)\n";
    echo "\n";
}
