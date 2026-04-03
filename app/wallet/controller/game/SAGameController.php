<?php

namespace app\wallet\controller\game;


use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameQueueService;
use Exception;
use SimpleXMLElement;
use support\Log;
use support\Request;
use support\Response;

class SAGameController
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
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_SA);
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
            Log::channel('sa_server')->info('sa余额查询记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }
            $balance = $this->service->balance();
            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], array_merge($data, ['amount' => $balance]));
        } catch (Exception $e) {
            Log::error('SA balance failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SA', '余额查询异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
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
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sa_server')->info('sa下注记录', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['txnid'];
            $bet = $data['amount'];

            // 获取当前余额
            $currentBalance = \app\service\WalletService::getBalance($player->id);

            // 检查幂等性
            $betKey = "sa:bet:lock:{$orderNo}";
            $isDuplicate = !\support\Redis::setnx($betKey, 1);
            if (!$isDuplicate) {
                \support\Redis::expire($betKey, 300);
            }

            if ($isDuplicate) {
                // 重复订单，返回当前余额
                return $this->error(self::API_CODE_GENERAL_ERROR, [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => $currentBalance,
                ]);
            }

            // 准备队列参数
            $queueParams = [
                'order_no' => $orderNo,
                'amount' => $bet,
                'platform_id' => $this->service->platform->id,
                'game_code' => $data['hostid'],
                'order_time' => $data['timestamp'],
                'original_data' => $data,
            ];

            // 立即写入 Redis 预占状态（在入队列之前）
            try {
                \support\Redis::hMSet("order:pending:{$orderNo}", [
                    'player_id' => $player->id,
                    'order_no' => $orderNo,
                    'amount' => $bet,
                    'platform_id' => $this->service->platform->id,
                    'game_code' => $data['hostid'],
                    'status' => 'pending',
                    'created_at' => time(),
                ]);
                \support\Redis::expire("order:pending:{$orderNo}", 300);
            } catch (\Throwable $e) {
                // Redis 失败不影响主流程
            }

            // 发送下注队列
            $sent = GameQueueService::sendBet('SA', $player, $queueParams);

            if ($sent) {
                // 预估余额：扣款
                $estimatedBalance = bcsub($currentBalance, $bet, 2);
                $estimatedBalance = max(0, $estimatedBalance);

                $return = [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => $estimatedBalance,
                ];
            } else {
                // 队列失败，同步降级
                \support\Redis::del($betKey);
                $balance = $this->service->bet($data);
                $return = [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => $balance,
                ];
                if ($this->service->error) {
                    return $this->error($this->service->error, $return);
                }
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('SA bet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SA', '下注异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
        }
    }

    /**
     * 取消下注
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sa_server')->info('sa取消下注', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $orderNo = $data['txn_reverse_id'];
            $refundAmount = $data['amount'];

            // 获取当前余额
            $currentBalance = \app\service\WalletService::getBalance($player->id);

            // 准备队列参数
            $queueParams = [
                'order_no' => $orderNo,
                'bet_order_no' => $orderNo,
                'amount' => $refundAmount,
                'original_data' => $data,
            ];

            // 发送取消队列
            $sent = GameQueueService::sendCancel('SA', $player, $queueParams);

            if ($sent) {
                // 预估余额：退款
                $estimatedBalance = bcadd($currentBalance, $refundAmount, 2);

                $return = [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => $estimatedBalance,
                ];
            } else {
                // 队列失败，同步降级
                $balance = $this->service->cancelBet($data);
                $return = [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => $balance,
                ];
                if ($this->service->error) {
                    return $this->error($this->service->error, $return);
                }
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('SA cancelBet failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SA', '取消下注异常', $e, ['params' => $request->rawBody()]);
            return $this->error(self::API_CODE_GENERAL_ERROR);
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
            $params = $request->rawBody();
            $data = $this->service->decrypt($params);
            Log::channel('sa_server')->info('sa结算下注', ['params' => $data]);
            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            $player = $this->service->player;
            $totalWinAmount = $data['amount'] ?? 0;

            // 解析批量结算列表
            $detail = json_decode($data['payoutdetails'], true);
            $betList = $detail['betlist'] ?? [];

            // 获取当前余额
            $currentBalance = \app\service\WalletService::getBalance($player->id);

            // 批量发送结算队列
            $allSent = true;
            foreach ($betList as $betInfo) {
                $orderNo = $betInfo['txnid'];

                $queueParams = [
                    'order_no' => $orderNo,
                    'bet_order_no' => $orderNo,
                    'amount' => max($betInfo['resultamount'], 0),
                    'result_amount' => $betInfo['resultamount'],
                    'original_data' => $betInfo,
                ];

                // 发送结算队列
                $sent = GameQueueService::sendSettle('SA', $player, $queueParams);
                if (!$sent) {
                    $allSent = false;
                    break;
                }
            }

            if ($allSent) {
                // 预估余额：加款（如果 totalWinAmount > 0）
                $estimatedBalance = $currentBalance;
                if ($totalWinAmount > 0) {
                    $estimatedBalance = bcadd($currentBalance, $totalWinAmount, 2);
                }

                $return = [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => $estimatedBalance,
                ];
            } else {
                // 队列失败，同步降级
                $balance = $this->service->betResulet($data);
                $return = [
                    'username' => $data['username'],
                    'currency' => $data['currency'],
                    'amount' => $balance,
                ];
                if ($this->service->error) {
                    return $this->error($this->service->error, $return);
                }
            }

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], $return);
        } catch (Exception $e) {
            Log::error('SA betResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendTelegramAlert('SA', '结算异常', $e, ['params' => $request->rawBody()]);
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