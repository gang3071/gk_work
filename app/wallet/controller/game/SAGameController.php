<?php

namespace app\wallet\controller\game;


use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use Exception;
use SimpleXMLElement;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class SAGameController
{
    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_DECRYPT_ERROR = 1006;
    public const API_CODE_MAINTENANCE = 9999;
    public const API_CODE_PLAYER_NOT_EXIST = 1000;
    public const API_CODE_INSUFFICIENT_BALANCE = 1004;
    public const API_CODE_GENERAL_ERROR = 1005;

    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => '成功',
        self::API_CODE_DECRYPT_ERROR => '解密错误',
        self::API_CODE_MAINTENANCE => '系统错误',
        self::API_CODE_PLAYER_NOT_EXIST => '此玩家帳戶不存在',
        self::API_CODE_INSUFFICIENT_BALANCE => '不足够点数',
        self::API_CODE_GENERAL_ERROR => '一般错误',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_SA);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request)
    {
        $params = $request->rawBody();
        $data = $this->service->decrypt($params);
        Log::channel('sa_server')->info('sa余额查询记录', ['params' => $data]);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        $balance = $this->service->balance();
        // 3. 使用常量获取状态码描述
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], array_merge($data, ['amount' => $balance]));
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
        $params = $request->rawBody();
        $data = $this->service->decrypt($params);
        Log::channel('sa_server')->info('sa下注记录', ['params' => $data]);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        $balance = $this->service->bet($data);
        $return = [
            'username' => $data['username'],
            'currency' => $data['currency'],
            'amount' => $balance,
        ];
        if ($this->service->error) {
            return $this->error($this->service->error, $return);
        }
        // 3. 使用常量获取状态码描述
        return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
    }

    /**
     * 取消下注
     * @param Request $request
     * @return Response
     */
    #[RateLimiter(limit: 5)]
    public function cancelBet(Request $request): Response
    {
        $params = $request->rawBody();
        $data = $this->service->decrypt($params);
        Log::channel('sa_server')->info('sa取消下注', ['params' => $data]);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        $balance = $this->service->cancelBet($data);
        $return = [
            'username' => $data['username'],
            'currency' => $data['currency'],
            'amount' => $balance,
        ];
        if ($this->service->error) {
            return $this->error($this->service->error, $return);
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
        $params = $request->rawBody();
        $data = $this->service->decrypt($params);
        Log::channel('sa_server')->info('sa结算下注', ['params' => $data]);
        if ($this->service->error) {
            return $this->error($this->service->error);
        }
        $balance = $this->service->betResulet($data);
        $return = [
            'username' => $data['username'],
            'currency' => $data['currency'],
            'amount' => $balance,
        ];
        if ($this->service->error) {
            return $this->error($this->service->error, $return);
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
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><RequestResponse/>');
        $xml->error = self::API_CODE_SUCCESS;
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild($key);
                foreach ($value as $k => $v) {
                    $child->addChild($k, htmlspecialchars($v));
                }
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }

        // 获取XML字符串
        $xmlString = $xml->asXML();

        return new Response(
            $httpCode,
            ['Content-Type' => 'text/xml'],
            $xmlString
        );
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
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><RequestResponse/>');
        $xml->error = $code;
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild($key);
                foreach ($value as $k => $v) {
                    $child->addChild($k, htmlspecialchars($v));
                }
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }

        // 获取XML字符串
        $xmlString = $xml->asXML();

        return new Response(
            $httpCode,
            ['Content-Type' => 'text/xml'],
            $xmlString
        );
    }
}