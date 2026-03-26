<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\service\game\BTGServiceInterface;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * BTG单一钱包
 */
class BTGGameController
{
    use TelegramAlertTrait;

    /**
     * @var BTGServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_BTG);
        $this->logger = Log::channel('btg_server');
    }

    #[RateLimiter(limit: 5)]
    /**
     * 查询余额 - get_user_balance
     * @param Request $request
     * @return Response
     */
    public function balance(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('BTG查询余额请求', ['params' => $params]);

            // 验证必要参数
            if ($error = $this->validateRequiredParams($params, [
                'tran_id' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'username' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'currency' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'auth_code' => BTGServiceInterface::ERROR_CODE_AUTHORIZATION_INVALID,
            ], 'BTG查询余额')) {
                return $error;
            }

            // 验证签名
            if (!$this->service->verifyAuthCode($params)) {
                $this->logger->error('BTG查询余额失败：auth_code验证失败', ['params' => $params]);
                return $this->error(BTGServiceInterface::ERROR_CODE_AUTHORIZATION_INVALID);
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['username'])->first();
            if (!$player) {
                $this->logger->error('BTG查询余额失败：玩家不存在', ['username' => $params['username']]);
                return $this->error(BTGServiceInterface::ERROR_CODE_PLAYER_NOT_EXIST);
            }

            $this->service->player = $player;

            // 获取余额
            $balance = $this->service->balance();
            if ($this->service->error) {
                $this->logger->error('BTG查询余额失败：获取余额错误', ['error' => $this->service->error, 'player_id' => $player->id]);
                return $this->error($this->service->error);
            }

            $this->logger->info('BTG查询余额成功', [
                'username' => $params['username'],
                'balance' => $balance,
                'tran_id' => $params['tran_id']
            ]);

            return $this->success([
                'balance' => number_format($balance, 1, '.', ''),
                'currency' => $params['currency'],
                'tran_id' => $params['tran_id'],
            ]);
        } catch (Exception $e) {
            $this->logger->error('BTG查询余额异常', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('BTG', '查询余额异常', $e, ['params' => $request->post()]);
            return $this->error(BTGServiceInterface::ERROR_CODE_SOMETHING_WRONG, [], 'message');
        }
    }

    #[RateLimiter(limit: 5)]
    /**
     * 下注扣款
     * @param Request $request
     * @return Response
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('BTG下注记录', ['params' => $params]);

            // 验证签名
            $this->service->verifySign($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 下注扣款
            $result = $this->service->bet($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 如果返回的是数组则成功，否则返回余额表示失败
            if (is_array($result)) {
                return $this->success([
                    'account_id' => $params['account_id'] ?? '',
                    'username' => $params['username'] ?? '',
                    'external_order_id' => $params['external_order_id'] ?? '',
                    'balance' => $result['balance'] ?? 0,
                    'order_id' => $result['order_id'] ?? '',
                ]);
            } else {
                return $this->error($this->service->error, [
                    'account_id' => $params['account_id'] ?? '',
                    'username' => $params['username'] ?? '',
                    'balance' => $result,
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('BTG bet failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('BTG', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(BTGServiceInterface::ERROR_CODE_GENERAL_ERROR);
        }
    }

    #[RateLimiter(limit: 5)]
    /**
     * 结算加款
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('BTG结算记录', ['params' => $params]);

            // 验证签名
            $this->service->verifySign($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 结算加款
            $result = $this->service->betResulet($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 如果返回的是数组则成功，否则返回余额表示失败
            if (is_array($result)) {
                return $this->success([
                    'account_id' => $params['account_id'] ?? '',
                    'username' => $params['username'] ?? '',
                    'external_order_id' => $params['external_order_id'] ?? '',
                    'balance' => $result['balance'] ?? 0,
                    'order_id' => $result['order_id'] ?? '',
                ]);
            } else {
                return $this->error($this->service->error, [
                    'account_id' => $params['account_id'] ?? '',
                    'username' => $params['username'] ?? '',
                    'balance' => $result,
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('BTG betResult failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('BTG', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(BTGServiceInterface::ERROR_CODE_GENERAL_ERROR);
        }
    }

    #[RateLimiter(limit: 5)]
    /**
     * 取消下注
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('BTG取消下注', ['params' => $params]);

            // 验证签名
            $this->service->verifySign($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 取消下注
            $result = $this->service->cancelBet($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 如果返回的是数组则成功，否则返回余额表示失败
            if (is_array($result)) {
                return $this->success([
                    'account_id' => $params['account_id'] ?? '',
                    'username' => $params['username'] ?? '',
                    'external_order_id' => $params['external_order_id'] ?? '',
                    'balance' => $result['balance'] ?? 0,
                    'order_id' => $result['order_id'] ?? '',
                ]);
            } else {
                return $this->error($this->service->error, [
                    'account_id' => $params['account_id'] ?? '',
                    'username' => $params['username'] ?? '',
                    'balance' => $result,
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('BTG cancelBet failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('BTG', '取消下注异常', $e, ['params' => $request->post()]);
            return $this->error(BTGServiceInterface::ERROR_CODE_GENERAL_ERROR);
        }
    }

    /**
     * 成功响应
     *
     * @param array $data 响应数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function success(array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'status' => [
                'code' => (int)BTGServiceInterface::ERROR_CODE_SUCCESS, // 转换为整数类型
                'message' => BTGServiceInterface::ERROR_CODE_MAP[BTGServiceInterface::ERROR_CODE_SUCCESS],
                'datetime' => date('Y-m-d\TH:i:sP'),
            ],
            'data' => $data,
        ];

        $this->logger->info('BTG成功返回', ['response' => $responseData]);

        return new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 验证必要参数
     *
     * @param array $params 请求参数
     * @param array $requiredParams 必要参数配置 [参数名 => 错误码]
     * @param string $logPrefix 日志前缀
     * @return Response|null 如果验证失败返回错误响应，成功返回null
     */
    private function validateRequiredParams(array $params, array $requiredParams, string $logPrefix = 'BTG'): ?Response
    {
        foreach ($requiredParams as $param => $errorCode) {
            if (!isset($params[$param]) || $params[$param] === '') {
                $this->logger->error("{$logPrefix}失败：缺少{$param}参数", ['params' => $params]);
                return $this->error($errorCode, [], $param);
            }
        }
        return null;
    }

    /**
     * 失败响应
     *
     * @param string|int $code 错误码
     * @param array $data 额外数据
     * @param string $argName 参数名称（用于格式化错误消息）
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function error(string|int $code, array $data = [], string $argName = '', int $httpCode = 200): Response
    {
        // BTG使用字符串类型的错误码
        $code = (string)$code;

        // 获取错误消息
        $message = BTGServiceInterface::ERROR_CODE_MAP[$code] ?? '未知错误';

        // 如果提供了参数名称，格式化错误消息
        if ($argName !== '') {
            $message = str_replace('(arg)', '(' . $argName . ')', $message);
        }

        $responseData = [
            'status' => [
                'code' => (int)$code,
                'message' => $message,
                'datetime' => date('Y-m-d\TH:i:sP'),
            ],
        ];

        // 只有当data不为空时才添加data字段
        if (!empty($data)) {
            $responseData['data'] = $data;
        }

        $this->logger->error('BTG错误返回', ['response' => $responseData]);

        return new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData, JSON_UNESCAPED_UNICODE)
        );
    }
}
