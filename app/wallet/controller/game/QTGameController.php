<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\service\game\QTServiceInterface;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * QT中心钱包
 */
class QTGameController
{
    use TelegramAlertTrait;

    // QT标准错误码
    const ERROR_INVALID_TOKEN = 'INVALID_TOKEN';           // 400 - 缺失、无效或过期的会话令牌
    const ERROR_ACCOUNT_BLOCKED = 'ACCOUNT_BLOCKED';       // 403 - 玩家账户被锁定
    const ERROR_LOGIN_FAILED = 'LOGIN_FAILED';             // 401 - Pass-Key不正确
    const ERROR_REQUEST_DECLINED = 'REQUEST_DECLINED';     // 400 - 通用错误，请求无法处理
    const ERROR_INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS'; // 400 - 余额不足
    const ERROR_LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';         // 400 - 超过游戏限制
    const ERROR_UNKNOWN_ERROR = 'UNKNOWN_ERROR';           // 500 - 意外错误

    /**
     * @var QTServiceInterface
     */
    private GameServiceInterface|SingleWalletServiceInterface $service;

    private $logger;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_QT);
        $this->logger = Log::channel('qt_server');
    }

    /**
     * 验证请求头
     * @param Request $request
     * @param bool $requireWalletSession 是否要求Wallet-Session
     * @return Response|null 如果验证失败返回错误响应，成功返回null
     */
    private function verifyHeaders(Request $request, bool $requireWalletSession = true): ?Response
    {
        $passKey = $request->header('Pass-Key');
        $walletSession = $request->header('Wallet-Session');

        $this->logger->info('QT验证请求头', [
            'pass_key' => $passKey ? substr($passKey, 0, 10) . '...' : 'missing',
            'wallet_session' => $walletSession ? substr($walletSession, 0, 10) . '...' : 'missing'
        ]);

        // 验证Pass-Key
        if (!$this->service->verifyPassKey($passKey)) {
            $this->logger->error('QT Pass-Key验证失败');
            return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
        }

        // 验证Wallet-Session（如果需要）
        if ($requireWalletSession && !$walletSession) {
            $this->logger->error('QT Wallet-Session缺失');
            return $this->errorResponse(self::ERROR_INVALID_TOKEN, 'Missing player session token.', 400);
        }

        return null;
    }

    /**
     * 检查玩家状态
     * @param Player $player
     * @return Response|null 如果玩家被锁定返回错误响应，正常返回null
     */
    private function checkPlayerStatus(Player $player): ?Response
    {
        // 检查玩家是否被删除（软删除）
        if ($player->trashed()) {
            $this->logger->error('QT玩家账户已被删除', ['player_id' => $player->id]);
            return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
        }

        // 检查玩家状态（1=正常，0=锁定）
        if (isset($player->status) && $player->status != 1) {
            $this->logger->error('QT玩家账户被锁定', ['player_id' => $player->id, 'status' => $player->status]);
            return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
        }

        return null;
    }

    #[RateLimiter(limit: 10)]
    /**
     * 1. 验证会话 - Verify Session
     * GET /accounts/{playerId}/session?gameId={gameId}
     * @param Request $request
     * @param string $playerId
     * @return Response
     */
    public function verifySession(Request $request, string $playerId): Response
    {
        try {
            $gameId = $request->get('gameId');
            $this->logger->info('QT验证会话请求', [
                'playerId' => $playerId,
                'gameId' => $gameId
            ]);

            // 验证请求头（需要Wallet-Session）
            if ($error = $this->verifyHeaders($request, true)) {
                return $error;
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $playerId)->first();
            if (!$player) {
                return $this->errorResponse(self::ERROR_INVALID_TOKEN, 'Player not found', 400);
            }

            // 检查玩家状态
            if ($error = $this->checkPlayerStatus($player)) {
                return $error;
            }

            $this->service->player = $player;

            // 获取余额
            $balance = $this->service->balance();

            $response = [
                'balance' => number_format($balance, 2, '.', ''),
                'currency' => 'CNY', // 根据实际情况调整
            ];

            $this->logger->info('QT验证会话成功', $response);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
        } catch (Exception $e) {
            $this->logger->error('QT验证会话异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('QT', '验证会话异常', $e, ['playerId' => $playerId]);
            return $this->errorResponse(self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500);
        }
    }

    #[RateLimiter(limit: 10)]
    /**
     * 2. 查询余额 - Get Balance
     * GET /accounts/{playerId}/balance?gameId={gameId}
     *
     * 可能的错误:
     * - REQUEST_DECLINED (400) - 通用错误，请求无法处理
     * - LOGIN_FAILED (401) - Pass-Key不正确
     * - UNKNOWN_ERROR (500) - 意外错误
     *
     * @param Request $request
     * @param string $playerId
     * @return Response
     */
    public function getBalance(Request $request, string $playerId): Response
    {
        try {
            $gameId = $request->get('gameId');
            $this->logger->info('QT查询余额请求', [
                'playerId' => $playerId,
                'gameId' => $gameId
            ]);

            // 验证Pass-Key
            $passKey = $request->header('Pass-Key');
            if (!$this->service->verifyPassKey($passKey)) {
                $this->logger->error('QT查询余额失败：Pass-Key验证失败');
                return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
            }

            // 注意：余额查询允许过期或缺失的wallet-session，所以不验证它

            // 查询玩家
            $player = Player::query()->where('uuid', $playerId)->first();
            if (!$player) {
                $this->logger->error('QT查询余额失败：玩家不存在', ['playerId' => $playerId]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player not found', 400);
            }

            // 检查玩家状态（软删除或锁定）
            if ($player->trashed()) {
                $this->logger->error('QT查询余额失败：玩家账户已删除', ['player_id' => $player->id]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is deleted', 400);
            }

            // 检查玩家状态（1=正常，0=锁定）
            if (isset($player->status) && $player->status != 1) {
                $this->logger->error('QT查询余额失败：玩家账户被锁定', ['player_id' => $player->id, 'status' => $player->status]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is blocked', 400);
            }

            $this->service->player = $player;

            // 获取余额
            $balance = $this->service->balance();

            if ($this->service->error) {
                $this->logger->error('QT查询余额失败：获取余额错误', ['error' => $this->service->error]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Failed to get balance', 400);
            }

            $response = [
                'balance' => number_format($balance, 2, '.', ''),
                'currency' => 'CNY', // 根据实际情况调整
            ];

            $this->logger->info('QT查询余额成功', $response);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
        } catch (Exception $e) {
            $this->logger->error('QT查询余额异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('QT', '查询余额异常', $e, ['playerId' => $playerId]);
            return $this->errorResponse(self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500);
        }
    }

    #[RateLimiter(limit: 20)]
    /**
     * 3. 交易接口 - Transactions (DEBIT/CREDIT)
     * POST /transactions
     *
     * DEBIT可能的错误:
     * - REQUEST_DECLINED (400) - 通用错误，请求无法处理
     * - INVALID_TOKEN (400) - 缺失、无效或过期的会话令牌
     * - INSUFFICIENT_FUNDS (400) - 余额不足
     * - LIMIT_EXCEEDED (400) - 超过游戏限制
     * - ACCOUNT_BLOCKED (403) - 玩家账户被锁定
     * - LOGIN_FAILED (401) - Pass-Key不正确
     * - UNKNOWN_ERROR (500) - 意外错误
     *
     * CREDIT可能的错误:
     * - REQUEST_DECLINED (400) - 通用错误，请求无法处理
     * - LOGIN_FAILED (401) - Pass-Key不正确
     * - UNKNOWN_ERROR (500) - 意外错误
     *
     * @param Request $request
     * @return Response
     */
    public function transaction(Request $request): Response
    {
        try {
            $params = json_decode($request->rawBody(), true);
            $this->logger->info('QT交易请求', ['params' => $params]);

            $txnType = $params['txnType'] ?? '';

            // 验证Pass-Key
            $passKey = $request->header('Pass-Key');
            if (!$this->service->verifyPassKey($passKey)) {
                $this->logger->error('QT交易失败：Pass-Key验证失败');
                return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
            }

            // 验证Wallet-Session（DEBIT和CREDIT都需要）
            $walletSession = $request->header('Wallet-Session');
            if (!$walletSession) {
                $this->logger->error('QT交易失败：Wallet-Session缺失');
                // DEBIT使用INVALID_TOKEN，CREDIT使用REQUEST_DECLINED
                if ($txnType === 'DEBIT') {
                    return $this->errorResponse(self::ERROR_INVALID_TOKEN, 'Missing player session token.', 400);
                } else {
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Missing player session token.', 400);
                }
            }

            // 验证必要参数
            $requiredFields = ['txnType', 'txnId', 'playerId', 'roundId', 'amount', 'currency', 'gameId', 'created', 'completed'];
            foreach ($requiredFields as $field) {
                if (!isset($params[$field])) {
                    $this->logger->error('QT交易失败：缺少必要参数', ['field' => $field]);
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, "Missing required field: {$field}", 400);
                }
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['playerId'])->first();
            if (!$player) {
                $this->logger->error('QT交易失败：玩家不存在', ['playerId' => $params['playerId']]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player not found', 400);
            }

            // 检查玩家状态
            if ($player->trashed()) {
                $this->logger->error('QT交易失败：玩家账户已删除', ['player_id' => $player->id]);
                // DEBIT使用ACCOUNT_BLOCKED，CREDIT使用REQUEST_DECLINED
                if ($txnType === 'DEBIT') {
                    return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
                } else {
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'The player account is blocked.', 400);
                }
            }

            // 检查玩家状态（1=正常，0=锁定）
            if (isset($player->status) && $player->status != 1) {
                $this->logger->error('QT交易失败：玩家账户被锁定', ['player_id' => $player->id, 'status' => $player->status]);
                // DEBIT使用ACCOUNT_BLOCKED，CREDIT使用REQUEST_DECLINED
                if ($txnType === 'DEBIT') {
                    return $this->errorResponse(self::ERROR_ACCOUNT_BLOCKED, 'The player account is blocked.', 403);
                } else {
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'The player account is blocked.', 400);
                }
            }

            $this->service->player = $player;

            // 根据交易类型处理
            $result = match ($params['txnType']) {
                'DEBIT' => $this->service->debit($params),
                'CREDIT' => $this->service->credit($params),
                default => throw new Exception("Invalid transaction type: {$params['txnType']}")
            };

            if ($this->service->error) {
                $this->logger->error('QT交易失败', [
                    'txnType' => $params['txnType'],
                    'error' => $this->service->error
                ]);

                // 根据交易类型映射错误码
                if ($params['txnType'] === 'DEBIT') {
                    // DEBIT专用错误码映射
                    $errorMap = [
                        'INSUFFICIENT_BALANCE' => [self::ERROR_INSUFFICIENT_FUNDS, 'Insufficient funds', 400],
                        'LIMIT_EXCEEDED' => [self::ERROR_LIMIT_EXCEEDED, 'Game limit exceeded', 400],
                        'INTERNAL_ERROR' => [self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500],
                    ];
                    $errorInfo = $errorMap[$this->service->error] ?? [self::ERROR_REQUEST_DECLINED, 'Transaction failed', 400];
                } else {
                    // CREDIT只使用3种错误码：REQUEST_DECLINED, LOGIN_FAILED, UNKNOWN_ERROR
                    $errorMap = [
                        'INTERNAL_ERROR' => [self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500],
                    ];
                    $errorInfo = $errorMap[$this->service->error] ?? [self::ERROR_REQUEST_DECLINED, 'Transaction failed', 400];
                }

                return $this->errorResponse($errorInfo[0], $errorInfo[1], $errorInfo[2]);
            }

            $this->logger->info('QT交易成功', [
                'txnType' => $params['txnType'],
                'txnId' => $params['txnId'],
                'result' => $result
            ]);

            return new Response(201, ['Content-Type' => 'application/json'], json_encode($result));
        } catch (Exception $e) {
            $this->logger->error('QT交易异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('QT', '交易异常', $e, ['params' => $request->rawBody()]);
            return $this->errorResponse(self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500);
        }
    }

    #[RateLimiter(limit: 10)]
    /**
     * 4. 回滚交易 - Rollback
     * POST /transactions/rollback
     *
     * 可能的错误:
     * - REQUEST_DECLINED (400) - 通用错误，请求无法处理
     * - LOGIN_FAILED (401) - Pass-Key不正确
     * - UNKNOWN_ERROR (500) - 意外错误
     *
     * @param Request $request
     * @return Response
     */
    public function rollback(Request $request): Response
    {
        try {
            $params = json_decode($request->rawBody(), true);
            $this->logger->info('QT回滚请求', ['params' => $params]);

            // 验证Pass-Key
            $passKey = $request->header('Pass-Key');
            if (!$this->service->verifyPassKey($passKey)) {
                $this->logger->error('QT回滚失败：Pass-Key验证失败');
                return $this->errorResponse(self::ERROR_LOGIN_FAILED, 'The given pass-key is incorrect.', 401);
            }

            // 注意：回滚允许过期或缺失的wallet-session，所以不验证它

            // 验证必要参数
            $requiredFields = ['betId', 'txnId', 'playerId', 'roundId', 'amount', 'currency', 'gameId', 'created', 'completed'];
            foreach ($requiredFields as $field) {
                if (!isset($params[$field])) {
                    $this->logger->error('QT回滚失败：缺少必要参数', ['field' => $field]);
                    return $this->errorResponse(self::ERROR_REQUEST_DECLINED, "Missing required field: {$field}", 400);
                }
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['playerId'])->first();
            if (!$player) {
                $this->logger->error('QT回滚失败：玩家不存在', ['playerId' => $params['playerId']]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player not found', 400);
            }

            // 检查玩家状态（软删除或锁定）
            if ($player->trashed()) {
                $this->logger->error('QT回滚失败：玩家账户已删除', ['player_id' => $player->id]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is deleted', 400);
            }

            // 检查玩家状态（1=正常，0=锁定）
            if (isset($player->status) && $player->status != 1) {
                $this->logger->error('QT回滚失败：玩家账户被锁定', ['player_id' => $player->id, 'status' => $player->status]);
                return $this->errorResponse(self::ERROR_REQUEST_DECLINED, 'Player account is blocked', 400);
            }

            $this->service->player = $player;

            // 执行回滚
            $result = $this->service->rollback($params);

            if ($this->service->error) {
                $this->logger->error('QT回滚失败', ['error' => $this->service->error]);
                // Rollback只使用3种错误码：REQUEST_DECLINED, LOGIN_FAILED, UNKNOWN_ERROR
                $errorMap = [
                    'INTERNAL_ERROR' => [self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500],
                ];
                // 默认所有其他错误使用REQUEST_DECLINED
                $errorInfo = $errorMap[$this->service->error] ?? [self::ERROR_REQUEST_DECLINED, 'Rollback failed', 400];
                return $this->errorResponse($errorInfo[0], $errorInfo[1], $errorInfo[2]);
            }

            $this->logger->info('QT回滚成功', [
                'betId' => $params['betId'],
                'txnId' => $params['txnId'],
                'result' => $result
            ]);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($result));
        } catch (Exception $e) {
            $this->logger->error('QT回滚异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('QT', '回滚异常', $e, ['params' => $request->rawBody()]);
            return $this->errorResponse(self::ERROR_UNKNOWN_ERROR, 'Unexpected error', 500);
        }
    }

    /**
     * 错误响应
     * @param string $code
     * @param string $message
     * @param int $httpCode
     * @return Response
     */
    private function errorResponse(string $code, string $message, int $httpCode = 400): Response
    {
        $response = [
            'code' => $code,
            'message' => $message
        ];

        $this->logger->error('QT错误响应', $response);

        return new Response($httpCode, ['Content-Type' => 'application/json'], json_encode($response));
    }
}
