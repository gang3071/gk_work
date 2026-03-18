<?php

namespace app\wallet\controller\game;

use app\service\game\DGServiceInterface;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * DG
 */
class DGGameController
{
    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_DECRYPT_ERROR = 1;
    public const API_CODE_INSUFFICIENT_BALANCE = 120;
    public const API_CODE_DUPLICATE_TRANSACTION = 323;


    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'Success',
        self::API_CODE_DECRYPT_ERROR => 'Parameter Error',
        self::API_CODE_DUPLICATE_TRANSACTION => 'Used serial numbers for Transfer',
        self::API_CODE_INSUFFICIENT_BALANCE => 'Insufficient balance',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    /**
     * @var DGServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_DG);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 获取玩家钱包
     * @param Request $request
     * @param string $agentName
     * @return Response
     */
    public function balance(Request $request, string $agentName): Response
    {
        $params = $request->post();

        Log::channel('dg_server')->info('dg余额查询记录', ['params' => $params, 'name' => $agentName]);
        $this->service->verifyToken($params, $agentName);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        $this->service->decrypt($params);

        $balance = $this->service->balance();

        $return = [
            'member' => [
                'username' => $params['member']['username'],
                'balance' => $balance,
            ]
        ];

        // 3. 使用常量获取状态码描述
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 下注
     * @param Request $request
     * @param string $agentName
     * @return Response
     */
    public function bet(Request $request, string $agentName): Response
    {
        $params = $request->post();

        Log::channel('dg_server')->info('dg下注记录', ['params' => $params, 'name' => $agentName]);
        $this->service->verifyToken($params, $agentName);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        $this->service->decrypt($params);


        $type = $params['type'];
        //转账类型(1:下注 2:派彩 3:补单 5:红包 6:小费)
        if (in_array($type, [2, 5])) {
            return $this->betResult($params);
        }

        $return = $this->service->bet($params);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }

        // 3. 使用常量获取状态码描述
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
    }

    /**
     * 結算
     * @param $data
     * @return Response
     */
    #[RateLimiter(limit: 5)]
    public function betResult($data): Response
    {
        Log::channel('rsg_server')->info('dg结算记录', ['params' => $data]);

        $return = $this->service->betResulet($data);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        // 3. 使用常量获取状态码描述
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
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
            'codeId' => self::API_CODE_SUCCESS, // 使用业务状态码常量
        ];

        $reqBase64 = json_encode(array_merge($responseData, $data));

        Log::channel('dg_server')->info('dg返回记录', ['response' => array_merge($responseData, $data)]);

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            $reqBase64
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
    public function error(string $code, ?string $message = null, array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'codeId' => $code, // 使用业务状态码常量
        ];

        $reqBase64 = json_encode($responseData);

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            $reqBase64
        ));
    }
}