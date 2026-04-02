<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameQueueService;
use support\Log;
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

    private $logger;

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
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
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
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG下注请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数
            $queueParams = [
                'order_no' => $data['SequenNumber'],  // RSG使用SequenNumber
                'amount' => $data['Amount'],
                'platform_id' => $this->service->platform->id,
                'game_code' => $data['GameId'],
                'original_data' => $data,
            ];

            // 4. 发送到队列
            $sent = GameQueueService::sendBet('RSG', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                $this->logger->warning('RSG: 队列发送失败，降级到同步模式');
                $balance = $this->service->bet($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
            }

            // 5. 快速返回（返回预估余额）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $estimatedBalance = bcsub($currentBalance, $data['Amount'], 2);

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->logger->info('RSG下注已入队（快速响应）', [
                'order_no' => $data['SequenNumber'],
                'elapsed_ms' => round($elapsed, 2),
                'estimatedBalance' => $estimatedBalance
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => (float)$estimatedBalance]);
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
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG取消下注请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数（⚠️ RSG cancelBet 使用 BetAmount）
            $queueParams = [
                'order_no' => 'CANCEL_' . $data['SequenNumber'],
                'bet_order_no' => $data['SequenNumber'],
                'amount' => $data['BetAmount'],  // ⚠️ 注意：cancelBet使用BetAmount
                'platform_id' => $this->service->platform->id,
                'original_data' => $data,
            ];

            // 4. 发送到队列
            $sent = GameQueueService::sendCancel('RSG', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                $this->logger->warning('RSG: 取消队列发送失败，降级到同步模式');
                $balance = $this->service->cancelBet($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
            }

            // 5. 快速返回（返回预估余额）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $estimatedBalance = bcadd($currentBalance, $data['BetAmount'], 2);

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->logger->info('RSG取消下注已入队（快速响应）', [
                'order_no' => $data['SequenNumber'],
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => $estimatedBalance
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
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG结算请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                $this->logger->warning('betResult: 结算队');
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数
            $queueParams = [
                'order_no' => $data['SequenNumber'],
                'bet_order_no' => $data['SequenNumber'],  // RSG结算使用相同的SequenNumber
                'amount' => $data['Amount'],  // 中奖金额
                'bet_amount' => $data['BetAmount'] ?? 0,  // 下注金额
                'play_time' => $data['PlayTime'] ?? '',
                'is_game_flow_end' => $data['IsGameFlowEnd'] ?? false,
                'belong_sequen_number' => $data['BelongSequenNumber'] ?? '',
                'platform_id' => $this->service->platform->id,
                'game_code' => $data['GameId'] ?? '',
                'original_data' => $data,
            ];

            // 4. 发送到队列
            $sent = GameQueueService::sendSettle('RSG', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                $this->logger->warning('RSG: 结算队列发送失败，降级到同步模式');
                $balance = $this->service->betResulet($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
            }

            // 5. 快速返回（返回预估余额 - 只有Amount>0才加钱）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $winAmount = $data['Amount'] ?? 0;
            $estimatedBalance = ($winAmount > 0)
                ? bcadd($currentBalance, $winAmount, 2)
                : $currentBalance;

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->logger->info('RSG结算已入队（快速响应）', [
                'order_no' => $data['SequenNumber'],
                'amount' => $winAmount,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => $estimatedBalance
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
     * 重新結算（异步队列版）
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
            $this->logger->info('RSG重新结算请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数（类似betResult）
            $queueParams = [
                'order_no' => $data['SequenNumber'],
                'bet_order_no' => $data['SequenNumber'],
                'amount' => $data['Amount'] ?? 0,
                'bet_amount' => $data['BetAmount'] ?? 0,
                'play_time' => $data['PlayTime'] ?? '',
                'platform_id' => $this->service->platform->id,
                'game_code' => $data['GameId'] ?? '',
                'is_rebet' => true,  // 标记为重新结算
                'original_data' => $data,
            ];

            // 4. 发送到队列
            $sent = GameQueueService::sendSettle('RSG', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                $this->logger->warning('RSG: 重新结算队列发送失败，降级到同步模式');
                $balance = $this->service->reBetResulet($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
            }

            // 5. 快速返回
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->logger->info('RSG重新结算已入队（快速响应）', [
                'order_no' => $data['SequenNumber'],
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => $currentBalance
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
            $this->logger->info('RSG Jackpot中奖请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数（⚠️ jackpot特殊：无下注，直接中奖）
            $queueParams = [
                'order_no' => $data['SequenNumber'],
                'amount' => $data['Amount'],  // 中奖金额
                'platform_id' => $this->service->platform->id,
                'game_code' => $data['GameId'] ?? '',
                'play_time' => $data['PlayTime'] ?? '',
                'is_jackpot' => true,  // ⚠️ 标记为jackpot类型
                'original_data' => $data,
            ];

            // 4. 发送到队列（jackpot使用settle操作）
            $sent = GameQueueService::sendSettle('RSG', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                $this->logger->warning('RSG: Jackpot队列发送失败，降级到同步模式');
                $balance = $this->service->jackpotResult($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
            }

            // 5. 快速返回（返回预估余额）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $jackpotAmount = $data['Amount'] ?? 0;
            $estimatedBalance = bcadd($currentBalance, $jackpotAmount, 2);

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->logger->info('RSG Jackpot已入队（快速响应）', [
                'order_no' => $data['SequenNumber'],
                'amount' => $jackpotAmount,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => $estimatedBalance
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
     * 打鱼机预扣金额（异步队列版）
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
            $this->logger->info('RSG打鱼机预扣金额请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数（⚠️ prepay使用SessionId作为order_no）
            $queueParams = [
                'order_no' => $data['SessionId'],  // ⚠️ 使用SessionId
                'amount' => $data['Amount'],
                'platform_id' => $this->service->platform->id,
                'game_code' => $data['GameId'] ?? '',
                'type' => 'prepay',  // ⚠️ 标记为prepay类型
                'original_data' => $data,
            ];

            // 4. 发送到队列（prepay使用bet操作）
            $sent = GameQueueService::sendBet('RSG', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                $this->logger->warning('RSG: 预扣队列发送失败，降级到同步模式');
                $balance = $this->service->prepay($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $balance);
            }

            // 5. 快速返回（返回预估余额 - prepay特殊逻辑）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $requestAmount = $data['Amount'] ?? 0;

            // ⚠️ prepay特殊：余额不足时扣除所有金额
            $actualDeductAmount = min($currentBalance, $requestAmount);
            $estimatedBalance = bcsub($currentBalance, $actualDeductAmount, 2);

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->logger->info('RSG打鱼机预扣已入队（快速响应）', [
                'session_id' => $data['SessionId'],
                'request_amount' => $requestAmount,
                'actual_deduct' => $actualDeductAmount,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            // 返回格式：['Balance' => ..., 'Amount' => ...]
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => max(0, $estimatedBalance),
                'Amount' => $actualDeductAmount
            ]);

        } catch (Throwable $e) {
            $this->logger->error('RSG打鱼机预扣金额异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('RSG', '预扣金额异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 打鱼机退款（异步队列版）
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['Msg']);
            $this->logger->info('RSG打鱼机退款请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['UserId'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数（⚠️ refund使用SessionId查找原prepay记录）
            $queueParams = [
                'order_no' => 'REFUND_' . $data['SessionId'],  // 新订单号
                'session_id' => $data['SessionId'],  // ⚠️ 用于查找原prepay记录
                'amount' => $data['Amount'],
                'platform_id' => $this->service->platform->id,
                'game_code' => $data['GameId'] ?? '',
                'type' => 'refund',  // ⚠️ 标记为refund类型
                'original_data' => $data,
            ];

            // 4. 发送到队列（refund使用settle操作）
            $sent = GameQueueService::sendSettle('RSG', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                $this->logger->warning('RSG: 退款队列发送失败，降级到同步模式');
                $balance = $this->service->refund($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $balance);
            }

            // 5. 快速返回（返回预估余额）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $refundAmount = $data['Amount'] ?? 0;
            $estimatedBalance = bcadd($currentBalance, $refundAmount, 2);

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->logger->info('RSG打鱼机退款已入队（快速响应）', [
                'session_id' => $data['SessionId'],
                'amount' => $refundAmount,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            // 返回格式：['Balance' => ..., 'Amount' => ...]
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => $estimatedBalance,
                'Amount' => $refundAmount
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