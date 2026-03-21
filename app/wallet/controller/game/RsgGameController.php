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
 * RSG皇家电子
 */
class RsgGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
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
        self::API_CODE_SUCCESS => 'OK',
        self::API_CODE_INVALID_PARAM => '無效參數',
        self::API_CODE_DECRYPT_ERROR => '解密異常',
        self::API_CODE_PLAYER_NOT_EXIST => '此玩家帳戶不存在',
        self::API_CODE_DUPLICATE_ORDER => '重複的 SequenNumber',
        self::API_CODE_DUPLICATE_TRANSACTION => '重複的TransactionId',
        self::API_CODE_INSUFFICIENT_BALANCE => '餘額不足',
        self::API_CODE_ORDER_SETTLED => '此 SequenNumber 已被結算',
        self::API_CODE_ORDER_NOT_EXIST => '此 SequenNumber 不存在',
        self::API_CODE_ORDER_CANCELLED => '此 SequenNumber 已被取消',
        self::API_CODE_DENY_PREPAY => '拒絕預扣，其他原因',
        self::API_CODE_TRANSACTION_NOT_FOUND => '找不到交易結果',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_RSG);
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

            Log::channel('rsg_server')->info('rsg余额查询记录', ['params' => $params]);
            $data = $this->service->decrypt($params['Msg']);
            Log::channel('rsg_server')->info('rsg余额查询记录', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $balance = $this->service->balance();
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
        } catch (Exception $e) {
            Log::error('RSG balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
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

            $data = $this->service->decrypt($params['Msg']);

            Log::channel('rsg_server')->info('rsg下注记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->bet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
        } catch (Exception $e) {
            Log::error('RSG bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('RSG', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
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
            $data = $this->service->decrypt($params['Msg']);
            Log::channel('rsg_server')->info('rsg取消下注记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->cancelBet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => $balance]);
        } catch (Exception $e) {
            Log::error('RSG cancelBet failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('RSG', '取消下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
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
            $data = $this->service->decrypt($params['Msg']);
            Log::channel('rsg_server')->info('rsg结算记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->betResulet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
        } catch (Exception $e) {
            Log::error('RSG betResult failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('RSG', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
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
            $data = $this->service->decrypt($params['Msg']);
            Log::channel('mt_server')->info('rsg余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->reBetResulet($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['bet_sn' => $data['bet_sn'], 'balance' => $balance]);
        } catch (Exception $e) {
            Log::error('RSG reBetResult failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('RSG', '重新结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * Jackpot 中獎
     * @param Request $request
     * @return Response
     */
    #[RateLimiter(limit: 5)]
    public function jackpotResult(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt($params['Msg']);
            Log::channel('rsg_server')->info('rsgJackpot记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->jackpotResult($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['Balance' => $balance]);
        } catch (Exception $e) {
            Log::error('RSG jackpotResult failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('RSG', 'Jackpot异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }

    /**
     * 打鱼机预扣金额
     * @param Request $request
     * @return Response
     */
    public function prepay(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt($params['Msg']);
            Log::channel('rsg_server')->info('打鱼机预扣金额记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->prepay($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $balance);
        } catch (Exception $e) {
            Log::error('RSG prepay failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('RSG', '预扣金额异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
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
            Log::channel('rsg_server')->info('打鱼退款金额记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->refund($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $balance);
        } catch (Exception $e) {
            Log::error('RSG refund failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('RSG', '退款异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
        }
    }


    /**
     * 检查交易
     * @param Request $request
     * @return Response
     */
    public function checkTransaction(Request $request): Response
    {
        try {
            $params = $request->post();
            $data = $this->service->decrypt($params['Msg']);
            Log::channel('rsg_server')->info('检查交易记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $result = $this->service->checkTransaction($data);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $result);
        } catch (Exception $e) {
            Log::error('RSG checkTransaction failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('RSG', '检查交易异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_INVALID_PARAM, $e->getMessage());
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
            'ErrorCode' => self::API_CODE_SUCCESS, // 使用业务状态码常量
            'ErrorMessage' => $message ?: self::API_CODE_MAP[self::API_CODE_SUCCESS],
            'Timestamp' => $timestamp,
            'Data' => $data,
        ];

        $reqBase64 = $this->service->encrypt(json_encode($responseData));

        return (new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
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
            'ErrorCode' => $code, // 使用业务状态码常量
            'ErrorMessage' => $message ?: (self::API_CODE_MAP[$code] ?? '未知错误'),
            'Timestamp' => time(),
            'Data' => null,
        ];

        $reqBase64 = $this->service->encrypt(json_encode($responseData));

        return (new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
            $reqBase64
        ));
    }
}