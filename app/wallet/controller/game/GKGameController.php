<?php

namespace app\wallet\controller\game;

use app\Constants\TransactionType;
use app\service\game\GameServiceFactory;
use app\service\game\GameServiceInterface;
use app\service\game\SingleWalletServiceInterface;
use app\service\GameRecordCacheService;
use app\service\RedisLuaScripts;
use app\service\WalletService;
use Exception;
use support\Log;
use support\Request;
use support\Response;

class GKGameController
{
    use TelegramAlertTrait;

    public const API_CODE_SUCCESS = true;
    public const API_CODE_FAIL = false;

    public const API_CODE_MAINTENANCE = 1000;
    public const API_CODE_INVALID_PARAM = 1001;
    public const API_CODE_DECRYPT_ERROR = 1002;
    public const API_CODE_PLAYER_NOT_EXIST = 1003;
    public const API_CODE_INSUFFICIENT_BALANCE = 1004;

    public const API_CODE_MAP = [
        self::API_CODE_MAINTENANCE => 'System Error',
        self::API_CODE_INVALID_PARAM => 'Invalid Param',
        self::API_CODE_DECRYPT_ERROR => 'Decrypt Error',
        self::API_CODE_PLAYER_NOT_EXIST => 'Player Not Exist',
        self::API_CODE_INSUFFICIENT_BALANCE => 'Insufficient balance'
    ];

    public $log;
    public $platform;
    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->log = Log::channel('gk_server');
        $this->platform = 'GK';
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_GK);
    }

    /**
     * 獲取玩家錢包
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function getUsrBalance(Request $request)
    {
        try {
            $params = $request->post();
            $this->log->info('餘額查詢', ['params' => $params]);
            $this->service->decrypt($params);

            if ($this->service->error) {
                return self::error($this->service->error);
            }

            return self::success('', [
                'balance' => $this->service->balance(),
                'currencyType' => $params['currencyType'],
                'username' => $params['username']
            ]);
        } catch (Exception $e) {
            $this->log->error('餘額查詢失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert($this->platform, '餘額查詢異常', $e, ['params' => $request->post()]);

            return self::error(self::API_CODE_MAINTENANCE);
        }
    }

    /**
     * 下注 & 結算 (三方把兩個接口放一起)
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function transfer(Request $request)
    {
        try {
            $params = $request->post();
            $this->log->info('下注&結算', ['params' => $params]);
            $this->service->decrypt($params);

            if ($this->service->error) {
                return self::error($this->service->error);
            }

            if (empty($params['remitno']) || empty($params['note']) || !isset($params['withdrawal']) || !isset($params['deposit']) || empty($params['currencyType'])) {
                return self::error(self::API_CODE_INVALID_PARAM);
            }

            if ($params['withdrawal'] > 0 && $params['deposit'] > 0) {
                return self::error(self::API_CODE_INVALID_PARAM);
            }

            /********** 交易序號 目前用不到 **********
            $note = json_decode($params['note'], true);

            if (empty($note['WagersID'])) {
                return self::error(self::API_CODE_INVALID_PARAM);
            }

            $idempotent = $note['WagersID'];  // 冪等唯一值
            *******************************************/

            $player = $this->service->player;
            $orderNo = $params['remitno'];  // 局號

            if (! empty($params['withdrawal'])) {
                // 下注
                $amount = abs($params['withdrawal']);
                $case = 1;
            } else {
                // 結算 (結算金額可能為 0)
                $amount = abs($params['deposit']);
                $case = 2;
            }

            // ---------------------------------------- 下注 ----------------------------------------
            if ($case == 1) {
                $this->log->info('開始調用下注處理器', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'amount' => $amount
                ]);

                if ($this->service->checkAndHandleMachineCrash()) {
                    $this->log->error('下注失敗：設備爆機', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'error' => $this->service->error
                    ]);

                    return self::error(self::API_CODE_INSUFFICIENT_BALANCE);
                }

                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $amount,
                    'game_code' => $params['gameID'] ?? $note['gameName'] ?? '',
                    'transaction_type' => TransactionType::BET,
                    'original_data' => $params,
                ];

                // 參數驗證
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'platform_id' => ['required', 'integer'],
                    'amount' => ['required', 'numeric', 'min:0'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicBet');

                $result = RedisLuaScripts::atomicBet($player->id, $this->platform, $luaParams);

                // 審計日誌
                logLuaScriptCall('bet', $this->platform, $player->id, $luaParams);

                if ($result['ok'] === 1) {
                    // 保存下注記錄到 Redis
                    GameRecordCacheService::saveBet($this->platform, [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $luaParams['platform_id'],
                        'amount' => $luaParams['amount'],
                        'game_code' => $luaParams['game_code'],
                        'original_data' => $luaParams['original_data'],
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);

                    // 遊戲交互日誌
                    logGameInteraction($this->platform, 'bet', $params, [
                        'ok' => $result['ok'],
                        'balance' => $result['balance'],
                        'order_no' => $orderNo,
                    ]);

                    $this->log->info('下注成功', ['order_no' => $orderNo]);
                }

                if ($result['ok'] === 0) {
                    if ($result['error'] == 'duplicate_order') {
                        $this->log->info('下注重複請求', ['order_no' => $orderNo]);
                    }

                    if ($result['error'] == 'insufficient_balance') {
                        return self::error(self::API_CODE_INSUFFICIENT_BALANCE);
                    }
                }
            }

            // ---------------------------------------- 結算 ----------------------------------------
            if ($case == 2) {
                $this->log->info('開始調用結算處理器', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'amount' => $amount
                ]);

                $betAmount = getBetAmountWithFallback($this->platform, $orderNo, $player->id, $this->service->platform->id);
                $diff = bcsub($amount, $betAmount, 2);

                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $amount,
                    'diff' => $amount,
                    'game_code' => $params['gameID'] ?? $note['gameName'] ?? '',
                    'transaction_type' => TransactionType::SETTLE,
                    'original_data' => $params
                ];

                // 參數驗證
                validateLuaScriptParams($luaParams, [
                    'order_no' => ['required', 'string'],
                    'platform_id' => ['required', 'integer'],
                    'amount' => ['required', 'numeric'],
                    'diff' => ['required', 'numeric'],
                    'transaction_type' => ['required', 'string'],
                ], 'atomicSettle');

                $result = RedisLuaScripts::atomicSettle($player->id, $this->platform, $luaParams);

                // 審計日誌
                logLuaScriptCall('settle', $this->platform, $player->id, $luaParams);

                if ($result['ok'] === 1) {
                    // 保存結算記錄到 Redis
                    GameRecordCacheService::saveSettle($this->platform, [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $luaParams['platform_id'],
                        'amount' => $amount,
                        'diff' => $diff,
                        'game_code' => $luaParams['game_code'],
                        'original_data' => $luaParams['original_data'],
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);

                    // 結算成功後檢查是否爆機，如果爆機更新狀態
                    WalletService::checkMachineCrashAfterTransaction(
                        $player->id,
                        $result['balance'],
                        $result['old_balance'] ?? null
                    );

                    $this->log->info('結算成功', [
                        'order_no' => $orderNo,
                        'win_amount' => $amount,
                        'balance_before' => $result['old_balance'],
                        'balance_after' => $result['balance'],
                    ]);
                }

                if ($result['ok'] === 0) {
                    if ($result['error'] == 'duplicate_settle') {
                        $this->log->info('重複結算', [
                            'order_no' => $orderNo,
                            'balance' => $result['balance']
                        ]);
                    }
                }
            }

            return self::success('', [
                'balance' => $result['balance'] ?? WalletService::getBalance($player->id),
                'currencyType' => $params['currencyType'],
                'remitno' => $orderNo
            ]);
        } catch (Exception $e) {
            $this->log->error('下注&結算失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert($this->platform, '下注&結算異常', $e, ['params' => $request->post()]);

            return self::error(self::API_CODE_MAINTENANCE);
        }
    }

    /**
     * 取消下注
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function cancelTransfer(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->log->info('取消下注', ['params' => $params]);
            $this->service->decrypt($params);

            if ($this->service->error) {
                return self::error($this->service->error);
            }

            if (empty($params['remitno']) || empty($params['note'])) {
                return self::error(self::API_CODE_INVALID_PARAM);
            }

            /********** 交易序號 目前用不到 **********
            $note = json_decode($params['note'], true);

            if (empty($note['WagersID'])) {
                return self::error(self::API_CODE_INVALID_PARAM);
            }

            $idempotent = $note['WagersID'];  // 冪等唯一值
            *******************************************/

            $player = $this->service->player;
            $orderNo = $params['remitno'];  // 局號
            $refundAmount = getBetAmountWithFallback($this->platform, $orderNo, $player->id, $this->service->platform->id);

            $luaParams = [
                'order_no' => $orderNo,
                'platform_id' => $this->service->platform->id,
                'refund_amount' => $refundAmount,
                'transaction_type' => TransactionType::CANCEL_REFUND,
                'original_data' => $params,
            ];

            // 參數驗證
            validateLuaScriptParams($luaParams, [
                'order_no' => ['required', 'string'],
                'platform_id' => ['required', 'integer'],
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'transaction_type' => ['required', 'string'],
            ], 'atomicCancel');

            $result = RedisLuaScripts::atomicCancel($player->id, $this->platform, $luaParams);

            // 審計日誌
            logLuaScriptCall('cancel', $this->platform, $player->id, $luaParams);

            if ($result['ok'] === 1) {
                // 保存取消記錄到 Redis
                GameRecordCacheService::saveCancel($this->platform, [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $luaParams['platform_id'],
                    'refund_amount' => $luaParams['refund_amount'],
                    'original_data' => $luaParams['original_data'],
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);

                $this->log->info('退款成功', [
                    'order_no' => $orderNo,
                    'refund_amount' => $refundAmount,
                    'balance_before' => $result['old_balance'],
                    'balance_after' => $result['balance'],
                ]);
            }

            if ($result['ok'] === 0) {
                if ($result['error'] == 'duplicate_cancel') {
                    $this->log->info('重複取消下注', [
                        'order_no' => $orderNo,
                        'balance' => $result['balance'],
                    ]);
                }

                if ($result['error'] == 'order_not_found') {
                    $this->log->error('取消下注訂單不存在', ['order_no' => $orderNo]);
                    return self::error(self::API_CODE_INVALID_PARAM);
                }
            }

            return self::success('', [
                'balance' => $result['balance'] ?? WalletService::getBalance($player->id),
                'currencyType' => $params['currencyType'] ?? 'NTD',
                'remitno' => $orderNo
            ]);
        } catch (Exception $e) {
            $this->log->error('取消下注失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert($this->platform, '取消下注異常', $e, ['params' => $request->post()]);

            return self::error(self::API_CODE_MAINTENANCE);
        }
    }

    /**
     * 成功響應方法
     * @param $message 響應訊息
     * @param $data 響應數據
     * @param $httpCode HTTP 狀態碼
     * @return Response
     */
    public function success(string $message = '', array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'result' => self::API_CODE_SUCCESS,
            'data' => $data
        ];

        $reqBase64 = json_encode($responseData);
        $this->log->info('返回紀錄', $responseData);

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            $reqBase64
        ));
    }

    /**
     * 失敗響應方法
     * @param $code 錯誤碼
     * @param $data 響應數據
     * @param $httpCode HTTP 狀態碼
     * @return Response
     */
    public function error(int $code, array $data = [], int $httpCode = 200): Response
    {
        $responseData = [
            'result' => self::API_CODE_FAIL,
            'error' => [
                'code' => $code,
                'message' => self::API_CODE_MAP[$code] ?? 'System Error'
            ]
        ];

        $reqBase64 = json_encode($responseData);

        return (new Response(
            $httpCode,
            ['Content-Type' => 'application/json'],
            $reqBase64
        ));
    }
}