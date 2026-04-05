<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use Carbon\Carbon;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use support\Cache;
use support\Log;

class BTGServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    // 错误码常量
    public const ERROR_CODE_SUCCESS = '1000';
    public const ERROR_CODE_GENERAL_ERROR = '2001';
    public const ERROR_CODE_GAME_MAINTENANCE = '4001';
    public const ERROR_CODE_GAME_NOT_EXIST = '4002';
    public const ERROR_CODE_OPERATION_FREQUENT = '4003';
    public const ERROR_CODE_TIME_FORMAT_ERROR = '4004';
    public const ERROR_CODE_GAME_NO_DEMO = '4005';
    public const ERROR_CODE_ACTIVITY_TIME_OVERLAP = '4051';
    public const ERROR_CODE_ACTIVITY_UNSUPPORTED_GAMES = '4052';
    public const ERROR_CODE_ACTIVITY_NOT_EXIST = '4053';
    public const ERROR_CODE_IP_NOT_ALLOWED = '4102';
    public const ERROR_CODE_INVALID_CHECK_CODE = '4103';
    public const ERROR_CODE_AGENT_NOT_EXIST = '4104';
    public const ERROR_CODE_AGENT_LOCKED = '4105';
    public const ERROR_CODE_PLAYER_PASSWORD_ERROR = '4201';
    public const ERROR_CODE_PLAYER_NOT_EXIST = '4202';
    public const ERROR_CODE_PLAYER_ALREADY_EXIST = '4203';
    public const ERROR_CODE_GAME_RECORD_NOT_EXIST = '4204';
    public const ERROR_CODE_PLAYER_LOCKED = '4206';
    public const ERROR_CODE_PARAM_FORMAT_ERROR = '4302';
    public const ERROR_CODE_PARAM_VALUE_ERROR = '4303';
    public const ERROR_CODE_AUTHORIZATION_INVALID = '5001';
    public const ERROR_CODE_BAD_FORMAT_PARAMS = '5002';
    public const ERROR_CODE_INSUFFICIENT_BALANCE = '5101';
    public const ERROR_CODE_UNFINISHED_TRANSACTIONS = '5102';
    public const ERROR_CODE_PLAYER_SUSPENDED = '5103';
    public const ERROR_CODE_GAME_CLOSED = '5104';
    public const ERROR_CODE_TRANSACTION_NOT_EXIST = '5105';
    public const ERROR_CODE_TRANSACTION_SETTLED = '5106';
    public const ERROR_CODE_DUPLICATE_TRAN_ID = '5107';
    public const ERROR_CODE_SOMETHING_WRONG = '5201';
    public const ERROR_CODE_WITHDRAW_FAILED = '6101';
    public const ERROR_CODE_DEPOSIT_FAILED = '6102';
    public const ERROR_CODE_DUPLICATE_ORDER = '6104';
    public const ERROR_CODE_TRANSACTION_NOT_FOUND = '6105';
    public const ERROR_CODE_DEPOSIT_AMOUNT_ERROR = '6107';
    public const ERROR_CODE_WITHDRAW_AMOUNT_ERROR = '6108';
    public const ERROR_CODE_PARAM_CONFLICT = '6109';
    public const ERROR_CODE_PLAYER_TRANSACTION_LOCKED = '6110';
    public const ERROR_CODE_GET_BALANCE_FAILED = '6111';
    public const ERROR_CODE_TRANSACTION_TOO_FREQUENT = '6112';

    // 错误码消息映射
    public const ERROR_CODE_MAP = [
        self::ERROR_CODE_SUCCESS => '成功',
        self::ERROR_CODE_GENERAL_ERROR => '發生預期外錯誤',
        self::ERROR_CODE_GAME_MAINTENANCE => '該遊戲目前維護中',
        self::ERROR_CODE_GAME_NOT_EXIST => '該遊戲不存在',
        self::ERROR_CODE_OPERATION_FREQUENT => '操作頻繁，請稍後再試(間隔1秒以上)',
        self::ERROR_CODE_TIME_FORMAT_ERROR => '請使用美東時間格式',
        self::ERROR_CODE_GAME_NO_DEMO => '遊戲不提供試玩網址',
        self::ERROR_CODE_ACTIVITY_TIME_OVERLAP => '活動時間重疊',
        self::ERROR_CODE_ACTIVITY_UNSUPPORTED_GAMES => '內含不支援遊戲',
        self::ERROR_CODE_ACTIVITY_NOT_EXIST => '活動不存在或是已結束',
        self::ERROR_CODE_IP_NOT_ALLOWED => '不被允許訪問的ip',
        self::ERROR_CODE_INVALID_CHECK_CODE => '錯誤的驗證碼',
        self::ERROR_CODE_AGENT_NOT_EXIST => '該代理商不存在',
        self::ERROR_CODE_AGENT_LOCKED => '該代理被鎖定',
        self::ERROR_CODE_PLAYER_PASSWORD_ERROR => '玩家帳號或密碼錯誤',
        self::ERROR_CODE_PLAYER_NOT_EXIST => '該玩家不存在',
        self::ERROR_CODE_PLAYER_ALREADY_EXIST => '該玩家已註冊',
        self::ERROR_CODE_GAME_RECORD_NOT_EXIST => '欲查詢之遊戲紀錄不存在',
        self::ERROR_CODE_PLAYER_LOCKED => '該玩家被鎖定',
        self::ERROR_CODE_PARAM_FORMAT_ERROR => '特定參數(arg)格式錯誤',
        self::ERROR_CODE_PARAM_VALUE_ERROR => '特定參數(arg)值錯誤',
        self::ERROR_CODE_AUTHORIZATION_INVALID => '錯誤的驗證',
        self::ERROR_CODE_BAD_FORMAT_PARAMS => '特定引數(arg)錯誤',
        self::ERROR_CODE_INSUFFICIENT_BALANCE => '該玩家餘額不足',
        self::ERROR_CODE_UNFINISHED_TRANSACTIONS => '尚有交易處理中',
        self::ERROR_CODE_PLAYER_SUSPENDED => '玩家帳號被停權',
        self::ERROR_CODE_GAME_CLOSED => '遊戲已被關閉',
        self::ERROR_CODE_TRANSACTION_NOT_EXIST => '交易不存在',
        self::ERROR_CODE_TRANSACTION_SETTLED => '交易已處理',
        self::ERROR_CODE_DUPLICATE_TRAN_ID => '重複的 tran_id',
        self::ERROR_CODE_SOMETHING_WRONG => '發生了以下錯誤',
        self::ERROR_CODE_WITHDRAW_FAILED => '提款交易執行失敗',
        self::ERROR_CODE_DEPOSIT_FAILED => '存款交易執行失敗',
        self::ERROR_CODE_DUPLICATE_ORDER => '該外部交易流水號已存在',
        self::ERROR_CODE_TRANSACTION_NOT_FOUND => '查無該交易紀錄',
        self::ERROR_CODE_DEPOSIT_AMOUNT_ERROR => '存款金額數值錯誤',
        self::ERROR_CODE_WITHDRAW_AMOUNT_ERROR => '提款金額數值錯誤',
        self::ERROR_CODE_PARAM_CONFLICT => 'take_all=true與withdraw_amount 參數衝突',
        self::ERROR_CODE_PLAYER_TRANSACTION_LOCKED => '玩家交易狀態被鎖定',
        self::ERROR_CODE_GET_BALANCE_FAILED => '取餘額失敗',
        self::ERROR_CODE_TRANSACTION_TOO_FREQUENT => '交易過於頻繁'
    ];

    public $method = 'POST';
    public $successCode = self::ERROR_CODE_SUCCESS;
    public string $error = '';

    private $apiDomain;
    private $appId;
    private $md5Key;
    private $appSecret;

    private $path = [
        'getBalance' => '/user/get_balance',
        'depositAmount' => '/user/deposit_amount',
        'withdrawAmount' => '/user/withdraw_amount',
        'getTransactionStatus' => '/user/get_transaction_status',
        'gameLogin' => '/agent/user_login',
        'lobbyLogin' => '/agent/lobby_login',
        'userLogout' => '/agent/user_logout',
        'getGameList' => '/agent/get_gamelist',
        'getGameHistories' => '/record/get_game_histories',
        'getPlayerHistories' => '/record/get_player_histories',
        'getPlayerFinances' => '/record/get_player_finances',
        'getFinances' => '/record/get_finances',
        'getSingleBettingRecord' => '/record/get_single_betting_record',
        'getEventRecords' => '/event/get_event_records',
    ];

    private $lang = [
        'zh-CN' => 'zh-cn',
        'zh-TW' => 'zh-tw',
        'jp' => 'ja',
        'en' => 'en',
        'th' => 'th',
        'vi' => 'vi',
        'my' => 'my',
        'id' => 'id',
        'hi_hi' => 'hi',
        'kr_ko' => 'ko',
    ];

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.BTG');
        $this->appId = $config['app_id'];
        $this->md5Key = $config['md5_key'];
        $this->apiDomain = $config['api_domain'];
        $this->appSecret = $config['app_secret'];
        $this->platform = GamePlatform::query()->where('code', 'BTG')->first();
        $this->player = $player;
    }

    /**
     * 查詢玩家餘額
     * @param array $data
     * @return float
     * @throws GameException
     */
    public function getBalance(array $data = []): float
    {
        $this->checkPlayer($data['lang']);
        $params = [
            'account_id' => $this->appId,
            'username' => $this->player->uuid,
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('getBalance'), $params);
        if ($res['status']['code'] != $this->successCode) {
            if ($res['status']['code'] == '4202') {
                $this->lobbyLogin($data);
                return 0;
            }
            throw new GameException(self::ERROR_CODE_MAP[$res['status']['code']], 0);
        }

        return $res['data']['balance'] ?? 0;
    }

    /**
     * 创建游戏记录的基础数据
     *
     * @param string $orderId 订单号
     * @param array $params 请求参数
     * @return array 基础记录数据
     */
    private function buildGameRecordBaseData(string $orderId, array $params): array
    {
        return [
            'player_id' => $this->player->id,
            'parent_player_id' => $this->player->recommend_id ?? 0,
            'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $this->player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $params['game_code'] ?? '',
            'department_id' => $this->player->department_id,
            'order_no' => $orderId,
            'order_time' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * 创建资金交易记录
     *
     * @param PlayerPlatformCash $wallet 钱包
     * @param PlayGameRecord $record 游戏记录
     * @param float $amount 金额
     * @param int $type 交易类型
     * @param string $source 来源
     * @param string $remark 备注
     * @param float $beforeBalance 交易前余额
     */
    private function createDeliveryRecord(
        PlayerPlatformCash $wallet,
        PlayGameRecord $record,
        float $amount,
        int $type,
        string $source,
        string $remark,
        float $beforeBalance
    ): void {
        $playerDeliveryRecord = new PlayerDeliveryRecord();
        $playerDeliveryRecord->player_id = $this->player->id;
        $playerDeliveryRecord->department_id = $this->player->department_id;
        $playerDeliveryRecord->target = $record->getTable();
        $playerDeliveryRecord->target_id = $record->id;
        $playerDeliveryRecord->platform_id = $this->platform->id;
        $playerDeliveryRecord->type = $type;
        $playerDeliveryRecord->source = $source;
        $playerDeliveryRecord->amount = $amount;
        $playerDeliveryRecord->amount_before = $beforeBalance;
        $playerDeliveryRecord->amount_after = $wallet->money;
        $playerDeliveryRecord->tradeno = $record->order_no;
        $playerDeliveryRecord->remark = $remark;
        $playerDeliveryRecord->user_id = 0;
        $playerDeliveryRecord->user_name = '';
        $playerDeliveryRecord->save();
    }

    /**
     * 查找并锁定游戏记录
     *
     * @param string $orderId 订单号
     * @return PlayGameRecord|null
     */
    private function findAndLockGameRecord(string $orderId): ?PlayGameRecord
    {
        return PlayGameRecord::query()
            ->where('order_no', $orderId)
            ->where('platform_id', $this->platform->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * 检查玩家
     * @throws GameException
     */
    private function checkPlayer($lang)
    {
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if (!empty($playerGamePlatform)) {
            return $this->lobbyLogin([
                'lang' => $lang
            ]);
        }
        $playerGamePlatform = new PlayerGamePlatform();
        $playerGamePlatform->player_id = $this->player->id;
        $playerGamePlatform->platform_id = $this->platform->id;
        $playerGamePlatform->player_name = $this->player->name;
        $playerGamePlatform->player_code = $this->player->uuid;
        $playerGamePlatform->save();

        return true;
    }

    /**
     * 进入游戏大厅
     * @param array $data
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function lobbyLogin(array $data = []): string
    {
        $params = [
            'account_id' => $this->appId,
            'username' => $this->player->uuid,
            'lang' => $this->lang[$data['lang']],
            'routing' => 'zh-cn',
            'device' => 'mobile',
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('lobbyLogin'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException(self::ERROR_CODE_MAP[$res['status']['code']], 0);
        }

        return $res['data']['lobby_url'] ?? '';
    }

    /**
     * @param $params
     * @return string
     */
    public function createSign($params): string
    {
        ksort($params);
        $queryString = '';
        foreach ($params as $key => $value) {
            if ($key != 'back_url') {
                $queryString .= urlencode($key) . '=' . str_replace('%3A', ':', urlencode($value)) . '&';
            } else {
                $queryString .= urlencode($key) . '=' . $value . '&';
            }
        }
        $queryString = rtrim($queryString, '&');
        return md5('security_code=' . $this->appSecret . '&' . $queryString);
    }

    /**
     * 生成请求url
     * @param $method
     * @return string
     */
    public function createUrl($method): string
    {
        return $this->apiDomain . $this->path[$method];
    }

    /**
     * 儲值玩家額度
     * @param array $data
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function depositAmount(array $data = []): string
    {
        $this->checkPlayer($data['lang']);
        $params = [
            'account_id' => $this->appId,
            'username' => $this->player->uuid,
            'deposit_amount' => $data['amount'] ?? 0,
            'external_order_id' => $data['order_no'] ?? '',
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('depositAmount'), $params);
        if ($res['status']['code'] != $this->successCode) {
            Log::error(self::ERROR_CODE_MAP[$res['status']['code']], ['res' => $res]);
            throw new GameException(self::ERROR_CODE_MAP[$res['status']['code']], 0);
        }
        if (empty($res['data']['order_id'])) {
            throw new GameException(trans('transfer_fail', [], 'message'), 0);
        }
        Cache::set('depositAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('withdrawAmount_' . $this->player->id);

        return $res['data']['order_id'];
    }

    /**
     * 回放记录
     * @param array $data
     * @return mixed
     */
    public function replay(array $data = [])
    {
        $origin = json_decode($data['original_data'], true);
        return $origin['cn_detail_url'];
    }

    /**
     * 提領玩家額度
     * @param array $data
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function withdrawAmount(array $data = []): array
    {
        $this->checkPlayer($data['lang']);
        $params['account_id'] = $this->appId;
        $params['username'] = $this->player->uuid;
        $params['external_order_id'] = $data['order_no'] ?? '';
        $params['take_all'] = $data['take_all'];
        if ($data['take_all'] == 'false') {
            $params['withdraw_amount'] = $data['amount'] ?? 0;
        }
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('withdrawAmount'), $params);
        if ($res['status']['code'] != $this->successCode) {
            Log::error(self::ERROR_CODE_MAP[$res['status']['code']], ['res' => $res]);
            throw new GameException(self::ERROR_CODE_MAP[$res['status']['code']], 0);
        }
        if (empty($res['data']['order_id'])) {
            throw new GameException(trans('transfer_fail', [], 'message'), 0);
        }
        if ($res['data']['amount'] <= 0) {
            throw new GameException(trans('withdraw_amount_zero', [], 'message'), 0);
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $res['data']['order_id'],
            'amount' => $res['data']['amount'],
        ];
    }

    /**
     * 登出游戏
     * @return true
     * @throws GameException
     * @throws Exception
     */
    public function userLogout(): bool
    {
        $params = [
            'account_id' => $this->appId,
            'username' => $this->player->uuid,
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('userLogout'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException(self::ERROR_CODE_MAP[$res['status']['code']], 0);
        }

        return true;
    }

    /**
     * 查詢交易狀態
     * @param string $orderNo
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function getTransactionStatus(string $orderNo = ''): array
    {
        $params = [
            'account_id' => $this->appId,
            'external_order_id' => $orderNo,
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('getTransactionStatus'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException(self::ERROR_CODE_MAP[$res['status']['code']], 0);
        }

        return $res;
    }

    /**
     * 取得區間內遊戲紀錄
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function handleGameHistories(): array
    {
        try {
            $page = 1;
            $list = [];
            $startTime = (new DateTime('now', new DateTimeZone('Etc/GMT+4')))
                ->setTime(00, 00, 00);
            $endFormatted = (new DateTime('now', new DateTimeZone('Etc/GMT+4')))
                ->setTime(23, 59, 59)
                ->format(DateTimeInterface::RFC3339);
            /** @var PlayGameRecord $playGameRecord */
            $playGameRecord = PlayGameRecord::query()
                ->where('platform_id', '31')
                ->orderBy('platform_action_at', 'desc')
                ->first();
            $startFormatted = $startTime->format(DateTimeInterface::RFC3339);
            if (!empty($playGameRecord)) {
                $playGameRecordDate = new DateTime($playGameRecord->platform_action_at);
                if ($startTime->format('Y-m-d') === $playGameRecordDate->format('Y-m-d')) {
                    $startFormatted = $playGameRecord->platform_action_at;
                }
            }
            $data = $this->getGameHistories([
                'start_time' => $startFormatted,
                'end_time' => $endFormatted,
                'page' => $page,
                'lang' => 'en',
            ]);

            if (!empty($data['data'])) {
                $total = $data['data']['total'] ?? 0;
                if ($total > 0) {
                    $pageSize = 200;
                    if (!empty($data['data']['histories'])) {
                        foreach ($data['data']['histories'] as $item) {
                            /** @var Player $player */
                            $player = Player::withTrashed()->with('recommend_promoter')->where('uuid',
                                $item['player_name'])->first();
                            if (!empty($player)) {
                                $list[] = [
                                    'player_id' => $player->id,
                                    'parent_player_id' => $player->recommend_id ?? 0,
                                    'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                                    'player_uuid' => $player->uuid,
                                    'platform_id' => $this->platform->id,
                                    'game_code' => $item['game_code'],
                                    'department_id' => $player->department_id,
                                    'bet' => $item['valid_bet'],
                                    'win' => $item['win'],
                                    'diff' => $item['diff'],
                                    'order_no' => $item['order_id'],
                                    'original_data' => json_encode($item),
                                    'platform_action_at' => $item['result_time'],
                                ];
                            }
                        }
                    }
                    if ($total > $pageSize) {
                        $totalPages = ceil($total / $pageSize);
                        for ($page = 2; $page <= $totalPages; $page++) {
                            $nextData = $this->getGameHistories([
                                'start_time' => $startFormatted,
                                'end_time' => $endFormatted,
                                'page' => $page,
                                'lang' => 'en',
                            ]);
                            if (!empty($nextData['data']['histories'])) {
                                foreach ($nextData['data']['histories'] as $item) {
                                    /** @var Player $player */
                                    $player = Player::withTrashed()->with('recommend_promoter')->where('uuid',
                                        $item['player_name'])->first();
                                    if (!empty($player)) {
                                        $list[] = [
                                            'player_id' => $player->id,
                                            'parent_player_id' => $player->recommend_id ?? 0,
                                            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                                            'player_uuid' => $player->uuid,
                                            'platform_id' => $this->platform->id,
                                            'game_code' => $item['game_code'],
                                            'department_id' => $player->department_id,
                                            'bet' => $item['valid_bet'],
                                            'win' => $item['win'],
                                            'diff' => $item['diff'],
                                            'order_no' => $item['order_id'],
                                            'original_data' => json_encode($item),
                                            'platform_action_at' => $item['result_time'],
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            return [];
        }

        return $list;
    }

    /**
     * 取得區間內遊戲紀錄
     * @param array $data
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function getGameHistories(array $data = []): array
    {
        $params = [
            'account_id' => $this->appId,
            'datetime_from' => $data['start_time'] ?? '',
            'datetime_to' => $data['end_time'] ?? '',
            'page' => $data['page'] ?? 1,
            'page_count' => $data['size'] ?? 200,
            'game_type' => 'ALL',
            'lang' => $this->lang[$data['lang']],
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('getGameHistories'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException(self::ERROR_CODE_MAP[$res['status']['code']], 0);
        }

        return $res;
    }

    /**
     * 进入游戏
     * @param Game $game
     * @param string $lang
     * @return mixed|string
     * @throws GameException
     */
    public function gameLogin(Game $game, string $lang = 'zh-CN')
    {
        $this->checkPlayer($lang);
        $params = [
            'account_id' => $this->appId,
            'back_url' => '',
            'username' => $this->player->uuid,
            'lang' => $this->lang[$lang],
            'game_code' => $game->game_extend->code,
            'routing' => 'zh-cn',
            'device' => 'mobile',
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('gameLogin'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException(self::ERROR_CODE_MAP[$res['status']['code']], 0);
        }

        return $res['data']['game_url'] ?? '';
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $params = [
            'account_id' => $this->appId,
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('getGameList'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException(self::ERROR_CODE_MAP[$res['status']['code']], 0);
        }
        $insertData = [];
        $langList = [
            'zh-CN' => 'cn',
            'zh-TW' => 'tw',
            'en' => 'en',
        ];
        if (!empty($res['data'])) {
            foreach ($res['data'] as $item) {
                foreach ($item as $game) {
                    $insertData[] = [
                        'platform_id' => $this->platform->id,
                        'cate_id' => 7,
                        'name' => $game[$langList[$lang]] ?? '',
                        'code' => $game['game_code'],
                        'status' => $game['maintain'] ? 0 : 1,
                        'org_data' => json_encode($item),
                    ];
                }
            }
        }
        if (!empty($insertData)) {
            GameExtend::query()->upsert($insertData, ['platform_id', 'code']);
        }

        return true;
    }

    public function createPlayer()
    {
        // TODO: Implement createPlayer() method.
    }

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }

    /**
     * 单一钱包 - 验证签名
     * @param array $params
     * @return bool
     */
    public function verifySign(array $params): bool
    {
        if (!isset($params['check_code'])) {
            $this->error = self::ERROR_CODE_INVALID_CHECK_CODE;
            return false;
        }

        $checkParams = $params;
        unset($checkParams['check_code']);

        $expectedSign = $this->createSign($checkParams);

        if ($params['check_code'] !== $expectedSign) {
            $this->error = self::ERROR_CODE_INVALID_CHECK_CODE;
            return false;
        }

        // 验证账号ID
        if (!isset($params['account_id']) || $params['account_id'] !== $this->appId) {
            $this->error = self::ERROR_CODE_AGENT_NOT_EXIST;
            return false;
        }

        return true;
    }

    /**
     * 单一钱包 - 查询余额
     * @return float
     */
    public function balance(): float
    {
        try {
            // 返回玩家余额
            if (!$this->player) {
                // 单一钱包模式：player不存在视为参数错误（不使用4202游戏平台错误码）
                $this->error = self::ERROR_CODE_BAD_FORMAT_PARAMS;
                return 0;
            }

            // ✅ 使用 Redis 缓存查询余额
            return (float)\app\service\WalletService::getBalance($this->player->id);
        } catch (Exception $e) {
            Log::error('BTG balance error', ['error' => $e->getMessage()]);
            $this->error = self::ERROR_CODE_GET_BALANCE_FAILED;
            return 0;
        }
    }

    /**
     * 单一钱包 - 下注扣款
     * @param $data
     * @return array|float
     */
    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return self::ERROR_CODE_INSUFFICIENT_BALANCE;
    }


    /**
     * 下注
     * @param $data
     * @return mixed
     * @deprecated 已迁移到 RedisLuaScripts::atomicBet，此方法不再使用
     */
    public function bet($data): mixed
    {
        // 已迁移到 Controller 中使用 RedisLuaScripts::atomicBet
        throw new \RuntimeException('bet() 方法已废弃，请使用 RedisLuaScripts::atomicBet');
    }

    /**
     * 取消下注
     * @param $data
     * @return mixed
     * @deprecated 已迁移到 RedisLuaScripts::atomicCancel，此方法不再使用
     */
    public function cancelBet($data): mixed
    {
        // 已迁移到 Controller 中使用 RedisLuaScripts::atomicCancel
        throw new \RuntimeException('cancelBet() 方法已废弃，请使用 RedisLuaScripts::atomicCancel');
    }

    /**
     * 结算
     * @param $data
     * @return mixed
     * @deprecated 已迁移到 RedisLuaScripts::atomicSettle，此方法不再使用
     */
    public function betResulet($data): mixed
    {
        // 已迁移到 Controller 中使用 RedisLuaScripts::atomicSettle
        throw new \RuntimeException('betResulet() 方法已废弃，请使用 RedisLuaScripts::atomicSettle');
    }

    /**
     * 单一钱包 - 重新结算
     * @param $data
     * @return mixed
     * @deprecated 已迁移到 RedisLuaScripts::atomicSettle，此方法不再使用
     */
    public function reBetResulet($data): mixed
    {
        // 已迁移到 Controller 中使用 RedisLuaScripts::atomicSettle
        throw new \RuntimeException('reBetResulet() 方法已废弃，请使用 RedisLuaScripts::atomicSettle');
    }

    /**
     * 单一钱包 - 送礼
     * @param $data
     * @return mixed
     *@deprecated 平台不支持送礼功能
     */
    public function gift($data): mixed
    {
        // 平台不支持送礼功能
        throw new \RuntimeException('平台不支持 gift() 功能');
    }

    /**
     * 单一钱包 - 解密
     * @param $data
     * @return array|null
     */
    public function decrypt($data)
    {
        // BTG不需要解密,直接返回原数据
        return $data;
    }

    /**
     * Transfer - start (下注扣款)
     *
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @return array
     * @deprecated 此方法已废弃，不再被使用
     * @reason BTGGameController 直接调用 RedisLuaScripts::atomicBet()
     *
     */
    public function transferStart(array $params, array $transDetails): array
    {
        try {
            $amount = abs((float)$params['amount']); // amount 为负数，取绝对值
            $orderId = $transDetails['order_id'] ?? '';
            $roundId = $transDetails['round_id'] ?? '';

            Log::channel('btg_server')->info('BTG transferStart 开始处理', [
                'order_id' => $orderId,
                'round_id' => $roundId,
                'amount' => $amount,
                'player_id' => $this->player->id,
            ]);

            // 只拒绝负数金额，允许0（免费游戏）
            if ($amount < 0) {
                Log::channel('btg_server')->warning('BTG transferStart 金额无效（负数）', [
                    'amount' => $amount,
                    'original_amount' => $params['amount']
                ]);
                $this->error = self::ERROR_CODE_BAD_FORMAT_PARAMS;
                return ['balance' => \app\service\WalletService::getBalance($this->player->id)];
            }

            // amount=0 的情况（免费游戏）
            if ($amount == 0) {
                Log::channel('btg_server')->info('BTG transferStart 免费游戏（amount=0）', [
                    'order_id' => $orderId,
                    'player_id' => $this->player->id
                ]);

                // ✅ 使用 Lua 脚本处理免费游戏（金额为0，不扣款但记录）
                $result = \app\service\RedisLuaScripts::atomicBet(
                    $this->player->id,
                    'BTG',
                    [
                        'order_no' => $orderId,
                        'platform_id' => $this->platform->id,
                        'amount' => 0,
                        'game_code' => $params['game_code'] ?? '',
                        'game_type' => $params['game_type'] ?? '',
                        'transaction_type' => 'bet',
                        'original_data' => $params,
                    ]
                );

                if ($result['ok'] == 1) {
                    return ['balance' => (float)$result['balance']];
                }

                // 免费游戏理论上不会失败，但还是处理错误
                $this->error = $result['error'] == 'duplicate_order'
                    ? self::ERROR_CODE_DUPLICATE_TRAN_ID
                    : self::ERROR_CODE_SOMETHING_WRONG;

                return ['balance' => (float)($result['balance'] ?? 0)];
            }

            // amount>0 的情况（正常下注）
            // 检查设备是否爆机
            if ($this->checkAndHandleMachineCrash()) {
                return ['balance' => \app\service\WalletService::getBalance($this->player->id)];
            }

            // ✅ 使用 Redis Lua 原子脚本（检查余额 + 扣款 + 幂等性 + 保存记录 全部原子完成）
            $result = \app\service\RedisLuaScripts::atomicBet(
                $this->player->id,
                'BTG',
                [
                    'order_no' => $orderId,
                    'platform_id' => $this->platform->id,
                    'amount' => $amount,
                    'game_code' => $params['game_code'] ?? '',
                    'game_type' => $params['game_type'] ?? '',
                    'transaction_type' => 'bet',
                    'original_data' => $params,
                ]
            );

            // 处理 Lua 脚本返回结果
            if ($result['ok'] == 1) {
                // ✅ 成功：余额已扣，记录已保存到 Redis，已加入同步队列
                Log::channel('btg_server')->info('BTG transferStart 成功', [
                    'order_id' => $orderId,
                    'old_balance' => $result['old_balance'],
                    'new_balance' => $result['balance'],
                    'bet_amount' => $amount
                ]);

                return ['balance' => (float)$result['balance']];
            }

            // ❌ 失败：处理各种错误情况
            if ($result['error'] == 'insufficient_balance') {
                $this->error = self::ERROR_CODE_INSUFFICIENT_BALANCE;

                Log::channel('btg_server')->warning('BTG transferStart 余额不足', [
                    'order_id' => $orderId,
                    'bet_amount' => $amount,
                    'balance' => $result['balance']
                ]);
            } elseif ($result['error'] == 'duplicate_order') {
                $this->error = self::ERROR_CODE_DUPLICATE_TRAN_ID;

                Log::channel('btg_server')->info('BTG transferStart 重复订单', [
                    'order_id' => $orderId
                ]);
            } else {
                $this->error = self::ERROR_CODE_SOMETHING_WRONG;

                Log::channel('btg_server')->error('BTG transferStart Lua脚本错误', [
                    'order_id' => $orderId,
                    'error' => $result['error'] ?? 'unknown'
                ]);
            }

            return ['balance' => (float)($result['balance'] ?? 0)];

        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferStart 异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $params
            ]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => \app\service\WalletService::getBalance($this->player->id)];
        }
    }

    /**
     * Transfer - end (结算派彩)
     *
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @param array $betformDetails 注单详情
     * @return array
     *@deprecated 此方法已废弃，不再被使用
     * @reason BTGGameController 直接调用 RedisLuaScripts::atomicSettle()
     *
     */
    public function transferEnd(array $params, array $transDetails, array $betformDetails): array
    {
        try {
            $winAmount = (float)$params['amount']; // amount 为正数（派彩金额）
            $orderId = $transDetails['order_id'] ?? '';
            $roundId = $transDetails['round_id'] ?? '';

            Log::channel('btg_server')->info('BTG transferEnd 开始处理', [
                'order_id' => $orderId,
                'round_id' => $roundId,
                'win_amount' => $winAmount,
                'player_id' => $this->player->id
            ]);

            // ✅ 使用 Redis Lua 原子脚本（加钱 + 更新记录 + 幂等性 全部原子完成）
            $result = \app\service\RedisLuaScripts::atomicSettle(
                $this->player->id,
                'BTG',
                [
                    'order_no' => $orderId,
                    'platform_id' => $this->platform->id,
                    'amount' => $winAmount,
                    'diff' => $winAmount, // BTG 的 diff 在 Lua 脚本内计算（win - bet）
                    'game_code' => $params['game_code'] ?? '',
                    'transaction_type' => 'settle',
                    'original_data' => $params,
                ]
            );

            // 处理 Lua 脚本返回结果
            if ($result['ok'] == 1) {
                // ✅ 成功：余额已加，记录已更新到 Redis
                Log::channel('btg_server')->info('BTG transferEnd 成功', [
                    'order_id' => $orderId,
                    'old_balance' => $result['old_balance'],
                    'new_balance' => $result['balance'],
                    'win_amount' => $winAmount
                ]);

                // 彩金记录 - 过滤鱼机类型
                // 注意：这里暂时保留，但实际上在 Redis 阶段还没有 MySQL record->id
                // 后续可能需要移到 GameRecordSyncWorker 中处理
                $gameType = $params['game_type'] ?? '';
                if ($gameType !== 'fish' && $winAmount > 0) {
                    // 由于 Lua 脚本只保存到 Redis，MySQL 的 record 还未创建
                    // 彩金队列将在 GameRecordSyncWorker 同步时触发
                    // 这里只是保留逻辑兼容性，实际执行在 Worker 中
                }

                return ['balance' => (float)$result['balance']];
            }

            // ❌ 失败：处理各种错误情况
            if ($result['error'] == 'duplicate_settle') {
                $this->error = self::ERROR_CODE_TRANSACTION_SETTLED;

                Log::channel('btg_server')->info('BTG transferEnd 重复结算', [
                    'order_id' => $orderId
                ]);
            } else {
                $this->error = self::ERROR_CODE_SOMETHING_WRONG;

                Log::channel('btg_server')->error('BTG transferEnd Lua脚本错误', [
                    'order_id' => $orderId,
                    'error' => $result['error'] ?? 'unknown'
                ]);
            }

            return ['balance' => (float)($result['balance'] ?? \app\service\WalletService::getBalance($this->player->id))];

        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferEnd 异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $params,
                'order_id' => $orderId ?? null,
                'player_id' => $this->player->id ?? null
            ]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => \app\service\WalletService::getBalance($this->player->id)];
        }
    }

    /**
     * Transfer - refund (退款)
     *
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @return array
     * @deprecated 此方法已废弃，不再被使用
     * @reason BTGGameController 直接调用 RedisLuaScripts::atomicCancel()
     *
     */
    public function transferRefund(array $params, array $transDetails): array
    {
        try {
            $refundAmount = (float)$params['amount']; // amount 为正数（退款金额）
            $orderId = $transDetails['order_id'] ?? '';

            Log::channel('btg_server')->info('BTG transferRefund 开始处理', [
                'order_id' => $orderId,
                'refund_amount' => $refundAmount,
                'player_id' => $this->player->id
            ]);

            // ✅ 使用 Redis Lua 原子脚本（退款 + 更新记录 + 幂等性 全部原子完成）
            $result = \app\service\RedisLuaScripts::atomicCancel(
                $this->player->id,
                'BTG',
                [
                    'order_no' => $orderId,
                    'platform_id' => $this->platform->id,
                    'refund_amount' => $refundAmount,
                    'transaction_type' => 'refund',
                    'original_data' => $params,
                ]
            );

            // 处理 Lua 脚本返回结果
            if ($result['ok'] == 1) {
                // ✅ 成功：余额已退，记录已更新到 Redis
                Log::channel('btg_server')->info('BTG transferRefund 成功', [
                    'order_id' => $orderId,
                    'old_balance' => $result['old_balance'],
                    'new_balance' => $result['balance'],
                    'refund_amount' => $refundAmount
                ]);

                return ['balance' => (float)$result['balance']];
            }

            // ❌ 失败：处理各种错误情况
            if ($result['error'] == 'duplicate_cancel') {
                $this->error = self::ERROR_CODE_TRANSACTION_SETTLED;

                Log::channel('btg_server')->info('BTG transferRefund 重复退款', [
                    'order_id' => $orderId
                ]);
            } else {
                $this->error = self::ERROR_CODE_SOMETHING_WRONG;

                Log::channel('btg_server')->error('BTG transferRefund Lua脚本错误', [
                    'order_id' => $orderId,
                    'error' => $result['error'] ?? 'unknown'
                ]);
            }

            return ['balance' => (float)($result['balance'] ?? \app\service\WalletService::getBalance($this->player->id))];

        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferRefund 异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $params
            ]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => \app\service\WalletService::getBalance($this->player->id)];
        }
    }

    /**
     * Transfer - adjust (调整金额)
     *
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @param array $betformDetails 注单详情
     * @return array
     *@deprecated 此方法已废弃，不再被使用
     * @reason BTGGameController 直接调用 RedisLuaScripts (正数用atomicSettle，负数用atomicBet)
     *
     */
    public function transferAdjust(array $params, array $transDetails, array $betformDetails): array
    {
        try {
            $adjustAmount = (float)$params['amount']; // amount 可正可负
            $orderId = $transDetails['order_id'] ?? '';

            Log::channel('btg_server')->info('BTG transferAdjust 开始处理', [
                'order_id' => $orderId,
                'adjust_amount' => $adjustAmount,
                'player_id' => $this->player->id
            ]);

            // 如果是扣款操作，检查设备是否爆机
            if ($adjustAmount < 0 && $this->checkAndHandleMachineCrash()) {
                return ['balance' => \app\service\WalletService::getBalance($this->player->id)];
            }

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 如果调整金额为0，直接返回
            if ($adjustAmount == 0) {
                return ['balance' => (float)$machineWallet->money];
            }

            // 查找对应的游戏记录（加锁防止并发）
            $record = $this->findAndLockGameRecord($orderId);

            if (!$record) {
                $this->error = self::ERROR_CODE_TRANSACTION_NOT_EXIST;
                return ['balance' => $machineWallet->money];
            }

            // 调整余额
            $beforeBalance = $machineWallet->money;
            if ($adjustAmount > 0) {
                // 加款
                $machineWallet->money = bcadd($machineWallet->money, $adjustAmount, 2);
            } else {
                // 扣款
                $deductAmount = abs($adjustAmount);
                if ($machineWallet->money < $deductAmount) {
                    $this->error = self::ERROR_CODE_INSUFFICIENT_BALANCE;
                    return ['balance' => $machineWallet->money];
                }
                $machineWallet->money = bcsub($machineWallet->money, $deductAmount, 2);
            }
            $machineWallet->save();

            // 更新游戏记录
            $newWin = (float)($betformDetails['win'] ?? $record->win);
            $newDiff = (float)($betformDetails['diff'] ?? $record->diff);

            $record->win = $newWin;
            $record->diff = $newDiff;
            $record->save();

            Log::channel('btg_server')->info('BTG transferAdjust 成功', [
                'order_id' => $orderId,
                'old_balance' => $beforeBalance,
                'new_balance' => $machineWallet->money,
                'adjust_amount' => $adjustAmount
            ]);

            return ['balance' => (float)$machineWallet->money];

        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferAdjust 异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $params
            ]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => \app\service\WalletService::getBalance($this->player->id)];
        }
    }

    /**
     * Transfer - reward (额外奖金)
     *
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @param array $betformDetails 注单详情
     * @return array
     *@deprecated 此方法已废弃，不再被使用
     * @reason BTGGameController 直接调用 RedisLuaScripts::atomicSettle()
     *
     */
    public function transferReward(array $params, array $transDetails, array $betformDetails): array
    {
        try {
            $rewardAmount = (float)$params['amount']; // amount 为正数（奖励金额）
            $orderId = $transDetails['order_id'] ?? '';
            $roundId = $transDetails['round_id'] ?? '';

            if ($rewardAmount <= 0) {
                $this->error = self::ERROR_CODE_BAD_FORMAT_PARAMS;
                return ['balance' => \app\service\WalletService::getBalance($this->player->id)];
            }

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 检查订单是否已存在（使用 order_id，避免重复派发）
            $existingRecord = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if ($existingRecord) {
                // 订单已存在，返回当前余额（幂等性）
                Log::channel('btg_server')->info('BTG transferReward 订单已存在（幂等性）', [
                    'order_id' => $orderId,
                    'existing_record_id' => $existingRecord->id
                ]);
                $this->error = self::ERROR_CODE_TRANSACTION_SETTLED;
                return ['balance' => $machineWallet->money];
            }

            // 奖励加款
            $beforeBalance = $machineWallet->money;
            $machineWallet->money = bcadd($machineWallet->money, $rewardAmount, 2);
            $machineWallet->save();

            // 创建游戏记录（奖励记录）
            $insert = array_merge($this->buildGameRecordBaseData($orderId, $params), [
                'bet' => 0,
                'win' => $rewardAmount,
                'diff' => $rewardAmount,
                'original_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'action_data' => json_encode($betformDetails, JSON_UNESCAPED_UNICODE),
                'platform_action_at' => Carbon::now()->toDateTimeString(),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_SETTLED
            ]);

            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

            // 创建奖励交易记录
            $this->createDeliveryRecord(
                $machineWallet,
                $record,
                $rewardAmount,
                PlayerDeliveryRecord::TYPE_SETTLEMENT,
                'player_bet_reward',
                '額外獎金',
                $beforeBalance
            );

            return ['balance' => (float)$machineWallet->money];
        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferReward error', ['error' => $e->getMessage(), 'params' => $params]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => \app\service\WalletService::getBalance($this->player->id)];
        }
    }

    /**
     * 单一钱包 - 验证auth_code
     * @param array $params
     * @return bool
     */
    public function verifyAuthCode(array $params): bool
    {
        if (!isset($params['auth_code'])) {
            $this->error = self::ERROR_CODE_AUTHORIZATION_INVALID;
            return false;
        }

        // 移除 trans_details、betform_details 和 auth_code 参数
        $checkParams = $params;
        unset($checkParams['auth_code']);
        unset($checkParams['trans_details']);
        unset($checkParams['betform_details']);

        // 按字母顺序排序
        ksort($checkParams);

        // 拼接参数字符串：key=value&key=value 格式
        $paramsArray = [];
        foreach ($checkParams as $key => $value) {
            $paramsArray[] = $key . '=' . $value;
        }
        $paramsString = implode('&', $paramsArray);

        // 生成初始字符串：params_string + "&" + md5_key
        $signStr = $paramsString . '&' . $this->md5Key;

        // MD5加密生成auth_code
        $expectedAuthCode = md5($signStr);

        // 验证签名
        if (strtolower($params['auth_code']) !== strtolower($expectedAuthCode)) {
            Log::error('BTG auth_code验证失败', [
                'params' => $params,
                'expected' => $expectedAuthCode,
                'received' => $params['auth_code'],
                'params_string' => $paramsString,
                'sign_string' => $signStr
            ]);
            $this->error = self::ERROR_CODE_AUTHORIZATION_INVALID;
            return false;
        }

        return true;
    }
}
