<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\model\PlayGameRecord;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\game\TNineServiceInterface;
use app\service\RedisLuaScripts;
use Exception;
use support\Log;
use support\Request;
use support\Response;

/**
 * T9真人视讯平台
 */
class TNineGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_ERROR = 1;
    public const API_CODE_SIGN_ERROR = 3;
    public const API_CODE_INSUFFICIENT_BALANCE = 108;
    public const API_CODE_ORDER_NOT_FOUND = 110;


    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'Success',
        self::API_CODE_ERROR => '失敗',
        self::API_CODE_SIGN_ERROR => '簽名錯誤',
        self::API_CODE_INSUFFICIENT_BALANCE => '會員餘額不足',
        self::API_CODE_ORDER_NOT_FOUND => '檔案不存在',

    ];

    public const ORDER_STATUS_SUCCESS = 1; //已派彩/贈禮成功
    public const ORDER_STATUS_PENDING_SETTLEMENT = 2;  //待結算
    public const ORDER_STATUS_FAIL = 3;  //不結算/贈禮失敗
    public const ORDER_STATUS_PENDING = 10;

    public const ORDER_STATUS_MAP = [
        self::ORDER_STATUS_SUCCESS => PlayGameRecord::SETTLEMENT_STATUS_SETTLED,
        self::ORDER_STATUS_PENDING_SETTLEMENT => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED,
        self::ORDER_STATUS_FAIL => PlayGameRecord::SETTLEMENT_STATUS_CANCELLED,
        self::ORDER_STATUS_PENDING => PlayGameRecord::SETTLEMENT_STATUS_CONFIRM,
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    /**
     * @var TNineServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_TNINE);
        $this->logger = Log::channel('tnine_server');
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

            $this->logger->info('t9余额查询记录', ['params' => $params]);

            $this->service->verifySign($params);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $users = $params['Members'];

            // ✅ 性能优化：批量查询玩家和余额，避免 N+1 问题
            // 1. 批量查询玩家（1 次数据库查询）
            $players = Player::query()->whereIn('uuid', $users)->get()->keyBy('uuid');

            $this->logger->info('t9余额查询记录:players', ['players' => $players]);

            // 2. 批量查询余额（使用 WalletService::getBatchBalance）
            $playerIds = $players->pluck('id')->toArray();
            $balances = \app\service\WalletService::getBatchBalance($playerIds);

            $this->logger->info('t9余额查询记录:balances', ['balances' => $balances]);

            // 3. 组装返回数据
            $return = [];
            $time = date('Y-m-d H:i:s');

            foreach ($users as $userId) {
                $player = $players->get($userId);
                if (!$player) {
                    continue;
                }

                $return[] = [
                    'MemberAccount' => $userId,
                    'Balance' => $balances[$player->id] ?? 0,
                    'SyncTime' => $time,
                ];
            }

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }

    /**
     * 下注（Lua 原子操作版本 - 支持批量订单）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('t9下注请求（Lua原子）', ['params' => $params]);

            $this->service->verifySign($params);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            /** @var Player $player */
            $player = Player::query()->where('uuid', $params['MemberAccount'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_ERROR);
            }

            $orders = $params['OrderList'] ?? [];
            $return = ['OrderList' => []];
            $finalBalance = 0;

            // 批量处理每个订单（每个订单一次 Lua 原子操作）
            foreach ($orders as $order) {
                $orderNo = (string)($order['OrderNumber'] ?? '');
                $bet = $order['BetAmount'];

                // Lua 原子下注
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $bet,
                    'game_code' => $params['GameType'] ?? '',
                    'transaction_type' => TransactionType::BET,
                    'original_data' => $order,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric', 'min:0'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicBet');

                $result = RedisLuaScripts::atomicBet($player->id, 'TNINE', $luaParams);

                // 审计日志
                logLuaScriptCall('bet', 'TNINE', $player->id, $luaParams);

                // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步）
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveBet('TNINE', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $bet,
                        'game_code' => $params['GameType'] ?? '',
                        'original_data' => $order,
                    ]);
                }

                // 游戏交互日志
                logGameInteraction('TNINE', 'bet', $params, [
                    'ok' => $result['ok'],
                    'balance' => $result['balance'],
                    'order_no' => $orderNo,
                ]);


                if ($result['ok'] === 0) {
                    if ($result['error'] === 'duplicate_order') {
                        $this->logger->info('TNine下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                        $return['OrderList'][] = [
                            'OrderNumber' => $orderNo,
                            'MerchantOrderNumber' => $orderNo,
                        ];
                        $finalBalance = $result['balance'];
                        continue;
                    } elseif ($result['error'] === 'insufficient_balance') {
                        return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                    }
                }

                $return['OrderList'][] = [
                    'OrderNumber' => $orderNo,
                    'MerchantOrderNumber' => $orderNo,
                ];
                $finalBalance = $result['balance'];
            }

            $this->logger->info('TNine下注成功（Lua原子）', ['count' => count($orders)]);

            $return['Balance'] = (float)$finalBalance;
            $return['SyncTime'] = date('Y-m-d H:i:s');

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }

    /**
     * 結算（Lua 原子操作版本）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('t9结算请求（Lua原子）', ['params' => $params]);

            $this->service->verifySign($params);

            /** @var Player $player */
            $player = Player::query()->where('uuid', $params['MemberAccount'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_ERROR);
            }

            $orderNo = (string)($params['OrderNumber'] ?? '');
            $money = $params['GameAmount'] ?? 0;  // 派彩金额
            $winAmount = $params['WinAmount'] ?? 0;  // 净输赢

            // Lua 原子结算
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => max($money, 0),
                'diff' => $winAmount,  // TNine 的 WinAmount 是净输赢
                'transaction_type' => TransactionType::SETTLE,
                'original_data' => $params,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $result = RedisLuaScripts::atomicSettle($player->id, 'TNINE', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'TNINE', $player->id, $luaParams);

            // 保存结算记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveSettle('TNINE', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => max($money, 0),
                    'diff' => $winAmount,
                    'game_code' => $params['GameType'] ?? '',
                    'original_data' => $params,
                ]);
            }

            // 处理返回结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_settle') {
                $this->logger->info('TNine重复结算（Lua检测）', ['order_no' => $orderNo]);
            }

            $this->logger->info('TNine结算成功（Lua原子）', ['order_no' => $orderNo]);

            $return = [
                'MerchantOrderNumber' => $orderNo,
                'SyncTime' => date('Y-m-d H:i:s'),
                'Balance' => (float)$result['balance'],
            ];

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }


    /**
     * 检查
     * @param Request $request
     * @return Response
     */
    public function check(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('商戶注單查核', ['params' => $params]);

            $this->service->verifySign($params);
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->where('order_no', $params['OrderNumber'])->first();
            if (empty($record)) {
                return $this->error(self::API_CODE_ORDER_NOT_FOUND);
            }
            $origin = json_decode($record->action_data, true);
            $return = [
                'MerchantOrderNumber' => $record->id,
                'OrderStatus' => $origin['OrderStatus'],
                'BetAmount' => $origin['BetAmount'],
                'ValidBetAmount' => $origin['ValidBetAmount'],
                'WinAmount' => $origin['WinAmount'],
            ];

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine check failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '订单查核异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }

    /**
     * 商戶注單修改
     * @param Request $request
     * @return Response
     */
    public function update(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('商戶注單修改', ['params' => $params]);

            $this->service->verifySign($params);
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->where('order_no', $params['OrderNumber'])->first();

            if (empty($record)) {
                return $this->error(self::API_CODE_ORDER_NOT_FOUND);
            }

            $record->settlement_status = self::ORDER_STATUS_MAP[$params['OrderStatus']];


            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS]);
        } catch (Exception $e) {
            Log::error('TNine update failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '订单修改异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }

    /**
     * 送礼
     * @param Request $request
     * @return Response
     */
    public function gift(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('商戶會員贈禮確認', ['params' => $params]);

            $this->service->verifySign($params);
            $return = $this->service->gift($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine gift failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '赠礼异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
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
            'Error' => [
                'Code' => self::API_CODE_SUCCESS,
                'Message' => self::API_CODE_MAP[self::API_CODE_SUCCESS],
            ],
        ];

        if (!empty($data)) {
            $responseData['Data'] = $data;
        }


        $this->logger->info('t9结算记录', $responseData);


        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
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
            'Data' => [],
            'Error' => [
                'Code' => $code,
                'Message' => self::API_CODE_MAP[$code],
            ],
        ];

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
        ));
    }
}