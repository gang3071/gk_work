<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\RSGLiveServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
use Exception;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;

/**
 * RSG皇家真人
 */
class RsgLiveGameController
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
    public const API_CODE_CERTIFICATE_ERROR = 9;
    public const API_CODE_MAXIMUM_DISABLE_LIMIT_REACHED = 10;
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
        self::API_CODE_CERTIFICATE_ERROR => '憑證錯誤',
        self::API_CODE_MAXIMUM_DISABLE_LIMIT_REACHED => '已達到停用額度上限',
        self::API_CODE_REQUEST_A_TIMEOUT => '請求逾時',
        self::API_CODE_DATABASE_ERROR => '數據庫錯誤',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    /**
     * @var RSGLiveServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_RSG_LIVE);
        $this->logger = Log::channel('rsg_live_server');
    }


    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function checkUser(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('rsg_live檢查使用者', ['params' => $params]);
            $account = $params['account'];

            $player = Player::query()->where('uuid', $account)->first();

            if (!$player) {
                return $this->error(self::API_CODE_TOKEN_DOES_NOT_EXIST);
            }

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'authToken' => $this->service->getToken('authToken', $account),
                'sessionToken' => $this->service->getToken('sessionToken', $account, 86400),
                'requestId' => $params['requestId'],
                'account' => $params['account'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive checkUser failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '检查用户异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function RequestExtendToken(Request $request): Response
    {
        try {
            $params = $request->post();

            $token = $this->service->refresh(request()->header('authorization'));

            $this->logger->info('rsg_live檢查使用者', ['params' => $params]);

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'sessionToken' => $token,
                'requestId' => $params['requestId'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive RequestExtendToken failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '延长令牌异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_AUTHTOKEN_EXPIRED);
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
            $this->logger->info('rsg_live余额查询记录', ['params' => $params, 'token' => $token]);
            $data = $this->service->decrypt($token);
            $this->logger->info('rsg_live余额查询记录', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $balance = $this->service->balance();
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => $balance,
                'status' => 0,
                'requestId' => $params['requestId'],
                'account' => $data['memberaccount'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * 下注（Lua原子操作）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');
            $this->logger->info('rsg_live下注请求（Lua原子）', ['params' => $params, 'token' => $token]);
            $data = $this->service->decrypt($token);
            $this->logger->info('rsg_live下注数据', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($params['transaction']['id'] ?? '');
            $betAmount = $params['transaction']['amount'] ?? 0;

            // Lua 原子下注
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $betAmount,
                'game_code' => $params['transaction']['gameCode'] ?? '',
                'transaction_type' => TransactionType::BET,
                'original_data' => $params,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'game_code' => ['string'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $result = RedisLuaScripts::atomicBet($player->id, 'RSGLIVE', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'RSGLIVE', $player->id, $luaParams);

            // 处理下注结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    $this->logger->info('RSGLive下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'transaction' => [
                            'id' => $orderNo,
                            'balance' => (float)$result['balance'],
                        ],
                        'requestId' => $params['requestId'],
                        'account' => $data['memberaccount'],
                    ]);
                } elseif ($result['error'] === 'insufficient_balance') {
                    return $this->error(self::API_CODE_AMOUNT_OVER_BALANCE);
                }
            }

            $this->logger->info('rsg_live下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'transaction' => [
                    'id' => $orderNo,
                    'balance' => (float)$result['balance'],
                ],
                'requestId' => $params['requestId'],
                'account' => $data['memberaccount'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_DATABASE_ERROR);
        }
    }

    /**
     * 結算（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();
            $token = request()->header('authorization');
            $this->logger->info('rsg_live结算请求（Lua原子）', ['params' => $params, 'token' => $token]);
            $data = $this->service->decrypt($token);
            $this->logger->info('rsg_live结算数据', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($params['transaction']['id'] ?? '');
            $winAmount = $params['transaction']['winAmount'] ?? 0;

            // 从 Redis 获取下注金额以计算正确的 diff（win - bet）
            $betRecordKey = "game:record:bet:RSGLIVE:{$orderNo}";
            $betRecord = Redis::hGetAll($betRecordKey);
            $betAmount = isset($betRecord['amount']) ? (float)$betRecord['amount'] : 0;

            // 计算 diff = win - bet
            $diff = bcsub($winAmount, $betAmount, 2);

            // Lua 原子结算
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => max($winAmount, 0),
                'diff' => $diff,  // ✅ 修正：diff = win - bet
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

            $result = RedisLuaScripts::atomicSettle($player->id, 'RSGLIVE', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'RSGLIVE', $player->id, $luaParams);

            // 处理结算结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                $this->logger->info('RSGLive结算重复请求（Lua检测）', ['order_no' => $orderNo]);
            }

            $this->logger->info('rsg_live结算成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'transaction' => [
                    'id' => $orderNo,
                    'balance' => (float)$result['balance'],
                ],
                'requestId' => $params['requestId'],
                'account' => $data['memberaccount'],
            ]);
        } catch (Exception $e) {
            Log::error('RSGLive betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG_LIVE', '结算异常', $e, ['params' => $request->post()]);
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

        $timestamp = time();
        $responseData = [
            'msgId' => self::API_CODE_SUCCESS, // 使用业务状态码常量
            'message' => $message ?: self::API_CODE_MAP[self::API_CODE_SUCCESS],
            'timestamp' => $timestamp,
            'data' => $data,
        ];

//        $reqBase64 = $this->service->encrypt(json_encode($responseData));

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