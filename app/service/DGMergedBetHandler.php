<?php

namespace app\service;

use support\Redis;
use support\Log;

/**
 * DG 平台多次下注合并处理器
 *
 * 背景：
 * - DG 平台同一局游戏（同一个 ticketId）会有多次下注请求
 * - 每次请求有唯一的转账流水号（data）
 * - 需要合并同一 ticketId 的所有下注，同时保证转账流水号的唯一性
 *
 * 数据结构：
 * - 主订单：ticketId（聚合所有下注）
 * - 转账流水号：data（防止重复处理）
 */
class DGMergedBetHandler
{
    /**
     * 处理 DG 多次下注合并
     *
     * @param int $playerId 玩家ID
     * @param array $params 请求参数
     * @param int $platformId 平台ID
     * @return array ['ok' => 1|0, 'balance' => float, 'error' => string|null, 'is_first_bet' => bool]
     */
    public static function handleMergedBet(int $playerId, array $params, int $platformId): array
    {
        $ticketId = (string)($params['ticketId'] ?? '');
        $data = (string)($params['data'] ?? '');
        $amount = abs($params['member']['amount']);
        $detail = json_decode($params['detail'], true);
        $type = $params['type'];

        Log::channel('dg_server')->info('[DGMergedBetHandler] 开始处理', [
            'player_id' => $playerId,
            'ticketId' => $ticketId,
            'data' => $data,
            'amount' => $amount,
            'type' => $type
        ]);

        // 1. 幂等性检查：转账流水号是否已处理
        $transferKey = "dg:transfer:{$data}";
        if (Redis::exists($transferKey)) {
            $currentBalance = self::getPlayerBalance($playerId);
            Log::channel('dg_server')->info('DG转账流水号重复', [
                'data' => $data,
                'ticketId' => $ticketId,
                'balance' => $currentBalance
            ]);
            return [
                'ok' => 0,
                'error' => 'duplicate_transfer',
                'balance' => $currentBalance,
                'is_first_bet' => false
            ];
        }

        // 2. 检查主订单是否存在
        $mainOrderKey = "game:record:bet:DG:{$ticketId}";
        $isFirstBet = !Redis::exists($mainOrderKey);

        // 3. 获取余额
        $balanceKey = "wallet:balance:{$playerId}";
        $currentBalance = (float)(Redis::get($balanceKey) ?? 0);

        // 4. 余额检查
        if ($currentBalance < $amount) {
            return [
                'ok' => 0,
                'error' => 'insufficient_balance',
                'balance' => $currentBalance,
                'is_first_bet' => $isFirstBet
            ];
        }

        // 5. 执行 Lua 原子操作（合并下注）
        $result = self::executeAtomicMergedBet(
            $playerId,
            $ticketId,
            $data,
            $amount,
            $detail,
            $platformId,
            $type,
            $isFirstBet
        );

        return $result;
    }

    /**
     * Lua 原子操作：合并下注
     */
    private static function executeAtomicMergedBet(
        int $playerId,
        string $ticketId,
        string $data,
        float $amount,
        array $detail,
        int $platformId,
        int $type,
        bool $isFirstBet
    ): array {
        $lua = <<<'LUA'
-- KEYS[1] = 余额 Key (wallet:balance:{player_id})
-- KEYS[2] = 主订单 Key (game:record:bet:DG:{ticketId})
-- KEYS[3] = 转账流水号 Key (dg:transfer:{data})
-- KEYS[4] = 转账记录集合 Key (dg:transfers:{ticketId})
-- KEYS[5] = 同步队列 Key (game:sync:queue)

-- ARGV[1] = 玩家ID
-- ARGV[2] = 下注金额（增量）
-- ARGV[3] = 平台ID
-- ARGV[4] = 游戏代码
-- ARGV[5] = 交易类型
-- ARGV[6] = 当前时间戳
-- ARGV[7] = 创建时间字符串
-- ARGV[8] = 转账流水号（data）
-- ARGV[9] = 是否首次下注（1=是，0=否）
-- ARGV[10] = 订单号（ticketId）
-- ARGV[11] = betPoints（累计值，从detail中提取）

local balanceKey = KEYS[1]
local mainOrderKey = KEYS[2]
local transferKey = KEYS[3]
local transfersSetKey = KEYS[4]
local syncQueueKey = KEYS[5]

local playerId = ARGV[1]
local betAmount = tonumber(ARGV[2]) or 0
local platformId = ARGV[3]
local gameCode = ARGV[4]
local transactionType = ARGV[5]
local timestamp = ARGV[6]
local createdAt = ARGV[7]
local transferNo = ARGV[8]
local isFirstBet = ARGV[9] == '1'
local ticketId = ARGV[10]
local betPoints = tonumber(ARGV[11]) or 0

-- 1. 再次检查转账流水号（双重保险）
if redis.call('EXISTS', transferKey) == 1 then
    local currentBalance = tonumber(redis.call('GET', balanceKey)) or 0
    return cjson.encode({ok = 0, error = 'duplicate_transfer', balance = currentBalance})
end

-- 2. 获取当前余额
local currentBalance = tonumber(redis.call('GET', balanceKey)) or 0

-- 3. 余额检查
if currentBalance < betAmount then
    return cjson.encode({ok = 0, error = 'insufficient_balance', balance = currentBalance})
end

-- 4. 扣款
local newBalance = currentBalance - betAmount
redis.call('SETEX', balanceKey, 3600, newBalance)

-- 5. 记录转账流水号（防止重复）
redis.call('SETEX', transferKey, 3600, 1)

-- 6. 将转账流水号添加到集合（用于查询和统计）
redis.call('SADD', transfersSetKey, transferNo)
redis.call('EXPIRE', transfersSetKey, 3600)

-- 7. 处理主订单
if isFirstBet then
    -- 首次下注：创建主订单
    redis.call('HMSET', mainOrderKey,
        'platform', 'DG',
        'order_no', ticketId,
        'player_id', playerId,
        'platform_id', platformId,
        'amount', betAmount,
        'game_code', gameCode,
        'transaction_type', transactionType,
        'bet_time', timestamp,
        'status', 'pending',
        'settlement_status', 0,
        'win', 0,
        'diff', 0,
        'bet_count', 1,
        'bet_points', betPoints,
        'created_at', createdAt
    )
    redis.call('EXPIRE', mainOrderKey, 3600)

    -- 加入同步队列
    redis.call('ZADD', syncQueueKey, timestamp, mainOrderKey)
else
    -- 追加下注：累加金额
    local currentAmount = tonumber(redis.call('HGET', mainOrderKey, 'amount') or 0)
    local currentBetCount = tonumber(redis.call('HGET', mainOrderKey, 'bet_count') or 0)

    redis.call('HMSET', mainOrderKey,
        'amount', currentAmount + betAmount,
        'bet_count', currentBetCount + 1,
        'bet_points', betPoints,
        'status', 'pending'  -- ✅ 重置状态，确保累加后的金额能同步到数据库
    )

    -- 更新同步队列优先级
    redis.call('ZADD', syncQueueKey, timestamp, mainOrderKey)
end

-- 8. 返回成功
return cjson.encode({
    ok = 1,
    balance = newBalance,
    old_balance = currentBalance,
    is_first_bet = isFirstBet,
    total_amount = isFirstBet and betAmount or (tonumber(redis.call('HGET', mainOrderKey, 'amount')) or betAmount)
})
LUA;

        try {
            $keys = [
                "wallet:balance:{$playerId}",
                "game:record:bet:DG:{$ticketId}",
                "dg:transfer:{$data}",
                "dg:transfers:{$ticketId}",
                "game:sync:queue"
            ];

            $args = [
                $playerId,
                $amount,
                $platformId,
                $detail['gameId'] ?? '',
                $type == 3 ? \app\Constants\TransactionType::BET_ADJUST : \app\Constants\TransactionType::BET,
                time(),
                date('Y-m-d H:i:s'),
                $data,
                $isFirstBet ? '1' : '0',
                $ticketId,
                $detail['betPoints'] ?? 0
            ];

            Log::channel('dg_server')->info('[DGMergedBetHandler] 准备执行Lua脚本', [
                'ticketId' => $ticketId,
                'data' => $data,
                'keys' => $keys,
                'args' => $args
            ]);

            // ✅ 修复：Redis::eval 参数应该是展开的，不是数组
            $result = Redis::eval(
                $lua,
                count($keys),  // KEYS 数量
                $keys[0], $keys[1], $keys[2], $keys[3], $keys[4],  // KEYS
                ...$args  // ARGV（展开数组）
            );

            Log::channel('dg_server')->info('[DGMergedBetHandler] Lua脚本执行完成', [
                'ticketId' => $ticketId,
                'data' => $data,
                'raw_result' => $result
            ]);
            $decoded = json_decode($result, true);

            // 记录审计日志
            Log::channel('dg_server')->info('[DG合并下注] Lua执行结果', [
                'ticketId' => $ticketId,
                'data' => $data,
                'amount' => $amount,
                'is_first_bet' => $isFirstBet,
                'result' => $decoded
            ]);

            return $decoded;

        } catch (\Exception $e) {
            Log::error('[DG合并下注] Lua执行失败', [
                'error' => $e->getMessage(),
                'ticketId' => $ticketId,
                'data' => $data
            ]);

            return [
                'ok' => 0,
                'error' => 'lua_execution_failed',
                'balance' => self::getPlayerBalance($playerId),
                'is_first_bet' => $isFirstBet
            ];
        }
    }

    /**
     * 获取玩家余额
     */
    private static function getPlayerBalance(int $playerId): float
    {
        $balanceKey = "wallet:balance:{$playerId}";
        return (float)(Redis::get($balanceKey) ?? 0);
    }

    /**
     * 获取注单的所有转账流水号
     *
     * @param string $ticketId 注单号
     * @return array 转账流水号列表
     */
    public static function getTransfersByTicketId(string $ticketId): array
    {
        $transfersSetKey = "dg:transfers:{$ticketId}";
        return Redis::smembers($transfersSetKey) ?: [];
    }

    /**
     * 获取注单的累计金额
     *
     * @param string $ticketId 注单号
     * @return float 累计金额
     */
    public static function getTotalAmount(string $ticketId): float
    {
        $mainOrderKey = "game:record:bet:DG:{$ticketId}";
        return (float)(Redis::hget($mainOrderKey, 'amount') ?? 0);
    }

    /**
     * 获取注单的下注次数
     *
     * @param string $ticketId 注单号
     * @return int 下注次数
     */
    public static function getBetCount(string $ticketId): int
    {
        $mainOrderKey = "game:record:bet:DG:{$ticketId}";
        return (int)(Redis::hget($mainOrderKey, 'bet_count') ?? 0);
    }
}
