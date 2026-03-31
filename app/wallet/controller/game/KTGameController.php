<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\KTServiceInterface;
use app\service\game\SingleWalletServiceInterface;
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
        $params = $request->post();
        $hash = $request->get('Hash');

        $this->service->verifyToken($params, $hash);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }

        $this->service->player = Player::query()->where('uuid', $params['Token'])->first();

        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
            'Username' => $this->service->player->uuid,
            'Currency' => 'TWD',
            'Balance' => $this->service->balance(),
        ]);
    }

    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request): Response
    {
        $params = $request->post();
        $hash = $request->get('Hash');

        $this->service->verifyToken($params, $hash);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }

        $this->service->player = Player::query()->where('uuid', $params['Username'])->first();

        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
            'Balance' => $this->service->balance(),
        ]);
    }

    /**
     * 下注
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        $params = $request->post();
        $hash = $request->get('Hash');

        $this->logger->info('kt_server 下注记录', ['params' => $params, 'get' => $hash]);

        $this->service->verifyToken($params, $hash);

        $this->service->player = Player::query()->where('uuid', $params['Username'])->first();
        $balance = $this->service->bet($params);

        //是否结算
        if ($params['TakeWin'] == 1) {
            $balance = $this->betResult($params);
        }

        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        // 3. 使用常量获取状态码描述
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
            'Balance' => $balance,
        ]);
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