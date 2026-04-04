<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameRecordCacheService;
use Exception;
use Monolog\Logger;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;
use Throwable;

/**
 * RSG皇家电子
 */
class RsgGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_DECRYPT_ERROR = 2002;
    public const API_CODE_INVALID_PARAM = 2001;
    public const API_CODE_PLAYER_NOT_EXIST = 4001;
    public const API_CODE_DUPLICATE_ORDER = 4002;
    public const API_CODE_INSUFFICIENT_BALANCE = 4003;
    public const API_CODE_ORDER_NOT_EXIST = 4004;
    public const API_CODE_ORDER_SETTLED = 4005;
    public const API_CODE_ORDER_CANCELLED = 4006;
    public const API_CODE_DUPLICATE_TRANSACTION = 4007;
    public const API_CODE_DENY_PREPAY = 4008;
    public const API_CODE_TRANSACTION_NOT_FOUND = 4009;


    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'OK',
        self::API_CODE_INVALID_PARAM => '無效參數',
        self::API_CODE_DECRYPT_ERROR => '解密異常',
        self::API_CODE_PLAYER_NOT_EXIST => '此玩家帳戶不存在',
        self::API_CODE_DUPLICATE_ORDER => '重複的 SequenNumber',
        self::API_CODE_DUPLICATE_TRANSACTION => '重複的TransactionId',
        self::API_CODE_INSUFFICIENT_BALANCE => '餘額不足',
        self::API_CODE_ORDER_SETTLED => '此 SequenNumber 已被結算',
        self::API_CODE_ORDER_NOT_EXIST => '此 SequenNumber 不存在',
        self::API_CODE_ORDER_CANCELLED => '此 SequenNumber 已被取消',
        self::API_CODE_DENY_PREPAY => '拒絕預扣，其他原因',
        self::API_CODE_TRANSACTION_NOT_FOUND => '找不到交易結果',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    private GameServiceInterface|SingleWalletServiceInterface $service;

    private null|Logger $logger;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_RSG);
        $this->logger = Log::channel('rsg_server');
    }

    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function balance(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('RSG余额查询请求', ['params' => $params]);
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG余额查询（解密后）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $balance = $this->service->balance();
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => (float)$balance]);
        } catch (Throwable $e) {
            $this->logger->error('RSG余额查询异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 下注（异步队列版）
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG下注请求（Redis缓存）', ['order_no' => $data['SequenNumber'] ?? '']);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }
            $orderNo = $data['SequenNumber'];
            // 3. 幂等性检查（Redis 锁）
            $betKey = "rsg:bet:lock:{$orderNo}";
            if (!\support\Redis::set($betKey, 1, ['NX', 'EX' => 300])) {
                // 重复订单，返回当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                $this->logger->warning('RSG: 重复下注请求', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                ]);

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => (float)$currentBalance
                ]);
            }

            // 4. 获取当前余额（从缓存）
            $currentBalance = GameRecordCacheService::getCachedBalance($player->id);

            // 5. 预检查余额（快速失败）
            if ($currentBalance < $data['Amount']) {
                \support\Redis::del($betKey);  // 清理锁

                $this->logger->warning('RSG: 余额不足', [
                    'order_no' => $orderNo,
                    'balance' => $currentBalance,
                    'amount' => $data['Amount'],
                ]);
                return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
            }

            // 6. ✅ 写入 Redis 缓存（<0.5ms）
            try {
                GameRecordCacheService::saveBet('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $data['Amount'],
                    'game_code' => $data['GameId'] ?? '',
                    'original_data' => $data,
                ]);
            } catch (\Throwable $e) {
                \support\Redis::del($betKey);  // 清理锁
                throw $e;
            }

            // 7. ✅ 更新余额缓存（<0.2ms）
            $newBalance = bcsub($currentBalance, $data['Amount'], 2);
            $newBalance = max(0, $newBalance);
            GameRecordCacheService::updateCachedBalance($player->id, $newBalance);

            $this->logger->info('RSG下注成功（Redis缓存）', [
                'username' => $data['UserId'],
                'order_no' => $orderNo,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$newBalance
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG下注异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 取消下注（异步队列版）
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG取消下注请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 2.5 获取当前余额（使用缓存模式）
            $currentBalance = \app\service\WalletService::getBalance($player->id, \app\model\PlayerPlatformCash::PLATFORM_SELF);
            $orderNo = $data['SequenNumber'];

            // 2.6 幂等性检查（原子锁 - 防止重复取消）
            $cancelKey = "rsg:cancel:lock:{$orderNo}";
            if (!\support\Redis::set($cancelKey, 1, ['NX', 'EX' => 300])) {
                // 重复取消请求
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => (float)$currentBalance
                ]);
            }

            // 3. 写入 Redis 缓存
            try {
                GameRecordCacheService::saveCancel('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'cancel_type' => 'cancel',
                    'original_data' => $data,
                ]);

                // 4. 更新余额缓存（退回下注金额）
                $newBalance = bcadd($currentBalance, $data['BetAmount'], 2);
                GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                Redis::del($cancelKey);
                throw $e;
            }

            // 5. 快速返回
            $this->logger->info('RSG取消下注已入队（快速响应）', [
                'order_no' => $orderNo,
            ]);
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$newBalance
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG取消下注异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '取消下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 結算（异步队列版）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();
            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG结算请求（Redis缓存）', ['order_no' => $data['SequenNumber'] ?? '']);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['SequenNumber'];

            // 3. 幂等性检查（Redis 原子锁）
            $settleKey = "rsg:settle:lock:{$orderNo}";
            if (!\support\Redis::set($settleKey, 1, ['NX', 'EX' => 300])) {
                // 重复结算请求，返回当前余额
                $currentBalance = GameRecordCacheService::getCachedBalance($player->id);

                $this->logger->warning('RSG: 重复结算请求', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                ]);

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => (float)$currentBalance
                ]);
            }

            // 4. 获取当前余额（从缓存）
            $currentBalance = GameRecordCacheService::getCachedBalance($player->id);

            // 5. ✅ 写入 Redis 缓存（<0.5ms）
            try {
                GameRecordCacheService::saveSettle('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $data['Amount'],
                    'diff' => bcsub($data['Amount'], $data['BetAmount'] ?? 0, 2),
                    'play_time' => $data['PlayTime'] ?? '',
                    'is_game_flow_end' => $data['IsGameFlowEnd'] ?? false,
                    'belong_sequen_number' => $data['BelongSequenNumber'] ?? '',
                    'game_code' => $data['GameId'] ?? '',
                    'original_data' => $data,
                ]);
            } catch (\Throwable $e) {
                Redis::del($settleKey);
                throw $e;
            }

            // 6. ✅ 更新余额缓存（<0.2ms）
            $winAmount = $data['Amount'] ?? 0;
            $newBalance = $currentBalance;
            if ($winAmount > 0) {
                $newBalance = bcadd($currentBalance, $winAmount, 2);
                GameRecordCacheService::updateCachedBalance($player->id, $newBalance);
            }

            // 7. ✅ 立即返回（总耗时 <1ms）

            $this->logger->info('RSG结算成功（Redis缓存）', [
                'order_no' => $orderNo,
                'amount' => $winAmount,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$newBalance
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG结算异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 重新結算（Redis 缓存版）
     * @param Request $request
     * @return Response
     */
    public function reBetResult(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG重新结算请求（Redis缓存）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['SequenNumber'];

            // 幂等性检查
            $lockKey = "order:settle:lock:{$orderNo}";
            if (!Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复重新结算
                $balance = GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => (float)$balance
                ]);
            }

            try {
                // 获取当前余额
                $currentBalance = GameRecordCacheService::getCachedBalance($player->id);

                // 写入重新结算记录
                GameRecordCacheService::saveSettle('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $data['Amount'] ?? 0,
                    'diff' => ($data['Amount'] ?? 0) - ($data['BetAmount'] ?? 0),
                    'settle_type' => 'adjust',  // 重新结算标记为调整
                    'original_data' => $data,
                ]);

                // 更新余额缓存
                $winAmount = ($data['Amount'] ?? 0) - ($data['BetAmount'] ?? 0);
                $newBalance = $currentBalance;
                if ($winAmount != 0) {
                    $newBalance = bcadd($currentBalance, $winAmount, 2);
                    GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                }

            } catch (\Throwable $e) {
                Redis::del($lockKey);
                throw $e;
            }

            $this->logger->info('RSG重新结算成功（Redis缓存）', [
                'order_no' => $orderNo,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$newBalance
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG重新结算异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '重新结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * Jackpot 中獎（异步队列版）
     * @param Request $request
     * @return Response
     */
    public function jackpotResult(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG Jackpot中奖请求（Redis缓存）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['SequenNumber'];

            // 幂等性检查
            $lockKey = "order:settle:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复Jackpot
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => (float)$balance
                ]);
            }

            try {
                // 获取当前余额
                $currentBalance = GameRecordCacheService::getCachedBalance($player->id);

                $jackpotAmount = $data['Amount'] ?? 0;

                // 写入Jackpot记录（无下注，直接中奖）
                GameRecordCacheService::saveSettle('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $jackpotAmount,
                    'diff' => $jackpotAmount,
                    'settle_type' => 'jackpot',
                    'original_data' => $data,
                ]);

                // 更新余额缓存
                $newBalance = bcadd($currentBalance, $jackpotAmount, 2);
                GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                Redis::del($lockKey);
                throw $e;
            }

            $this->logger->info('RSG Jackpot成功（Redis缓存）', [
                'order_no' => $orderNo,
                'amount' => $jackpotAmount,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$newBalance
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG Jackpot中奖异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', 'Jackpot异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 打鱼机预扣金额（Redis 缓存版）
     * @param Request $request
     * @return Response
     */
    public function prepay(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG打鱼机预扣金额请求（Redis缓存）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            /** @var Player $player */
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['SessionId'];  // prepay使用SessionId作为订单号

            // 3. 幂等性检查（原子锁）
            $lockKey = "order:bet:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复请求
                $balance = GameRecordCacheService::getCachedBalance($player->id);
                $this->logger->warning('RSG: 重复预扣请求', ['session_id' => $orderNo]);

                // 返回当前余额和0扣款金额
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => (float)$balance,
                    'Amount' => 0
                ]);
            }

            try {
                // 4. 获取当前余额
                $currentBalance = GameRecordCacheService::getCachedBalance($player->id);
                $requestAmount = $data['Amount'] ?? 0;

                // prepay特殊逻辑：余额不足时扣除所有余额
                $actualDeductAmount = min($currentBalance, $requestAmount);

                // 5. 写入预扣记录
                GameRecordCacheService::saveBet('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $actualDeductAmount,
                    'game_code' => $data['GameId'] ?? '',
                    'bet_type' => 'prepay',  // 标记为prepay类型
                    'original_data' => $data,
                ]);

                // 6. 更新余额缓存
                $newBalance = bcsub($currentBalance, $actualDeductAmount, 2);
                $newBalance = max(0, $newBalance);
                GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                Redis::del($lockKey);
                throw $e;
            }

            $this->logger->info('RSG打鱼机预扣成功（Redis缓存）', [
                'session_id' => $orderNo,
                'request_amount' => $requestAmount,
                'actual_deduct' => $actualDeductAmount,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$newBalance,
                'Amount' => $actualDeductAmount
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG打鱼机预扣金额异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 打鱼机退款（Redis 缓存版）
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG打鱼机退款请求（Redis缓存）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::query()->where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['SessionId'];
            // 3. 幂等性检查（原子锁）
            $lockKey = "order:settle:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复请求
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                $this->logger->warning('RSG: 重复退款请求', ['orderNo' => $orderNo]);

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => (float)$balance,
                    'Amount' => 0
                ]);
            }

            try {
                // 4. 获取当前余额
                $currentBalance = GameRecordCacheService::getCachedBalance($player->id);
                $refundAmount = $data['Amount'] ?? 0;

                // 5. 写入退款记录
                GameRecordCacheService::saveSettle('RSG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $refundAmount,
                    'diff' => $refundAmount,
                    'settle_type' => 'refund',  // 标记为refund类型
                    'session_id' => $orderNo,  // 关联原prepay的SessionId
                    'game_code' => $data['GameId'] ?? '',
                    'original_data' => $data,
                ]);

                // 6. 更新余额缓存（退回金额）
                $newBalance = bcadd($currentBalance, $refundAmount, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $this->logger->info('RSG打鱼机退款成功（Redis缓存）', [
                'amount' => $refundAmount,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => (float)$newBalance,
                'Amount' => (float)$refundAmount
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG打鱼机退款异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '退款异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }


    /**
     * 检查交易
     * @param Request $request
     * @return Response
     */
    public function checkTransaction(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG检查交易请求', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $result = $this->service->checkTransaction($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $result);
        } catch (Throwable $e) {
            $this->logger->error('RSG检查交易异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '检查交易异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 成功响应方法
     *
     * @param string $message 响应消息
     * @param array $data 响应数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function success(string $message = '', array $data = [], int $httpCode = 200): Response
    {

        $timestamp = time();
        $responseData = [
            'ErrorCode' => self::API_CODE_SUCCESS, // 使用业务状态码常量
            'ErrorMessage' => $message ?: self::API_CODE_MAP[self::API_CODE_SUCCESS],
            'Timestamp' => $timestamp,
            'Data' => $data,
        ];

        $reqBase64 = $this->service->encrypt(json_encode($responseData));

        return (new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
            $reqBase64
        ));
    }

    /**
     * 失败响应方法
     *
     * @param int $code 错误码
     * @param string|null $message 自定义错误信息
     * @param array $data 额外数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function error(int $code, ?string $message = null, array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'ErrorCode' => $code, // 使用业务状态码常量
            'ErrorMessage' => $message ?: (self::API_CODE_MAP[$code] ?? '未知错误'),
            'Timestamp' => time(),
            'Data' => null,
        ];

        $reqBase64 = $this->service->encrypt(json_encode($responseData));

        return (new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
            $reqBase64
        ));
    }
}