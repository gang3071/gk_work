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

class SPGameController
{
    use TelegramAlertTrait;
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
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_SP);
    }

    /**
     * 获取玩家钱包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function balance(Request $request)
    {
        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sp_server')->info('sp余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->balance();
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], array_merge($data, ['amount' => $balance]));
        } catch (Exception $e) {
            Log::error('SP balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '余额查询异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
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
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sp_server')->info('SP下注请求（Redis缓存）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['txnid'];
            $bet = $data['amount'];

            // 幂等性检查
            $lockKey = "order:bet:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复订单
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->error(self::API_CODE_GENERAL_ERROR, [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => (float)$balance,
                ]);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // 余额预检查
                if ($currentBalance < $bet) {
                    \support\Redis::del($lockKey);
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => (float)$currentBalance,
                    ]);
                }

                // 写入 Redis 缓存
                \app\service\GameRecordCacheService::saveBet('SP', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $bet,
                    'game_code' => $data['gamecode'] ?? '',
                    'original_data' => $data,
                ]);

                // 更新余额缓存
                $newBalance = bcsub($currentBalance, $bet, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('sp_server')->info('SP下注成功（Redis缓存）', [
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => (float)$newBalance,
            ]);
        } catch (Exception $e) {
            Log::error('SP bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '下注异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
        }
    }

    /**
     * 取消下注（Redis 缓存版）
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sp_server')->info('SP取消下注请求（Redis缓存）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['txn_reverse_id'];
            $refundAmount = $data['amount'];

            // 幂等性检查
            $lockKey = "order:cancel:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => (float)$balance,
                ]);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // 写入取消记录
                \app\service\GameRecordCacheService::saveCancel('SP', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'cancel_type' => 'refund',
                    'original_data' => $data,
                ]);

                // 更新余额缓存
                $newBalance = bcadd($currentBalance, $refundAmount, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('sp_server')->info('SP取消下注成功（Redis缓存）', [
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => (float)$newBalance,
            ]);
        } catch (Exception $e) {
            Log::error('SP cancelBet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '取消下注异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
        }
    }

    /**
     * 結算（Redis 缓存版 - 批量处理）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sp_server')->info('SP结算请求（Redis缓存）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $totalWinAmount = $data['amount'] ?? 0;

            // 解析批量结算列表
            $detail = json_decode($data['payoutdetails'], true);
            $betList = $detail['betlist'] ?? [];

            // 获取当前余额
            $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
            $newBalance = $currentBalance;

            // 批量处理结算
            foreach ($betList as $betInfo) {
                $orderNo = $betInfo['txnid'];

                // 每个订单幂等性检查
                $lockKey = "order:settle:lock:{$orderNo}";
                if (\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {

                    try {
                        $resultAmount = max($betInfo['resultamount'], 0);

                        // 写入结算记录
                        \app\service\GameRecordCacheService::saveSettle('SP', [
                            'order_no' => $orderNo,
                            'player_id' => $player->id,
                            'platform_id' => $this->service->platform->id,
                            'amount' => $resultAmount,
                            'diff' => $betInfo['resultamount'], // 保留原始值（可能为负）
                            'original_data' => $betInfo,
                        ]);

                        // 累加派彩金额
                        if ($resultAmount > 0) {
                            $newBalance = bcadd($newBalance, $resultAmount, 2);
                        }

                    } catch (\Throwable $e) {
                        \support\Redis::del($lockKey);
                        throw $e;
                    }
                }
            }

            // 更新余额缓存（只更新一次）
            if ($newBalance != $currentBalance) {
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('sp_server')->info('SP结算成功（Redis缓存）', [
                'count' => count($betList),
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => (float)$newBalance,
            ]);
        } catch (Exception $e) {
            Log::error('SP betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '结算异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
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