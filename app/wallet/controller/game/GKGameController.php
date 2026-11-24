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

    public const API_CODE_PLAYER_NOT_EXIST = 1000;
    public const API_CODE_INSUFFICIENT_BALANCE = 1004;
    public const API_CODE_INVALID_PARAM = 1005;
    public const API_CODE_DECRYPT_ERROR = 1006;
    public const API_CODE_MAINTENANCE = 9999;

    public const API_CODE_MAP = [
        self::API_CODE_PLAYER_NOT_EXIST => 'Player Not Exist',
        self::API_CODE_INSUFFICIENT_BALANCE => 'Insufficient balance',
        self::API_CODE_INVALID_PARAM => 'Invalid Param',
        self::API_CODE_DECRYPT_ERROR => 'Decrypt Error',
        self::API_CODE_MAINTENANCE => 'System Error',
    ];

    public $log;
    private GameServiceInterface|SingleWalletServiceInterface $service;

    public function __construct()
    {
        $this->log = Log::channel('gk_server');
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
            $this->log->info('GK 餘額查詢', ['params' => $params]);
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
            $this->log->error('GK 餘額查詢失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('GK', '餘額查詢異常', $e, ['params' => $request->post()]);

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
            $this->log->info('GK 下注&結算', ['params' => $params]);
            $this->service->decrypt($params);

            if ($this->service->error) {
                return self::error($this->service->error);
            }

            if (empty($params['note'])) {
                return self::error(self::API_CODE_INVALID_PARAM);
            }

            $note = json_decode($params['note'], true);

            if (empty($note['WagersID'])) {
                return self::error(self::API_CODE_INVALID_PARAM);
            }

            $player = $this->service->player;
            $orderNo = $params['remitno'];  // 局號
            // $idempotent = $note['WagersID'];  // 冪等唯一值

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
                $this->log->info('GK 開始調用下注處理器', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'amount' => $amount
                ]);

                if ($this->service->checkAndHandleMachineCrash()) {
                    $this->log->error('GK 下注失敗：設備爆機', [
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
                    'game_code' => $note['gameName'] ?? '',
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

                $result = RedisLuaScripts::atomicBet($player->id, 'GK', $luaParams);

                // 審計日誌
                logLuaScriptCall('bet', 'GK', $player->id, $luaParams);

                if ($result['ok'] === 1) {
                    // 保存下注記錄到 Redis
                    GameRecordCacheService::saveBet('GK', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $amount,
                        'game_code' => $note['gameName'] ?? '',
                        'original_data' => $params,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);

                    // 遊戲交互日誌
                    logGameInteraction('GK', 'bet', $params, [
                        'ok' => $result['ok'],
                        'balance' => $result['balance'],
                        'order_no' => $orderNo,
                    ]);

                    $this->log->info('GK 下注成功', ['order_no' => $orderNo]);
                }

                if ($result['ok'] === 0) {
                    if ($result['error'] === 'duplicate_order') {
                        $this->log->info('GK 下注重複請求', ['order_no' => $orderNo]);
                    } elseif ($result['error'] === 'insufficient_balance') {
                        return self::error(self::API_CODE_INSUFFICIENT_BALANCE);
                    }
                }
            }

            // ---------------------------------------- 結算 ----------------------------------------
            if ($case == 2) {
                $this->log->info('GK 開始調用結算處理器', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'amount' => $amount
                ]);

                $betAmount = getBetAmountWithFallback('GK', $orderNo, $player->id, $this->service->platform->id);
                $win = bcadd($amount, $betAmount, 2);

                $luaParams = [
                    'order_no' => $orderNo,
                    'platform_id' => $this->service->platform->id,
                    'amount' => $win,
                    'diff' => $amount,
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

                $result = RedisLuaScripts::atomicSettle($player->id, 'GK', $luaParams);

                // 審計日誌
                logLuaScriptCall('settle', 'GK', $player->id, $luaParams);

                if ($result['ok'] === 1) {
                    // 保存結算記錄到 Redis
                    GameRecordCacheService::saveSettle('GK', [
                        'order_no' => $orderNo,
                        'player_id' => $player->id,
                        'platform_id' => $this->service->platform->id,
                        'amount' => $win,
                        'diff' => $amount,
                        'game_code' => $note['gameName'] ?? '',
                        'original_data' => $params,
                        'balance_before' => $result['old_balance'] ?? 0,
                        'balance_after' => $result['balance'],
                    ]);

                    // 結算成功後檢查是否爆機，如果爆機更新狀態
                    WalletService::checkMachineCrashAfterTransaction(
                        $player->id,
                        $result['balance'],
                        $result['old_balance'] ?? null
                    );

                    $this->log->info('GK 結算成功', [
                        'order_no' => $orderNo,
                        'win_amount' => $win,
                        'balance_before' => $result['old_balance'],
                        'balance_after' => $result['balance'],
                    ]);
                }

                if ($result['ok'] === 0) {
                    if ($result['error'] === 'duplicate_settle') {
                        $this->log->info('GK 重複結算', [
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
            $this->log->error('GK 下注&結算失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('GK', '下注&結算異常', $e, ['params' => $request->post()]);

            return self::error(self::API_CODE_MAINTENANCE);
        }
    }

    /**
     * 取消下注
     * @param Request $request
     * @return Response
     */
    public function cancelTransfer(Request $request): Response
    {
        try {
            $params = $request->post();
            $this->log->info('GK 取消下注', ['params' => $params]);
            $this->service->decrypt($params);

            if ($this->service->error) {
                return self::error($this->service->error);
            }

            $note = json_decode($params['note'], true);

            if (empty($note['WagersID'])) {
                return self::error(self::API_CODE_INVALID_PARAM);
            }

            $player = $this->service->player;
            $orderNo = $params['remitno'];  // 局號
            // $idempotent = $note['WagersID'];  // 冪等唯一值
            $refundAmount = getBetAmountWithFallback('GK', $orderNo, $player->id, $this->service->platform->id);

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

            $result = RedisLuaScripts::atomicCancel($player->id, 'GK', $luaParams);

            // 審計日誌
            logLuaScriptCall('cancel', 'GK', $player->id, $luaParams);

            if ($result['ok'] === 1) {
                // 保存取消記錄到 Redis
                GameRecordCacheService::saveCancel('GK', [
                    'order_no' => $orderNo,
                    'player_id' => $player->id,
                    'platform_id' => $this->service->platform->id,
                    'refund_amount' => $refundAmount,
                    'original_data' => $params,
                    'balance_before' => $result['old_balance'] ?? 0,
                    'balance_after' => $result['balance'],
                ]);

                $this->log->info('GK 退款成功', [
                    'order_no' => $orderNo,
                    'refund_amount' => $refundAmount,
                    'balance_before' => $result['old_balance'],
                    'balance_after' => $result['balance'],
                ]);
            }

            if ($result['ok'] === 0) {
                if ($result['error'] === 'duplicate_cancel') {
                    $this->log->info('GK 重複取消下注', [
                        'order_no' => $orderNo,
                        'balance' => $result['balance'],
                    ]);
                }
            }

            return self::success('', [
                'balance' => $result['balance'] ?? WalletService::getBalance($player->id),
                'currencyType' => $params['currencyType'],
                'remitno' => $orderNo
            ]);
        } catch (Exception $e) {
            $this->log->error('GK 取消下注失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendTelegramAlert('GK', '取消下注異常', $e, ['params' => $request->post()]);

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
        $this->log->info('GK 返回紀錄', ['response' => $responseData]);

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
    public function error(string $code, array $data = [], int $httpCode = 200): Response
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