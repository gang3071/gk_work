<?php

namespace app\wallet\controller\game;

use app\model\GameExtend;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\O8ServiceInterface;
use app\service\game\SingleWalletServiceInterface;
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
                        ['code' => 'MainWallet', 'bal' => $balance, 'cur' => 'TWD']
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
     * 下注
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');

            $this->logger->info('o8_server下注记录', ['params' => $params, 'token' => $token]);

            $this->service->verifyToken($token);

            $orders = $params['transactions'];
            $return = ['transactions' => []];

            // 批量处理每个订单（Redis 缓存版）
            foreach ($orders as $order) {
                /** @var Player $player */
                $player = Player::query()->where('uuid', $order['userid'])->first();
                if (empty($player)) {
                    continue;
                }

                $orderNo = $order['externalroundid'];
                $bet = $order['amt'];

                // 幂等性检查
                $lockKey = "order:bet:lock:{$orderNo}";
                if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                    // 重复订单
                    $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                    $return['transactions'][] = [
                        'txid' => $orderNo,
                        'ptxid' => $order['ptxid'],
                        'bal' => (float)$balance,
                        'cur' => 'TWD',
                        'dup' => true
                    ];
                    continue;
                }

                try {
                    // 获取当前余额
                    $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                    // 余额预检查
                    if ($currentBalance < $bet) {
                        \support\Redis::del($lockKey);
                        return $this->error(self::API_CODE_AMOUNT_OVER_BALANCE);
                    }

                    // 查询平台ID
                    $platformId = GameExtend::query()
                        ->where('code', $order['gamecode'])
                        ->value('platform_id') ?? $this->service->platform->id;

                    // 写入 Redis 缓存
                    \app\service\GameRecordCacheService::saveBet('O8', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $platformId,
                        'amount' => $bet,
                        'game_code' => $order['gamecode'],
                        'original_data' => $order,
                    ]);

                    // 更新余额缓存
                    $newBalance = bcsub($currentBalance, $bet, 2);
                    \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

                    $return['transactions'][] = [
                        'txid' => $orderNo,
                        'ptxid' => $order['ptxid'],
                        'bal' => (float)$newBalance,
                        'cur' => 'TWD',
                        'dup' => false
                    ];

                } catch (\Throwable $e) {
                    \support\Redis::del($lockKey);
                    throw $e;
                }
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('O8 bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('O8', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
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
            $token = request()->header('authorization');

            $this->logger->info('o8_server结算记录', ['params' => $params, 'token' => $token]);

            $this->service->verifyToken($token);

            $orders = $params['transactions'];
            $return = ['transactions' => []];

            // 批量处理每个订单
            foreach ($orders as $order) {
                /** @var Player $player */
                $player = Player::query()->where('uuid', $order['userid'])->first();
                if (empty($player)) {
                    continue;
                }

                $orderNo = $order['externalroundid'];
                $winAmount = $order['turnover'] - $order['ggr'];
                $txtype = $order['txtype'];

                // 幂等性检查
                $lockKey = "order:settle:lock:{$orderNo}";
                if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                    // 已结算，返回缓存余额
                    $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                    $return['transactions'][] = [
                        'txid' => $orderNo,
                        'ptxid' => $order['ptxid'],
                        'bal' => (float)$balance,
                        'cur' => 'TWD',
                        'dup' => true
                    ];
                    continue;
                }

                try {
                    // 获取当前余额
                    $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                    $newBalance = $currentBalance;

                    // 写入结算记录
                    \app\service\GameRecordCacheService::saveSettle('O8', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $winAmount,
                        'diff' => $winAmount,
                        'original_data' => $order,
                    ]);

                    // txtype=510 且 winAmount>0 才加钱
                    if ($txtype == 510 && $winAmount > 0) {
                        $newBalance = bcadd($currentBalance, $winAmount, 2);
                        \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                    }

                    $return['transactions'][] = [
                        'txid' => $orderNo,
                        'ptxid' => $order['ptxid'],
                        'bal' => (float)$newBalance,
                        'cur' => 'TWD',
                        'dup' => false
                    ];

                } catch (\Throwable $e) {
                    \support\Redis::del($lockKey);
                    throw $e;
                }
            }

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
            Log::error('O8 refund failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('O8', '退款异常', $e, ['params' => $request->post()]);
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