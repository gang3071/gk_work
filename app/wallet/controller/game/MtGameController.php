<?php

namespace app\wallet\controller\game;

use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use Exception;
use support\Log;
use support\Request;
use support\Response;

class MtGameController
{
    use TelegramAlertTrait;

    // 1. 使用常量定义状态码，更符合常量的语义
    public const API_CODE_SUCCESS = '00000';
    public const API_CODE_INVALID_PARAM = '20001';
    public const API_CODE_DECRYPT_ERROR = '20002';
    public const API_CODE_MAINTENANCE = '20003';
    public const API_CODE_FAILURE = '20004';
    public const API_CODE_PLAYER_NOT_EXIST = '20101';
    public const API_CODE_INSUFFICIENT_BALANCE = '20102';
    public const API_CODE_ORDER_NOT_EXIST = '20201';
    public const API_CODE_DUPLICATE_ORDER = '20202';
    public const API_CODE_ORDER_SETTLED = '20203';
    public const API_CODE_ORDER_CANCELLED = '20204';
    public const API_CODE_DUPLICATE_SERIAL = '20501';

    // 2. 将状态码映射移到私有常量或属性
    public const API_CODE_MAP = [
        self::API_CODE_SUCCESS => '成功',
        self::API_CODE_INVALID_PARAM => '無效參數',
        self::API_CODE_DECRYPT_ERROR => '解密異常',
        self::API_CODE_MAINTENANCE => '系統維護中',
        self::API_CODE_FAILURE => '執行失敗',
        self::API_CODE_PLAYER_NOT_EXIST => '此玩家帳戶不存在',
        self::API_CODE_INSUFFICIENT_BALANCE => '餘額不足',
        self::API_CODE_ORDER_NOT_EXIST => '單號不存在',
        self::API_CODE_DUPLICATE_ORDER => '重複單號',
        self::API_CODE_ORDER_SETTLED => '注單已結算',
        self::API_CODE_ORDER_CANCELLED => '注單已取消',
        self::API_CODE_DUPLICATE_SERIAL => '重覆序號',
    ];

    // MT平台状态常量
    private const BET_STATUS_NOT = 2;  // 未中奖
    private const BET_STATUS_WIN = 3;  // 中奖
    private const BET_STATUS_TIE = 4;  // 和局

    /** 排除签名验证的接口 */
    protected array $noNeedSign = [];

    protected array $column = [
        'msg',
        'apici',
        'apisi',
        'apits'
    ];

    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_MT);
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

            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->balance();
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => $balance]);
        } catch (Exception $e) {
            Log::error('MT balance failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '余额查询异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
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

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT下注请求（Redis缓存）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['bet_sn'];

            // 3. 幂等性检查（Redis 原子锁）
            $lockKey = "order:bet:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复请求，返回缓存余额
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                Log::channel('mt_server')->info('MT下注重复请求', ['order_no' => $orderNo]);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => (float)$balance]);
            }

            // 4. 获取当前余额
            $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

            // 5. 余额预检查
            if ($currentBalance < $data['order_money']) {
                \support\Redis::del($lockKey);
                return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
            }

            // 6. 写入 Redis 缓存（<0.5ms）
            try {
                \app\service\GameRecordCacheService::saveBet('MT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $data['order_money'],
                    'game_code' => $data['game_code'],
                    'game_type' => $data['gameType'] ?? '',
                    'game_name' => $data['gameName'] ?? '',
                    'original_data' => $data,
                ]);

                // 7. 更新余额缓存（<0.2ms）
                $newBalance = bcsub($currentBalance, $data['order_money'], 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            // 8. 立即返回（总耗时 <1ms）
            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT下注成功（Redis缓存）', [
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => (float)$newBalance
            ]);

        } catch (Exception $e) {
            Log::error('MT bet failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '下注异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
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
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT取消下注请求（Redis缓存）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['bet_sn'];

            // 3. 幂等性检查
            $lockKey = "order:cancel:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => (float)$balance]);
            }

            // 4. 获取当前余额
            $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

            // 5. 写入取消记录
            try {
                \app\service\GameRecordCacheService::saveCancel('MT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'cancel_type' => 'cancel',
                    'original_data' => $data,
                ]);

                // 6. 更新余额缓存（退回下注金额）
                $newBalance = bcadd($currentBalance, $data['order_money'], 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            // 7. 立即返回
            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT取消下注成功（Redis缓存）', [
                'order_no' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => (float)$newBalance
            ]);

        } catch (Exception $e) {
            Log::error('MT cancelBet failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '取消下注异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
        }
    }

    /**
     * 結算（Redis 缓存版）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT结算请求（Redis缓存）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['bet_sn'];

            // 3. 幂等性检查
            $lockKey = "order:settle:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复请求
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'bet_sn' => $orderNo,
                    'balance' => (float)$balance
                ]);
            }

            // 4. 获取当前余额
            $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
            $winMoney = $data['win_money'] ?? 0;
            $status = $data['status'] ?? null;

            // 5. 写入 Redis 缓存
            try {
                \app\service\GameRecordCacheService::saveSettle('MT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $winMoney,
                    'diff' => $winMoney,  // MT的win_money就是输赢金额
                    'original_data' => $data,
                ]);

                // 6. 更新余额缓存（只在有派彩时）
                $newBalance = $currentBalance;
                if ($status !== self::BET_STATUS_NOT && $winMoney > 0) {
                    $newBalance = bcadd($currentBalance, $winMoney, 2);
                    \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                }

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            // 7. 立即返回（<1ms）
            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT结算成功（Redis缓存）', [
                'bet_sn' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'bet_sn' => $orderNo,
                'balance' => (float)$newBalance
            ]);

        } catch (Exception $e) {
            Log::error('MT betResult failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '结算异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
        }
    }

    /**
     * 重新結算（Redis 缓存版）
     * @param Request $request
     * @return Response
     */
    public function reBetResult(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT重新结算请求（Redis缓存）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['bet_sn'];

            // 3. 幂等性检查
            $lockKey = "order:settle:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复请求
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'bet_sn' => $orderNo,
                    'balance' => (float)$balance
                ]);
            }

            try {
                // 4. 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                $winMoney = $data['win_money'] ?? 0;
                $status = $data['status'] ?? null;

                // 5. 写入重新结算记录
                \app\service\GameRecordCacheService::saveSettle('MT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $winMoney,
                    'diff' => $winMoney,  // MT的win_money就是输赢金额
                    'settle_type' => 'adjust',  // 重新结算标记为调整
                    'original_data' => $data,
                ]);

                // 6. 更新余额缓存（只在有派彩时）
                $newBalance = $currentBalance;
                if ($status !== self::BET_STATUS_NOT && $winMoney > 0) {
                    $newBalance = bcadd($currentBalance, $winMoney, 2);
                    \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
                }

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT重新结算成功（Redis缓存）', [
                'bet_sn' => $orderNo,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'bet_sn' => $orderNo,
                'balance' => (float)$newBalance
            ]);

        } catch (Exception $e) {
            Log::error('MT reBetResult failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '重新结算异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
        }
    }

    /**
     * 送礼/打赏（Redis 缓存版）
     * @param Request $request
     * @return Response
     */
    public function gift(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT打赏请求（Redis缓存）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = $data['tip_sn'];  // MT使用tip_sn
            $giftAmount = $data['money'] ?? 0;

            // 3. 幂等性检查
            $lockKey = "order:bet:lock:{$orderNo}";
            if (!\support\Redis::set($lockKey, 1, ['NX', 'EX' => 300])) {
                // 重复请求
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'balance' => (float)$balance
                ]);
            }

            try {
                // 4. 获取当前余额
                $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // 5. 余额预检查
                if ($currentBalance < $giftAmount) {
                    \support\Redis::del($lockKey);
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                }

                // 6. 写入打赏记录（打赏是扣款操作）
                \app\service\GameRecordCacheService::saveBet('MT', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $giftAmount,
                    'game_code' => $data['game_code'] ?? 'gift',
                    'game_type' => 'gift',
                    'game_name' => '打赏',
                    'bet_type' => 'gift',  // 标记为gift类型
                    'original_data' => $data,
                ]);

                // 7. 更新余额缓存（扣款）
                $newBalance = bcsub($currentBalance, $giftAmount, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

            } catch (\Throwable $e) {
                \support\Redis::del($lockKey);
                throw $e;
            }

            $elapsed = (microtime(true) - $startTime) * 1000;
            Log::channel('mt_server')->info('MT打赏成功（Redis缓存）', [
                'tip_sn' => $orderNo,
                'amount' => $giftAmount,
                'elapsed_ms' => round($elapsed, 2),
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => (float)$newBalance
            ]);

        } catch (Exception $e) {
            Log::error('MT gift failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('MT', '打赏异常', $e, [
                'params' => $request->post(),
            ]);
            return $this->error(self::API_CODE_FAILURE, $e->getMessage());
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
            'code' => self::API_CODE_SUCCESS, // 使用业务状态码常量
            'msg' => $message ?: self::API_CODE_MAP[self::API_CODE_SUCCESS],
            'timestamp' => time(),
            'data' => $data,
        ];

        return new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
            $this->service->encrypt(json_encode($responseData, JSON_UNESCAPED_UNICODE))
        );
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
    public function error(string $code, ?string $message = null, array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'code' => $code,
            'message' => $message ?: (self::API_CODE_MAP[$code] ?? '未知错误'),
            'timestamp' => time(),
            'data' => $data,
        ];

        return new Response(
            $httpCode,
            ['Content-Type' => 'text/plain'],
            $this->service->encrypt(json_encode($responseData, JSON_UNESCAPED_UNICODE))
        );
    }

    /**
     * 根据业务错误码返回响应
     *
     * @param string $apiCode 业务错误码常量
     * @param array $data 响应数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    public function responseWithApiCode(string $apiCode, array $data = [], int $httpCode = 200): Response
    {
        if ($apiCode === self::API_CODE_SUCCESS) {
            return $this->success(self::API_CODE_MAP[$apiCode], $data, $httpCode);
        }

        return $this->error($apiCode, self::API_CODE_MAP[$apiCode] ?? null, $data, $httpCode);
    }
}