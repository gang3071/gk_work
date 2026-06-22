<?php
/**
 * 通过HTTP方式获取生产服务器日志
 * 前提：需要在生产服务器上部署一个简单的日志查看接口
 */

echo "=== 生产日志分析方案 ===\n\n";

echo "由于SSH连接限制,建议使用以下方式之一:\n\n";

echo "方案1: 使用Xshell手动执行以下命令:\n";
echo str_repeat('-', 60) . "\n";
echo <<<'BASH'
# 连接到服务器
ssh root@34.80.234.173
# 密码: gang3071

# 进入日志目录
cd /www/wwwroot/admin.supergames9.com/runtime/logs

# === 关键诊断命令 ===

# 1. 检查队列积压数量
redis-cli -h 127.0.0.1 -p 6379 ZCARD game:sync:queue

# 2. 查看Worker是否在运行
ps aux | grep GameRecordSyncWorker

# 3. 查看最近的Worker日志(最后200行)
tail -200 GameRecordSyncWorker.log

# 4. 查找duplicate key错误
grep -i 'duplicate' *.log 2>/dev/null | tail -50

# 5. 查找EVALSHA相关问题
grep -iE 'evalsha|noscript|script.*load' GameRecordSyncWorker.log | tail -30

# 6. 统计各平台的记录数
for platform in RSG MT DG SA T9SLOT QT KT; do
    count=$(grep -c "$platform" GameRecordSyncWorker.log 2>/dev/null || echo 0)
    echo "$platform: $count 条记录"
done

# 7. 查看批次处理日志
grep -E '批次处理|读取.*条记录|插入.*成功|去重|合并' GameRecordSyncWorker.log | tail -50

# 8. 检查Redis中实际有多少记录
redis-cli -h 127.0.0.1 -p 6379 KEYS "game:record:bet:*" | wc -l

# 9. 抽样查看队列中的记录
redis-cli -h 127.0.0.1 -p 6379 ZRANGE game:sync:queue 0 9

# 10. 检查某条记录的详细信息(替换为实际key)
redis-cli -h 127.0.0.1 -p 6379 HGETALL "game:record:bet:RSG:xxxxx"

BASH;

echo "\n\n";
echo "方案2: 将日志文件下载到本地分析:\n";
echo str_repeat('-', 60) . "\n";
echo "scp root@34.80.234.173:/www/wwwroot/admin.supergames9.com/runtime/logs/GameRecordSyncWorker.log D:\\gk_work\\prod_worker.log\n";
echo "scp root@34.80.234.173:/www/wwwroot/admin.supergames9.com/runtime/logs/webman.log D:\\gk_work\\prod_webman.log\n";

echo "\n\n";
echo "方案3: 在生产服务器上创建临时诊断脚本:\n";
echo str_repeat('-', 60) . "\n";
echo <<<'PHP'
# 在服务器上创建文件: /www/wwwroot/admin.supergames9.com/diagnose_logs.php
<?php
header('Content-Type: text/plain; charset=utf-8');

$logDir = __DIR__ . '/runtime/logs';

echo "=== 队列状态 ===\n";
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
echo "队列积压: " . $redis->zCard('game:sync:queue') . " 条\n";
echo "Redis记录数: " . count($redis->keys('game:record:bet:*')) . " 条\n\n";

echo "=== GameRecordSyncWorker.log (最后100行) ===\n";
echo shell_exec("tail -100 $logDir/GameRecordSyncWorker.log");

echo "\n\n=== Duplicate错误 ===\n";
echo shell_exec("grep -i 'duplicate' $logDir/*.log 2>/dev/null | tail -30");

echo "\n\n=== EVALSHA问题 ===\n";
echo shell_exec("grep -iE 'evalsha|noscript' $logDir/GameRecordSyncWorker.log | tail -20");

# 然后访问: http://34.80.234.173/diagnose_logs.php
PHP;

echo "\n\n";
echo "请选择一种方式并将输出结果复制回来,我将进行分析。\n";
