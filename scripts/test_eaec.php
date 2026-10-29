#!/usr/bin/env php
<?php
/**
 * 测试脚本：直接发送 EA EC 清旗标，再发 EA D4 查询机台状态
 *
 * 用法：
 *   php scripts/test_eaec.php <机台编号>
 *
 * 说明：
 *   - 直接通过 GatewayWorker\Lib\Gateway 发送原始 hex 指令，不经过 SongOfflineSlot 服务
 *   - EA EC（eaec）：清除开/洗分旗标，机台不回复
 *   - EA D4（ead4）：查询机台状态，机台回复当前 open_status / wash_status
 *   - 结果从 Redis（machine_tcp_data_cache_{id}_last_status_fields）读取
 */

define('ROOT', dirname(__DIR__));

require ROOT . '/vendor/autoload.php';

// ─── 加载 .env ────────────────────────────────────────────────────────────────
if (class_exists(\Dotenv\Dotenv::class) && file_exists(ROOT . '/.env')) {
    \Dotenv\Dotenv::createImmutable(ROOT)->safeLoad();
}

// ─── 参数 ─────────────────────────────────────────────────────────────────────
$machineCode = trim($argv[1] ?? '');
if ($machineCode === '') {
    echo "用法: php scripts/test_eaec.php <机台编号>\n";
    echo "示例: php scripts/test_eaec.php S001\n";
    exit(1);
}

// ─── 数据库（PDO，直接读 machine 表）────────────────────────────────────────
$dbHost   = $_ENV['DB_HOST']     ?? $_ENV['DB_READ_HOST']     ?? '127.0.0.1';
$dbPort   = $_ENV['DB_PORT']     ?? 3306;
$dbName   = $_ENV['DB_DATABASE'] ?? 'forge';
$dbUser   = $_ENV['DB_USERNAME'] ?? $_ENV['DB_READ_USERNAME'] ?? 'forge';
$dbPass   = $_ENV['DB_PASSWORD'] ?? $_ENV['DB_READ_PASSWORD'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
} catch (PDOException $e) {
    echo "数据库连接失败: " . $e->getMessage() . "\n";
    exit(1);
}

$stmt = $pdo->prepare('SELECT id, code, domain, port, type FROM machine WHERE code = ? LIMIT 1');
$stmt->execute([$machineCode]);
$machine = $stmt->fetch(PDO::FETCH_OBJ);

if (!$machine) {
    echo "机台不存在: {$machineCode}\n";
    exit(1);
}

echo "机台  : [{$machine->id}] {$machine->code}\n";
echo "连接  : {$machine->domain}:{$machine->port}\n";
echo str_repeat('─', 50) . "\n";

// ─── GatewayClient ────────────────────────────────────────────────────────────
\GatewayWorker\Lib\Gateway::$registerAddress = '127.0.0.1:1236';
$uid = $machine->domain . ':' . $machine->port;

if (!\GatewayWorker\Lib\Gateway::isUidOnline($uid)) {
    echo "错误：机台不在线 (uid={$uid})\n";
    exit(1);
}
echo "连线  : 在线 ✓\n";

// ─── Redis ────────────────────────────────────────────────────────────────────
$redis = new Redis();
$redis->connect(
    $_ENV['REDIS_HOST'] ?? '127.0.0.1',
    (int)($_ENV['REDIS_PORT'] ?? 6379),
    5
);
$redisPwd = $_ENV['REDIS_PASSWORD'] ?? null;
if ($redisPwd) {
    $redis->auth($redisPwd);
}
$redis->select((int)($_ENV['REDIS_DB'] ?? 0));

$cacheDataKey    = 'machine_tcp_data_cache_' . $machine->id;
$fieldsKey       = $cacheDataKey . '_last_status_fields';
$rawKey          = $cacheDataKey . '_last_status_raw';

// ─── 步骤 1：发送 EA EC ───────────────────────────────────────────────────────
echo "\n[1] 发送 EA EC (eaec) ...\n";
\GatewayWorker\Lib\Gateway::sendToUid($uid, hex2bin('eaec'));
echo "    已发送（机台不回复，等待 500ms）\n";
usleep(500000);

// ─── 步骤 2：发送 EA D4 查询状态 ─────────────────────────────────────────────
echo "\n[2] 发送 EA D4 (ead4) ...\n";
$redis->del($fieldsKey);
$redis->del($rawKey);
usleep(50000); // 50ms：让删除前已在途的心跳 D4 回复先抵达并被丢弃，避免污染
\GatewayWorker\Lib\Gateway::sendToUid($uid, hex2bin('ead4'));
echo "    已发送，等待机台回复（最多 3 秒）...\n";

// ─── 步骤 3：轮询 Redis 等待回复 ─────────────────────────────────────────────
$waited   = 0;
$maxWait  = 3000000; // 3s
$interval = 100000;  // 100ms
$fields   = null;
$rawHex   = null;

// Symfony Cache (RedisAdapter) 用 PHP serialize() 存储值，直接 get() 取到的是序列化字节
// 需要先 unserialize() 才能拿到原始 JSON 字符串
function cacheGet(Redis $r, string $key): mixed
{
    $raw = $r->get($key);
    if ($raw === false || $raw === null) {
        return null;
    }
    $decoded = @unserialize($raw);
    // unserialize 失败（不是 PHP 序列化格式）则直接返回原始值
    return ($decoded === false && $raw !== 'b:0;') ? $raw : $decoded;
}

while ($waited < $maxWait) {
    usleep($interval);
    $waited += $interval;
    $val = cacheGet($redis, $fieldsKey);
    if ($val !== null) {
        $fields = json_decode($val, true) ?: [];
        $rawHex = cacheGet($redis, $rawKey) ?? '(无)';
        break;
    }
}

// ─── 输出结果 ─────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";

if ($fields === null) {
    echo "❌ 超时：3 秒内未收到 EA D4 回复\n";
    echo "   可能原因：机台未在线、或机台未响应 EA D4 指令\n";
    exit(1);
}

$statusDesc = [
    'd0' => 'D0 闲置',  'd2' => 'D2 开分完成',  'd3' => 'D3 开分中',
    'd5' => 'D5 闲置',  'd6' => 'D6 洗分完成',  'd7' => 'D7 洗分中',
];

$openStatus = $fields['open_status'] ?? '?';
$washStatus = $fields['wash_status'] ?? '?';
$retry      = (int)($fields['retry_count'] ?? 0);

// 若 _last_status_fields 解析不到状态（Redis 序列化格式不兼容），直接从原始 hex 解析
// 原始 hex 格式: a7 <open_status> 00 <retry> 00 00 <wash_status> ...
if (($openStatus === '?' || $washStatus === '?') && is_string($rawHex) && strlen($rawHex) > 10) {
    // 去掉可能的非 hex 前缀字节（如 Symfony 缓存 tag 字节）
    $cleanHex = preg_replace('/^[^a-fA-F0-9]+/', '', $rawHex);
    // 确保以 a7 开头
    if (str_starts_with(strtolower($cleanHex), 'a7') && strlen($cleanHex) >= 44) {
        $openStatus = strtolower(substr($cleanHex, 2, 2));   // byte 1
        $retry      = hexdec(substr($cleanHex, 6, 2));        // byte 3
        $washStatus = strtolower(substr($cleanHex, 12, 2));   // byte 6
    }
}

$openDesc   = $statusDesc[$openStatus] ?? $openStatus;
$washDesc   = $statusDesc[$washStatus] ?? $washStatus;

echo "EA D4 回复：\n";
echo "  开分状态 : {$openDesc}\n";
echo "  洗分状态 : {$washDesc}\n";
echo "  回补次数 : {$retry}\n";
echo "  原始字节 : {$rawHex}\n";

if ($openStatus === 'd0' && $washStatus === 'd5') {
    echo "\n✅ EA EC 生效，旗标已清除，机台就绪\n";
} elseif ($openStatus === 'd3' || $washStatus === 'd7') {
    echo "\n⚠️  机台正在执行操作中（{$openDesc} / {$washDesc}），EA EC 无法清除\n";
} else {
    echo "\n❌ EA EC 未清除旗标，机台仍为 开分={$openDesc} / 洗分={$washDesc}\n";
    echo "   建议：再发一次 EA D4 确认是否 Read-to-Clear（第一次 D4 可能是触发清除，第二次才会显示 D0/D5）\n";
}
