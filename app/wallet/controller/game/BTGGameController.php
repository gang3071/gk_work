<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\model\PlayGameRecord;
use app\service\game\BTGServiceInterface;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

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

    // 允许的transfer类型
    private const ALLOWED_TRANSFER_TYPES = ['start', 'end', 'refund', 'adjust', 'reward'];

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_BTG);
        $this->logger = Log::channel('btg_server');
    }

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

            $systemCurrency = config('app.currency', 'TWD');

            // 验证必要参数
            if ($error = $this->validateRequiredParams($params, [
                'tran_id' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'username' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'currency' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'auth_code' => BTGServiceInterface::ERROR_CODE_AUTHORIZATION_INVALID,
            ], 'BTG查询余额')) {
                return $error;
            }

            // 验证签名和币别
            if ($error = $this->validateAuthAndCurrency($params, $systemCurrency, 'BTG查询余额')) {
                return $error;
            }

            // 检查tran_id重复
            if ($response = $this->checkIdempotency($params['tran_id'])) {
                return $response;
            }

            // 查询玩家
            $player = $this->getPlayer($params['username'], 'BTG查询余额');
            if ($player instanceof Response) {
                return $player;
            }

            $this->service->player = $player;

            // 获取余额
            $balance = $this->service->balance();
            if ($this->service->error) {
                $this->logger->error('BTG查询余额失败：获取余额错误', [
                    'error' => $this->service->error,
                    'player_id' => $player->id
                ]);
                return $this->error($this->service->error);
            }

            $this->logger->info('BTG查询余额成功', [
                'username' => $params['username'],
                'balance' => $balance,
                'tran_id' => $params['tran_id']
            ]);

            return $this->success([
                'balance' => number_format($balance, 2, '.', ''),
                'currency' => $systemCurrency,
                'tran_id' => $params['tran_id'],
            ]);
        } catch (Exception $e) {
            $this->logger->error('BTG查询余额异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('BTG', '查询余额异常', $e, ['params' => $request->post()]);
            return $this->error(BTGServiceInterface::ERROR_CODE_SOMETHING_WRONG, [], 'message');
        }
    }

    /**
     * 转账 - transfer
     * 处理所有类型的金额变动：下注、结算、退款、调整、奖励
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function transfer(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();
            $this->logger->info('BTG转账请求', ['params' => $params]);

            $systemCurrency = config('app.currency', 'TWD');

            // 验证必要参数
            if ($error = $this->validateRequiredParams($params, [
                'tran_id' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'username' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'amount' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'currency' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'transfer_type' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'game_type' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'game_code' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'trans_details' => BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS,
                'auth_code' => BTGServiceInterface::ERROR_CODE_AUTHORIZATION_INVALID,
            ], 'BTG转账')) {
                return $error;
            }

            // 验证签名和币别
            if ($error = $this->validateAuthAndCurrency($params, $systemCurrency, 'BTG转账')) {
                return $error;
            }

            // 验证 transfer_type
            if (!in_array($params['transfer_type'], self::ALLOWED_TRANSFER_TYPES)) {
                $this->logger->error('BTG转账失败：无效的transfer_type', [
                    'transfer_type' => $params['transfer_type']
                ]);
                return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'transfer_type');
            }

            // 检查tran_id重复
            if ($response = $this->checkIdempotency($params['tran_id'])) {
                return $response;
            }

            // 查询玩家
            $player = $this->getPlayer($params['username'], 'BTG转账');
            if ($player instanceof Response) {
                return $player;
            }

            $this->service->player = $player;

            // 解析 trans_details
            $transDetails = $this->parseJsonParam($params['trans_details'], 'trans_details', 'BTG转账');
            if ($transDetails instanceof Response) {
                return $transDetails;
            }

            // 解析 betform_details（如果存在）
            $betformDetails = [];
            if (isset($params['betform_details'])) {
                $betformDetails = $this->parseJsonParam($params['betform_details'], 'betform_details', 'BTG转账');
                if ($betformDetails instanceof Response) {
                    return $betformDetails;
                }
            }

            // ========== Redis 缓存处理 ==========
            $amount = abs((float)$params['amount']);
            $orderId = $transDetails['order_id'] ?? '';
            $roundId = $transDetails['round_id'] ?? '';
            $transferType = $params['transfer_type'];

            // 幂等性检查（使用 tran_id）
            $lockKey = "order:btg:lock:{$params['tran_id']}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复请求
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success([
                    'balance' => number_format($balance, 2, '.', ''),
                    'currency' => $systemCurrency,
                    'tran_id' => $params['tran_id'],
                ]);
            }

            try {
                // 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                $newBalance = $currentBalance;

                // 根据 transfer_type 处理
                switch ($transferType) {
                    case 'start':
                        // 余额预检查
                        if ($currentBalance < $amount) {
                            \support\Redis::del($lockKey);
                            return $this->error(BTGServiceInterface::ERROR_CODE_INSUFFICIENT_BALANCE);
                        }
                        // 写 Redis 缓存
                        \app\service\GameRecordCacheService::saveBet('BTG', [
                            'order_no' => $orderId,
                            'player_id' => $player->id,
                            'platform_id' => $this->service->platform->id,
                            'amount' => $amount,
                            'game_code' => $params['game_code'] ?? '',
                            'game_type' => $params['game_type'] ?? '',
                            'bet_type' => 'bet',
                            'original_data' => $params,
                        ]);

                        $newBalance = ($amount > 0) ? bcsub($currentBalance, $amount, 2) : $currentBalance;
                        $newBalance = max(0, $newBalance);
                        \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                        break;
                    case 'end':
                        // 结算派彩
                        \app\service\GameRecordCacheService::saveSettle('BTG', [
                            'order_no' => $orderId,
                            'player_id' => $player->id,
                            'platform_id' => $this->service->platform->id,
                            'amount' => $amount,
                            'diff' => $amount,
                            'settle_type' => 'settle',
                            'original_data' => $params,
                        ]);

                        if ($amount > 0) {
                            $newBalance = bcadd($currentBalance, $amount, 2);
                            \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                        }
                        break;

                    case 'refund':
                        // 退款
                        \app\service\GameRecordCacheService::saveSettle('BTG', [
                            'order_no' => $orderId,
                            'player_id' => $player->id,
                            'platform_id' => $this->service->platform->id,
                            'amount' => $amount,
                            'diff' => $amount,
                            'settle_type' => 'refund',
                            'original_data' => $params,
                        ]);

                        $newBalance = bcadd($currentBalance, $amount, 2);
                        \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                        break;

                    case 'adjust':
                        // 调整金额（可正可负）
                        $adjustAmount = (float)$params['amount'];

                        if ($adjustAmount > 0) {
                            // 正数：加款
                            \app\service\GameRecordCacheService::saveSettle('BTG', [
                                'order_no' => $orderId,
                                'player_id' => $player->id,
                                'platform_id' => $this->service->platform->id,
                                'amount' => $adjustAmount,
                                'diff' => $adjustAmount,
                                'settle_type' => 'adjust',
                                'original_data' => $params,
                            ]);

                            $newBalance = bcadd($currentBalance, $adjustAmount, 2);
                            \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

                        } else {
                            // 负数：扣款
                            $deductAmount = abs($adjustAmount);

                            if ($currentBalance < $deductAmount) {
                                \support\Redis::del($lockKey);
                                return $this->error(BTGServiceInterface::ERROR_CODE_INSUFFICIENT_BALANCE);
                            }

                            \app\service\GameRecordCacheService::saveBet('BTG', [
                                'order_no' => $orderId,
                                'player_id' => $player->id,
                                'platform_id' => $this->service->platform->id,
                                'amount' => $deductAmount,
                                'game_code' => $params['game_code'] ?? '',
                                'game_type' => $params['game_type'] ?? '',
                                'bet_type' => 'adjust',
                                'original_data' => $params,
                            ]);

                            $newBalance = bcsub($currentBalance, $deductAmount, 2);
                            \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                        }
                        break;

                    case 'reward':
                        // 额外奖金（无下注）
                        \app\service\GameRecordCacheService::saveSettle('BTG', [
                            'order_no' => $orderId,
                            'player_id' => $player->id,
                            'platform_id' => $this->service->platform->id,
                            'amount' => $amount,
                            'diff' => $amount,
                            'settle_type' => 'reward',
                            'original_data' => $params,
                        ]);

                        $newBalance = bcadd($currentBalance, $amount, 2);
                        \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                        break;
                }

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('BTG转账成功（Redis缓存）', [
                'transfer_type' => $transferType,
                'username' => $params['username'],
                'amount' => $params['amount'],
                'balance' => $newBalance,
                'tran_id' => $params['tran_id'],
                'response_time_ms' => $responseTime,
            ]);

            return $this->success([
                'balance' => number_format($newBalance, 2, '.', ''),
                'currency' => $systemCurrency,
                'tran_id' => $params['tran_id'],
            ]);
        } catch (Exception $e) {
            $this->logger->error('BTG转账异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('BTG', '转账异常', $e, ['params' => $request->post()]);
            return $this->error(BTGServiceInterface::ERROR_CODE_SOMETHING_WRONG);
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
     * 验证签名和币别
     *
     * @param array $params 请求参数
     * @param string $systemCurrency 系统币别
     * @param string $logPrefix 日志前缀
     * @return Response|null 如果验证失败返回错误响应，成功返回null
     */
    private function validateAuthAndCurrency(array $params, string $systemCurrency, string $logPrefix = 'BTG'): ?Response
    {
        // 验证签名
        if (!$this->service->verifyAuthCode($params)) {
            $this->logger->error("{$logPrefix}失败：auth_code验证失败", ['params' => $params]);
            return $this->error(BTGServiceInterface::ERROR_CODE_AUTHORIZATION_INVALID);
        }

        // 验证币别
        if ($params['currency'] !== $systemCurrency) {
            $this->logger->error("{$logPrefix}失败：币别错误", [
                'request_currency' => $params['currency'],
                'system_currency' => $systemCurrency
            ]);
            return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'currency');
        }

        return null;
    }

    /**
     * 查询玩家并返回
     *
     * @param string $username 玩家用户名
     * @param string $logPrefix 日志前缀
     * @return Player|Response 成功返回Player对象，失败返回Response
     */
    private function getPlayer(string $username, string $logPrefix = 'BTG')
    {
        $player = Player::query()->where('uuid', $username)->first();
        if (!$player) {
            $this->logger->error("{$logPrefix}失败：玩家不存在", ['username' => $username]);
            return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'username');
        }
        return $player;
    }

    /**
     * 检查tran_id是否重复
     *
     * @param string $tranId 交易ID
     * @return Response|null 如果是重复请求返回错误响应，否则返回null
     */
    private function checkIdempotency(string $tranId): ?Response
    {
        $existingRecord = PlayGameRecord::query()
            ->where('platform_id', $this->service->platform->id)
            ->where(function ($query) use ($tranId) {
                $query->where("original_data->tran_id", $tranId)
                      ->orWhere("action_data->tran_id", $tranId);
            })
            ->first();

        if (!$existingRecord) {
            return null;
        }

        // 重复的 tran_id，不管订单状态如何，都返回 5107
        $this->logger->error('BTG请求失败：重复的tran_id', [
            'tran_id' => $tranId,
            'existing_record_id' => $existingRecord->id,
            'settlement_status' => $existingRecord->settlement_status
        ]);
        return $this->error(BTGServiceInterface::ERROR_CODE_DUPLICATE_TRAN_ID);
    }

    /**
     * 解析JSON参数
     *
     * @param string $jsonString JSON字符串
     * @param string $paramName 参数名称
     * @param string $logPrefix 日志前缀
     * @return array|Response 成功返回数组，失败返回Response
     */
    private function parseJsonParam(string $jsonString, string $paramName, string $logPrefix = 'BTG')
    {
        // 空JSON对象视为空数组
        if ($jsonString === '{}') {
            return [];
        }

        $data = json_decode($jsonString, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("{$logPrefix}失败：{$paramName}格式错误", [
                $paramName => $jsonString,
                'json_error' => json_last_error_msg()
            ]);
            return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], $paramName);
        }

        return $data ?? [];
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
