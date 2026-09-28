<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameRecordCacheService;
use app\service\RedisLuaScripts;
use app\service\WalletService;
use Exception;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;

/**
 * T9电子平台 V2 单一钱包控制器
 *
 * ============================================================
 * V2 升级变更说明（对接日期：2026-09-23）
 * ============================================================
 * 路由：/single-wallet/t9-integration/*
 *       （替换 V1：/single-wallet/tnine-solt-channel/SeamlessGameHub/*）
 *
 * 响应格式变更：
 *   V1: {"resultCode": "OK",  "data": {...}}
 *   V2: {"statusCode": 0,     "data": {...}}
 *
 * 状态码变更：
 *   V1: 0=success, 1=error, 3=sign_error, 108=insufficient, 110=not_found, 116=player_not_exist
 *   V2: 0=success, 101=insufficient, 102=duplicate, 204=player_not_found, 206=param_error, 210=platform_reject
 *
 * 字段变更：
 *   winlose → payoutAmount（语义相同：净盈亏，值等同）
 *   betInfoData[Type][GameCode] → betInfo[gameCategory][gameCode]（key 命名变更）
 *
 * 新增字段：
 *   isFinal  - 是否最终交易（false = Free Spin 中间态，不触发彩金/打码量）
 *   betKind  - 注单类型（1=普通, 2=活动, 3=免费）
 *   gameCategory - 游戏类别（Slots / Fish 等）
 *   provider - 游戏供应商
 *
 * 新增接口：
 *   POST /bet              - 独立下注（V1 无此接口）
 *   POST /settle           - 独立结算（V1 无此接口）
 *   POST /modify-game-order - 修改订单（V1 无此接口）
 *
 * V1 保留：app/wallet/controller/game/TNineSlotGameController.php
 * ============================================================
 */
class TNineSlotV2GameController
{
    use TelegramAlertTrait;

    // ==================== V2 状态码 ====================
    public const CODE_SUCCESS = 0;    // 成功
    public const CODE_INSUFFICIENT = 101;  // 余额不足
    public const CODE_DUPLICATE = 102;  // 重复交易
    public const CODE_PLAYER_NOT_FOUND = 204; // 玩家不存在
    public const CODE_PARAM_ERROR = 206;  // 参数错误
    public const CODE_PLATFORM_REJECT = 210;  // 平台业务拒绝（2026-09-01 新增）

    // ==================== Redis Key ====================
    // 独立 bet/settle 流程的订单映射：gameOrderNumber → bet transactionId
    private const BET_MAPPING_PREFIX = 't9slot:v2:bet_mapping:';
    private const BET_MAPPING_TTL = 86400; // 24小时（一局游戏通常数分钟内完成）

    // ModifyGameOrder 幂等锁
    private const MODIFY_LOCK_PREFIX = 't9slot:v2:modify_lock:';
    private const MODIFY_LOCK_TTL = 86400; // 24小时

    private GameServiceInterface|SingleWalletServiceInterface $service;
    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_TNINE_SLOT_V2);
        $this->logger = Log::channel('tnine_slot_server');
    }

    // ================================================================
    // 接口 1：余额查询
    // V1 对应：TNineSlotGameController::balance()
    // 变更：响应格式 resultCode→statusCode
    // ================================================================

    public function balance(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('[V2] balance 请求', ['params' => $params]);

            $player = $this->resolvePlayer($params['gameAccount'] ?? '');
            if (!$player) {
                return $this->v2Error(self::CODE_PLAYER_NOT_FOUND);
            }

            $this->service->player = $player;
            $balance = $this->service->balance();

            return $this->v2Success(['balance' => $balance]);
        } catch (Exception $e) {
            Log::error('[TNineSlot V2] balance failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('TNINE_SLOT', '[V2] 余额查询异常', $e, ['params' => $request->post()]);
            return $this->v2Error(self::CODE_PLATFORM_REJECT);
        }
    }

    // ================================================================
    // 接口 2：下注+立即结算（合并）
    // V1 对应：TNineSlotGameController::bet()
    // 变更：
    //   - winlose → payoutAmount（值语义相同）
    //   - gameCode 提取路径变更（见 extractGameCodeV2）
    //   - 新增 isFinal / betKind 字段存入 Redis
    //   - 重复交易返回 102（V1 返回 0 幂等成功）
    //   ⚠️ 注意：单一钱包模式下 102 的行为需与 T9 确认（T9 是否会重试？）
    // ================================================================

    public function betAndSettle(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('[V2] betAndSettle 请求', ['params' => $params]);

            $player = $this->resolvePlayer($params['gameAccount'] ?? '');
            if (!$player) {
                return $this->v2Error(self::CODE_PLAYER_NOT_FOUND);
            }

            $this->service->player = $player;

            $orderNo = (string)($params['transactionId'] ?? '');
            $gameOrderNo = (string)($params['gameOrderNumber'] ?? '');
            $betAmount = (float)($params['betAmount'] ?? 0);
            $payoutAmount = (float)($params['payoutAmount'] ?? 0); // V2: payoutAmount = V1: winlose
            $actualWin = bcadd($betAmount, $payoutAmount, 2);   // 实际派彩 = bet + 净盈亏
            $isFinal = (bool)($params['isFinal'] ?? true);
            $betKind = (int)($params['betKind'] ?? 1);        // 1=普通 2=活动 3=免费

            if (!$orderNo) {
                return $this->v2Error(self::CODE_PARAM_ERROR);
            }

            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->v2Error(self::CODE_PLATFORM_REJECT);
            }

            $gameCode = $this->extractGameCodeV2($params);

            // --- 下注 ---
            $betLuaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $betAmount,
                'game_code' => $gameCode,
                'transaction_type' => TransactionType::BET,
                'original_data' => $params,
            ];

            validateLuaScriptParams($betLuaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $betResult = RedisLuaScripts::atomicBet($player->id, 'T9SLOT', $betLuaParams);
            logLuaScriptCall('bet', 'T9SLOT', $player->id, $betLuaParams);

            if ($betResult['ok'] === 0) {
                if ($betResult['error'] === 'duplicate_order') {
                    $this->logger->info('[V2] betAndSettle 重复交易', ['order_no' => $orderNo]);
                    return $this->v2Error(self::CODE_DUPLICATE);
                }
                if ($betResult['error'] === 'insufficient_balance') {
                    return $this->v2Error(self::CODE_INSUFFICIENT);
                }
                // 未知错误兜底：不能继续执行 settle（会在未扣注的情况下给玩家加钱）
                $this->logger->error('[V2] betAndSettle atomicBet 未知错误', ['result' => $betResult, 'order_no' => $orderNo]);
                return $this->v2Error(self::CODE_PLATFORM_REJECT);
            }

            $beforeBalance = (float)$betResult['balance'] + $betAmount;

            if ($betResult['ok'] === 1) {
                GameRecordCacheService::saveBet('T9SLOT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $betAmount,
                    'game_code' => $gameCode,
                    'original_data' => $params,
                    'balance_before' => $betResult['old_balance'] ?? 0,
                    'balance_after' => $betResult['balance'],
                    'game_order_number' => $gameOrderNo,
                    'bet_kind' => $betKind,
                ]);
            }

            // --- 结算 ---
            $diff = bcsub($actualWin, $betAmount, 2);
            $settleLuaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => max((float)$actualWin, 0),
                'diff' => $diff,
                'game_code' => $gameCode,
                'transaction_type' => TransactionType::SETTLE,
                'original_data' => $params,
            ];

            validateLuaScriptParams($settleLuaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $settleResult = RedisLuaScripts::atomicSettle($player->id, 'T9SLOT', $settleLuaParams);
            logLuaScriptCall('settle', 'T9SLOT', $player->id, $settleLuaParams);

            $afterBalance = (float)$settleResult['balance'];

            if ($settleResult['ok'] === 1) {
                GameRecordCacheService::saveSettle('T9SLOT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => max((float)$actualWin, 0),
                    'diff' => $diff,
                    'game_code' => $gameCode,
                    'original_data' => $params,
                    'balance_before' => $settleResult['old_balance'] ?? 0,
                    'balance_after' => $settleResult['balance'],
                    'game_order_number' => $gameOrderNo,
                    'is_final' => $isFinal ? 1 : 0,  // V2 新增：SyncWorker 据此过滤彩金/打码量触发
                    'bet_kind' => $betKind,           // V2 新增
                ]);

                WalletService::checkMachineCrashAfterTransaction(
                    $player->id,
                    $settleResult['balance'],
                    $settleResult['old_balance'] ?? null
                );
            } elseif (($settleResult['error'] ?? '') === 'duplicate_settle') {
                $this->logger->info('[V2] betAndSettle 结算重复', ['order_no' => $orderNo]);
            } else {
                // atomicSettle 未知错误：注已扣但结算失败，只能记录，不能返回错误（返回错误 T9 会重试，atomicBet 会返回 duplicate_order）
                $this->logger->error('[V2] betAndSettle atomicSettle 未知错误 - 注已扣结算失败', [
                    'result' => $settleResult,
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'bet_amount' => $betAmount,
                ]);
            }

            logGameInteraction('T9SLOT', 'betAndSettle_v2', $params, [
                'order_no' => $orderNo,
                'bet_amount' => $betAmount,
                'payout_amount' => $payoutAmount,
                'actual_win' => $actualWin,
                'is_final' => $isFinal,
                'bet_kind' => $betKind,
                'after_balance' => $afterBalance,
            ]);

            return $this->v2Success([
                'afterBalance' => $afterBalance,
                'beforeBalance' => $beforeBalance,
            ]);
        } catch (Exception $e) {
            Log::error('[TNineSlot V2] betAndSettle failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE_SLOT', '[V2] BetAndSettle 异常', $e, ['params' => $request->post()]);
            return $this->v2Error(self::CODE_PLATFORM_REJECT);
        }
    }

    // ================================================================
    // 接口 3：取消下注
    // V1 对应：TNineSlotGameController::cancelBet()
    // 变更：
    //   - 订单号字段：betId/roundId → transactionId
    //   - 退款金额：从 Redis 读取 betAmount → 直接用 payoutAmount（V2 修复了 V1 的 7 天过期 bug）
    //   - 重复交易返回 102（V1 返回 0 幂等成功）
    // ================================================================

    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('[V2] cancelBet 请求', ['params' => $params]);

            $player = $this->resolvePlayer($params['gameAccount'] ?? '');
            if (!$player) {
                return $this->v2Error(self::CODE_PLAYER_NOT_FOUND);
            }

            $this->service->player = $player;

            $orderNo = (string)($params['transactionId'] ?? ''); // V2 改用 transactionId
            $refundAmount = (float)($params['payoutAmount'] ?? 0);    // V2 直接传退款金额

            if (!$orderNo) {
                return $this->v2Error(self::CODE_PARAM_ERROR);
            }

            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL,
                'original_data' => $params,
            ];

            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'T9SLOT', $luaParams);
            logLuaScriptCall('cancel', 'T9SLOT', $player->id, $luaParams);

            if ($result['ok'] === 0) {
                // atomicCancel 重复取消返回 'duplicate_cancel'（不是 duplicate_order）
                if (($result['error'] ?? '') === 'duplicate_cancel') {
                    $this->logger->info('[V2] cancelBet 重复请求', ['order_no' => $orderNo]);
                    return $this->v2Error(self::CODE_DUPLICATE);
                }
                $this->logger->error('[V2] cancelBet atomicCancel 失败', ['result' => $result, 'order_no' => $orderNo]);
                return $this->v2Error(self::CODE_PLATFORM_REJECT);
            }

            if ($result['ok'] === 1) {
                GameRecordCacheService::saveCancel('T9SLOT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'original_data' => $params,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            $afterBalance = (float)$result['balance'];
            $beforeBalance = $afterBalance - $refundAmount;

            $this->logger->info('[V2] cancelBet 成功', ['order_no' => $orderNo, 'refund' => $refundAmount]);

            return $this->v2Success([
                'afterBalance' => $afterBalance,
                'beforeBalance' => $beforeBalance,
            ]);
        } catch (Exception $e) {
            Log::error('[TNineSlot V2] cancelBet failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('TNINE_SLOT', '[V2] CancelBet 异常', $e, ['params' => $request->post()]);
            return $this->v2Error(self::CODE_PLATFORM_REJECT);
        }
    }

    // ================================================================
    // 接口 4：独立下注（V2 新增，V1 无此接口）
    // 用于支持 bet/settle 分开发送的场景
    //
    // 关键处理：
    //   存储 gameOrderNumber → bet transactionId 映射到 Redis
    //   供后续 /settle 接口查找对应 bet 记录
    // ================================================================

    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('[V2] bet 请求（独立下注）', ['params' => $params]);

            $player = $this->resolvePlayer($params['gameAccount'] ?? '');
            if (!$player) {
                return $this->v2Error(self::CODE_PLAYER_NOT_FOUND);
            }

            $this->service->player = $player;

            $orderNo = (string)($params['transactionId'] ?? '');
            $gameOrderNo = (string)($params['gameOrderNumber'] ?? '');
            $betAmount = (float)($params['betAmount'] ?? 0);
            $betKind = (int)($params['betKind'] ?? 1);

            if (!$orderNo || !$gameOrderNo) {
                return $this->v2Error(self::CODE_PARAM_ERROR);
            }

            if ($this->service->checkAndHandleMachineCrash()) {
                return $this->v2Error(self::CODE_PLATFORM_REJECT);
            }

            $gameCode = $this->extractGameCodeV2($params);

            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $betAmount,
                'game_code' => $gameCode,
                'transaction_type' => TransactionType::BET,
                'original_data' => $params,
            ];

            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $betResult = RedisLuaScripts::atomicBet($player->id, 'T9SLOT', $luaParams);
            logLuaScriptCall('bet', 'T9SLOT', $player->id, $luaParams);

            if ($betResult['ok'] === 0) {
                if ($betResult['error'] === 'duplicate_order') {
                    $this->logger->info('[V2] bet 重复交易', ['order_no' => $orderNo]);
                    return $this->v2Error(self::CODE_DUPLICATE);
                }
                if ($betResult['error'] === 'insufficient_balance') {
                    return $this->v2Error(self::CODE_INSUFFICIENT);
                }
                $this->logger->error('[V2] bet atomicBet 未知错误', ['result' => $betResult, 'order_no' => $orderNo]);
                return $this->v2Error(self::CODE_PLATFORM_REJECT);
            }

            if ($betResult['ok'] === 1) {
                GameRecordCacheService::saveBet('T9SLOT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $betAmount,
                    'game_code' => $gameCode,
                    'original_data' => $params,
                    'balance_before' => $betResult['old_balance'] ?? 0,
                    'balance_after' => $betResult['balance'],
                    'game_order_number' => $gameOrderNo,
                    'bet_kind' => $betKind,
                ]);

                // 存储映射：gameOrderNumber → bet transactionId
                // /settle 接口用此映射将结算挂到正确的 bet 记录上
                Redis::setex(self::BET_MAPPING_PREFIX . $gameOrderNo, self::BET_MAPPING_TTL, $orderNo);

                $this->logger->info('[V2] bet 成功，存储订单映射', [
                    'order_no' => $orderNo,
                    'game_order_no' => $gameOrderNo,
                    'bet_amount' => $betAmount,
                ]);
            }

            $afterBalance = (float)$betResult['balance'];
            $beforeBalance = $afterBalance + $betAmount;

            return $this->v2Success([
                'afterBalance' => $afterBalance,
                'beforeBalance' => $beforeBalance,
            ]);
        } catch (Exception $e) {
            Log::error('[TNineSlot V2] bet failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('TNINE_SLOT', '[V2] 独立下注异常', $e, ['params' => $request->post()]);
            return $this->v2Error(self::CODE_PLATFORM_REJECT);
        }
    }

    // ================================================================
    // 接口 5：独立结算（V2 新增，V1 无此接口）
    // 配合 /bet 接口使用（bet/settle 分开发送场景）
    //
    // 关键处理：
    //   通过 gameOrderNumber 查找 bet transactionId（Redis 映射）
    //   将结算挂到对应的 bet 记录，而不是创建独立 settle 记录
    //
    // 竞态风险：/settle 比 /bet 先到达时，映射尚未写入 Redis
    //   处理：等待 200ms 后重试一次查找
    // ================================================================

    public function settle(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('[V2] settle 请求（独立结算）', ['params' => $params]);

            $player = $this->resolvePlayer($params['gameAccount'] ?? '');
            if (!$player) {
                return $this->v2Error(self::CODE_PLAYER_NOT_FOUND);
            }

            $this->service->player = $player;

            $settleTransactionId = (string)($params['transactionId'] ?? '');
            $gameOrderNo = (string)($params['gameOrderNumber'] ?? '');
            $payoutAmount = (float)($params['payoutAmount'] ?? 0);
            $betAmount = (float)($params['betAmount'] ?? 0);
            $isFinal = (bool)($params['isFinal'] ?? true);
            $betKind = (int)($params['betKind'] ?? 1);

            if (!$settleTransactionId || !$gameOrderNo) {
                return $this->v2Error(self::CODE_PARAM_ERROR);
            }

            // 通过 gameOrderNumber 找到对应的 bet transactionId
            $betTransactionId = Redis::get(self::BET_MAPPING_PREFIX . $gameOrderNo);

            // 竞态处理：/bet 还未写完映射时，等待一次
            if (!$betTransactionId) {
                $this->logger->warning('[V2] settle 找不到 bet 映射，等待 200ms 重试', [
                    'settle_tx' => $settleTransactionId,
                    'game_order_no' => $gameOrderNo,
                ]);
                usleep(200000);
                $betTransactionId = Redis::get(self::BET_MAPPING_PREFIX . $gameOrderNo);
            }

            // 以 bet transactionId 为 orderNo（关联到 bet 记录）
            // 若仍找不到，回退到 settle 的 transactionId（走独立 settle 路径）
            $orderNo = $betTransactionId ?: $settleTransactionId;
            $gameCode = $this->extractGameCodeV2($params);
            $actualWin = bcadd($betAmount, $payoutAmount, 2);
            $diff = bcsub($actualWin, $betAmount, 2);

            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => max((float)$actualWin, 0),
                'diff' => $diff,
                'game_code' => $gameCode,
                'transaction_type' => TransactionType::SETTLE,
                'original_data' => $params,
            ];

            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $settleResult = RedisLuaScripts::atomicSettle($player->id, 'T9SLOT', $luaParams);
            logLuaScriptCall('settle', 'T9SLOT', $player->id, $luaParams);

            if ($settleResult['ok'] === 0) {
                if (($settleResult['error'] ?? '') === 'duplicate_settle') {
                    $this->logger->info('[V2] settle 重复请求', ['order_no' => $orderNo]);
                    return $this->v2Error(self::CODE_DUPLICATE);
                }
                $this->logger->error('[V2] settle atomicSettle 未知错误', ['result' => $settleResult, 'order_no' => $orderNo]);
                return $this->v2Error(self::CODE_PLATFORM_REJECT);
            }

            $afterBalance = (float)$settleResult['balance'];
            // atomicSettle 加的是 max(actualWin, 0)，用同一值反推 beforeBalance
            $beforeBalance = $afterBalance - max((float)$actualWin, 0);

            if ($settleResult['ok'] === 1) {
                GameRecordCacheService::saveSettle('T9SLOT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => max((float)$actualWin, 0),
                    'diff' => $diff,
                    'game_code' => $gameCode,
                    'original_data' => $params,
                    'balance_before' => $settleResult['old_balance'] ?? 0,
                    'balance_after' => $settleResult['balance'],
                    'game_order_number' => $gameOrderNo,
                    'is_final' => $isFinal ? 1 : 0,
                    'bet_kind' => $betKind,
                ]);

                WalletService::checkMachineCrashAfterTransaction(
                    $player->id,
                    $settleResult['balance'],
                    $settleResult['old_balance'] ?? null
                );
            }

            $this->logger->info('[V2] settle 成功', [
                'order_no' => $orderNo,
                'settle_tx' => $settleTransactionId,
                'game_order_no' => $gameOrderNo,
                'payout_amount' => $payoutAmount,
                'is_final' => $isFinal,
            ]);

            return $this->v2Success([
                'afterBalance' => $afterBalance,
                'beforeBalance' => $beforeBalance,
            ]);
        } catch (Exception $e) {
            Log::error('[TNineSlot V2] settle failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('TNINE_SLOT', '[V2] 独立结算异常', $e, ['params' => $request->post()]);
            return $this->v2Error(self::CODE_PLATFORM_REJECT);
        }
    }

    // ================================================================
    // 接口 6：修改游戏订单（V2 新增，V1 无此接口）
    // 用于游戏结束后调整结算金额（jackpot 补偿、结算纠错等）
    //
    // payoutAmount > 0：向玩家加钱
    // payoutAmount < 0：从玩家扣钱
    // payoutAmount = 0：无余额变动，仅更新记录
    //
    // ⚠️ 注意：此接口不走 atomicBet/atomicSettle，因为：
    //   1. 对应 bet 记录可能已从 Redis 过期（7天TTL）
    //   2. 只需调整余额，不走完整下注/结算流程
    //   使用 Redis INCRBY（原子操作）更新余额
    // ================================================================

    public function modifyGameOrder(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('[V2] modifyGameOrder 请求', ['params' => $params]);

            $player = $this->resolvePlayer($params['gameAccount'] ?? '');
            if (!$player) {
                return $this->v2Error(self::CODE_PLAYER_NOT_FOUND);
            }

            $transactionId = (string)($params['transactionId'] ?? '');
            $gameOrderNo = (string)($params['gameOrderNumber'] ?? '');
            $payoutAmount = (float)($params['payoutAmount'] ?? 0);

            if (!$transactionId) {
                return $this->v2Error(self::CODE_PARAM_ERROR);
            }

            // 幂等锁：先原子写锁（SET NX），成功才操作余额
            // 顺序不能颠倒：若先改余额再写锁，进程崩溃后重试会重复操作
            $lockKey = self::MODIFY_LOCK_PREFIX . $transactionId;
            $lockSet = Redis::set($lockKey, 1, 'EX', self::MODIFY_LOCK_TTL, 'NX');
            if (!$lockSet) {
                $this->logger->info('[V2] modifyGameOrder 重复请求', ['transaction_id' => $transactionId]);
                return $this->v2Error(self::CODE_DUPLICATE);
            }

            $balanceKey = "wallet:balance:{$player->id}";
            $payoutCents = (int)round($payoutAmount * 100);

            // 防御：key 不存在时从数据库初始化（正常不会发生，仅 Redis 重启后未恢复时触发）
            // INCRBY 对不存在的 key 从 0 开始，不加保护会把玩家余额清零
            $initialized = Redis::set($balanceKey, (int)round($player->money * 100), 'NX');
            if ($initialized) {
                $this->logger->error('[V2] modifyGameOrder Redis 余额 key 缺失，已从数据库初始化', [
                    'player_id' => $player->id,
                    'init_balance' => $player->money,
                ]);
            }

            // 原子更新余额（INCRBY 是 Redis 原子操作）
            $newCents = Redis::incrBy($balanceKey, $payoutCents);

            // 扣款后变负数则回滚，并删除幂等锁让调用方可重试
            if ($newCents < 0) {
                Redis::incrBy($balanceKey, -$payoutCents);
                Redis::del($lockKey);
                $this->logger->warning('[V2] modifyGameOrder 余额不足', [
                    'transaction_id' => $transactionId,
                    'before_cents' => $newCents - $payoutCents, // 回滚前的值
                    'payout_cents' => $payoutCents,
                ]);
                return $this->v2Error(self::CODE_INSUFFICIENT);
            }

            // 同步余额到 MySQL
            Player::where('id', $player->id)->update(['money' => bcdiv($newCents, 100, 2)]);

            // 记录调整流水（供 SyncWorker 同步到 play_game_record）
            // beforeCents 由 INCRBY 结果反推，不受并发影响（比 GET 读数更准确）
            $beforeCents = $newCents - $payoutCents;
            $gameCode = $this->extractGameCodeV2($params);
            $recordKey = "game:record:settle:T9SLOT:{$transactionId}_modify";
            Redis::hMSet($recordKey, [
                'platform' => 'T9SLOT',
                'order_no' => $transactionId . '_modify',
                'player_id' => $player->id,
                'platform_id' => $this->service->platform->id,
                'amount' => 0,
                'win' => max($payoutCents, 0),
                'diff' => $payoutCents,
                'game_code' => $gameCode,
                'settlement_status' => 1,
                'settle_type' => 'adjust',
                'settle_time' => time(),
                'original_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'balance_before' => $beforeCents,
                'balance_after' => $newCents,
                'game_order_number' => $gameOrderNo,
                'is_final' => 1,
            ]);
            Redis::expire($recordKey, 604800);
            Redis::zAdd('game:sync:queue', time(), $recordKey);

            $afterBalance = $newCents / 100;
            $beforeBalance = $beforeCents / 100;

            $this->logger->info('[V2] modifyGameOrder 成功', [
                'transaction_id' => $transactionId,
                'game_order_no' => $gameOrderNo,
                'payout_amount' => $payoutAmount,
                'before_balance' => $beforeBalance,
                'after_balance' => $afterBalance,
            ]);

            return $this->v2Success([
                'afterBalance' => $afterBalance,
                'beforeBalance' => $beforeBalance,
            ]);
        } catch (Exception $e) {
            Log::error('[TNineSlot V2] modifyGameOrder failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('TNINE_SLOT', '[V2] ModifyGameOrder 异常', $e, ['params' => $request->post()]);
            return $this->v2Error(self::CODE_PLATFORM_REJECT);
        }
    }

    // ================================================================
    // 私有辅助方法
    // ================================================================

    /**
     * 从 gameAccount 解析玩家（格式：{uuid}_{suffix}）
     * V1/V2 共用逻辑，提取为独立方法
     */
    private function resolvePlayer(string $gameAccount): ?Player
    {
        if (!$gameAccount) {
            return null;
        }
        $userId = explode('_', $gameAccount)[0];
        return Player::query()->where('uuid', $userId)->first();
    }

    /**
     * V2 游戏代码提取
     *
     * V2 betInfo 结构（PascalCase key 改为 lowercase，GameCode 改为 gameCode）：
     * {
     *   "slots": {"gameCode": "SL2573", "gameName": "關老爺"},
     *   "fish":  {"gameCode": "FS001",  "gameName": "捕魚達人"}
     * }
     *
     * 对比 V1 extractGameCode()：
     *   V1: $params['betInfoData']['SlotsFishing']['GameCode']
     *   V2: $params['betInfo'][strtolower($gameCategory)]['gameCode']
     */
    private function extractGameCodeV2(array $params): string
    {
        $betInfo = $params['betInfo'] ?? [];
        if (empty($betInfo) || !is_array($betInfo)) {
            return '';
        }

        $category = strtolower($params['gameCategory'] ?? '');
        if ($category && isset($betInfo[$category])) {
            return $betInfo[$category]['gameCode'] ?? '';
        }

        // 降级：取第一个类别的 gameCode
        $first = reset($betInfo);
        return is_array($first) ? ($first['gameCode'] ?? '') : '';
    }

    /**
     * V2 成功响应
     * V2 格式：{"statusCode": 0, "data": {...}}
     * 对比 V1：{"resultCode": "OK", "data": {...}}
     */
    private function v2Success(array $data = [], int $httpCode = 200): Response
    {
        return new Response($httpCode, ['Content-Type' => 'application/json'], json_encode([
            'statusCode' => self::CODE_SUCCESS,
            'data' => $data,
        ]));
    }

    /**
     * V2 失败响应
     * V2 格式：{"statusCode": 101, "data": null}
     * 对比 V1：{"resultCode": "FAIL", "data": null, "errorMsg": ""}
     */
    private function v2Error(int $code, array $data = [], int $httpCode = 200): Response
    {
        return new Response($httpCode, ['Content-Type' => 'application/json'], json_encode([
            'statusCode' => $code,
            'data' => empty($data) ? null : $data,
        ]));
    }
}
