<?php

namespace app\wallet\controller\game;

use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameQueueService;
use Exception;
use support\Log;
use support\Request;
use support\Response;

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
     * 下注
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('atg下注记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['betId'];
            $bet = $data['bet'];

            // 获取当前余额
            $currentBalance = \app\service\WalletService::getBalance($player->id);

            // 检查幂等性
            $betKey = "atg:bet:lock:{$orderNo}";
            $isDuplicate = !\support\Redis::setnx($betKey, 1);
            if (!$isDuplicate) {
                \support\Redis::expire($betKey, 300);
            }

            if ($isDuplicate) {
                // 重复订单，返回当前余额
                return $this->success(['balance' => $currentBalance]);
            }

            // 准备队列参数
            $queueParams = [
                'order_no' => $orderNo,
                'amount' => $bet,
                'platform_id' => $this->service->platform->id,
                'game_code' => $data['game_code'] ?? '',
                'order_time' => $data['trade_time'] ?? date('Y-m-d H:i:s'),
                'original_data' => $data,
            ];

            // 立即写入 Redis 预占状态（在入队列之前）
            try {
                \support\Redis::hMSet("order:pending:{$orderNo}", [
                    'player_id' => $player->id,
                    'order_no' => $orderNo,
                    'amount' => $bet,
                    'platform_id' => $this->service->platform->id,
                    'game_code' => $data['game_code'] ?? '',
                    'status' => 'pending',
                    'created_at' => time(),
                ]);
                \support\Redis::expire("order:pending:{$orderNo}", 300);
            } catch (\Throwable $e) {
                // Redis 失败不影响主流程
            }

            // 发送下注队列
            $sent = GameQueueService::sendBet('ATG', $player, $queueParams);

            if ($sent) {
                // 预估余额：扣款
                $estimatedBalance = bcsub($currentBalance, $bet, 2);
                $estimatedBalance = max(0, $estimatedBalance);

                $return = ['balance' => $estimatedBalance];
            } else {
                // 队列失败，同步降级
                \support\Redis::del($betKey);
                $balance = $this->service->bet($data);
                if ($this->service->error) {
                    return $this->error($this->service->error);
                }
                $return = $balance;
            }

            return $this->success($return);
        } catch (Exception $e) {
            Log::error('ATG bet failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }

    /**
     * 結算
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->log->info('atg结算请求', array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('atg结算记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['betId'];
            $winAmount = $data['win'] ?? 0;

            // 获取当前余额
            $currentBalance = \app\service\WalletService::getBalance($player->id);

            // 准备队列参数
            $queueParams = [
                'order_no' => $orderNo,
                'bet_order_no' => $data['bet_trade_no'] ?? $orderNo,
                'amount' => max($winAmount, 0),
                'result_amount' => $winAmount,
                'original_data' => $data,
            ];

            // 发送结算队列
            $sent = GameQueueService::sendSettle('ATG', $player, $queueParams);

            if ($sent) {
                // 预估余额：加款（如果有中奖）
                $estimatedBalance = $currentBalance;
                if ($winAmount > 0) {
                    $estimatedBalance = bcadd($currentBalance, $winAmount, 2);
                }

                $return = ['balance' => $estimatedBalance];
            } else {
                // 队列失败，同步降级
                $balance = $this->service->betResulet($data);
                if ($this->service->error) {
                    return $this->error($this->service->error);
                }
                $return = $balance;
            }

            return $this->success($return);
        } catch (Exception $e) {
            Log::error('ATG betResult failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('ATG', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_FAIL);
        }
    }


    /**
     * 退款
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
            $this->log->info('atg退款记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['betId'];
            $refundAmount = $data['bet'] ?? 0;

            // 获取当前余额
            $currentBalance = \app\service\WalletService::getBalance($player->id);

            // 准备队列参数
            $queueParams = [
                'order_no' => $orderNo,
                'bet_order_no' => $data['bet_trade_no'] ?? $orderNo,
                'amount' => $refundAmount,
                'original_data' => $data,
            ];

            // 发送取消队列
            $sent = GameQueueService::sendCancel('ATG', $player, $queueParams);

            if ($sent) {
                // 预估余额：退款
                $estimatedBalance = bcadd($currentBalance, $refundAmount, 2);

                $return = ['balance' => $estimatedBalance];
            } else {
                // 队列失败，同步降级
                $balance = $this->service->refund($data);
                if ($this->service->error) {
                    return $this->error($this->service->error);
                }
                $return = $balance;
            }

            return $this->success($return);
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