<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\model\Player;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\RedisLuaScripts;
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
     * 下注（Lua原子操作）
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function bet(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT下注请求（Lua原子）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['bet_sn'] ?? '');

            // 3. Lua 原子下注
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $data['order_money'],
                'game_code' => $data['game_code'],
                'game_type' => $data['gameType'] ?? '',
                'game_name' => $data['gameName'] ?? '',
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

            $result = RedisLuaScripts::atomicBet($player->id, 'MT', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'MT', $player->id, $luaParams);

            // 游戏交互日志
            logGameInteraction('MT', 'bet', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
            ]);

            // 4. 处理结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    Log::channel('mt_server')->info('MT下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => (float)$result['balance']]);
                } elseif ($result['error'] === 'insufficient_balance') {
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                }
            }

            Log::channel('mt_server')->info('MT下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => (float)$result['balance']
            ]);

        } catch (Exception $e) {
            // 游戏交互日志
            logGameInteraction('MT', 'bet', $data ?? [], [
                'error' => $e->getMessage(),
                'ok' => 0,
            ]);

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
     * 取消下注（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function cancelBet(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT取消下注请求（Lua原子）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['bet_sn'] ?? '');

            // 3. Lua 原子取消
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $data['order_money'],
                'transaction_type' => TransactionType::CANCEL,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, 'MT', $luaParams);

            // 审计日志
            logLuaScriptCall('cancel', 'MT', $player->id, $luaParams);

            // 游戏交互日志
            logGameInteraction('MT', 'cancel', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
                'refund_amount' => $data['order_money'],
            ]);

            // 4. 处理结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                Log::channel('mt_server')->info('MT取消下注重复请求（Lua检测）', ['order_no' => $orderNo]);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => (float)$result['balance']]);
            }

            Log::channel('mt_server')->info('MT取消下注成功（Lua原子）', ['order_no' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => (float)$result['balance']
            ]);

        } catch (Exception $e) {
            // 游戏交互日志
            logGameInteraction('MT', 'cancel', $data ?? [], [
                'error' => $e->getMessage(),
                'ok' => 0,
            ]);

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
     * 結算（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function betResult(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT结算请求（Lua原子）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['bet_sn'] ?? '');
            $winMoney = $data['win_money'] ?? 0;
            $status = $data['status'] ?? null;

            // 3. Lua 原子结算
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $winMoney,
                'diff' => $winMoney,  // MT的win_money就是输赢金额
                'transaction_type' => TransactionType::SETTLE,
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $result = RedisLuaScripts::atomicSettle($player->id, 'MT', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'MT', $player->id, $luaParams);

            // 游戏交互日志
            logGameInteraction('MT', 'settle', $data, [
                'ok' => $result['ok'],
                'balance' => $result['balance'],
                'order_no' => $orderNo,
                'win_amount' => $winMoney,
            ]);

            // 4. 处理结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                Log::channel('mt_server')->info('MT结算重复请求（Lua检测）', ['bet_sn' => $orderNo]);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'bet_sn' => $orderNo,
                    'balance' => (float)$result['balance']
                ]);
            }

            Log::channel('mt_server')->info('MT结算成功（Lua原子）', ['bet_sn' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'bet_sn' => $orderNo,
                'balance' => (float)$result['balance']
            ]);

        } catch (Exception $e) {
            // 游戏交互日志
            logGameInteraction('MT', 'settle', $data ?? [], [
                'error' => $e->getMessage(),
                'ok' => 0,
            ]);

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
     * 重新結算（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function reBetResult(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT重新结算请求（Lua原子）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['bet_sn'] ?? '');
            $winMoney = $data['win_money'] ?? 0;

            // 3. Lua 原子重新结算
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $winMoney,
                'diff' => $winMoney,  // MT的win_money就是输赢金额
                'transaction_type' => TransactionType::SETTLE_ADJUST,  // 重新结算标记为调整
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric'],
                'diff' => ['required', 'numeric'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicSettle');

            $result = RedisLuaScripts::atomicSettle($player->id, 'MT', $luaParams);

            // 审计日志
            logLuaScriptCall('settle', 'MT', $player->id, $luaParams);

            // 4. 处理结果
            if ($result['ok'] === 0 && $result['error'] === 'duplicate_order') {
                Log::channel('mt_server')->info('MT重新结算重复请求（Lua检测）', ['bet_sn' => $orderNo]);
                return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                    'bet_sn' => $orderNo,
                    'balance' => (float)$result['balance']
                ]);
            }

            Log::channel('mt_server')->info('MT重新结算成功（Lua原子）', ['bet_sn' => $orderNo]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'bet_sn' => $orderNo,
                'balance' => (float)$result['balance']
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
     * 送礼/打赏（Lua原子操作）
     * @param Request $request
     * @return Response
     */
    public function gift(Request $request): Response
    {
        try {
            $params = $request->post();

            // 1. 解密和验证
            $data = $this->service->decrypt($params['msg']);
            Log::channel('mt_server')->info('MT打赏请求（Lua原子）', ['params' => $data]);

            if ($this->service->error) {
                return $this->error($this->service->error);
            }

            // 2. 查询玩家
            $player = Player::where('uuid', $data['account'])->first();
            if (!$player) {
                return $this->error(self::API_CODE_PLAYER_NOT_EXIST);
            }

            $orderNo = (string)($data['tip_sn'] ?? '');  // MT使用tip_sn
            $giftAmount = $data['money'] ?? 0;

            // 3. Lua 原子打赏（打赏是扣款操作，使用bet）
            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'amount' => $giftAmount,
                'game_code' => $data['game_code'] ?? 'gift',
                'game_type' => 'gift',
                'game_name' => '打赏',
                'transaction_type' => TransactionType::BET_GIFT,  // 标记为gift类型
                'original_data' => $data,
            ];

            // 参数验证
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:0'],
                'platform_id' => ['required', 'integer'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicBet');

            $result = RedisLuaScripts::atomicBet($player->id, 'MT', $luaParams);

            // 审计日志
            logLuaScriptCall('bet', 'MT', $player->id, $luaParams);

            // 4. 处理结果
            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_order') {
                    Log::channel('mt_server')->info('MT打赏重复请求（Lua检测）', ['tip_sn' => $orderNo]);
                    return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], ['balance' => (float)$result['balance']]);
                } elseif ($result['error'] === 'insufficient_balance') {
                    return $this->error(self::API_CODE_INSUFFICIENT_BALANCE);
                }
            }

            Log::channel('mt_server')->info('MT打赏成功（Lua原子）', [
                'tip_sn' => $orderNo,
                'amount' => $giftAmount,
            ]);

            return $this->success(self::API_CODE_MAP[self::API_CODE_SUCCESS], [
                'balance' => (float)$result['balance']
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