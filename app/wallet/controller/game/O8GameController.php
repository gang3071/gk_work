<?php

namespace app\wallet\controller\game;

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
use Webman\RateLimiter\Annotation\RateLimiter;

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


    #[RateLimiter(limit: 5)]
    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function token(Request $request): Response
    {
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
    }

    #[RateLimiter(limit: 5)]
    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request): Response
    {
        $params = $request->post();
        $token = request()->header('authorization');

        $this->logger->info('o8_server余额查询记录', ['params' => $params, 'token' => $token]);

        $this->service->verifyToken($token);
        $users = $params['users'];

        $return = [];

        foreach ($users as $user) {
            $this->service->player = Player::query()->where('uuid', $user['userid'])->first();
            if (empty($this->service->player)) {
                continue;
            }
            $balance = $this->service->balance();
            $return['users'][] = [
                'userid' => $user['userid'],
                'wallets' => [
                    ['code' => 'MainWallet', 'bal' => $balance, 'cur' => 'TWD']
                ]
            ];
        }

        // 3. 使用常量获取状态码描述
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 下注
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        $params = $request->post();
        $token = request()->header('authorization');

        $this->logger->info('o8_server下注记录', ['params' => $params, 'token' => $token]);

        $this->service->verifyToken($token);

        try {
            $return = $this->service->bet($params);
        } catch (Exception $e) {
            $this->logger->error('bet',[$e->getMessage()]);
        }
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        // 3. 使用常量获取状态码描述
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
    }

    /**
     * 結算
     * @param Request $request
     * @return Response
     */
    #[RateLimiter(limit: 5)]
    public function betResult(Request $request): Response
    {
        $params = $request->post();
        $token = request()->header('authorization');

        $this->logger->info('o8_server下注记录', ['params' => $params, 'token' => $token]);

        $this->service->verifyToken($token);
        $return = $this->service->betResulet($params);

        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
    }

    /**
     * 重新結算
     * @param Request $request
     * @return Response
     */
    #[RateLimiter(limit: 5)]
    public function reBetResult(Request $request): Response
    {
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
    }

    /**
     * 打鱼机预扣金额
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
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