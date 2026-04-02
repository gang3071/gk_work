<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameQueueService;
use Exception;
use support\Log;
use support\Request;
use support\Response;

class MtGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = '00000';
    public const API_CODE_INVALID_PARAM = '20001';
    public const API_CODE_DECRYPT_ERROR = '20002';
    public const API_CODE_MAINTENANCE = '20003';
    public const API_CODE_FAILURE = '20004';
    public const API_CODE_PLAYER_NOT_EXIST = '20101';
    public const API_CODE_INSUFFICIENT_BALANCE = '20102';
    public const API_CODE_ORDER_NOT_EXIST = '20201';
    public const API_CODE_DUPLICATE_ORDER = '20202';
    public const API_CODE_ORDER_SETTLED = '20203';
    public const API_CODE_ORDER_CANCELLED = '20204';
    public const API_CODE_DUPLICATE_SERIAL = '20501';

    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => '成功',
        self::API_CODE_INVALID_PARAM => '無效參數',
        self::API_CODE_DECRYPT_ERROR => '解密異常',
        self::API_CODE_MAINTENANCE => '系統維護中',
        self::API_CODE_FAILURE => '執行失敗',
        self::API_CODE_PLAYER_NOT_EXIST => '此玩家帳戶不存在',
        self::API_CODE_INSUFFICIENT_BALANCE => '餘額不足',
        self::API_CODE_ORDER_NOT_EXIST => '單號不存在',
        self::API_CODE_DUPLICATE_ORDER => '重複單號',
        self::API_CODE_ORDER_SETTLED => '注單已結算',
        self::API_CODE_ORDER_CANCELLED => '注單已取消',
        self::API_CODE_DUPLICATE_SERIAL => '重覆序號',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    protected array $column = [
        'msg',
        'apici',
        'apisi',
        'apits'
    ];

    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_MT);
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

            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->balance();
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => $balance]);
        } catch (Exception $e) {
            Log::error('MT balance failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '余额查询异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
        }
    }

    /**
     * 下注（异步队列版）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT下注请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数
            $queueParams = [
                'order_no' => $data['bet_sn'],  // MT使用bet_sn
                'amount' => $data['order_money'],  // MT使用order_money
                'platform_id' => $this->service->platform->id,
                'game_type' => $data['gameType'] ?? '',
                'game_code' => $data['game_code'],  // MT使用game_code
                'game_name' => $data['gameName'] ?? '',
                'order_time' => $data['order_time'] ?? '',  // 订单时间
                'original_data' => $data,
            ];

            // 4. 发送到队列
            $sent = GameQueueService::sendBet('MT', $player, $queueParams);

            if (!$sent) {
                // 队列发送失败，降级到同步处理
                Log::warning('MT: 队列发送失败，降级到同步模式');
                $balance = $this->service->bet($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => $balance]);
            }

            // 5. 快速返回（返回预估余额）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $estimatedBalance = bcsub($currentBalance, $data['order_money'], 2);

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT下注已入队（快速响应）', [
                'order_no' => $data['bet_sn'],
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => max(0, $estimatedBalance)  // 预估余额
            ]);

        } catch (Exception $e) {
            Log::error('MT bet failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '下注异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
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
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT取消下注请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数
            $queueParams = [
                'order_no' => 'CANCEL_' . $data['bet_sn'],  // MT使用bet_sn
                'bet_order_no' => $data['bet_sn'],  // 原下注订单号
                'amount' => $data['order_money'],  // MT使用order_money
                'platform_id' => $this->service->platform->id,
                'original_data' => $data,
            ];

            // 4. 发送到队列
            $sent = GameQueueService::sendCancel('MT', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                Log::warning('MT: 取消队列发送失败，降级到同步模式');
                $balance = $this->service->cancelBet($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => $balance]);
            }

            // 5. 快速返回（返回预估余额）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $estimatedBalance = bcadd($currentBalance, $data['order_money'], 2);

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT取消下注已入队（快速响应）', [
                'order_no' => $data['bet_sn'],
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => $estimatedBalance  // 预估余额
            ]);

        } catch (Exception $e) {
            Log::error('MT cancelBet failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '取消下注异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
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
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT结算请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数
            $queueParams = [
                'order_no' => $data['bet_sn'],  // 结算使用bet_sn
                'bet_order_no' => $data['bet_sn'],  // MT平台结算和下注使用相同的bet_sn
                'amount' => $data['win_money'] ?? 0,  // MT使用win_money
                'status' => $data['status'] ?? null,  // 状态：2=未中奖, 3=中奖, 4=和局
                'settle_time' => $data['settle_time'] ?? '',  // 结算时间
                'platform_id' => $this->service->platform->id,
                'game_type' => $data['gameType'] ?? '',
                'game_code' => $data['game_code'] ?? '',
                'original_data' => $data,
            ];

            // 4. 发送到队列
            $sent = GameQueueService::sendSettle('MT', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                Log::warning('MT: 结算队列发送失败，降级到同步模式');
                $balance = $this->service->betResulet($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'bet_sn' => $data['bet_sn'],
                    'balance' => $balance
                ]);
            }

            // 5. 快速返回（返回预估余额）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $winMoney = $data['win_money'] ?? 0;
            $status = $data['status'] ?? null;
            const BET_STATUS_NOT = 2;  // 未中奖

            // 只有非"未中奖"状态才预估加款
            $estimatedBalance = ($status !== BET_STATUS_NOT && $winMoney > 0)
                ? bcadd($currentBalance, $winMoney, 2)
                : $currentBalance;

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT结算已入队（快速响应）', [
                'bet_sn' => $data['bet_sn'],
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'bet_sn' => $data['bet_sn'],
                'balance' => $estimatedBalance  // 预估余额
            ]);

        } catch (Exception $e) {
            Log::error('MT betResult failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '结算异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
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
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT重新结算请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数
            $queueParams = [
                'order_no' => $data['bet_sn'],
                'bet_order_no' => $data['bet_sn'],  // MT平台使用相同的bet_sn
                'amount' => $data['win_money'] ?? 0,  // MT使用win_money
                'status' => $data['status'] ?? null,  // 状态
                'settle_time' => $data['settle_time'] ?? '',  // 结算时间
                'platform_id' => $this->service->platform->id,
                'original_data' => $data,
            ];

            // 4. 发送到队列
            $sent = GameQueueService::sendSettle('MT', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                Log::warning('MT: 重新结算队列发送失败，降级到同步模式');
                $balance = $this->service->reBetResulet($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'bet_sn' => $data['bet_sn'],
                    'balance' => $balance
                ]);
            }

            // 5. 快速返回
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT重新结算已入队（快速响应）', [
                'bet_sn' => $data['bet_sn'],
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'bet_sn' => $data['bet_sn'],
                'balance' => $currentBalance
            ]);

        } catch (Exception $e) {
            Log::error('MT reBetResult failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '重新结算异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
        }
    }

    /**
     * 送礼/打赏（异步队列版）
     * @param Request $request
     * @return Response
     */
    public function gift(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT打赏请求（异步）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            // 3. 准备队列参数（打赏是扣款操作，使用bet队列）
            $queueParams = [
                'order_no' => $data['tip_sn'],  // MT使用tip_sn
                'amount' => $data['money'] ?? 0,  // MT使用money字段
                'platform_id' => $this->service->platform->id,
                'game_type' => 'gift',
                'game_code' => $data['game_code'] ?? 'gift',
                'game_name' => '打赏',
                'order_time' => $data['tran_time'] ?? '',  // MT使用tran_time
                'original_data' => $data,
            ];

            // 4. 发送到队列（打赏是扣款，使用sendBet）
            $sent = GameQueueService::sendBet('MT', $player, $queueParams);

            if (!$sent) {
                // 降级到同步处理
                Log::warning('MT: 打赏队列发送失败，降级到同步模式');
                $balance = $this->service->gift($data);

                if ($this->service->error) {
                    return $this->error($this->service->error);
                }

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'balance' => $balance
                ]);
            }

            // 5. 快速返回（返回预估余额，打赏是扣款）
            $currentBalance = $player->machine_wallet()->value('money') ?? 0;
            $giftAmount = $data['money'] ?? 0;
            $estimatedBalance = bcsub($currentBalance, $giftAmount, 2);

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT打赏已入队（快速响应）', [
                'tip_sn' => $data['tip_sn'],
                'amount' => $giftAmount,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => max(0, $estimatedBalance)  // 预估余额（扣款后）
            ]);

        } catch (Exception $e) {
            Log::error('MT gift failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '打赏异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
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
        $responseData = [
            'code' => self::API_CODE_SUCCESS, // 使用业务状态码常量
            'msg' => $message ?: self::API_CODE_MAP[self::API_CODE_SUCCESS],
            'timestamp' => time(),
            'data' => $data,
        ];

        // return new Response(
        //     $httpCode,
        //     ['Content-Type' => 'text/plain'],
        //     json_encode($responseData, JSON_UNESCAPED_UNICODE)
        // );


        return new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
            $this->service->encrypt(json_encode($responseData, JSON_UNESCAPED_UNICODE))
        );
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
    public function error(string $code, ?string $message = null, array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'code' => $code,
            'message' => $message ?: (self::API_CODE_MAP[$code] ?? '未知错误'),
            'timestamp' => time(),
            'data' => $data,
        ];

        return new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
            $this->service->encrypt(json_encode($responseData, JSON_UNESCAPED_UNICODE))
        );
    }

    /**
     * 根据业务错误码返回响应
     *
     * @param string $apiCode 业务错误码常量
     * @param array $data 响应数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function responseWithApiCode(string $apiCode, array $data = [], int $httpCode = 200): Response
    {
        if ($apiCode === self::API_CODE_SUCCESS) {
            return $this->success(self::API_CODE_MAP[$apiCode], $data, $httpCode);
        }

        return $this->error($apiCode, self::API_CODE_MAP[$apiCode] ?? null, $data, $httpCode);
    }
}