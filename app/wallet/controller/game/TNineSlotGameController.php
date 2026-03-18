<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\model\PlayGameRecord;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\game\TNineSlotServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * T9电子平台
 */
class TNineSlotGameController
{
    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_ERROR = 1;
    public const API_CODE_SIGN_ERROR = 3;
    public const API_CODE_INSUFFICIENT_BALANCE = 108;
    public const API_CODE_ORDER_NOT_FOUND = 110;


    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'OK',
        self::API_CODE_ERROR => 'FAIL',
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
     * @var TNineSlotServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_TNINE_SLOT);
        $this->logger = Log::channel('tnine_slot_server');
    }


    #[RateLimiter(limit: 5)]
    /**
     *
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request): Response
    {
        $params = $request->post();

        $this->logger->info('t9电子余额查询记录', ['params' => $params]);

//        $this->service->verifySign($params);

        $user = $params['gameAccount'];
        $userId = explode('_', $user)[0];
        $this->service->player = Player::query()->where('uuid', $userId)->first();
        $balance = $this->service->balance();

        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
            'balance' => $balance,
        ]);
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

        //下注 结算一起处理
        //免费次数合并到一个订单
        $this->logger->info('t9电子下注记录', ['params' => $params]);

        $user = $params['gameAccount'];
        $userId = explode('_', $user)[0];
        $this->service->player = Player::query()->where('uuid', $userId)->first();

        if ($params['betKind'] == 3) {
            return $this->betResult($params);
        }

        $return = $this->service->bet($params);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }

        $this->betResult($params);
        // 3. 使用常量获取状态码描述
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
    }

    /**
     * 結算
     * @param $params
     * @return Response
     */
    public function betResult($params): Response
    {
        $return = $this->service->betResulet($params);
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
    }


    #[RateLimiter(limit: 5)]
    /**
     * 取消下注
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function cancelBet(Request $request): Response
    {
        $params = $request->post();

        $this->logger->info('t9电子取消下注', ['params' => $params]);

        $user = $params['gameAccount'];
        $userId = explode('_', $user)[0];
        $this->service->player = Player::query()->where('uuid', $userId)->first();
        $return = $this->service->cancelBet($params);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }

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
            'resultCode' => $message,
            'data' => $data,
        ];

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
            'resultCode' => self::API_CODE_MAP[$code],
            'data' => null,
            'errorMsg' => ''
        ];

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
        ));
    }
}