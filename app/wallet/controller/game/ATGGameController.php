<?php

namespace app\wallet\controller\game;

use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * ATG电子平台
 */
class ATGGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_FAIL = 1;
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
        self::API_CODE_SUCCESS => 'success',
        self::API_CODE_FAIL => 'fail',
        self::API_CODE_INVALID_PARAM => '無效參數',
        self::API_CODE_DECRYPT_ERROR => '解密異常',
        self::API_CODE_PLAYER_NOT_EXIST => '此玩家帳戶不存在',
        self::API_CODE_DUPLICATE_ORDER => '重複的订单',
        self::API_CODE_DUPLICATE_TRANSACTION => '重複的订单',
        self::API_CODE_INSUFFICIENT_BALANCE => '餘額不足',
        self::API_CODE_ORDER_SETTLED => '此订单已被結算',
        self::API_CODE_ORDER_NOT_EXIST => '此订单不存在',
        self::API_CODE_ORDER_CANCELLED => '此订单已被取消',
        self::API_CODE_DENY_PREPAY => '拒絕預扣，其他原因',
        self::API_CODE_TRANSACTION_NOT_FOUND => '找不到交易結果',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $log;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_ATG);
        $this->log = Log::channel('atg_server');
    }

    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->log->info('atg余额查询记录', ['params' => $params]);
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('atg余额查询解密', ['data' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->balance();
            return $this->success(['balance' => $balance]);
        } catch (Exception $e) {
            Log::error('ATG balance failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }

    /**
     * 下注（Redis 缓存版）
     * @param Request $request
     * @return Response
     * @throws Exception|Throwable
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('atg下注记录（Redis缓存）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['betId'];
            $bet = $data['amount'];

            // 幂等性检查
            $lockKey = "order:bet:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复订单
                return $this->error(self::API_CODE_DUPLICATE_ORDER);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // 余额预检查
                if ($currentBalance < $bet) {
                    \support\Redis::del($lockKey);
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                }

                // 写入下注记录
                \app\service\GameRecordCacheService::saveBet('ATG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $bet,
                    'game_code' => $data['gameCode'] ?? '',
                    'original_data' => $data,
                ]);

                // 更新余额缓存（扣款）
                $newBalance = bcsub($currentBalance, $bet, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $this->log->info('atg下注成功（Redis缓存）', [
                'order_no' => $orderNo,
            ]);
            return $this->success(['balanceOld' => $currentBalance, 'balance' => $newBalance]);
        } catch (Exception $e) {
            $this->log->error('ATG bet failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }

    /**
     * 結算（Redis 缓存版）
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->log->info('atg结算请求（Redis缓存）', array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('atg结算记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['betId'];
            $winAmount = $data['amount'] ?? 0;

            // 幂等性检查
            $lockKey = "order:settle:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复结算
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(['balanceOld' => $balance, 'balance' => $balance]);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // 写入结算记录
                \app\service\GameRecordCacheService::saveSettle('ATG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => max($winAmount, 0),
                    'diff' => $winAmount,
                    'original_data' => $data,
                ]);

                // 更新余额缓存（加上中奖金额）
                $newBalance = $currentBalance;
                if ($winAmount > 0) {
                    $newBalance = bcadd($currentBalance, $winAmount, 2);
                    \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                }

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $this->log->info('atg结算成功（Redis缓存）', [
                'order_no' => $orderNo,
            ]);

            return $this->success(['balanceOld' => $currentBalance, 'balance' => $newBalance]);
        } catch (Exception $e) {
            $this->log->error('ATG betResult failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }


    /**
     * 退款（Redis 缓存版）
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('atg退款记录（Redis缓存）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['betId'];
            $refundAmount = $data['amount'] ?? 0;

            // 幂等性检查
            $lockKey = "order:cancel:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复退款
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(['balanceOld' => $balance, 'balance' => $balance]);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // 写入退款记录
                \app\service\GameRecordCacheService::saveCancel('ATG', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'cancel_type' => 'refund',
                    'original_data' => $data,
                ]);

                // 更新余额缓存（退款）
                $newBalance = bcadd($currentBalance, $refundAmount, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->log->info('atg退款成功（Redis缓存）', [
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(['balanceOld' => $currentBalance, 'balance' => $newBalance]);
        } catch (Exception $e) {
            Log::error('ATG refund failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '退款异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }

    /**
     * 成功响应方法
     *
     * @param array $data 响应数据
     * @return Response
     */
    public function success(array $data = []): Response
    {
        $responseData = [
            'status' => self::API_CODE_MAP[self::API_CODE_SUCCESS],
            'data' => $data,
        ];

        return (new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($responseData, JSON_UNESCAPED_UNICODE)
        ));
    }

    /**
     * 失败响应方法
     *
     * @param string $code
     * @return Response
     */
    public function error(string $code): Response
    {
        $responseData = [
            'status' => self::API_CODE_MAP[self::API_CODE_FAIL],
            'data' => [
                'message' => self::API_CODE_MAP[$code]
            ],
        ];

        return (new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($responseData, JSON_UNESCAPED_UNICODE)
        ));
    }
}