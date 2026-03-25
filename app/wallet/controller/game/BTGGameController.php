<?php

namespace app\wallet\controller\game;

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

    // 状态码定义 - 使用字符串类型保持与BTG平台一致
    public const API_CODE_SUCCESS = '1000';
    public const API_CODE_GENERAL_ERROR = '2001';
    public const API_CODE_GAME_MAINTENANCE = '4001';
    public const API_CODE_GAME_NOT_EXIST = '4002';
    public const API_CODE_OPERATION_FREQUENT = '4003';
    public const API_CODE_TIME_FORMAT_ERROR = '4004';
    public const API_CODE_IP_NOT_ALLOWED = '4102';
    public const API_CODE_INVALID_CHECK_CODE = '4103';
    public const API_CODE_AGENT_NOT_EXIST = '4104';
    public const API_CODE_PLAYER_PASSWORD_ERROR = '4201';
    public const API_CODE_PLAYER_NOT_EXIST = '4202';
    public const API_CODE_PLAYER_ALREADY_EXIST = '4203';
    public const API_CODE_GAME_RECORD_NOT_EXIST = '4204';
    public const API_CODE_PLAYER_LOCKED = '4206';
    public const API_CODE_PARAM_FORMAT_ERROR = '4302';
    public const API_CODE_PARAM_VALUE_ERROR = '4303';
    public const API_CODE_WITHDRAW_FAILED = '6101';
    public const API_CODE_DEPOSIT_FAILED = '6102';
    public const API_CODE_DUPLICATE_ORDER = '6104';
    public const API_CODE_TRANSACTION_NOT_FOUND = '6105';
    public const API_CODE_DEPOSIT_AMOUNT_ERROR = '6107';
    public const API_CODE_WITHDRAW_AMOUNT_ERROR = '6108';
    public const API_CODE_PARAM_CONFLICT = '6109';
    public const API_CODE_PLAYER_TRANSACTION_LOCKED = '6110';
    public const API_CODE_GET_BALANCE_FAILED = '6111';
    public const API_CODE_TRANSACTION_TOO_FREQUENT = '6112';

    // 状态码映射
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => '成功',
        self::API_CODE_GENERAL_ERROR => '發生預期外錯誤',
        self::API_CODE_GAME_MAINTENANCE => '該遊戲目前維護中',
        self::API_CODE_GAME_NOT_EXIST => '該遊戲不存在',
        self::API_CODE_OPERATION_FREQUENT => '操作頻繁，請稍後再試(間隔1秒以上)',
        self::API_CODE_TIME_FORMAT_ERROR => '請使用美東時間格式',
        self::API_CODE_IP_NOT_ALLOWED => '不被允許訪問的ip',
        self::API_CODE_INVALID_CHECK_CODE => '錯誤的驗證碼',
        self::API_CODE_AGENT_NOT_EXIST => '該代理商不存在',
        self::API_CODE_PLAYER_PASSWORD_ERROR => '玩家帳號或密碼錯誤',
        self::API_CODE_PLAYER_NOT_EXIST => '該玩家不存在',
        self::API_CODE_PLAYER_ALREADY_EXIST => '該玩家已註冊',
        self::API_CODE_GAME_RECORD_NOT_EXIST => '欲查詢之遊戲紀錄不存在',
        self::API_CODE_PLAYER_LOCKED => '該玩家被鎖定',
        self::API_CODE_PARAM_FORMAT_ERROR => '特定參數(arg)格式錯誤',
        self::API_CODE_PARAM_VALUE_ERROR => '特定參數(arg)值錯誤',
        self::API_CODE_WITHDRAW_FAILED => '提款交易執行失敗',
        self::API_CODE_DEPOSIT_FAILED => '存款交易執行失敗',
        self::API_CODE_DUPLICATE_ORDER => '該外部交易流水號已存在',
        self::API_CODE_TRANSACTION_NOT_FOUND => '查無該交易紀錄',
        self::API_CODE_DEPOSIT_AMOUNT_ERROR => '存款金額數值錯誤',
        self::API_CODE_WITHDRAW_AMOUNT_ERROR => '提款金額數值錯誤',
        self::API_CODE_PARAM_CONFLICT => 'take_all=true與withdraw_amount 參數衝突',
        self::API_CODE_PLAYER_TRANSACTION_LOCKED => '玩家交易狀態被鎖定',
        self::API_CODE_GET_BALANCE_FAILED => '取餘額失敗',
        self::API_CODE_TRANSACTION_TOO_FREQUENT => '交易過於頻繁',
    ];

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

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
     * 查询余额
     * @param Request $request
     * @return Response
     */
    public function balance(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('BTG余额查询记录', ['params' => $params]);

            // 验证签名
            $this->service->verifySign($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 设置玩家
            if (isset($params['username'])) {
                $this->service->player = \app\model\Player::query()->where('uuid', $params['username'])->first();
            }

            // 获取余额
            $balance = $this->service->balance();
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            return $this->success([
                'account_id' => $params['account_id'] ?? '',
                'username' => $params['username'] ?? '',
                'balance' => $balance,
            ]);
        } catch (Exception $e) {
            $this->logger->error('BTG balance failed', ['error' => $e->getMessage()]);
            $this->sendTelegramAlert('BTG', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
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
            return $this->error(self::API_CODE_GENERAL_ERROR);
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
            return $this->error(self::API_CODE_GENERAL_ERROR);
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
            return $this->error(self::API_CODE_GENERAL_ERROR);
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
                'code' => self::API_CODE_SUCCESS, // 使用字符串常量 '1000'
                'message' => self::API_CODE_MAP[self::API_CODE_SUCCESS],
                'datetime' => date('Y-m-d\TH:i:sP'),
            ],
            'data' => $data,
        ];

        $this->logger->info('BTG返回记录', ['response' => $responseData]);

        return new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 失败响应
     *
     * @param string|int $code 错误码
     * @param array $data 额外数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function error(string|int $code, array $data = [], int $httpCode = 200): Response
    {
        // BTG使用字符串类型的错误码
        $code = (string)$code;
        $responseData = [
            'status' => [
                'code' => $code,
                'message' => self::API_CODE_MAP[$code] ?? '未知错误',
                'datetime' => date('Y-m-d\TH:i:sP'),
            ],
            'data' => $data,
        ];

        $this->logger->error('BTG错误返回', ['response' => $responseData]);

        return new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData, JSON_UNESCAPED_UNICODE)
        );
    }
}
