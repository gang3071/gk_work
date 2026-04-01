<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\model\PlayGameRecord;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\game\TNineServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;

/**
 * T9真人视讯平台
 */
class TNineGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = 0;
    public const API_CODE_ERROR = 1;
    public const API_CODE_SIGN_ERROR = 3;
    public const API_CODE_INSUFFICIENT_BALANCE = 108;
    public const API_CODE_ORDER_NOT_FOUND = 110;


    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => 'Success',
        self::API_CODE_ERROR => '失敗',
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
     * @var TNineServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_TNINE);
        $this->logger = Log::channel('tnine_server');
    }


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

            $this->logger->info('t9余额查询记录', ['params' => $params]);

            $this->service->verifySign($params);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $users = $params['Members'];

            // ✅ 性能优化：批量查询玩家和余额，避免 N+1 问题
            // 1. 批量查询玩家（1 次数据库查询）
            $players = Player::query()->whereIn('uuid', $users)->get()->keyBy('uuid');

            // 2. 批量查询余额（使用 WalletService::getBatchBalance）
            $playerIds = $players->pluck('id')->toArray();
            $balances = \app\service\WalletService::getBatchBalance($playerIds);

            // 3. 组装返回数据
            $return = [];
            $time = date('Y-m-d H:i:s');

            foreach ($users as $userId) {
                $player = $players->get($userId);
                if (!$player) {
                    continue;
                }

                $return[] = [
                    'MemberAccount' => $userId,
                    'Balance' => $balances[$player->id] ?? 0,
                    'SyncTime' => $time,
                ];
            }

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }

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

            $this->logger->info('t9下注记录', ['params' => $params]);

            $this->service->verifySign($params);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $return = $this->service->bet($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }

    /**
     * 結算
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('t9结算记录', ['params' => $params]);

            $this->service->verifySign($params);
            $return = $this->service->betResulet($params);
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '结算异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }


    /**
     * 检查
     * @param Request $request
     * @return Response
     */
    public function check(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('商戶注單查核', ['params' => $params]);

            $this->service->verifySign($params);
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->where('order_no', $params['OrderNumber'])->first();
            if (empty($record)) {
                return $this->error(self::API_CODE_ORDER_NOT_FOUND);
            }
            $origin = json_decode($record->action_data, true);
            $return = [
                'MerchantOrderNumber' => $record->id,
                'OrderStatus' => $origin['OrderStatus'],
                'BetAmount' => $origin['BetAmount'],
                'ValidBetAmount' => $origin['ValidBetAmount'],
                'WinAmount' => $origin['WinAmount'],
            ];

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine check failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '订单查核异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }

    /**
     * 商戶注單修改
     * @param Request $request
     * @return Response
     */
    public function update(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('商戶注單修改', ['params' => $params]);

            $this->service->verifySign($params);
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->where('order_no', $params['OrderNumber'])->first();

            if (empty($record)) {
                return $this->error(self::API_CODE_ORDER_NOT_FOUND);
            }

            $record->settlement_status = self::ORDER_STATUS_MAP[$params['OrderStatus']];


            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS]);
        } catch (Exception $e) {
            Log::error('TNine update failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '订单修改异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }

    /**
     * 送礼
     * @param Request $request
     * @return Response
     */
    public function gift(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('商戶會員贈禮確認', ['params' => $params]);

            $this->service->verifySign($params);
            $return = $this->service->gift($params);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 3. 使用常量获取状态码描述
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNine gift failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE', '赠礼异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
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
            'Error' => [
                'Code' => self::API_CODE_SUCCESS,
                'Message' => self::API_CODE_MAP[self::API_CODE_SUCCESS],
            ],
        ];

        if (!empty($data)) {
            $responseData['Data'] = $data;
        }


        $this->logger->info('t9结算记录', $responseData);


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
            'Data' => [],
            'Error' => [
                'Code' => $code,
                'Message' => self::API_CODE_MAP[$code],
            ],
        ];

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseData)
        ));
    }
}