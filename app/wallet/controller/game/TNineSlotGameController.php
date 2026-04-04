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
     * 下注（Redis 缓存版）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            $this->logger->info('t9电子下注记录（Redis缓存）', ['params' => $params]);

            $user = $params['gameAccount'];
            $userId = explode('_', $user)[0];
            $this->service->player = Player::query()->where('uuid', $userId)->first();
            $player = $this->service->player;

            $orderNo = $params['gameOrderNumber'];
            $betKind = $params['betKind'] ?? 0;
            $betAmount = $params['betAmount'] ?? 0;
            $winAmount = $params['winlose'] ?? $params['payoutAmount'] ?? 0;

            // 幂等性检查
            $lockKey = "order:bet:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复订单
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'afterBalance' => $balance,
                    'beforeBalance' => $balance,
                ]);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // betKind == 3：免费游戏，直接结算，不扣款
                if ($betKind == 3) {
                    // 写入结算记录（免费游戏）
                    \app\service\GameRecordCacheService::saveSettle('T9SLOT', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => max($winAmount, 0),
                        'diff' => $winAmount,
                        'original_data' => $params,
                    ]);

                    // 更新余额缓存（只加中奖金额）
                    $newBalance = $currentBalance;
                    if ($winAmount > 0) {
                        $newBalance = bcadd($currentBalance, $winAmount, 2);
                        \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                    }

                    $elapsed = (microtime(true) - $startTime) * 1000;
                    $this->logger->info('t9电子免费游戏成功（Redis缓存）', [
                        'order_no' => $orderNo,
                        'elapsed_ms' => round($elapsed, 2),
                    ]);

                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                        'afterBalance' => $newBalance,
                        'beforeBalance' => $currentBalance,
                    ]);
                }

                // 普通下注：余额预检查
                if ($currentBalance < $betAmount) {
                    \support\Redis::del($lockKey);
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                }

                // 写入下注记录
                \app\service\GameRecordCacheService::saveBet('T9SLOT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $betAmount,
                    'game_code' => $params['gameCode'] ?? '',
                    'original_data' => $params,
                ]);

                // 更新余额缓存（扣款）
                $newBalance = bcsub($currentBalance, $betAmount, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

                // T9Slot 总是下注后立即结算
                \app\service\GameRecordCacheService::saveSettle('T9SLOT', [
                    'order_no' => $orderNo . '_settle',
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => max($winAmount, 0),
                    'diff' => $winAmount,
                    'original_data' => $params,
                ]);

                // 更新余额缓存（加上中奖金额）
                if ($winAmount > 0) {
                    $newBalance = bcadd($newBalance, $winAmount, 2);
                    \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                }

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->logger->info('t9电子下注成功（Redis缓存）', [
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'afterBalance' => $newBalance,
                'beforeBalance' => $currentBalance,
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
     * 取消下注（Redis 缓存版）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function cancelBet(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            $this->logger->info('t9电子取消下注（Redis缓存）', ['params' => $params]);

            $user = $params['gameAccount'];
            $userId = explode('_', $user)[0];
            $this->service->player = Player::query()->where('uuid', $userId)->first();
            $player = $this->service->player;

            $orderNo = $params['betId'] ?? $params['roundId'] ?? '';
            $refundAmount = $params['betAmount'] ?? 0;

            // 幂等性检查
            $lockKey = "order:cancel:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复取消
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'afterBalance' => $balance,
                    'beforeBalance' => $balance,
                ]);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // 写入取消记录
                \app\service\GameRecordCacheService::saveCancel('T9SLOT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'cancel_type' => 'cancel',
                    'original_data' => $params,
                ]);

                // 更新余额缓存（退款）
                $newBalance = bcadd($currentBalance, $refundAmount, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->logger->info('t9电子取消下注成功（Redis缓存）', [
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'afterBalance' => $newBalance,
                'beforeBalance' => $currentBalance,
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