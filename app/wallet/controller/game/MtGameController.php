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

    #[RateLimiter(limit: 5)]
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

    #[RateLimiter(limit: 5)]
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

            $data = $this->service->decrypt($params['msg']);

            Log::channel('mt_server')->info('MT下注记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->bet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => $balance]);
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
     * 取消下注
     * @param Request $request
     * @return Response
     */
    #[RateLimiter(limit: 5)]
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->post();

            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT取消下注', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->cancelBet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => $balance]);
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
     * 結算
     * @param Request $request
     * @return Response
     */
    #[RateLimiter(limit: 5)]
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();

            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT结算记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->betResulet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['bet_sn' => $data['bet_sn'], 'balance' => $balance]);
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
     * 重新結算
     * @param Request $request
     * @return Response
     */
    #[RateLimiter(limit: 5)]
    public function reBetResult(Request $request): Response
    {
        try {
            $params = $request->post();

            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT重新结算结算记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->reBetResulet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['bet_sn' => $data['bet_sn'], 'balance' => $balance]);
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
     * 送礼
     * @param Request $request
     * @return Response
     */
    #[RateLimiter(limit: 5)]
    public function gift(Request $request): Response
    {
        try {
            $params = $request->post();

            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT打赏记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->gift($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => $balance]);
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