<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\KTServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameQueueService;
use Exception;
use support\Log;
use support\Request;
use support\Response;

/**
 * O8平台
 */
class KTGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_TRANSACTIONID_DUPLICATE = 1;
    public const API_CODE_AMOUNT_OVER_BALANCE = 2;
    public const API_CODE_TOKEN_DOES_NOT_EXIST = 3;
    public const API_CODE_OTHER_ERROR = 99;

    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'Success',
        self::API_CODE_TRANSACTIONID_DUPLICATE => '交易識別碼重複',
        self::API_CODE_AMOUNT_OVER_BALANCE => '餘額不足',
        self::API_CODE_TOKEN_DOES_NOT_EXIST => '认证令牌无效或已过期',
        self::API_CODE_OTHER_ERROR => '其他错误',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    /**
     * @var KTServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_KT);
        $this->logger = Log::channel('kt_server');
    }


    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function auth(Request $request): Response
    {
        try {
            $params = $request->post();
            $hash = $request->get('Hash');

            $this->service->verifyToken($params, $hash);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            if (!isset($params['Token'])) {
                return $this->error(self::API_CODE_OTHER_ERROR, '缺少Token参数');
            }

            $this->service->player = Player::query()->where('uuid', $params['Token'])->first();

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Username' => $this->service->player->uuid,
                'Currency' => 'TWD',
                'Balance' => $this->service->balance(),
            ]);
        } catch (Exception $e) {
            Log::error('KT auth failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '认证异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_TOKEN_DOES_NOT_EXIST);
        }
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
            $hash = $request->get('Hash');

            $this->service->verifyToken($params, $hash);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            if (!isset($params['Username'])) {
                return $this->error(self::API_CODE_OTHER_ERROR, '缺少Username参数');
            }

            $this->service->player = Player::query()->where('uuid', $params['Username'])->first();

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => $this->service->balance(),
            ]);
        } catch (Exception $e) {
            Log::error('KT balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
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
            $hash = $request->get('Hash');

            $this->logger->info('kt_server 下注记录', ['params' => $params, 'get' => $hash]);

            // 检查必要参数
            if (!isset($params['Username']) || !isset($params['TransactionId']) || !isset($params['Amount'])) {
                return $this->error(self::API_CODE_OTHER_ERROR, '缺少必要参数');
            }

            $this->service->verifyToken($params, $hash);

            $this->service->player = Player::query()->where('uuid', $params['Username'])->first();
            $player = $this->service->player;

            $orderNo = $params['TransactionId'];
            $bet = $params['Amount'];
            $takeWin = $params['TakeWin'] ?? 0;

            // 获取当前余额
            $currentBalance = \app\service\WalletService::getBalance($player->id);

            // 检查幂等性
            $betKey = "kt:bet:lock:{$orderNo}";
            $isDuplicate = !\support\Redis::set($betKey, 1, ['NX', 'EX' => 300]);

            if ($isDuplicate) {
                // 重复订单，返回当前余额
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'Balance' => $currentBalance,
                ]);
            }

            // 准备队列参数
            $queueParams = [
                'order_no' => $orderNo,
                'amount' => $bet,
                'platform_id' => $this->service->platform->id,
                'game_code' => $params['GameCode'] ?? '',
                'original_data' => $params,
            ];

            // 发送下注队列
            $sent = GameQueueService::sendBet('KT', $player, $queueParams);

            if ($sent) {
                // 预估余额：扣款
                $estimatedBalance = bcsub($currentBalance, $bet, 2);
                $estimatedBalance = max(0, $estimatedBalance);

                // TakeWin=1 表示立即结算
                if ($takeWin == 1) {
                    $winAmount = $params['WinAmount'] ?? 0;

                    // 发送结算队列
                    $settleParams = [
                        'order_no' => $orderNo . '_settle',
                        'bet_order_no' => $orderNo,
                        'amount' => max($winAmount, 0),
                        'result_amount' => $winAmount,
                        'original_data' => $params,
                    ];
                    GameQueueService::sendSettle('KT', $player, $settleParams);

                    // 预估余额：加上中奖金额
                    if ($winAmount > 0) {
                        $estimatedBalance = bcadd($estimatedBalance, $winAmount, 2);
                    }
                }

                $return = ['Balance' => $estimatedBalance];
            } else {
                // 队列失败，同步降级
                \support\Redis::del($betKey);
                $balance = $this->service->bet($params);

                // 是否结算
                if ($takeWin == 1) {
                    $balance = $this->betResult($params);
                }

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }
                $return = ['Balance' => $balance];
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('KT bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
        }
    }

    /**
     * 結算
     * @param $params
     * @return Response
     */
    public function betResult($params)
    {
        return $this->service->betResulet($params);
    }

    /**
     * 重新結算
     * @param Request $request
     * @return Response
     */
    public function reBetResult(Request $request): Response
    {
        try {
            $params = $request->post();

            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('rsg_live余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->reBetResulet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['bet_sn' => $data['bet_sn'], 'balance' => $balance]);
        } catch (Exception $e) {
            Log::error('KT reBetResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '重新结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
        }
    }

    /**
     * 投注与结算取消
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->post();
            $hash = $request->get('Hash');

            $this->logger->info('kt_server 取消投注记录', ['params' => $params, 'get' => $hash]);

            // 检查必要参数
            if (!isset($params['Username']) || !isset($params['TransactionId'])) {
                return $this->error(self::API_CODE_OTHER_ERROR, '缺少必要参数');
            }

            $this->service->verifyToken($params, $hash);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $this->service->player = \app\model\Player::query()->where('uuid', $params['Username'])->first();
            $player = $this->service->player;

            $orderNo = $params['TransactionId'];
            $refundAmount = $params['Amount'] ?? 0;

            // 获取当前余额
            $currentBalance = \app\service\WalletService::getBalance($player->id);

            // 准备队列参数
            $queueParams = [
                'order_no' => $orderNo,
                'bet_order_no' => $params['BetTransactionId'] ?? $orderNo,
                'amount' => $refundAmount,
                'original_data' => $params,
            ];

            // 发送取消队列
            $sent = GameQueueService::sendCancel('KT', $player, $queueParams);

            if ($sent) {
                // 预估余额：退款
                $estimatedBalance = bcadd($currentBalance, $refundAmount, 2);

                $return = ['Balance' => $estimatedBalance];
            } else {
                // 队列失败，同步降级
                $balance = $this->service->cancelBet($params);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }
                $return = ['Balance' => $balance];
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('KT cancelBet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '取消投注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
        }
    }

    /**
     * 打鱼机预扣金额
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
        try {
            $params = $request->post();

            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('打鱼退款金额记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->refund($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $balance);
        } catch (Exception $e) {
            Log::error('KT refund failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('KT', '退款异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_OTHER_ERROR);
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
        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($data)
        ));
    }

    /**
     * 失败响应方法
     *
     * @param string $code 错误码
     * @param string|null $message 自定义错误信息
     * @param array $data 额外数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function error(string $code, ?string $message = null, array $data = [], int $httpCode = 400): Response
    {
        $responseData = [
            'ErrorCode' => $code,
            'Message' => $message ?: (self::API_CODE_MAP[$code] ?? '未知错误'),
        ];

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
        ));
    }
}