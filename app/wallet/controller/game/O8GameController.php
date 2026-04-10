<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\GameExtend;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\O8ServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
use Exception;
use Firebase\JWT\JWT;
use support\Log;
use support\Request;
use support\Response;

/**
 * O8平台
 */
class O8GameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_TRANSACTIONID_DUPLICATE = 1;
    public const API_CODE_AMOUNT_OVER_BALANCE = 2;
    public const API_CODE_REFERENCEID_NOT_FOUND = 3;
    public const API_CODE_TOKEN_DOES_NOT_EXIST = 4;
    public const API_CODE_AUTHTOKEN_EXPIRED = 5;
    public const API_CODE_SESSION_TOKEN_EXPIRED = 6;
    public const API_CODE_TARGET_ID_NOT_FOUND = 7;
    public const API_CODE_ACCOUNT_LOCKED = 8;
    public const API_CODE_CERTIFICATE_ERROR = 10;
    public const API_CODE_REQUEST_A_TIMEOUT = 11;
    public const API_CODE_DATABASE_ERROR = 12;
    public const API_CODE_TRANSACTION_NOT_EXIST = 600;
    public const API_CODE_TRANSACTION_ALREADY_CANCELLED = 610;
    public const API_CODE_WAGER_TOO_EXPENSIVE = 1006;


    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'Success',
        self::API_CODE_TRANSACTIONID_DUPLICATE => '交易識別碼重複',
        self::API_CODE_AMOUNT_OVER_BALANCE => '餘額不足',
        self::API_CODE_REFERENCEID_NOT_FOUND => '參考編號不存在',
        self::API_CODE_TOKEN_DOES_NOT_EXIST => '令牌無效',
        self::API_CODE_AUTHTOKEN_EXPIRED => '身份令牌不存在',
        self::API_CODE_SESSION_TOKEN_EXPIRED => '對話令牌不存在',
        self::API_CODE_TARGET_ID_NOT_FOUND => '目標交易ID不存在',
        self::API_CODE_ACCOUNT_LOCKED => '帳戶鎖定',
        self::API_CODE_CERTIFICATE_ERROR => 'Token has expired',
        self::API_CODE_REQUEST_A_TIMEOUT => '請求逾時',
        self::API_CODE_DATABASE_ERROR => '數據庫錯誤',
        self::API_CODE_TRANSACTION_NOT_EXIST => 'Transaction does not exist',
        self::API_CODE_TRANSACTION_ALREADY_CANCELLED => 'Transaction has already been cancelled',
        self::API_CODE_WAGER_TOO_EXPENSIVE => 'Wager too expensive',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    /**
     * @var O8ServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_O8);
        $this->logger = Log::channel('o8_server');
    }


    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function token(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('o8_server 获取token', ['params' => $params]);

            $clientId = $params['client_id'];
            $clientSecret = $params['client_secret'];
            $grantType = $params['grant_type'];
            $scope = $params['scope'];

            $SessionTokenPayload = [
                'grant_type' => $grantType, // 签发时间
                'scope' => $scope, // 签发时间
                'iat' => time(), // 签发时间
                'nbf' => time(), // 某个时间点后才能访问
                'exp' => time() + 3600, // 过期时间
            ];

            $key = $clientId . $clientSecret;
            $token = JWT::encode($SessionTokenPayload, $key, 'HS256');


            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'wallet',
            ]);
        } catch (Exception $e) {
            Log::error('O8 token failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('O8', '获取Token异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_CERTIFICATE_ERROR);
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
            $token = request()->header('authorization');

            $this->logger->info('o8_server余额查询记录', ['params' => $params, 'token' => $token]);

            $this->service->verifyToken($token);
            $users = $params['users'];

            // ✅ 性能优化：批量查询玩家和余额，避免 N+1 问题
            $userIds = array_column($users, 'userid');

            // 1. 批量查询玩家（1 次数据库查询）
            $players = Player::query()->whereIn('uuid', $userIds)->get()->keyBy('uuid');

            // 2. 批量查询余额（使用 WalletService::getBatchBalance）
            $playerIds = $players->pluck('id')->toArray();
            $balances = \app\service\WalletService::getBatchBalance($playerIds);

            $this->logger->info('O8余额查询:余额数据', ['balances' => $balances]);

            // 3. 组装返回数据
            $return = [];
            foreach ($users as $user) {
                $player = $players->get($user['userid']);
                if (!$player) {
                    continue;
                }

                $balance = $balances[$player->id] ?? 0;
                $return['users'][] = [
                    'userid' => $user['userid'],
                    'wallets' => [
                        ['code' => 'MainWallet', 'bal' => round((float)$balance, 2), 'cur' => 'TWD']
                    ]
                ];
            }

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('O8 balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('O8', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * 下注（Lua原子操作 - 批量处理）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');

            $this->logger->info('o8_server下注请求（Lua原子）', ['params' => $params, 'token' => $token]);

            $this->service->verifyToken($token);

            $orders = $params['transactions'];
            $return = ['transactions' => []];

            // 批量处理每个订单（每个订单一次 Lua 原子操作）
            foreach ($orders as $order) {
                /** @var Player $player */
                $player = Player::query()->where('uuid', $order['userid'])->first();
                if (empty($player)) {
                    continue;
                }
                $this->service->player = $player;

                $orderNo = $order['ptxid'];
                $bet = $order['amt'];

                //判断当前设备是否爆机
                if ($this->service->checkAndHandleMachineCrash()) {
                    return $this->error($this->service->error);
                }

                // 查询平台ID
                $platformId = GameExtend::query()
                    ->where('code', $order['gamecode'])
                    ->value('platform_id') ?? $this->service->platform->id;

                // Lua 原子下注
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $platformId,
                    'amount' => $bet,
                    'game_code' => $order['gamecode'],
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

                $result = RedisLuaScripts::atomicBet($player->id, 'O8', $luaParams);

                // 审计日志
                logLuaScriptCall('bet', 'O8', $player->id, $luaParams);
                // 游戏交互日志
                logGameInteraction('O8', 'refund', $params, [
                    'ok' => $result['ok'],
                    'balance' => $result['balance'],
                ]);

                // 游戏交互日志
                logGameInteraction('O8', 'settle', $params, [
                    'ok' => $result['ok'],
                    'balance' => $result['balance'],
                ]);

                // 游戏交互日志
                logGameInteraction('O8', 'bet', $params, [
                    'ok' => $result['ok'],
                    'balance' => $result['balance'],
                ]);


                if ($result['ok'] === 0) {
                    if ($result['error'] === 'duplicate_order') {
                        $this->logger->info('O8下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                        $return['transactions'][] = [
                            'txid' => $orderNo,
                            'ptxid' => $order['ptxid'],
                            'bal' => round((float)$result['balance'], 2),
                            'cur' => 'TWD',
                            'dup' => true
                        ];
                        continue;
                    } elseif ($result['error'] === 'insufficient_balance') {
                        $this->logger->warning('O8下注失败：余额不足', [
                            'order_no' => $orderNo,
                            'bet_amount' => $bet,
                            'balance' => $result['balance']
                        ]);
                        return $this->error(self::API_CODE_WAGER_TOO_EXPENSIVE);
                    }

                    // ✅ 未知错误：记录日志并返回数据库错误
                    $this->logger->error('O8下注失败：未知错误', [
                        'order_no' => $orderNo,
                        'error' => $result['error'],
                        'result' => $result
                    ]);
                    return $this->error(self::API_CODE_DATABASE_ERROR);
                }

                // 保存下注记录到 Redis（供 GameRecordSyncWorker 同步和推送）
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveBet('O8', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $platformId,
                        'amount' => $bet,
                        'game_code' => $order['gamecode'] ?? '',
                        'original_data' => $order,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);
                }

                $return['transactions'][] = [
                    'txid' => $orderNo,
                    'ptxid' => $order['ptxid'],
                    'bal' => round((float)$result['balance'], 2),
                    'cur' => 'TWD',
                    'dup' => false
                ];
            }

            $this->logger->info('O8下注成功（Lua原子）', ['count' => count($orders),'return'=>$return]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('O8 bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('O8', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * 結算（Lua原子操作 - 批量处理）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');

            $this->logger->info('o8_server结算请求（Lua原子）', ['params' => $params, 'token' => $token]);

            $this->service->verifyToken($token);

            $orders = $params['transactions'];
            $return = ['transactions' => []];

            // 批量处理每个订单（每个订单一次 Lua 原子操作）
            foreach ($orders as $order) {
                /** @var Player $player */
                $player = Player::query()->where('uuid', $order['userid'])->first();
                if (empty($player)) {
                    continue;
                }

                $orderNo = $order['refptxid'];
                $txtype = $order['txtype'];

                // ✅ 根据 txtype 区分 settle 和 cancel 操作
                if ($txtype == 560) {
                    // Cancel 操作：从 Redis 读取原始下注金额
                    $betRecordKey = "game:record:bet:O8:{$orderNo}";
                    if (!\support\Redis::exists($betRecordKey)) {
                        $this->logger->warning('O8取消交易失败：交易不存在', ['refptxid' => $orderNo]);
                        $currentBalance = \app\service\WalletService::getBalance($player->id);
                        $return['transactions'][] = [
                            'txid' => $orderNo,
                            'ptxid' => $order['ptxid'],
                            'bal' => round((float)$currentBalance, 2),
                            'cur' => 'TWD',
                            'dup' => false,
                            'err' => self::API_CODE_TRANSACTION_NOT_EXIST,
                            'errdesc' => self::API_CODE_MAP[self::API_CODE_TRANSACTION_NOT_EXIST]
                        ];
                        continue;
                    }

                    // 从 Redis 读取原始下注金额
                    $originalBetAmount = (float)\support\Redis::hGet($betRecordKey, 'amount');
                    if ($originalBetAmount <= 0) {
                        $this->logger->error('O8取消交易失败：无法读取原始下注金额', [
                            'refptxid' => $orderNo,
                            'redis_key' => $betRecordKey
                        ]);
                        $currentBalance = \app\service\WalletService::getBalance($player->id);
                        $return['transactions'][] = [
                            'txid' => $orderNo,
                            'ptxid' => $order['ptxid'],
                            'bal' => round((float)$currentBalance, 2),
                            'cur' => 'TWD',
                            'dup' => false,
                            'err' => self::API_CODE_DATABASE_ERROR,
                            'errdesc' => self::API_CODE_MAP[self::API_CODE_DATABASE_ERROR]
                        ];
                        continue;
                    }

                    // 验证平台传入的金额是否一致
                    $platformAmt = $order['amt'] ?? 0;
                    if (abs($platformAmt - $originalBetAmount) > 0.01) {
                        $this->logger->warning('O8取消交易：平台传入金额与原始下注金额不一致', [
                            'refptxid' => $orderNo,
                            'platform_amt' => $platformAmt,
                            'original_bet_amount' => $originalBetAmount,
                            'diff' => $platformAmt - $originalBetAmount
                        ]);
                    }

                    // 使用原始下注金额作为退款金额
                    $refundAmount = $originalBetAmount;

                    // Lua 原子取消
                    $luaParams = [
                        'order_no' => $orderNo,
                        'platform_id' => $this->service->platform->id,
                        'refund_amount' => $refundAmount,
                        'transaction_type' => TransactionType::CANCEL_REFUND,
                        'original_data' => $order,
                    ];

                    // 参数验证
                    validateLuaScriptParams($luaParams, [
                        'order_no' => ['required', 'string'],
                        'refund_amount' => ['required', 'numeric', 'min:0'],
                        'platform_id' => ['required', 'integer'],
                        'transaction_type' => ['required', 'string'],
                    ], 'atomicCancel');

                    $result = RedisLuaScripts::atomicCancel($player->id, 'O8', $luaParams);

                    // 审计日志
                    logLuaScriptCall('cancel', 'O8', $player->id, $luaParams);

                    // 处理 Lua 返回的错误
                    if ($result['ok'] === 0) {
                        $currentBalance = $result['balance'] ?? \app\service\WalletService::getBalance($player->id);

                        if ($result['error'] === 'order_not_found') {
                            $this->logger->warning('O8取消交易失败：订单不存在', ['refptxid' => $orderNo]);
                            $return['transactions'][] = [
                                'txid' => $orderNo,
                                'ptxid' => $order['ptxid'],
                                'bal' => round((float)$currentBalance, 2),
                                'cur' => 'TWD',
                                'dup' => false,
                                'err' => self::API_CODE_TRANSACTION_NOT_EXIST,
                                'errdesc' => self::API_CODE_MAP[self::API_CODE_TRANSACTION_NOT_EXIST]
                            ];
                            continue;
                        }

                        if ($result['error'] === 'duplicate_cancel') {
                            $this->logger->info('O8取消交易重复请求（Lua检测）', ['refptxid' => $orderNo]);
                            $return['transactions'][] = [
                                'txid' => $orderNo,
                                'ptxid' => $order['ptxid'],
                                'bal' => round((float)$currentBalance, 2),
                                'cur' => 'TWD',
                                'dup' => true
                            ];
                            continue;
                        }

                        // 其他错误
                        $this->logger->error('O8取消交易失败', ['refptxid' => $orderNo, 'error' => $result['error']]);
                        $return['transactions'][] = [
                            'txid' => $orderNo,
                            'ptxid' => $order['ptxid'],
                            'bal' => round((float)$currentBalance, 2),
                            'cur' => 'TWD',
                            'dup' => false,
                            'err' => self::API_CODE_DATABASE_ERROR,
                            'errdesc' => self::API_CODE_MAP[self::API_CODE_DATABASE_ERROR]
                        ];
                        continue;
                    }

                    // 保存取消记录到 Redis
                    if ($result['ok'] === 1) {
                        \app\service\GameRecordCacheService::saveCancel('O8', [
                            'order_no' => $orderNo,
                            'player_id' => $player->id,
                            'platform_id' => $this->service->platform->id,
                            'refund_amount' => $refundAmount,
                            'original_data' => $order,
                            'balance_before' => $result['old_balance'] ?? 0,
                            'balance_after' => $result['balance'],
                        ]);
                    }

                    $this->logger->info('O8取消交易成功（Lua原子）', ['refptxid' => $orderNo]);

                    $return['transactions'][] = [
                        'txid' => $orderNo,
                        'ptxid' => $order['ptxid'],
                        'bal' => round((float)$result['balance'], 2),
                        'cur' => 'TWD',
                        'dup' => false
                    ];
                    continue;
                }

                // ✅ 修复：Settle 操作应直接使用 O8 传入的 amt 字段（派彩金额）
                // O8 API 字段含义：
                // - amt: 派彩金额（返还给玩家的总金额，包括本金）
                // - turnover: 累计流水（统计用）
                // - ggr: 累计GGR（统计用）
                $settleAmount = $order['amt'] ?? 0;  // 派彩金额

                // 计算实际变化金额（用于统计）
                // diff = 派彩金额 - 下注金额 = amt - (从Redis读取的原始下注金额)
                $betRecordKey = "game:record:bet:O8:{$orderNo}";
                $originalBetAmount = \support\Redis::exists($betRecordKey)
                    ? (float)\support\Redis::hGet($betRecordKey, 'amount')
                    : 0;
                $diffAmount = bcsub($settleAmount, $originalBetAmount, 2);

                // Lua 原子结算
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $settleAmount,  // ✅ 使用派彩金额
                    'diff' => $diffAmount,      // ✅ 派彩 - 下注
                    'transaction_type' => TransactionType::SETTLE,
                    'original_data' => $order,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric'],
                    'diff' => ['required', 'numeric'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $result = RedisLuaScripts::atomicSettle($player->id, 'O8', $luaParams);

                // 审计日志
                logLuaScriptCall('settle', 'O8', $player->id, $luaParams);

                if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                    $this->logger->info('O8结算重复请求（Lua检测）', ['order_no' => $orderNo]);
                    $return['transactions'][] = [
                        'txid' => $orderNo,
                        'ptxid' => $order['ptxid'],
                        'bal' => round((float)$result['balance'], 2),
                        'cur' => 'TWD',
                        'dup' => true
                    ];
                    continue;
                }

                // 保存结算记录到 Redis（供 GameRecordSyncWorker 同步和推送）
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveSettle('O8', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $settleAmount,  // ✅ 使用派彩金额
                        'diff' => $diffAmount,      // ✅ 使用计算的差值
                        'game_code' => $order['gamecode'] ?? '',
                        'original_data' => $order,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);

                    // ✅ 结算成功后检查是否爆机，如果爆机则更新状态
                    \app\service\WalletService::checkMachineCrashAfterTransaction(
                        $player->id,
                        $result['balance'],
                        $result['old_balance'] ?? null
                    );
                }

                $return['transactions'][] = [
                    'txid' => $orderNo,
                    'ptxid' => $order['ptxid'],
                    'bal' => round((float)$result['balance'], 2),
                    'cur' => 'TWD',
                    'dup' => false
                ];
            }

            $this->logger->info('O8结算成功（Lua原子）', ['count' => count($orders),'return'=>$return]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('O8 betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('O8', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
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
            Log::error('O8 reBetResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('O8', '重新结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
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
            $this->logger->info('O8打鱼退款请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['externalroundid'] ?? $data['TxID'] ?? '');
            $refundAmount = $data['amt'] ?? $data['Amount'] ?? 0;

            // Lua 原子退款
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL_REFUND,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'O8', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'O8', $player->id, $luaParams);

            // 处理结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_cancel') {
                $this->logger->info('O8退款重复请求（Lua检测）', ['order_no' => $orderNo]);
            }

            // 保存取消记录到 Redis
            if ($result['ok'] === 1) {
                \app\service\GameRecordCacheService::saveCancel('O8', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'original_data' => $data,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);
            }

            $this->logger->info('O8退款成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'Balance' => round((float)$result['balance'], 2)
            ]);
        } catch (Exception $e) {
            Log::error('O8 refund failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('O8', '退款异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * UGS Cancel接口 - 取消失败的Debit操作
     * @param Request $request
     * @return Response
     */
    public function cancel(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');

            $this->logger->info('O8取消交易请求（Lua原子）', ['params' => $params, 'token' => $token]);

            $this->service->verifyToken($token);

            $orders = $params['transactions'];
            $return = ['transactions' => []];

            foreach ($orders as $order) {
                /** @var Player $player */
                $player = Player::query()->where('uuid', $order['userid'])->first();
                if (empty($player)) {
                    $return['transactions'][] = [
                        'txid' => '',
                        'ptxid' => $order['refptxid'],
                        'bal' => 0,
                        'cur' => 'TWD',
                        'dup' => false,
                        'err' => self::API_CODE_TARGET_ID_NOT_FOUND,
                        'errdesc' => self::API_CODE_MAP[self::API_CODE_TARGET_ID_NOT_FOUND]
                    ];
                    continue;
                }

                $refPtxid = $order['refptxid'];
                $refundAmount = $order['amt'] ?? 0;

                // UGS规范：检查订单是否存在（Lua未返回此错误）
                $betRecordKey = "game:record:bet:O8:{$refPtxid}";
                if (!\support\Redis::exists($betRecordKey)) {
                    $this->logger->warning('O8取消交易失败：交易不存在', ['refptxid' => $refPtxid]);
                    $currentBalance = \app\service\WalletService::getBalance($player->id);
                    $return['transactions'][] = [
                        'txid' => $refPtxid,
                        'ptxid' => $order['refptxid'],
                        'bal' => round((float)$currentBalance, 2),
                        'cur' => 'TWD',
                        'dup' => false,
                        'err' => self::API_CODE_TRANSACTION_NOT_EXIST,
                        'errdesc' => self::API_CODE_MAP[self::API_CODE_TRANSACTION_NOT_EXIST]
                    ];
                    continue;
                }

                // UGS规范：检查订单是否已取消（Lua未返回此错误）
                $transactionType = \support\Redis::hGet($betRecordKey, 'transaction_type');
                if ($transactionType === 'cancel' || $transactionType === 'refund') {
                    $this->logger->info('O8取消交易失败：已被取消', ['refptxid' => $refPtxid]);
                    $currentBalance = \app\service\WalletService::getBalance($player->id);
                    $return['transactions'][] = [
                        'txid' => $refPtxid,
                        'ptxid' => $order['refptxid'],
                        'bal' => round((float)$currentBalance, 2),
                        'cur' => 'TWD',
                        'dup' => true,
                        'err' => self::API_CODE_TRANSACTION_ALREADY_CANCELLED,
                        'errdesc' => self::API_CODE_MAP[self::API_CODE_TRANSACTION_ALREADY_CANCELLED]
                    ];
                    continue;
                }

                // Lua 原子取消
                $luaParams = [
                    'order_no' => $refPtxid,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'transaction_type' => TransactionType::CANCEL_REFUND,
                    'original_data' => $order,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'refund_amount' => ['required', 'numeric', 'min:0'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicCancel');

                $result = RedisLuaScripts::atomicCancel($player->id, 'O8', $luaParams);

                // 审计日志
                logLuaScriptCall('cancel', 'O8', $player->id, $luaParams);

                // 游戏交互日志
                logGameInteraction('O8', 'cancel', $params, [
                    'ok' => $result['ok'],
                    'balance' => $result['balance'],
                ]);

                // 处理Lua返回的duplicate_cancel（冗余检查，保留作为防御）
                if ($result['ok'] === 0) {
                    if ($result['error'] === 'duplicate_cancel') {
                        $this->logger->info('O8重复取消（Lua检测）', ['refptxid' => $refPtxid]);
                        $return['transactions'][] = [
                            'txid' => $refPtxid,
                            'ptxid' => $order['refptxid'],
                            'bal' => round((float)$result['balance'], 2),
                            'cur' => 'TWD',
                            'dup' => true
                        ];
                        continue;
                    }
                }

                $this->logger->info('O8取消交易成功（Lua原子）', [
                    'refptxid' => $refPtxid,
                    'refund_amount' => $refundAmount,
                    'balance_before' => $result['old_balance'],
                    'balance_after' => $result['balance'],
                ]);

                // 保存取消记录到 Redis
                if ($result['ok'] === 1) {
                    \app\service\GameRecordCacheService::saveCancel('O8', [
                        'order_no' => $refPtxid,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'refund_amount' => $refundAmount,
                        'original_data' => $order,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);
                }

                $return['transactions'][] = [
                    'txid' => $refPtxid,
                    'ptxid' => $order['refptxid'],
                    'bal' => round((float)$result['balance'], 2),
                    'cur' => 'TWD',
                    'dup' => false
                ];
            }

            $this->logger->info('O8取消交易批量完成（Lua原子）', ['count' => count($orders),'return'=>$return]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('O8 cancel failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('O8', '取消交易异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
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
            'msgId' => $code,
            'message' => $message ?: (self::API_CODE_MAP[$code] ?? '未知错误'),
            'timestamp' => time(),
            'Data' => null,
        ];

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
        ));
    }
}