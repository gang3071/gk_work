<?php
/**
 * 机台问题排查脚本（PHP 版）
 *
 * 用法：php scripts/diagnose_machine.php <machine_id> [HH:MM-HH:MM] [YYYY-MM-DD]
 *
 * 示例：
 *   php scripts/diagnose_machine.php 1318
 *   php scripts/diagnose_machine.php 1318 15:50-16:00
 *   php scripts/diagnose_machine.php 1318 15:50-16:00 2026-11-16
 */

declare(strict_types=1);

// ───────────────── 参数解析 ─────────────────
$machineId = $argv[1] ?? null;
$timeRange = $argv[2] ?? '';   // HH:MM-HH:MM
$date      = $argv[3] ?? date('Y-m-d');
$logDir    = getenv('LOG_DIR') ?: '/www/wwwroot/work.5super9.com/runtime/logs';

if (!$machineId) {
    echo "用法: php scripts/diagnose_machine.php <machine_id> [HH:MM-HH:MM] [YYYY-MM-DD]\n";
    echo "示例: php scripts/diagnose_machine.php 1318 15:50-16:00\n";
    exit(1);
}

// ───────────────── 颜色常量 ─────────────────
const RED    = "\033[0;31m";
const YELLOW = "\033[1;33m";
const GREEN  = "\033[0;32m";
const CYAN   = "\033[0;36m";
const NC     = "\033[0m";

// ───────────────── 忽略的常规 action（时间线中不显示）─────────────────
const SKIP_ACTIONS = ['heartbeat'];

// ───────────────── 时间段过滤 ─────────────────
$startTs = null;
$endTs   = null;
if ($timeRange !== '') {
    [$startStr, $endStr] = explode('-', $timeRange, 2) + ['', ''];
    $startTs = strtotime("$date $startStr");
    $endTs   = strtotime("$date $endStr");
}

function inTimeRange(string $datetime): bool
{
    global $startTs, $endTs;
    if ($startTs === null) {
        return true;
    }
    $ts = strtotime($datetime);
    return $ts !== false && $ts >= $startTs && $ts <= $endTs;
}

// ───────────────── 辅助函数 ─────────────────
function section(string $title): void
{
    echo "\n";
    echo CYAN . "══════════════════════════════════════════" . NC . "\n";
    echo CYAN . "  $title" . NC . "\n";
    echo CYAN . "══════════════════════════════════════════" . NC . "\n";
}

function readLogLines(string $file, string $machineId): array
{
    if (!is_file($file)) {
        return [];
    }
    $lines = [];
    $handle = fopen($file, 'r');
    if (!$handle) {
        return [];
    }
    while (($line = fgets($handle)) !== false) {
        if (str_contains($line, '"machine_id":' . $machineId)) {
            $lines[] = rtrim($line);
        }
    }
    fclose($handle);
    return $lines;
}

function parseJson(string $line): ?array
{
    $d = json_decode($line, true);
    return is_array($d) ? $d : null;
}

function statusPrefix(string $status): string
{
    return match ($status) {
        'error'   => RED    . '[ERR]' . NC,
        'warning' => YELLOW . '[WRN]' . NC,
        default   => GREEN  . '[OK ]' . NC,
    };
}

function trunc(string $s, int $len = 120): string
{
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
}

// ───────────────── 读取 .env（Redis 配置）─────────────────
function readEnv(string $dir): array
{
    $envFile = rtrim($dir, '/') . '/../.env';
    $cfg = [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'db'       => 0,
        'password' => null,
    ];
    // 尝试项目根目录 .env
    $candidates = [
        dirname(__DIR__) . '/.env',
        '/www/wwwroot/work.5super9.com/.env',
    ];
    foreach ($candidates as $f) {
        if (!is_file($f)) {
            continue;
        }
        foreach (file($f) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$k, $v] = explode('=', $line, 2) + ['', ''];
            $v = trim($v, '"\'');
            match ($k) {
                'REDIS_HOST'     => $cfg['host']     = $v,
                'REDIS_PORT'     => $cfg['port']     = (int)$v,
                'REDIS_DB'       => $cfg['db']       = (int)$v,
                'REDIS_PASSWORD' => $cfg['password'] = $v ?: null,
                default          => null,
            };
        }
        break;
    }
    return $cfg;
}

// ───────────────── 文件路径 ─────────────────
$ctrlFile    = "$logDir/machine_ctrl-$date.log";
$lotteryFile = "$logDir/lottery_machine-$date.log";
$defaultFile = "$logDir/default-$date.log";
$stdoutFile  = "$logDir/stdout.log";

// ───────────────── 标题 ─────────────────
echo "\n";
echo GREEN . "==================================================" . NC . "\n";
echo GREEN . "  机台排查  machine_id={$machineId}  日期={$date}" . NC . "\n";
if ($timeRange !== '') {
    echo GREEN . "  时段限制：{$timeRange}" . NC . "\n";
}
echo GREEN . "==================================================" . NC . "\n";

// ══════════════════════════════════════════════════
// [1] 完整事件时间线
// ══════════════════════════════════════════════════
section('[1] 完整事件时间线（machine_ctrl）');
if (!is_file($ctrlFile)) {
    echo "  " . YELLOW . "文件不存在: $ctrlFile" . NC . "\n";
} else {
    $lines = readLogLines($ctrlFile, $machineId);
    $count = 0;
    foreach ($lines as $raw) {
        $d = parseJson($raw);
        if (!$d) {
            echo "  $raw\n";
            continue;
        }
        $dt     = $d['datetime'] ?? '';
        $action = $d['action']   ?? '';
        if (!inTimeRange($dt)) {
            continue;
        }
        if (in_array($action, SKIP_ACTIONS, true)) {
            continue;
        }
        $count++;
        $status = $d['log_status'] ?? 'info';
        $desc   = trunc($d['action_desc'] ?? '');
        $prefix = statusPrefix($status);
        printf("  %s %s  %-28s %s\n", $prefix, $dt, $action, $desc);
    }
    echo "\n  共 $count 条记录\n";
}

// ══════════════════════════════════════════════════
// [2] 异常记录
// ══════════════════════════════════════════════════
section('[2] 异常记录（error + warning）');
if (!is_file($ctrlFile)) {
    echo "  " . YELLOW . "跳过（文件不存在）" . NC . "\n";
} else {
    $lines = readLogLines($ctrlFile, $machineId);
    $found = false;
    foreach ($lines as $raw) {
        $d = parseJson($raw);
        if (!$d) {
            continue;
        }
        $status = $d['log_status'] ?? '';
        if (!in_array($status, ['error', 'warning'], true)) {
            continue;
        }
        $dt = $d['datetime'] ?? '';
        if (!inTimeRange($dt)) {
            continue;
        }
        $found  = true;
        $action = $d['action']      ?? '';
        $desc   = trunc($d['action_desc'] ?? '', 200);
        $prefix = statusPrefix($status);
        echo "  $prefix $dt  $action\n";
        echo "        $desc\n";
    }
    if (!$found) {
        echo "  " . GREEN . "无异常记录" . NC . "\n";
    }
}

// ══════════════════════════════════════════════════
// [3] 下珠指令追踪
// ══════════════════════════════════════════════════
section('[3] 下珠指令追踪（score_to_point）');
if (!is_file($ctrlFile)) {
    echo "  " . YELLOW . "跳过（文件不存在）" . NC . "\n";
} else {
    $lines = readLogLines($ctrlFile, $machineId);
    $cnt   = 0;
    foreach ($lines as $raw) {
        $d = parseJson($raw);
        if (!$d || ($d['action'] ?? '') !== 'score_to_point') {
            continue;
        }
        $dt = $d['datetime'] ?? '';
        if (!inTimeRange($dt)) {
            continue;
        }
        $cnt++;
        $status = $d['log_status']     ?? '';
        $dir    = $d['log_direction']  ?? '';
        $desc   = trunc($d['action_desc'] ?? '');
        echo "  [$status/$dir] $dt  $desc\n";
    }
    if ($cnt === 0) {
        echo "  " . RED . "⚠ 未找到任何 score_to_point 记录（下珠可能从未触发）" . NC . "\n";
    }
}

// ══════════════════════════════════════════════════
// [4] 开奖后处理
// ══════════════════════════════════════════════════
section('[4] 开奖后处理（reward_post_process）');
if (!is_file($ctrlFile)) {
    echo "  " . YELLOW . "跳过（文件不存在）" . NC . "\n";
} else {
    $lines = readLogLines($ctrlFile, $machineId);
    $found = false;
    foreach ($lines as $raw) {
        $d = parseJson($raw);
        if (!$d || ($d['action'] ?? '') !== 'reward_post_process') {
            continue;
        }
        $dt = $d['datetime'] ?? '';
        if (!inTimeRange($dt)) {
            continue;
        }
        $found  = true;
        $status = $d['log_status']    ?? '';
        $desc   = trunc($d['action_desc'] ?? '', 300);
        $prefix = statusPrefix($status);
        echo "  $prefix $dt\n";
        echo "    $desc\n";
    }
    if (!$found) {
        echo "  无记录（新版代码上线后才会有此 action）\n";
    }
}

// ══════════════════════════════════════════════════
// [5] 彩金队列
// ══════════════════════════════════════════════════
section('[5] 彩金队列（lottery_machine）');
if (!is_file($lotteryFile)) {
    echo "  " . YELLOW . "文件不存在: $lotteryFile" . NC . "\n";
} else {
    $handle = fopen($lotteryFile, 'r');
    $found  = false;
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if (!str_contains($line, '"machine_id":' . $machineId)
                && !str_contains($line, $machineId)
            ) {
                continue;
            }
            $d = parseJson(rtrim($line));
            if (!$d) {
                continue;
            }
            $dt = $d['datetime'] ?? ($d['@timestamp'] ?? '');
            if (!inTimeRange($dt)) {
                continue;
            }
            $found   = true;
            $level   = $d['level']   ?? $d['log_level'] ?? '';
            $message = $d['message'] ?? '';
            echo "  [$level] $dt  " . trunc($message) . "\n";
        }
        fclose($handle);
    }
    if (!$found) {
        echo "  无相关记录\n";
    }
}

// ══════════════════════════════════════════════════
// [6] 外层异常
// ══════════════════════════════════════════════════
section('[6] 外层异常（default.log / stdout）');
foreach ([$defaultFile, $stdoutFile] as $logFile) {
    if (!is_file($logFile)) {
        continue;
    }
    $handle  = fopen($logFile, 'r');
    $matches = [];
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if (!str_contains($line, $machineId)) {
                continue;
            }
            $d  = parseJson(rtrim($line));
            $dt = $d['datetime'] ?? '';
            if ($dt !== '' && !inTimeRange($dt)) {
                continue;
            }
            $matches[] = rtrim($line);
            if (count($matches) >= 20) {
                break;
            }
        }
        fclose($handle);
    }
    if ($matches) {
        echo "  " . YELLOW . "── $logFile ──" . NC . "\n";
        foreach ($matches as $m) {
            echo '  ' . trunc($m, 200) . "\n";
        }
    }
}

// ══════════════════════════════════════════════════
// [7] Redis 机台缓存
// ══════════════════════════════════════════════════
section("[7] Redis 机台缓存（machine_cache:$machineId）");
$redisCfg = readEnv($logDir);
$redis = null;
try {
    $redis = new Redis();
    $redis->connect($redisCfg['host'], $redisCfg['port'], 3.0);
    if ($redisCfg['password']) {
        $redis->auth($redisCfg['password']);
    }
    $redis->select($redisCfg['db']);
} catch (Throwable $e) {
    echo "  " . YELLOW . "Redis 连接失败：" . $e->getMessage() . NC . "\n";
    $redis = null;
}

if ($redis) {
    $key  = "machine_cache:$machineId";
    $type = $redis->type($key);
    if ($type === Redis::REDIS_NONE) {
        echo "  未找到 $key\n";
    } elseif ($type === Redis::REDIS_STRING) {
        $raw = $redis->get($key);
        $d   = json_decode($raw, true);
        if (is_array($d)) {
            $fields = ['score', 'reward_status', 'gaming_user_id', 'win_number', 'rush_status', 'bb_status'];
            foreach ($fields as $f) {
                if (array_key_exists($f, $d)) {
                    printf("  %-20s : %s\n", $f, json_encode($d[$f], JSON_UNESCAPED_UNICODE));
                }
            }
            // 其余字段
            foreach ($d as $k => $v) {
                if (!in_array($k, $fields, true)) {
                    printf("  %-20s : %s\n", $k, json_encode($v, JSON_UNESCAPED_UNICODE));
                }
            }
        } else {
            echo "  " . trunc($raw, 500) . "\n";
        }
    } elseif ($type === Redis::REDIS_HASH) {
        $data = $redis->hGetAll($key);
        foreach ($data as $k => $v) {
            printf("  %-20s : %s\n", $k, $v);
        }
    } else {
        echo "  Key 类型：$type（暂不支持展示）\n";
    }
}

// ───────────────── 诊断提示 ─────────────────
echo "\n";
echo GREEN . "════════════════ 排查完成 ════════════════" . NC . "\n";
echo "\n";
echo "诊断提示：\n";
echo "  - [3] score_to_point 无记录   → 下珠指令从未发出（查 [2][4] 找原因）\n";
echo "  - [2] message_handle 报错     → 被外层 catch 捕获，下珠被跳过（旧代码问题）\n";
echo "  - [3] score_to_point 尝试耗尽 → 指令发出但机台未响应（机台离线或故障）\n";
echo "  - [7] score > 0               → 机台缓存中仍有待出珠数，需手动触发弃台\n";
echo "\n";
