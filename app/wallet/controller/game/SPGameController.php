<?php

namespace app\wallet\controller\game;


use app\Constants\TransactionType;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
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
     * 下注（Lua原子操作）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sp_server')->info('SP下注请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txnid'] ?? '');
            $bet = $data['amount'];

            // Lua 原子下注
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $bet,
                'game_code' => $data['gamecode'] ?? '',
                'transaction_type' => TransactionType::BET,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $result = RedisLuaScripts::atomicBet($player->id, 'SP', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'SP', $player->id, $luaParams);

            // 游戏交互日志
            logGameInteraction('SP', 'bet', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);


            // 处理结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    Log::channel('sp_server')->info('SP下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                    return $this->error(self::API_CODE_GENERAL_ERROR, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => (float)$result['balance'],
                    ]);
                } elseif ($result['error'] === 'insufficient_balance') {
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE, [
                        'username' => $data['username'],
                        'currency' => $data['currency'],
                        'amount' => (float)$result['balance'],
                    ]);
                }
            }

            Log::channel('sp_server')->info('SP下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => (float)$result['balance'],
            ]);
        } catch (Exception $e) {
            Log::error('SP bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '下注异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
        }
    }

    /**
     * 取消下注（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sp_server')->info('SP取消下注请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = (string)($data['txn_reverse_id'] ?? '');
            $refundAmount = $data['amount'];

            // Lua 原子取消
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL_REFUND,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'SP', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'SP', $player->id, $luaParams);

            // 处理结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                Log::channel('sp_server')->info('SP取消下注重复请求（Lua检测）', ['order_no' => $orderNo]);
            }

            Log::channel('sp_server')->info('SP取消下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => (float)$result['balance'],
            ]);
        } catch (Exception $e) {
            Log::error('SP cancelBet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SP', '取消下注异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
        }
    }

    /**
     * 結算（Lua原子操作 - 批量处理）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sp_server')->info('SP结算请求（Lua原子）', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;

            // 解析批量结算列表
            $detail = json_decode($data['payoutdetails'], true);
            $betList = $detail['betlist'] ?? [];

            $processedCount = 0;
            $lastBalance = null;

            // 批量处理结算（每个订单一次 Lua 原子操作）
            foreach ($betList as $betInfo) {
                $orderNo = (string)($betInfo['txnid'] ?? '');
                $resultAmount = max($betInfo['resultamount'], 0);

                // Lua 原子结算
                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $resultAmount,
                    'diff' => $betInfo['resultamount'], // 保留原始值（可能为负）
                    'transaction_type' => TransactionType::SETTLE,
                    'original_data' => $betInfo,
                ];

                // 参数验证
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'amount' => ['required', 'numeric'],
                    'diff' => ['required', 'numeric'],
                    'platform_id' => ['required', 'integer'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $result = RedisLuaScripts::atomicSettle($player->id, 'SP', $luaParams);

                // 审计日志
                logLuaScriptCall('settle', 'SP', $player->id, $luaParams);

                if ($result['ok'] === 1) {
                    $processedCount++;
                    $lastBalance = $result['balance'];
                } elseif ($result['error'] === 'duplicate_order') {
                    Log::channel('sp_server')->info('SP结算订单重复（Lua检测）', ['order_no' => $orderNo]);
                    $lastBalance = $result['balance'];
                }
            }

            // 获取最终余额
            $finalBalance = $lastBalance ?? \app\service\GameRecordCacheService::getCachedBalance($player->id);

            Log::channel('sp_server')->info('SP结算成功（Lua原子）', [
                'total' => count($betList),
                'processed' => $processedCount,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'username' => $data['username'],
                'currency' => $data['currency'],
                'amount' => (float)$finalBalance,
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