<?php

namespace app\wallet\controller\game;

use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * ATG电子平台
 */
class ATGGameController
{
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

        $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));

        $this->log->info('atg余额查询记录', ['params' => $data]);

        if ($this->service->error) {
            return $this->error($this->service->error);
        }

        $balance = $this->service->balance();
        // 3. 使用常量获取状态码描述
        return $this->success(['balance' => $balance]);
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
        $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
        $this->log->info('atg下注记录', ['params' => $data]);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        $balance = $this->service->bet($data);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        // 3. 使用常量获取状态码描述
        return $this->success($balance);
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
        $this->log->info('atg余额查询记录', array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));

        $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
        $this->log->info('atg结算记录', ['params' => $data]);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        $balance = $this->service->betResulet($data);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        // 3. 使用常量获取状态码描述
        return $this->success($balance);
    }


    /**
     * 退款
     * @param Request $request
     * @return Response
     */
    public function refund(Request $request): Response
    {
        $params = $request->post();
        $data = $this->service->decrypt(array_merge(['token' => $request->header('token'), 'timestamp' => $request->header('timestamp')], $params));
        $this->log->info('atg退款记录', ['params' => $data]);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        $balance = $this->service->refund($data);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        // 3. 使用常量获取状态码描述
        return $this->success($balance);
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