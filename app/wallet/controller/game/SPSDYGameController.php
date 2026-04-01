<?php

namespace app\wallet\controller\game;


use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\game\SPSDYServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;

class SPSDYGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 200;
    public const API_CODE_CHECKCODE_ERROR = -101;
    public const API_CODE_DECRYPT_ERROR = -107;
    public const API_CODE_INSUFFICIENT_BALANCE = -201;

    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'Ok',
        self::API_CODE_CHECKCODE_ERROR => '商戶號驗簽錯誤',
        self::API_CODE_DECRYPT_ERROR => '請求參數錯誤',
        self::API_CODE_INSUFFICIENT_BALANCE => '餘額不足',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    /**
     * @var SPSDYServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_SPS_DY);
    }


    public function index(Request $request): Response
    {
        try {
            $params = $request->all();

            $cmd = $params['Cmd'] ?? '';

            switch ($cmd) {
                case 'GetUserBalance':
                    return $this->balance($params);
                    break;
                case 'TransferPoint':
                    return $this->bet($params);
                    break;
                case 'GetTransferStatus':
                    return $this->getStatus($params);
                    break;
            }

            return $this->error(self::API_CODE_DECRYPT_ERROR);
        } catch (Exception $e) {
            Log::error('SPSDY index failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SPSDY', '请求分发异常', $e, ['params' => $request->all()]);
            return $this->error(self::API_CODE_DECRYPT_ERROR);
        }
    }


    /**
     * 获取玩家钱包
     * @param $params
     * @return Response
     */
    public function balance($params)
    {
        try {
            Log::channel('sps_server')->info('sps余额查询记录', ['params' => $params]);

            $config = config('game_platform.SPSDY');

            $checkCode = strtoupper(MD5(strtoupper(MD5($params['VendorId'] . '&' . $params['User'] . '&' . $params['Timestamp'])) . '&' . $config['api_key']));

            if ($checkCode != $params['CheckCode']) {
                return $this->error(self::API_CODE_CHECKCODE_ERROR);
            }

            $this->service->player = Player::query()->where('uuid', $params['User'])->first();
            $balance = $this->service->balance();
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_SUCCESS, ['User' => $params['User'], 'Balance' => $balance]);
        } catch (Exception $e) {
            Log::error('SPSDY balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SPSDY', '余额查询异常', $e, ['params' => $params]);
            return $this->error(self::API_CODE_DECRYPT_ERROR);
        }
    }

    /**
     * 下注
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet($params): Response
    {
        try {
            Log::channel('sps_server')->info('sps下注记录', ['params' => $params]);
            $this->service->player = Player::query()->where('uuid', $params['User'])->first();
            //判断是下注还是结算加钱
            if ($params['TType'] == 1) {
                return $this->betResult($params);
            }
            $return = $this->service->bet($params);

            if ($this->service->error) {
                return $this->error($this->service->error, $return);
            }
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_SUCCESS, $return);
        } catch (Exception $e) {
            Log::error('SPSDY bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SPSDY', '下注异常', $e, ['params' => $params]);
            return $this->error(self::API_CODE_DECRYPT_ERROR);
        }
    }

    private function getStatus($params): Response
    {
        try {
            Log::channel('sps_server')->info('sps查詢交易紀錄', ['params' => $params]);
            $this->service->player = Player::query()->where('uuid', $params['User'])->first();
            //判断是下注还是结算加钱
            if ($params['TType'] == 1) {
                return $this->betResult($params);
            }
            $return = $this->service->bet($params);

            if ($this->service->error) {
                return $this->error($this->service->error, $return);
            }
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_SUCCESS, $return);
        } catch (Exception $e) {
            Log::error('SPSDY getStatus failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SPSDY', '查询状态异常', $e, ['params' => $params]);
            return $this->error(self::API_CODE_DECRYPT_ERROR);
        }
    }

    /**
     * 結算
     * @param $params
     * @return Response
     */
    public function betResult($params): Response
    {
        try {
            $return = $this->service->betResulet($params);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_SUCCESS, $return);
        } catch (Exception $e) {
            Log::error('SPSDY betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SPSDY', '结算异常', $e, ['params' => $params]);
            return $this->error(self::API_CODE_DECRYPT_ERROR);
        }
    }

    /**
     * 成功响应方法
     *
     * @param int $code
     * @param array $data 响应数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function success(int $code, array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'Code' => $code, // 使用业务状态码常量
            'Message' => (self::API_CODE_MAP[self::API_CODE_SUCCESS] ?? '未知错误'),
            'Data' => $data,
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
     * @param array $data 额外数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function error(string $code, array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'Code' => $code, // 使用业务状态码常量
            'Message' => (self::API_CODE_MAP[$code] ?? '未知错误'),
            'Data' => $data,
        ];

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
        ));
    }
}