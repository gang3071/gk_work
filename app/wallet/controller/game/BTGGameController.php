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

            // 获取系统币别配置
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

            // 验证签名
            if (!$this->service->verifyAuthCode($params)) {
                $this->logger->error('BTG查询余额失败：auth_code验证失败', ['params' => $params]);
                return $this->error(BTGServiceInterface::ERROR_CODE_AUTHORIZATION_INVALID);
            }

            // 验证币别
            if ($params['currency'] !== $systemCurrency) {
                $this->logger->error('BTG查询余额失败：币别错误（返回参数错误）', [
                    'request_currency' => $params['currency'],
                    'system_currency' => $systemCurrency
                ]);
                return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'currency');
            }

            // 检查 tran_id 是否重复（从数据库查询original_data和action_data）
            // 注意：balance操作不产生游戏记录，这里主要检查transfer类操作的重复
            $existingRecord = \app\model\PlayGameRecord::query()
                ->where('platform_id', $this->service->platform->id)
                ->where(function ($query) use ($params) {
                    $query->where("original_data->tran_id", $params['tran_id'])
                          ->orWhere("action_data->tran_id", $params['tran_id']);
                })
                ->first();

            if ($existingRecord) {
                // 幂等性：重复请求返回成功和当前余额
                $this->logger->info('BTG查询余额：请求已处理（幂等性，返回成功）', [
                    'tran_id' => $params['tran_id'],
                    'existing_record_id' => $existingRecord->id
                ]);

                // 查询玩家获取当前余额
                $player = Player::query()->where('uuid', $params['username'])->first();
                if (!$player) {
                    $this->logger->error('BTG查询余额失败：玩家不存在（返回参数错误）', ['username' => $params['username']]);
                    return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'username');
                }

                // 返回成功和当前余额（幂等性）
                $balance = $player->machine_wallet->money ?? 0;
                return $this->success([
                    'balance' => number_format($balance, 2, '.', ''),
                    'currency' => $systemCurrency,
                    'tran_id' => $params['tran_id'],
                ]);
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['username'])->first();
            if (!$player) {
                // 单一钱包模式：用户不存在视为参数格式错误
                $this->logger->error('BTG查询余额失败：玩家不存在（返回参数错误）', ['username' => $params['username']]);
                return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'username');
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
                'balance' => number_format($balance, 2, '.', ''),
                'currency' => $systemCurrency, // 使用系统币别，不是请求的币别
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
     * 转账 - transfer
     * 处理所有类型的金额变动：下注、结算、退款、调整、奖励
     * @param Request $request
     * @return Response
     */
    public function transfer(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->logger->info('BTG转账请求', ['params' => $params]);

            // 获取系统币别配置
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

            // 验证签名
            if (!$this->service->verifyAuthCode($params)) {
                $this->logger->error('BTG转账失败：auth_code验证失败', ['params' => $params]);
                return $this->error(BTGServiceInterface::ERROR_CODE_AUTHORIZATION_INVALID);
            }

            // 验证币别
            if ($params['currency'] !== $systemCurrency) {
                $this->logger->error('BTG转账失败：币别错误（返回参数错误）', [
                    'request_currency' => $params['currency'],
                    'system_currency' => $systemCurrency
                ]);
                return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'currency');
            }

            // 验证 transfer_type
            $allowedTypes = ['start', 'end', 'refund', 'adjust', 'reward'];
            if (!in_array($params['transfer_type'], $allowedTypes)) {
                $this->logger->error('BTG转账失败：无效的transfer_type', ['transfer_type' => $params['transfer_type']]);
                return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'transfer_type');
            }

            // 检查 tran_id 是否重复（从数据库查询original_data和action_data）
            // original_data: start操作的tran_id
            // action_data: end/refund/adjust/reward操作的tran_id
            $existingRecord = \app\model\PlayGameRecord::query()
                ->where('platform_id', $this->service->platform->id)
                ->where(function ($query) use ($params) {
                    $query->where("original_data->tran_id", $params['tran_id'])
                          ->orWhere("action_data->tran_id", $params['tran_id']);
                })
                ->first();

            if ($existingRecord) {
                // 幂等性：重复请求返回成功和当前余额
                $this->logger->info('BTG转账：请求已处理（幂等性，返回成功）', [
                    'tran_id' => $params['tran_id'],
                    'existing_record_id' => $existingRecord->id
                ]);

                // 查询玩家获取当前余额
                $player = Player::query()->where('uuid', $params['username'])->first();
                if (!$player) {
                    $this->logger->error('BTG转账失败：玩家不存在（返回参数错误）', ['username' => $params['username']]);
                    return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'username');
                }

                // 返回成功和当前余额（幂等性）
                $balance = $player->machine_wallet->money ?? 0;
                return $this->success([
                    'balance' => number_format($balance, 2, '.', ''),
                    'currency' => $systemCurrency,
                    'tran_id' => $params['tran_id'],
                ]);
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['username'])->first();
            if (!$player) {
                // 单一钱包模式：用户不存在视为参数格式错误
                $this->logger->error('BTG转账失败：玩家不存在（返回参数错误）', ['username' => $params['username']]);
                return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'username');
            }

            $this->service->player = $player;

            // 解析 trans_details
            $transDetails = json_decode($params['trans_details'], true);
            if (!$transDetails) {
                $this->logger->error('BTG转账失败：trans_details格式错误', ['trans_details' => $params['trans_details']]);
                return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'trans_details');
            }

            // 解析 betform_details（如果存在）
            $betformDetails = [];
            if (isset($params['betform_details']) && $params['betform_details'] !== '{}') {
                $betformDetails = json_decode($params['betform_details'], true);
                if (!$betformDetails) {
                    $this->logger->error('BTG转账失败：betform_details格式错误', ['betform_details' => $params['betform_details']]);
                    return $this->error(BTGServiceInterface::ERROR_CODE_BAD_FORMAT_PARAMS, [], 'betform_details');
                }
            }

            // 根据 transfer_type 分发处理
            $result = match ($params['transfer_type']) {
                'start' => $this->service->transferStart($params, $transDetails),
                'end' => $this->service->transferEnd($params, $transDetails, $betformDetails),
                'refund' => $this->service->transferRefund($params, $transDetails),
                'adjust' => $this->service->transferAdjust($params, $transDetails, $betformDetails),
                'reward' => $this->service->transferReward($params, $transDetails, $betformDetails),
            };

            if ($this->service->error) {
                $this->logger->error('BTG转账失败', [
                    'transfer_type' => $params['transfer_type'],
                    'error' => $this->service->error,
                    'tran_id' => $params['tran_id']
                ]);
                return $this->error($this->service->error);
            }

            $this->logger->info('BTG转账成功', [
                'transfer_type' => $params['transfer_type'],
                'username' => $params['username'],
                'amount' => $params['amount'],
                'balance' => $result['balance'],
                'tran_id' => $params['tran_id']
            ]);

            return $this->success([
                'balance' => number_format($result['balance'], 2, '.', ''),
                'currency' => $systemCurrency, // 使用系统币别，不是请求的币别
                'tran_id' => $params['tran_id'],
            ]);
        } catch (Exception $e) {
            $this->logger->error('BTG转账异常', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
