<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\model\PlayGameRecord;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\game\TNineSlotServiceInterface;
use app\service\RedisLuaScripts;
use Exception;
use support\Log;
use support\Request;
use support\Response;

/**
 * T9电子平台
 */
class TNineSlotGameController
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


    /**
     *
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request): Response
    {
        try {
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
        } catch (Exception $e) {
            Log::error('TNineSlot balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE_SLOT', '余额查询异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
        }
    }

    /**
     * 下注（Lua原子操作）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('t9电子下注请求（Lua原子）', ['params' => $params]);

            $user = $params['gameAccount'];
            $userId = explode('_', $user)[0];
            $this->service->player = Player::query()->where('uuid', $userId)->first();
            $player = $this->service->player;

            $orderNo = (string)($params['gameOrderNumber'] ?? '');
            $betKind = $params['betKind'] ?? 0;
            $betAmount = $params['betAmount'] ?? 0;
            $winAmount = $params['winlose'] ?? $params['payoutAmount'] ?? 0;

            // betKind == 3：免费游戏，只结算不扣款（Lua 原子操作）
            if ($betKind == 3) {
                // Lua 原子结算（免费游戏）
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => max($winAmount, 0),
                    'diff' => $winAmount,
                    'transaction_type' => TransactionType::SETTLE_FREEGAME,
                    'original_data' => $params,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric'],
                    'diff' => ['required', 'numeric'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $result = RedisLuaScripts::atomicSettle($player->id, 'T9SLOT', $luaParams);

                // 审计日志
                logLuaScriptCall('settle', 'T9SLOT', $player->id, $luaParams);

                if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                    $this->logger->info('TNineSlot免费游戏重复请求（Lua检测）', ['order_no' => $orderNo]);
                }

                $afterBalance = $result['balance'];
                $beforeBalance = $afterBalance - $winAmount;

                $this->logger->info('t9电子免费游戏成功（Lua原子）', ['order_no' => $orderNo]);

                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'afterBalance' => (float)$afterBalance,
                    'beforeBalance' => (float)$beforeBalance,
                ]);
            }

            // 普通下注：Lua 原子下注（扣款）
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $betAmount,
                'game_code' => $params['gameCode'] ?? '',
                'transaction_type' => TransactionType::BET,
                'original_data' => $params,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $betResult = RedisLuaScripts::atomicBet($player->id, 'T9SLOT', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'T9SLOT', $player->id, $luaParams);

            if ($betResult['ok'] === 0) {
                if ($betResult['error'] === 'duplicate_order') {
                    $this->logger->info('TNineSlot下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'afterBalance' => (float)$betResult['balance'],
                        'beforeBalance' => (float)$betResult['balance'],
                    ]);
                } elseif ($betResult['error'] === 'insufficient_balance') {
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                }
            }

            $beforeBalance = $betResult['balance'] + $betAmount;
            $afterBalance = $betResult['balance'];

            // T9Slot 总是下注后立即结算（Lua 原子操作）
            $settleOrderNo = $orderNo . '_settle';
            $settleLuaParams = [
                'order_no' => $settleOrderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => max($winAmount, 0),
                'diff' => $winAmount,
                'transaction_type' => TransactionType::SETTLE,
                'original_data' => $params,
            ];

            // 参数验证
            validateLuaScriptParams($settleLuaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $settleResult = RedisLuaScripts::atomicSettle($player->id, 'T9SLOT', $settleLuaParams);

            // 审计日志
            logLuaScriptCall('settle', 'T9SLOT', $player->id, $settleLuaParams);

            if ($settleResult['ok'] === 1) {
                $afterBalance = $settleResult['balance'];
            } elseif ($settleResult['error'] === 'duplicate_order') {
                $this->logger->info('TNineSlot立即结算重复请求（Lua检测）', ['order_no' => $settleOrderNo]);
                $afterBalance = $settleResult['balance'];
            }

            $this->logger->info('t9电子下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'afterBalance' => (float)$afterBalance,
                'beforeBalance' => (float)$beforeBalance,
            ]);
        } catch (Exception $e) {
            Log::error('TNineSlot bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE_SLOT', '下注异常', $e, ['params' => $request->post()]);
            return $this->error(self::API_CODE_ERROR);
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

            // 检查是否有错误
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('TNineSlot betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE_SLOT', '结算异常', $e, ['params' => $params]);
            return $this->error(self::API_CODE_ERROR);
        }
    }


    /**
     * 取消下注（Lua原子操作）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->post();

            $this->logger->info('t9电子取消下注请求（Lua原子）', ['params' => $params]);

            $user = $params['gameAccount'];
            $userId = explode('_', $user)[0];
            $this->service->player = Player::query()->where('uuid', $userId)->first();
            $player = $this->service->player;

            $orderNo = (string)($params['betId'] ?? $params['roundId'] ?? '');
            $refundAmount = $params['betAmount'] ?? 0;

            // Lua 原子取消
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL,
                'original_data' => $params,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'T9SLOT', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'T9SLOT', $player->id, $luaParams);

            if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                $this->logger->info('TNineSlot取消下注重复请求（Lua检测）', ['order_no' => $orderNo]);
            }

            $beforeBalance = $result['balance'] - $refundAmount;
            $afterBalance = $result['balance'];

            $this->logger->info('t9电子取消下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'afterBalance' => (float)$afterBalance,
                'beforeBalance' => (float)$beforeBalance,
            ]);
        } catch (Exception $e) {
            Log::error('TNineSlot cancelBet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('TNINE_SLOT', '取消下注异常', $e, ['params' => $request->post()]);
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