<?php
/**
 * 手动补单工具：按订单号查询平台 API，优先走 Redis 路径结算
 *
 * 用法：
 *   php settle_game_record.php <order_no>
 *
 * 支持平台 API 自动查询：
 *   BTG（单订单直查）、ATG/ATG2/ATG3（时间窗口+bettingId过滤）、MT（时间窗口+sn过滤）
 * 其他平台：手动输入 win 金额（"元"，亏损为负值）
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use app\model\PlayGameRecord;
use app\service\game\ATGServiceInterface;
use app\service\game\ATG2ServiceInterface;
use app\service\game\ATG3ServiceInterface;
use app\service\game\BTGServiceInterface;
use app\service\game\MTServiceInterface;
use support\Db;
use support\Redis;

// ─────────────────────────────────────────────
// 辅助函数
// ─────────────────────────────────────────────
function hr(string $title = ''): void
{
    $line = str_repeat('═', 50);
    if ($title === '') {
        echo $line . "\n";
    } else {
        $pad = max(0, 50 - mb_strlen($title) - 2);
        echo '══ ' . $title . ' ' . str_repeat('═', $pad) . "\n";
    }
}

function step(int $n, int $total, string $title): void
{
    echo "\n";
    hr("STEP {$n}/{$total}  {$title}");
}

function line(string $msg = ''): void  { echo $msg . "\n"; }
function info(string $msg): void       { echo "  [INFO]  {$msg}\n"; }
function warn(string $msg): void       { echo "  [WARN]  {$msg}\n"; }
function success(string $msg): void    { echo "  [OK]    {$msg}\n"; }
function fail(string $msg): void       { echo "  [ERROR] {$msg}\n"; }

function ask(string $prompt): string
{
    echo "  {$prompt}";
    return trim(fgets(STDIN));
}

function confirm(string $prompt): bool
{
    $ans = ask($prompt . ' [y/N] ');
    return strtolower($ans) === 'y';
}

function settlementLabel(int $status): string
{
    return match ($status) {
        PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED => '未结算(0)',
        PlayGameRecord::SETTLEMENT_STATUS_SETTLED   => '已结算(1)',
        PlayGameRecord::SETTLEMENT_STATUS_CANCELLED => '已取消(2)',
        PlayGameRecord::SETTLEMENT_STATUS_CONFIRM   => '确认(3)',
        default => "未知({$status})",
    };
}

// ─────────────────────────────────────────────
// 参数检查
// ─────────────────────────────────────────────
$orderNo = $argv[1] ?? null;
if (!$orderNo) {
    line("用法: php settle_game_record.php <order_no>");
    exit(1);
}

// ══════════════════════════════════════════════
// STEP 1/4  查询数据库订单信息
// ══════════════════════════════════════════════
step(1, 4, '查询数据库订单信息');

/** @var PlayGameRecord $record */
$record = PlayGameRecord::query()
    ->with('gamePlatform')
    ->where('order_no', $orderNo)
    ->first();

if (!$record) {
    fail("订单不存在: {$orderNo}");
    exit(1);
}

$platformCode = $record->gamePlatform->code ?? '?';

line();
line("  订单号     : {$record->order_no}");
line("  平台       : {$platformCode}（ID={$record->platform_id}）");
line("  玩家 ID    : {$record->player_id}");
line("  游戏       : {$record->game_code}");
line("  下注（元） : {$record->bet}");
line("  赢（元）   : {$record->win}");
line("  输赢(diff) : {$record->diff}");
line("  结算状态   : " . settlementLabel((int)$record->settlement_status));
line("  订单时间   : {$record->order_time}");
line();

if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
    warn("该订单已结算，无需处理。");
    exit(0);
}
if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
    warn("该订单已取消，无需处理。");
    exit(0);
}

if (!confirm("订单信息确认无误，继续？")) {
    warn("已退出，未做任何修改。");
    exit(0);
}

// ══════════════════════════════════════════════
// STEP 2/4  检查 Redis 状态
// ══════════════════════════════════════════════
step(2, 4, '检查 Redis 状态');

$redis       = Redis::connection('work')->client();
$redisKey    = "game:record:bet:{$platformCode}:{$orderNo}";
$syncQueue   = 'game:sync:queue';
$redisRecord = $redis->hGetAll($redisKey);
$inRedis     = !empty($redisRecord);

line();
if ($inRedis) {
    $redisStatus = $redisRecord['settlement_status'] ?? '?';
    info("Redis 中找到该订单");
    line("  key               : {$redisKey}");
    line("  settlement_status : {$redisStatus}");
    line("  win               : " . ($redisRecord['win']  ?? '-') . " 分");
    line("  diff              : " . ($redisRecord['diff'] ?? '-') . " 分");
    line("  status            : " . ($redisRecord['status'] ?? '-'));
    line();

    if ((int)$redisStatus === 1) {
        warn("Redis 已标记为已结算，等待 SyncWorker 同步到 MySQL，通常几秒内完成。");
        exit(0);
    }

    info("写入路径：→ 更新 Redis → SyncWorker 自动同步 MySQL");
} else {
    info("Redis 中未找到该订单（已过期或已同步）");
    line("  key : {$redisKey}");
    line();
    info("写入路径：→ 直接更新 MySQL");
}

line();
if (!confirm("Redis 状态确认，继续查询平台 API？")) {
    warn("已退出，未做任何修改。");
    exit(0);
}

// ══════════════════════════════════════════════
// STEP 3/4  平台 API 查询 / 手动输入
// ══════════════════════════════════════════════
step(3, 4, '平台 API 查询');

$apiWin  = null;
$apiDiff = null;
$apiRaw  = null;

// ── BTG ──────────────────────────────────────
if ($platformCode === 'BTG') {
    info("BTG：调用 getTransactionStatus ...");
    line();
    try {
        $service = new BTGServiceInterface();
        $res     = $service->getTransactionStatus($record->order_no);
        $apiRaw  = $res;

        line("  ── 原始响应 ─────────────────────────────");
        line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        line("  ─────────────────────────────────────────");
        line();

        $txData = $res['data'] ?? [];
        $apiWin = isset($txData['win_amount']) ? (float)$txData['win_amount']
                : (isset($txData['win'])        ? (float)$txData['win'] : null);
        $apiBet = isset($txData['bet_amount'])  ? (float)$txData['bet_amount']
                : (float)$record->bet;
        if ($apiWin !== null) {
            $apiDiff = round($apiWin - $apiBet, 2);
            info("解析结果 → win={$apiWin} 元  bet={$apiBet} 元  diff={$apiDiff} 元");
        } else {
            warn("响应中未找到 win 字段，请手动输入。");
        }
    } catch (\Throwable $e) {
        warn("BTG API 调用失败: " . $e->getMessage());
    }

// ── ATG / ATG2 / ATG3 ────────────────────────
} elseif (in_array($platformCode, ['ATG', 'ATG2', 'ATG3'])) {
    info("{$platformCode}：按 bettingId 在订单时间窗口（±2.5 分钟）内查询 ...");
    line();
    try {
        $service = match ($platformCode) {
            'ATG2'  => new ATG2ServiceInterface(),
            'ATG3'  => new ATG3ServiceInterface(),
            default => new ATGServiceInterface(),
        };
        $orderTs = strtotime($record->order_time ?? $record->created_at ?? 'now');
        $txData  = $service->queryTransactionByBettingId($record->order_no, $orderTs);
        $apiRaw  = $txData;

        if ($txData) {
            line("  ── 原始响应 ─────────────────────────────");
            line(json_encode($txData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            line("  ─────────────────────────────────────────");
            line();

            $txStatus = $txData['status'] ?? '';
            if ($txStatus !== 'close') {
                warn("注单状态为 [{$txStatus}]，尚未结算，无法自动补单。");
            } else {
                $apiBet  = (float)($txData['validbet'] ?? $txData['totalstake'] ?? $record->bet);
                $apiDiff = (float)($txData['winloseamount'] ?? 0);
                $apiWin  = round($apiBet + $apiDiff, 4);
                info("解析结果 → win={$apiWin} 元  bet={$apiBet} 元  diff={$apiDiff} 元");
            }
        } else {
            warn("时间窗口内未找到 bettingId=[{$record->order_no}]，请检查订单时间或手动输入。");
        }
    } catch (\Throwable $e) {
        warn("{$platformCode} API 调用失败: " . $e->getMessage());
    }

// ── MT ───────────────────────────────────────
} elseif ($platformCode === 'MT') {
    info("MT：按 sn 在订单时间窗口（±5 分钟）内查询 ...");
    line();
    try {
        $service = new MTServiceInterface();
        $orderTs = strtotime($record->order_time ?? $record->created_at ?? 'now');
        $txData  = $service->queryTransactionBySn($record->order_no, $orderTs);
        $apiRaw  = $txData;

        if ($txData) {
            line("  ── 原始响应 ─────────────────────────────");
            line(json_encode($txData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            line("  ─────────────────────────────────────────");
            line();

            $txStatus = (int)($txData['status'] ?? -1);
            if ($txStatus === 0) {
                warn("MT 注单状态为 [待结算(0)]，尚未结算，无法自动补单。");
            } else {
                // MT: win = win_money（中奖额，不含本金），diff = profit（输赢净额）
                $apiWin  = (float)($txData['win_money'] ?? 0);
                $apiDiff = (float)($txData['profit']    ?? 0);
                info("解析结果 → win={$apiWin} 元  diff={$apiDiff} 元");
            }
        } else {
            warn("时间窗口内未找到 sn=[{$record->order_no}]，请检查订单时间或手动输入。");
        }
    } catch (\Throwable $e) {
        warn("MT API 调用失败: " . $e->getMessage());
    }

// ── 其他平台 ─────────────────────────────────
} else {
    warn("平台 [{$platformCode}] 暂未接入自动查询，请到平台后台查询该笔订单后手动输入。");
}

// ── 确认 API 结果或手动输入 ──────────────────
line();
if ($apiWin !== null) {
    if (!confirm("使用上方 API 结果（win={$apiWin} 元, diff={$apiDiff} 元）？")) {
        info("不使用 API 结果，切换为手动输入。");
        $apiWin = null;
    }
}

if ($apiWin === null) {
    line();
    $winInput = ask("请输入 win（派彩金额，元；亏损为负；直接回车=0）: ");
    $apiWin   = $winInput === '' ? 0.0 : (float)$winInput;
    $apiDiff  = round($apiWin - (float)$record->bet, 2);
    info("手动输入 → win={$apiWin} 元  diff = win({$apiWin}) - bet({$record->bet}) = {$apiDiff} 元");
}

// ══════════════════════════════════════════════
// STEP 4/4  确认写入
// ══════════════════════════════════════════════
step(4, 4, '确认写入');

line();
line("  订单号   : {$record->order_no}");
line("  平台     : {$platformCode}");
line("  写入路径 : " . ($inRedis ? "Redis → SyncWorker → MySQL" : "直接写 MySQL"));
line("  win      : {$apiWin} 元");
line("  diff     : {$apiDiff} 元");
line();

if (!confirm("确认执行写入？")) {
    warn("已取消，未做任何修改。");
    exit(0);
}

// ──────────────────────────────────────────────
// 执行写入
// ──────────────────────────────────────────────
$now = date('Y-m-d H:i:s');

if ($inRedis) {
    // 路径 A：更新 Redis，由 SyncWorker 自动同步到 MySQL
    // Redis 存"分"（整数），MySQL 存"元"
    $winCents  = (int)round($apiWin  * 100);
    $diffCents = (int)round($apiDiff * 100);

    $updates = [
        'win'                => $winCents,
        'diff'               => $diffCents,
        'settlement_status'  => 1,
        'settle_type'        => 'settle',
        'settle_time'        => time(),
        'platform_action_at' => $now,
        'status'             => 'pending',
    ];
    if ($apiRaw !== null) {
        $updates['action_data'] = json_encode($apiRaw, JSON_UNESCAPED_UNICODE);
    }

    $redis->hMSet($redisKey, $updates);
    $redis->zAdd($syncQueue, time(), $redisKey);

    line();
    success("Redis 已更新（win={$winCents} 分, diff={$diffCents} 分）");
    success("SyncWorker 将在数秒内同步到 MySQL，可通过后台刷新确认。");

} else {
    // 路径 B：Redis 已过期，直接写 MySQL
    try {
        Db::beginTransaction();

        $updateData = [
            'win'                => $apiWin,
            'diff'               => $apiDiff,
            'settlement_status'  => PlayGameRecord::SETTLEMENT_STATUS_SETTLED,
            'action_at'          => $now,
            'platform_action_at' => $now,
            'is_rebet'           => 0,
        ];
        if ($apiRaw !== null) {
            $updateData['action_data'] = json_encode($apiRaw, JSON_UNESCAPED_UNICODE);
        }

        PlayGameRecord::query()->where('id', $record->id)->update($updateData);

        Db::commit();
        line();
        success("MySQL 已直接更新：win={$apiWin} 元, diff={$apiDiff} 元");
    } catch (\Throwable $e) {
        Db::rollBack();
        fail("写库失败: " . $e->getMessage());
        exit(1);
    }
}

line();
hr();
