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
use app\wallet\controller\game\BTGGameController;
use Carbon\Carbon;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Client;

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
                $this->error = self::ERROR_CODE_PLAYER_NOT_EXIST;
                return 0;
            }

            return (float)$this->player->machine_wallet->money;
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

    public function bet($data): array|float
    {
        try {
            $params = $data;

            // 验证必要参数
            if (!isset($params['username']) || !isset($params['external_order_id']) || !isset($params['deposit_amount'])) {
                $this->error = self::ERROR_CODE_PARAM_FORMAT_ERROR;
                return $this->player->machine_wallet->money ?? 0;
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['username'])->first();
            if (!$player) {
                $this->error = self::ERROR_CODE_PLAYER_NOT_EXIST;
                return 0;
            }
            $this->player = $player;

            // 检查设备是否爆机
            if ($this->checkAndHandleMachineCrash()) {
                return $player->machine_wallet->money;
            }

            $bet = (float)$params['deposit_amount'];

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

            // 检查余额
            if ($machineWallet->money < $bet) {
                $this->error = self::ERROR_CODE_WITHDRAW_FAILED;
                return $machineWallet->money;
            }

            // 检查订单是否已存在
            if (PlayGameRecord::query()->where('order_no', $params['external_order_id'])->where('platform_id', $this->platform->id)->exists()) {
                $this->error = self::ERROR_CODE_DUPLICATE_ORDER;
                return $machineWallet->money;
            }

            // 创建游戏记录
            $insert = [
                'player_id' => $player->id,
                'parent_player_id' => $player->recommend_id ?? 0,
                'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $player->uuid,
                'platform_id' => $this->platform->id,
                'game_code' => $params['game_code'] ?? '',
                'department_id' => $player->department_id,
                'bet' => $bet,
                'win' => 0,
                'diff' => 0,
                'order_no' => $params['external_order_id'],
                'original_data' => json_encode($params),
                'order_time' => Carbon::now()->toDateTimeString(),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
            ];
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

            // 使用父类方法扣款并创建交易记录
            $afterBalance = $this->createBetRecord($machineWallet, $player, $record, $bet);

            return [
                'balance' => (float)$afterBalance,
                'order_id' => (string)$record->id,
            ];
        } catch (Exception $e) {
            Log::error('BTG bet error', ['error' => $e->getMessage()]);
            $this->error = self::ERROR_CODE_DEPOSIT_FAILED;
            return $this->player->machine_wallet->money ?? 0;
        }
    }

    /**
     * 单一钱包 - 结算加款
     * @param $data
     * @return array|float
     */
    public function betResulet($data): array|float
    {
        try {
            $params = $data;

            // 验证必要参数
            if (!isset($params['username']) || !isset($params['external_order_id']) || !isset($params['withdraw_amount'])) {
                $this->error = self::ERROR_CODE_PARAM_FORMAT_ERROR;
                return $this->player->machine_wallet->money ?? 0;
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['username'])->first();
            if (!$player) {
                $this->error = self::ERROR_CODE_PLAYER_NOT_EXIST;
                return 0;
            }
            $this->player = $player;

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

            // 查找下注记录
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()
                ->where('order_no', $params['external_order_id'])
                ->where('platform_id', $this->platform->id)
                ->first();

            if (!$record) {
                $this->error = self::ERROR_CODE_TRANSACTION_NOT_FOUND;
                return $machineWallet->money;
            }

            // 检查是否已结算
            if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                $this->error = self::ERROR_CODE_DUPLICATE_ORDER;
                return $machineWallet->money;
            }

            $money = (float)$params['withdraw_amount'];

            // 有金额则为赢
            if ($money > 0) {
                $beforeGameAmount = $machineWallet->money;
                // 更新玩家余额
                $machineWallet->money = bcadd($machineWallet->money, $money, 2);
                $machineWallet->save();

                // 创建交易记录
                $playerDeliveryRecord = new PlayerDeliveryRecord();
                $playerDeliveryRecord->player_id = $player->id;
                $playerDeliveryRecord->department_id = $player->department_id;
                $playerDeliveryRecord->target = $record->getTable();
                $playerDeliveryRecord->target_id = $record->id;
                $playerDeliveryRecord->platform_id = $this->platform->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
                $playerDeliveryRecord->source = 'player_bet_settlement';
                $playerDeliveryRecord->amount = $money;
                $playerDeliveryRecord->amount_before = $beforeGameAmount;
                $playerDeliveryRecord->amount_after = $machineWallet->money;
                $playerDeliveryRecord->tradeno = $record->order_no ?? '';
                $playerDeliveryRecord->remark = '遊戲結算';
                $playerDeliveryRecord->user_id = 0;
                $playerDeliveryRecord->user_name = '';
                $playerDeliveryRecord->save();
            }

            // 更新游戏记录
            $record->platform_action_at = Carbon::now()->toDateTimeString();
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $record->action_data = json_encode($params, JSON_UNESCAPED_UNICODE);
            $record->win = $money;
            $record->diff = $money - $record->bet;
            $record->save();

            // 彩金记录 - 过滤鱼机类型
            $originalData = json_decode($record->original_data, true);
            $gameType = $originalData['game_type'] ?? $params['game_type'] ?? '';
            if ($gameType !== 'fish') {
                Client::send('game-lottery', [
                    'player_id' => $player->id,
                    'bet' => $record->bet,
                    'play_game_record_id' => $record->id
                ]);
            } else {
                Log::channel('btg_server')->info('BTG betResulet 鱼机游戏跳过彩金记录', [
                    'order_id' => $record->order_no,
                    'game_type' => $gameType
                ]);
            }

            return [
                'balance' => (float)$machineWallet->money,
                'order_id' => (string)$record->id,
            ];
        } catch (Exception $e) {
            Log::error('BTG betResulet error', ['error' => $e->getMessage()]);
            $this->error = self::ERROR_CODE_WITHDRAW_FAILED;
            return $this->player->machine_wallet->money ?? 0;
        }
    }

    /**
     * 单一钱包 - 取消下注
     * @param $data
     * @return array|float
     */
    public function cancelBet($data): array|float
    {
        try {
            $params = $data;

            // 验证必要参数
            if (!isset($params['username']) || !isset($params['external_order_id'])) {
                $this->error = self::ERROR_CODE_PARAM_FORMAT_ERROR;
                return $this->player->machine_wallet->money ?? 0;
            }

            // 查询玩家
            $player = Player::query()->where('uuid', $params['username'])->first();
            if (!$player) {
                $this->error = self::ERROR_CODE_PLAYER_NOT_EXIST;
                return 0;
            }
            $this->player = $player;

            // 查找下注记录
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()
                ->where('order_no', $params['external_order_id'])
                ->where('platform_id', $this->platform->id)
                ->first();

            if (!$record) {
                $this->error = self::ERROR_CODE_TRANSACTION_NOT_FOUND;
                return $player->machine_wallet->money;
            }

            // 检查是否已取消
            if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
                $this->error = self::ERROR_CODE_DUPLICATE_ORDER;
                return $player->machine_wallet->money;
            }

            // 使用父类方法处理取消下注
            $bet = (float)$record->bet;
            $afterBalance = $this->createCancelBetRecord($record, $params, $bet);

            return [
                'balance' => (float)$afterBalance,
                'order_id' => (string)$record->id,
            ];
        } catch (Exception $e) {
            Log::error('BTG cancelBet error', ['error' => $e->getMessage()]);
            $this->error = self::ERROR_CODE_GENERAL_ERROR;
            return $this->player->machine_wallet->money ?? 0;
        }
    }

    /**
     * 单一钱包 - 重新结算
     * @param $data
     * @return array
     */
    public function reBetResulet($data): array
    {
        // BTG不需要重新结算,返回空数组
        $this->error = self::ERROR_CODE_GAME_MAINTENANCE;
        return [];
    }

    /**
     * 单一钱包 - 送礼
     * @param $data
     * @return array
     */
    public function gift($data): array
    {
        // BTG不支持送礼功能
        $this->error = self::ERROR_CODE_GAME_MAINTENANCE;
        return [];
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
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @return array
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
                'balance' => $this->player->machine_wallet->money
            ]);

            if ($amount <= 0) {
                Log::channel('btg_server')->warning('BTG transferStart 金额无效', [
                    'amount' => $amount,
                    'original_amount' => $params['amount']
                ]);
                $this->error = self::ERROR_CODE_BAD_FORMAT_PARAMS;
                return ['balance' => $this->player->machine_wallet->money ?? 0];
            }

            // 检查设备是否爆机
            if ($this->checkAndHandleMachineCrash()) {
                return ['balance' => $this->player->machine_wallet->money ?? 0];
            }

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 检查余额
            if ($machineWallet->money < $amount) {
                $this->error = self::ERROR_CODE_INSUFFICIENT_BALANCE;
                return ['balance' => $machineWallet->money];
            }

            // 检查订单是否已存在（使用 order_id）
            $existingRecord = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if ($existingRecord) {
                // 订单已存在，返回当前余额（幂等性）
                $this->error = self::ERROR_CODE_DUPLICATE_TRAN_ID;
                return ['balance' => $machineWallet->money];
            }

            // 创建游戏记录
            $insert = [
                'player_id' => $this->player->id,
                'parent_player_id' => $this->player->recommend_id ?? 0,
                'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $this->player->uuid,
                'platform_id' => $this->platform->id,
                'game_code' => $params['game_code'] ?? '',
                'department_id' => $this->player->department_id,
                'bet' => $amount,
                'win' => 0,
                'diff' => -$amount,
                'order_no' => $orderId,
                'original_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'order_time' => Carbon::now()->toDateTimeString(),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
            ];

            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

            // 扣款并创建交易记录
            $afterBalance = $this->createBetRecord($machineWallet, $this->player, $record, $amount);

            return ['balance' => (float)$afterBalance];
        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferStart error', ['error' => $e->getMessage(), 'params' => $params]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => $this->player->machine_wallet->money ?? 0];
        }
    }

    /**
     * Transfer - end (结算派彩)
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @param array $betformDetails 注单详情
     * @return array
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

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 查找对应的下注记录
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

            // 如果找不到下注记录，根据betform_details自动创建
            if (!$record) {
                Log::channel('btg_server')->warning('BTG transferEnd 找不到下注记录，尝试自动创建', [
                    'order_id' => $orderId,
                    'platform_id' => $this->platform->id,
                    'player_id' => $this->player->id,
                    'betform_details' => $betformDetails
                ]);

                // 从 betform_details 获取下注金额
                $betAmount = (float)($betformDetails['bet'] ?? 0);

                // 如果有下注金额，需要先扣款
                if ($betAmount > 0) {
                    // 检查余额
                    if ($machineWallet->money < $betAmount) {
                        Log::channel('btg_server')->error('BTG transferEnd 自动创建下注记录失败：余额不足', [
                            'order_id' => $orderId,
                            'bet_amount' => $betAmount,
                            'balance' => $machineWallet->money
                        ]);
                        $this->error = self::ERROR_CODE_INSUFFICIENT_BALANCE;
                        return ['balance' => $machineWallet->money];
                    }

                    // 创建下注记录
                    $insert = [
                        'player_id' => $this->player->id,
                        'parent_player_id' => $this->player->recommend_id ?? 0,
                        'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                        'player_uuid' => $this->player->uuid,
                        'platform_id' => $this->platform->id,
                        'game_code' => $params['game_code'] ?? '',
                        'department_id' => $this->player->department_id,
                        'bet' => $betAmount,
                        'win' => 0,
                        'diff' => -$betAmount,
                        'order_no' => $orderId,
                        'original_data' => json_encode(['auto_created' => true, 'from' => 'end', 'roundId' => $roundId], JSON_UNESCAPED_UNICODE),
                        'order_time' => Carbon::now()->toDateTimeString(),
                        'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
                    ];

                    $record = PlayGameRecord::query()->create($insert);

                    // 扣款
                    $this->createBetRecord($machineWallet, $this->player, $record, $betAmount);

                    // 重新获取钱包（因为扣款后余额变了）
                    $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

                    Log::channel('btg_server')->info('BTG transferEnd 自动创建下注记录成功', [
                        'record_id' => $record->id,
                        'bet_amount' => $betAmount,
                        'balance_after_bet' => $machineWallet->money
                    ]);
                } else {
                    // 没有下注金额（免费游戏或奖励），直接创建记录
                    $insert = [
                        'player_id' => $this->player->id,
                        'parent_player_id' => $this->player->recommend_id ?? 0,
                        'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                        'player_uuid' => $this->player->uuid,
                        'platform_id' => $this->platform->id,
                        'game_code' => $params['game_code'] ?? '',
                        'department_id' => $this->player->department_id,
                        'bet' => 0,
                        'win' => 0,
                        'diff' => 0,
                        'order_no' => $orderId,
                        'original_data' => json_encode(['auto_created' => true, 'from' => 'end', 'type' => 'free_game', 'roundId' => $roundId], JSON_UNESCAPED_UNICODE),
                        'order_time' => Carbon::now()->toDateTimeString(),
                        'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
                    ];

                    $record = PlayGameRecord::query()->create($insert);

                    Log::channel('btg_server')->info('BTG transferEnd 创建免费游戏记录', [
                        'record_id' => $record->id,
                        'order_id' => $orderId
                    ]);
                }
            } else {
                Log::channel('btg_server')->info('BTG transferEnd 找到下注记录', [
                    'record_id' => $record->id,
                    'order_no' => $record->order_no,
                    'settlement_status' => $record->settlement_status,
                    'bet' => $record->bet
                ]);
            }

            // 检查是否已结算
            if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                // 已结算，返回当前余额（幂等性）
                $this->error = self::ERROR_CODE_TRANSACTION_SETTLED;
                return ['balance' => $machineWallet->money];
            }

            // 派彩加款
            if ($winAmount > 0) {
                $beforeBalance = $machineWallet->money;
                $machineWallet->money = bcadd($machineWallet->money, $winAmount, 2);
                $machineWallet->save();

                // 创建派彩交易记录
                $playerDeliveryRecord = new PlayerDeliveryRecord();
                $playerDeliveryRecord->player_id = $this->player->id;
                $playerDeliveryRecord->department_id = $this->player->department_id;
                $playerDeliveryRecord->target = $record->getTable();
                $playerDeliveryRecord->target_id = $record->id;
                $playerDeliveryRecord->platform_id = $this->platform->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
                $playerDeliveryRecord->source = 'player_bet_settlement';
                $playerDeliveryRecord->amount = $winAmount;
                $playerDeliveryRecord->amount_before = $beforeBalance;
                $playerDeliveryRecord->amount_after = $machineWallet->money;
                $playerDeliveryRecord->tradeno = $orderId;
                $playerDeliveryRecord->remark = '遊戲結算';
                $playerDeliveryRecord->user_id = 0;
                $playerDeliveryRecord->user_name = '';
                $playerDeliveryRecord->save();
            }

            // 更新游戏记录
            $record->platform_action_at = Carbon::now()->toDateTimeString();
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $record->action_data = json_encode($betformDetails, JSON_UNESCAPED_UNICODE);
            $record->win = $winAmount;
            $record->diff = $winAmount - $record->bet;

            // 有效投注保存在 action_data 中
            if (!empty($betformDetails)) {
                Log::channel('btg_server')->info('BTG transferEnd 有效投注', [
                    'order_id' => $record->order_no,
                    'valid_bet' => $betformDetails['valid_bet'] ?? $record->bet
                ]);
            }

            $record->save();

            // 彩金记录 - 过滤鱼机类型
            $gameType = $params['game_type'] ?? '';
            if ($gameType !== 'fish') {
                Client::send('game-lottery', [
                    'player_id' => $this->player->id,
                    'bet' => $record->bet,
                    'play_game_record_id' => $record->id
                ]);
            } else {
                Log::channel('btg_server')->info('BTG transferEnd 鱼机游戏跳过彩金记录', [
                    'order_id' => $record->order_no,
                    'game_type' => $gameType
                ]);
            }

            return ['balance' => (float)$machineWallet->money];
        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferEnd error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $params,
                'order_id' => $orderId ?? null,
                'player_id' => $this->player->id ?? null
            ]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => $this->player->machine_wallet->money ?? 0];
        }
    }

    /**
     * Transfer - refund (退款)
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @return array
     */
    public function transferRefund(array $params, array $transDetails): array
    {
        try {
            $refundAmount = (float)$params['amount']; // amount 为正数（退款金额）
            $orderId = $transDetails['order_id'] ?? '';

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 查找对应的下注记录
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if (!$record) {
                $this->error = self::ERROR_CODE_TRANSACTION_NOT_EXIST;
                return ['balance' => $machineWallet->money];
            }

            // 检查是否已取消
            if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
                // 已退款，返回当前余额（幂等性）
                $this->error = self::ERROR_CODE_TRANSACTION_SETTLED;
                return ['balance' => $machineWallet->money];
            }

            // 退款加款
            $beforeBalance = $machineWallet->money;
            $machineWallet->money = bcadd($machineWallet->money, $refundAmount, 2);
            $machineWallet->save();

            // 创建退款交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $this->player->id;
            $playerDeliveryRecord->department_id = $this->player->department_id;
            $playerDeliveryRecord->target = $record->getTable();
            $playerDeliveryRecord->target_id = $record->id;
            $playerDeliveryRecord->platform_id = $this->platform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_CANCEL_BET;
            $playerDeliveryRecord->source = 'player_bet_refund';
            $playerDeliveryRecord->amount = $refundAmount;
            $playerDeliveryRecord->amount_before = $beforeBalance;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $orderId;
            $playerDeliveryRecord->remark = '下注退款';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            // 更新游戏记录状态
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_CANCELLED;
            $record->platform_action_at = Carbon::now()->toDateTimeString();
            $record->action_data = json_encode($params, JSON_UNESCAPED_UNICODE);
            $record->save();

            return ['balance' => (float)$machineWallet->money];
        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferRefund error', ['error' => $e->getMessage(), 'params' => $params]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => $this->player->machine_wallet->money ?? 0];
        }
    }

    /**
     * Transfer - adjust (调整金额)
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @param array $betformDetails 注单详情
     * @return array
     */
    public function transferAdjust(array $params, array $transDetails, array $betformDetails): array
    {
        try {
            $adjustAmount = (float)$params['amount']; // amount 可正可负
            $orderId = $transDetails['order_id'] ?? '';

            // 如果是扣款操作，检查设备是否爆机
            if ($adjustAmount < 0 && $this->checkAndHandleMachineCrash()) {
                return ['balance' => $this->player->machine_wallet->money ?? 0];
            }

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 如果调整金额为0，直接返回
            if ($adjustAmount == 0) {
                return ['balance' => (float)$machineWallet->money];
            }

            // 查找对应的游戏记录
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

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

            // 创建调整交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $this->player->id;
            $playerDeliveryRecord->department_id = $this->player->department_id;
            $playerDeliveryRecord->target = $record->getTable();
            $playerDeliveryRecord->target_id = $record->id;
            $playerDeliveryRecord->platform_id = $this->platform->id;
            $playerDeliveryRecord->type = $adjustAmount > 0 ? PlayerDeliveryRecord::TYPE_SETTLEMENT : PlayerDeliveryRecord::TYPE_BET;
            $playerDeliveryRecord->source = 'player_bet_adjust';
            $playerDeliveryRecord->amount = abs($adjustAmount);
            $playerDeliveryRecord->amount_before = $beforeBalance;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $orderId;
            $playerDeliveryRecord->remark = '金額調整';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            // 更新游戏记录（使用新的betform_details）
            if (!empty($betformDetails)) {
                $record->win = (float)($betformDetails['win'] ?? $record->win);
                $record->diff = (float)($betformDetails['diff'] ?? $record->diff);
                $record->action_data = json_encode($betformDetails, JSON_UNESCAPED_UNICODE);
                $record->platform_action_at = Carbon::now()->toDateTimeString();

                // 有效投注保存在 action_data 中
                Log::channel('btg_server')->info('BTG transferAdjust 更新记录', [
                    'order_id' => $record->order_no,
                    'valid_bet' => $betformDetails['valid_bet'] ?? null,
                    'win' => $record->win,
                    'diff' => $record->diff
                ]);

                $record->save();
            }

            return ['balance' => (float)$machineWallet->money];
        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferAdjust error', ['error' => $e->getMessage(), 'params' => $params]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => $this->player->machine_wallet->money ?? 0];
        }
    }

    /**
     * Transfer - reward (额外奖金)
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @param array $betformDetails 注单详情
     * @return array
     */
    public function transferReward(array $params, array $transDetails, array $betformDetails): array
    {
        try {
            $rewardAmount = (float)$params['amount']; // amount 为正数（奖励金额）
            $orderId = $transDetails['order_id'] ?? '';
            $roundId = $transDetails['round_id'] ?? '';

            if ($rewardAmount <= 0) {
                $this->error = self::ERROR_CODE_BAD_FORMAT_PARAMS;
                return ['balance' => $this->player->machine_wallet->money ?? 0];
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
                $this->error = self::ERROR_CODE_DUPLICATE_TRAN_ID;
                return ['balance' => $machineWallet->money];
            }

            // 奖励加款
            $beforeBalance = $machineWallet->money;
            $machineWallet->money = bcadd($machineWallet->money, $rewardAmount, 2);
            $machineWallet->save();

            // 创建游戏记录（奖励记录）
            $insert = [
                'player_id' => $this->player->id,
                'parent_player_id' => $this->player->recommend_id ?? 0,
                'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $this->player->uuid,
                'platform_id' => $this->platform->id,
                'game_code' => $params['game_code'] ?? '',
                'department_id' => $this->player->department_id,
                'bet' => 0,
                'win' => $rewardAmount,
                'diff' => $rewardAmount,
                'order_no' => $orderId,
                'original_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'action_data' => json_encode($betformDetails, JSON_UNESCAPED_UNICODE),
                'order_time' => Carbon::now()->toDateTimeString(),
                'platform_action_at' => Carbon::now()->toDateTimeString(),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_SETTLED
            ];

            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

            // 创建奖励交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $this->player->id;
            $playerDeliveryRecord->department_id = $this->player->department_id;
            $playerDeliveryRecord->target = $record->getTable();
            $playerDeliveryRecord->target_id = $record->id;
            $playerDeliveryRecord->platform_id = $this->platform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
            $playerDeliveryRecord->source = 'player_bet_reward';
            $playerDeliveryRecord->amount = $rewardAmount;
            $playerDeliveryRecord->amount_before = $beforeBalance;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $orderId;
            $playerDeliveryRecord->remark = '額外獎金';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            return ['balance' => (float)$machineWallet->money];
        } catch (Exception $e) {
            Log::channel('btg_server')->error('BTG transferReward error', ['error' => $e->getMessage(), 'params' => $params]);
            $this->error = self::ERROR_CODE_SOMETHING_WRONG;
            return ['balance' => $this->player->machine_wallet->money ?? 0];
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
