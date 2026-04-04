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
     * 下注（Redis 缓存版）
     * @param $params
     * @return Response
     * @throws \Throwable
     */
    public function bet($params): Response
    {
        $startTime = microtime(true);

        try {
            Log::channel('sps_server')->info('sps下注记录（Redis缓存）', ['params' => $params]);
            $this->service->player = Player::query()->where('uuid', $params['User'])->first();
            $player = $this->service->player;

            $orderNo = $params['OrderId'] ?? '';
            $ttype = $params['TType'] ?? 0;
            $amount = $params['Amount'] ?? 0;

            // TType == 1：结算
            if ($ttype == 1) {
                // 幂等性检查
                $lockKey = "order:settle:lock:{$orderNo}";
                if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                    // 重复结算
                    $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                    return $this->success(self::API_CODE_SUCCESS, [
                        'User' => $params['User'],
                        'Balance' => $balance,
                        'TransferId' => $orderNo,
                    ]);
                }

                try {
                    // 获取当前余额
                    $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                    // 写入结算记录
                    \app\service\GameRecordCacheService::saveSettle('SPSDY', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => max($amount, 0),
                        'diff' => $amount,
                        'original_data' => $params,
                    ]);

                    // 更新余额缓存（加上中奖金额）
                    $newBalance = $currentBalance;
                    if ($amount > 0) {
                        $newBalance = bcadd($currentBalance, $amount, 2);
                        \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                    }

                } catch (\Throwable $e) {
                    \support\Redis::del($lockKey);
                    throw $e;
                }

                $elapsed = (microtime(true) - $startTime) * 1000;
                Log::channel('sps_server')->info('sps结算成功（Redis缓存）', [
                    'order_no' => $orderNo,
                    'elapsed_ms' => round($elapsed, 2),
                ]);

                return $this->success(self::API_CODE_SUCCESS, [
                    'User' => $params['User'],
                    'Balance' => $newBalance,
                    'TransferId' => $orderNo,
                ]);
            }

            // 普通下注：幂等性检查
            $lockKey = "order:bet:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复订单
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_SUCCESS, [
                    'User' => $params['User'],
                    'Balance' => $balance,
                    'TransferId' => $orderNo,
                ]);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // 余额预检查
                if ($currentBalance < $amount) {
                    \support\Redis::del($lockKey);
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                }

                // 写入下注记录
                \app\service\GameRecordCacheService::saveBet('SPSDY', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $amount,
                    'original_data' => $params,
                ]);

                // 更新余额缓存（扣款）
                $newBalance = bcsub($currentBalance, $amount, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('sps_server')->info('sps下注成功（Redis缓存）', [
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_SUCCESS, [
                'User' => $params['User'],
                'Balance' => $newBalance,
                'TransferId' => $orderNo,
            ]);
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
            // getStatus 与 bet 逻辑相同，直接调用 bet 方法
            return $this->bet($params);
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