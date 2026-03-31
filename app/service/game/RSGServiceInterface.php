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
use app\model\AdminUserLimitGroup;
use app\model\PlatformLimitGroupConfig;
use app\wallet\controller\game\RsgGameController;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class RSGServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    public $method = 'POST';
    public $successCode = '0';
    public $failCode = [
        '1001' => '執行失敗',
        '1002' => '系統維護中',
        '2001' => '無效的參數',
        '2002' => '解密失敗',
        '3005' => '餘額不足',
        '3006' => '找不到交易結果',
        '3008' => '此玩家帳戶不存在',
        '3010' => '此玩家帳戶已存在',
        '3011' => '系統商權限不足',
        '3012' => '遊戲權限不足',
        '3014' => '重複的 TransactionID',
        '3015' => '時間不在允許的範圍內',
        '3016' => '拒絕提點，玩家正在遊戲中',
        '3018' => '此幣別不被允許',
    ];

    private $apiDomain;
    private $systemCode;
    private $lang = [
        'zh-CN' => 'zh-TW',
        'zh-TW' => 'zh-TW',
        'en' => 'en-US',
        'th' => 'th-TH',
        'vi' => 'vi-VN',
        'jp' => 'ja-JP',
        'kr_ko' => 'ko-KR',
        'my' => 'en-MY',
        'id' => 'id-ID',
    ];
    private $path = [
        'createPlayer' => '/SingleWallet/Player/CreatePlayer',
        'userLogout' => '/SingleWallet/Player/Kickout',
        'getGameHistories' => '/SingleWallet/History/GetGameDetail',
        'lobbyLogin' => '/SingleWallet/Player/GetLobbyURLToken',
        'getGameList' => '/SingleWallet/Game/GameList',
        'gameLogin' => '/SingleWallet/Player/GetURLToken',
        'replay' => '/SingleWallet/Player/GetSlotGameRecordURLToken',
    ];

    private $currency = [
        'TWD' => 'NT',
        'CNY' => 'NT',
        'JPY' => 'JPY',
        'USD' => 'USA',
    ];

    public ?\Monolog\Logger $log = null;

    private array $config;

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.RSG');
        $this->config = $config;
        $this->apiDomain = $config['api_domain'];
        $this->systemCode = $config['SystemCode'];
        $this->platform = GamePlatform::query()->where('code', 'RSG')->first();
        $this->player = $player;
        $this->log = Log::channel('rsg_server');
    }

    /**
     * @return PlayerGamePlatform
     * @throws GameException
     */
    private function checkPlayer(): PlayerGamePlatform
    {
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if (empty($playerGamePlatform)) {
            $result = $this->createPlayer();
            $playerGamePlatform = new PlayerGamePlatform();
            $playerGamePlatform->player_id = $this->player->id;
            $playerGamePlatform->platform_id = $this->platform->id;
            $playerGamePlatform->player_name = $this->player->name;
            $playerGamePlatform->player_code = $this->player->uuid;
            $playerGamePlatform->web_id = $this->getWebId();
            $playerGamePlatform->player_password = $result['password'] ?? '';
            $playerGamePlatform->save();
        }

        return $playerGamePlatform;
    }

    /**
     * 注册玩家
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function createPlayer(): array
    {
        $params = [
            'SystemCode' => $this->systemCode,
            'WebId' => $this->getWebId(),
            'UserId' => $this->player->uuid,
            'Currency' => $this->currency[$this->player->currency],
        ];
        $res = $this->doCurl($this->createUrl('createPlayer'), $params);
        if ($res['ErrorCode'] != $this->successCode) {
            $this->log->info('createPlayer', ['params' => $params, 'response' => $res]);
            if ($res['ErrorCode'] == '3010') {
                return $params;
            }
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $params;
    }

    /**
     * 组装请求
     * @param string $url
     * @param array $params
     * @return array|mixed
     * @throws Exception
     */
    public function doCurl(string $url, array $params = [])
    {
        $config = config('game_platform.RSG');
        $encryptData = openssl_encrypt(json_encode($params), 'DES-CBC', $config['DesKey'], OPENSSL_RAW_DATA,
            $config['DesIV']);
        $reqBase64 = base64_encode($encryptData);
        $timestamp = time();
        $response = Http::timeout(7)
            ->withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'X-API-ClientID' => $config['app_id'],
                'X-API-Signature' => md5($config['app_id'] . $config['app_secret'] . $timestamp . $reqBase64),
                'X-API-Timestamp' => $timestamp
            ])
            ->withBody('Msg=' . $reqBase64, 'application/json')
            ->post($url);

        if (!$response->ok()) {
            Log::channel('rsg_server')->error($url, ['config' => $config, 'params' => $params, 'response' => $response->body()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }
        $data = openssl_decrypt(base64_decode($response->body()), 'DES-CBC', $config['DesKey'], OPENSSL_RAW_DATA,
            $config['DesIV']);
        if (empty($data)) {
            throw new GameException(trans('system_busy', [], 'message'));
        }

        return json_decode($data, true);
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
     * 进入游戏大厅
     * @param array $data
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function lobbyLogin(array $data = []): string
    {
        $this->checkPlayer();
        $params = [
            'SystemCode' => $this->systemCode,
            'WebId' => $this->getWebId(),
            'UserId' => $this->player->uuid,
            'UserName' => $this->player->uuid,
            'Currency' => $this->currency[$this->player->currency],
            'Language' => $this->lang[$data['lang']],
        ];
        $res = $this->doCurl($this->createUrl('lobbyLogin'), $params);
        $this->log->info('lobbyLogin', ['params' => $params, $res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $res['Data']['URL'] ?? '';
    }

    /**
     * 取得區間內遊戲紀錄
     * @return array
     * @throws Exception
     */
    public function handleGameHistories(): array
    {
        $slotData = $this->getGameHistories(1);
        $fishData = $this->getGameHistories(2);
        $list = [];
        if (!empty($slotData)) {
            $list = array_merge($list, $this->processPlayerData($slotData));
        }
        if (!empty($fishData)) {
            $list = array_merge($list, $this->processPlayerData($fishData));
        }

        return $list;
    }

    /**
     * 取得區間內遊戲紀錄
     * @param int $gameType
     * @return array
     * @throws GameException
     */
    public function getGameHistories(int $gameType): array
    {
        $params = [
            'SystemCode' => $this->systemCode,
            'WebId' => $this->getWebId(),
            'GameType' => $gameType,
            'TimeStart' => date('Y-m-d H:i', strtotime('-7 minutes')),
            'TimeEnd' => date('Y-m-d H:i', strtotime('-3 minutes')),
        ];
        $res = $this->doCurl($this->createUrl('getGameHistories'), $params);
        $this->log->info('getGameHistories', [$res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $res['Data']['GameDetail'] ?? [];
    }

    /**
     * @param $data
     * @return array
     */
    public function processPlayerData($data): array
    {
        $list = [];
        foreach ($data as $item) {
            /** @var Player $player */
            $player = Player::withTrashed()->with('recommend_promoter')->where('uuid', $item['UserId'])->first();
            if (!empty($player)) {
                $list[] = [
                    'player_id' => $player->id,
                    'parent_player_id' => $player->recommend_id ?? 0,
                    'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                    'player_uuid' => $player->uuid,
                    'platform_id' => $this->platform->id,
                    'game_code' => $item['GameId'],
                    'department_id' => $player->department_id,
                    'bet' => $item['BetAmt'],
                    'win' => $item['WinAmt'],
                    'diff' => ($item['WinAmt']) - ($item['BetAmt']),
                    'reward' => $item['JackpotContribution'],
                    'order_no' => $item['SequenNumber'],
                    'original_data' => json_encode($item),
                    'platform_action_at' => $item['PlayTime'],
                ];
            }
        }

        return $list;
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $params = [
            'SystemCode' => $this->systemCode,
        ];
        $res = $this->doCurl($this->createUrl('getGameList'), $params);
        $this->log->info('getGameList', [$res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }
        $insertData = [];
        $langKey = Str::replace('-', '_', $this->lang[$lang]);
        if (!empty($res['Data']['GameList'])) {
            foreach ($res['Data']['GameList'] as $item) {
                $insertData[] = [
                    'game_id' => $item['GameId'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => 7,
                    'name' => $item['GameName'][$langKey],
                    'code' => $item['GameId'],
                    'logo' => $item['GamePicUrl'],
                    'status' => $item['GameStatus'] == 2 ? 0 : 1,
                    'org_data' => json_encode($item),
                ];
            }
        }
        if (!empty($insertData)) {
            GameExtend::query()->upsert($insertData, ['platform_id', 'code']);
        }

        return true;
    }

    /**
     * 获取玩家的限红配置
     * @return array|null 返回限红配置数组，包含MinBetAmount和MaxBetAmount，如果没有配置则返回null
     */
    private function getLimitRedConfig(): ?array
    {
        $limitGroupConfig = null;

        // 如果玩家有店家ID，优先查询店家绑定的限红组配置
        if (!empty($this->player->store_admin_id)) {
            // 查询店家绑定的RSG平台限红组配置
            $adminUserLimitGroup = AdminUserLimitGroup::query()
                ->where('admin_user_id', $this->player->store_admin_id)
                ->where('platform_id', $this->platform->id)
                ->where('status', 1)
                ->first();

            // 如果店家有绑定限红组，获取该限红组的平台配置
            if ($adminUserLimitGroup) {
                $limitGroupConfig = PlatformLimitGroupConfig::query()
                    ->where('limit_group_id', $adminUserLimitGroup->limit_group_id)
                    ->where('platform_id', $this->platform->id)
                    ->where('status', 1)
                    ->first();
            }
        }

        // 如果没有找到店家限红组配置，则使用平台的默认限红组配置
        if (!$limitGroupConfig && !empty($this->platform->default_limit_group_id)) {
            // 从游戏平台表的 default_limit_group_id 字段获取默认限红组配置
            $limitGroupConfig = PlatformLimitGroupConfig::query()
                ->where('limit_group_id', $this->platform->default_limit_group_id)
                ->where('platform_id', $this->platform->id)
                ->where('status', 1)
                ->first();

            // 记录使用了默认限红组
            if ($limitGroupConfig) {
                $this->log->info('RSG使用平台默认限红组', [
                    'player_id' => $this->player->id,
                    'store_admin_id' => $this->player->store_admin_id ?? 'null',
                    'default_limit_group_id' => $this->platform->default_limit_group_id,
                ]);
            }
        }

        // 如果没有配置数据，返回null
        if (!$limitGroupConfig || empty($limitGroupConfig->config_data)) {
            return null;
        }

        $configData = $limitGroupConfig->config_data;

        // 构建限红参数（RSG支持MinBetAmount和MaxBetAmount）
        $limitConfig = [];

        if (isset($configData['min_bet_amount']) && $configData['min_bet_amount'] > 0) {
            $limitConfig['MinBetAmount'] = $configData['min_bet_amount'];
        }

        if (isset($configData['max_bet_amount']) && $configData['max_bet_amount'] > 0) {
            $limitConfig['MaxBetAmount'] = $configData['max_bet_amount'];
        }

        return !empty($limitConfig) ? $limitConfig : null;
    }

    /**
     * 进入游戏
     * @param Game $game
     * @param string $lang
     * @return mixed|string
     * @throws GameException
     */
    public function gameLogin(Game $game, string $lang = 'zh-CN'): mixed
    {
        $this->checkPlayer();
        $params = [
            'SystemCode' => $this->systemCode,
            'WebId' => $this->getWebId(),
            'UserId' => $this->player->uuid,
            'UserName' => $this->player->uuid,
            'GameId' => (int)$game->game_extend->code,
            'Currency' => $this->currency[$this->player->currency],
            'Language' => $this->lang[$lang],
            'ExitAction' => '',
        ];

        // 获取并应用限红配置
        $limitConfig = $this->getLimitRedConfig();
        if ($limitConfig) {
            $params = array_merge($params, $limitConfig);
            $this->log->info('RSG应用限红配置', [
                'player_id' => $this->player->id,
                'store_admin_id' => $this->player->store_admin_id,
                'limit_config' => $limitConfig
            ]);
        }

        $res = $this->doCurl($this->createUrl('gameLogin'), $params);
        $this->log->info('gameLogin', [$res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $res['Data']['URL'] ?? '';
    }

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }

    public function userLogout()
    {
        // TODO: Implement userLogout() method.
    }

    /**
     * 播放地址
     * @param array $data
     * @return mixed|string
     * @throws GameException
     */
    public function replay(array $data = [])
    {
        $origin = json_decode($data['original_data'], true);
        $params = [
            'SystemCode' => $this->systemCode,
            'WebId' => $origin['WebId'],
            'UserId' => $origin['UserId'],
            'Currency' => $origin['Currency'],
            'GameId' => $origin['GameId'],
            'SequenNumber' => $origin['SequenNumber'],
            'Language' => 'zh-TW',
        ];
        $this->log->info('replay',$params);
        $res = $this->doCurl($this->createUrl('replay'), $params);
        $this->log->info('replay', [$res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $res['Data']['URL'] ?? '';
    }

    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return RsgGameController::API_CODE_INSUFFICIENT_BALANCE;
    }

    public function bet($data)
    {
        if (PlayGameRecord::query()->where('order_no', $data['SequenNumber'])->exists()) {
            return $this->error = RsgGameController::API_CODE_DUPLICATE_ORDER;
        }

        $player = $this->player;
        $bet = $data['Amount'];

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return $this->error;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        if ($machineWallet->money < $bet) {
            return $this->error = RsgGameController::API_CODE_INSUFFICIENT_BALANCE;
        }

        //下注记录
        $insert = [
            'player_id' => $this->player->id,
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $data['GameId'],
            'department_id' => $player->department_id,
            'bet' => $bet,
            'win' => 0,
            'diff' => 0,
            'order_no' => $data['SequenNumber'],
            'original_data' => json_encode($data),
            'order_time' => Carbon::now()->toDateTimeString(),
            'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
        ];


        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->create($insert);

        return $this->createBetRecord($machineWallet, $player, $record, $bet);

    }

    /**
     * 取消下注
     * @param $data
     * @return float|mixed|string
     */
    public function cancelBet($data): mixed
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['SequenNumber'])->first();

        if (!$record) {
            return $this->error = RsgGameController::API_CODE_ORDER_NOT_EXIST;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
            return $this->error = RsgGameController::API_CODE_ORDER_CANCELLED;
        }

        //返还用户金钱  修改注单状态
        $bet = $data['BetAmount'];

        return $this->createCancelBetRecord($record, $data, $bet);
    }

    public function betResulet($data)
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['SequenNumber'])->first();

        if (!$record) {
            return $this->error = RsgGameController::API_CODE_ORDER_NOT_EXIST;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            return $this->error = RsgGameController::API_CODE_ORDER_SETTLED;
        }
        //处理用户中奖金额
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        //判断输赢
        if ($data['Amount'] > 0) {
            //赢
            $money = $data['Amount'];
            $beforeGameAmount = $machineWallet->money;
            //处理用户金额记录
            // 更新玩家统计
            $machineWallet->money = bcadd($machineWallet->money, $money, 2);
            $machineWallet->save();

            $player = $this->player;
            //todo 语言文件后续处理
            //用户交易记录  现在单一钱包没有转账的说法 暂不记录转账记录
            $playerDeliveryRecord = new PlayerDeliveryRecord;
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

        $record->platform_action_at = $data['PlayTime'];
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->action_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $record->win = $data['Amount'];
        $record->diff = bcsub($data['Amount'], $data['BetAmount'], 2);
        $record->save();

        //判断游戏是否已经完结
        if ($data['IsGameFlowEnd'] && $data['BelongSequenNumber'] != $data['SequenNumber']) {
            $record = PlayGameRecord::query()->where('order_no', $data['BelongSequenNumber'])->first();
        }

        if ($record->bet > 0) {
            //彩金记录
            Client::send('game-lottery', ['player_id' => $this->player->id, 'bet' => $record->bet, 'play_game_record_id' => $record->id]);
        }

        return $this->player->machine_wallet->money;
    }

    public function reBetResulet($data)
    {
        // TODO: Implement reBetResulet() method.
    }

    public function gift($data)
    {
        // TODO: Implement gift() method.
    }


    public function jackpotResult($data)
    {
        //单独使用（没有成对的 Bet，直接创建记录并结算）

        // 检查是否重复
        if (PlayGameRecord::query()->where('order_no', $data['SequenNumber'])->exists()) {
            return $this->error = RsgGameController::API_CODE_DUPLICATE_ORDER;
        }

        $player = $this->player;
        $money = $data['Amount'];

        // 锁定玩家钱包
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        $beforeGameAmount = $machineWallet->money;

        // 创建游戏记录（JackpotResult 没有下注，bet=0，直接是已结算状态）
        $insert = [
            'player_id' => $this->player->id,
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $data['GameId'],
            'department_id' => $player->department_id,
            'bet' => 0, // JackpotResult 没有下注金额
            'win' => $money,
            'diff' => $money, // diff = win - bet = money - 0
            'order_no' => $data['SequenNumber'],
            'original_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'order_time' => Carbon::now()->toDateTimeString(),
            'platform_action_at' => $data['PlayTime'],
            'action_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_SETTLED // 直接已结算
        ];

        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->create($insert);

        // 处理中奖金额（只赢不输）
        if ($money > 0) {
            // 更新玩家余额
            $machineWallet->money = bcadd($machineWallet->money, $money, 2);
            $machineWallet->save();

            // 创建交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $record->getTable();
            $playerDeliveryRecord->target_id = $record->id;
            $playerDeliveryRecord->platform_id = $this->platform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
            $playerDeliveryRecord->source = 'jackpot_result';
            $playerDeliveryRecord->amount = $money;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $record->order_no ?? '';
            $playerDeliveryRecord->remark = '遊戲彩池結算';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();
        }



        return $this->player->machine_wallet->money;
    }

    /**
     * 打鱼机预扣金额
     * @param $data
     * @return mixed
     */
    public function prepay($data): mixed
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['SessionId'])->first();
        if ($record) {
            return $this->error = RsgGameController::API_CODE_DUPLICATE_TRANSACTION;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        $player = $this->player;
        //需要扣除金额
        $money = $data['Amount'];
        //已预扣金额
        $beforeGameAmount = $machineWallet->money;

        if ($money > $machineWallet->money) {
            //余额不足
            $this->error = RsgGameController::API_CODE_INSUFFICIENT_BALANCE;
            //扣除现有所有金额进入游戏
            $machineWallet->money = 0;
            $machineWallet->save();
            $amount = $beforeGameAmount;
        } else {
            $machineWallet->money = bcsub($machineWallet->money, $money, 2);
            $machineWallet->save();
            $amount = $money;
        }

        //记录交易后余额
        $data['AfterBalance'] = $machineWallet->money;
        //预扣款记录
        $insert = [
            'player_id' => $this->player->id,
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $data['GameId'],
            'department_id' => $player->department_id,
            'bet' => $money,
            'win' => 0,
            'diff' => 0,
            'order_no' => $data['SessionId'],
            'original_data' => json_encode($data),
            'order_time' => Carbon::now()->toDateTimeString(),
            'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED,
            'type' => PlayGameRecord::TYPE_PREPAY
        ];


        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->create($insert);

        //用户交易记录  现在单一钱包没有转账的说法 暂不记录转账记录
        $playerDeliveryRecord = new PlayerDeliveryRecord;
        $playerDeliveryRecord->player_id = $player->id;
        $playerDeliveryRecord->department_id = $player->department_id;
        $playerDeliveryRecord->target = '';
        $playerDeliveryRecord->target_id = 0;
        $playerDeliveryRecord->platform_id = $this->platform->id;
        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_PREPAY;
        $playerDeliveryRecord->source = 'player_prepay';
        $playerDeliveryRecord->amount = $money;
        $playerDeliveryRecord->amount_before = $beforeGameAmount;
        $playerDeliveryRecord->amount_after = $machineWallet->money;
        $playerDeliveryRecord->tradeno = $record->order_no ?? '';
        $playerDeliveryRecord->remark = '遊戲預付';
        $playerDeliveryRecord->user_id = 0;
        $playerDeliveryRecord->user_name = '';
        $playerDeliveryRecord->save();

        return ['Balance' => $machineWallet->money, 'Amount' => $amount];
    }


    /**
     * 打鱼机退款
     * @param $data
     * @return mixed
     */
    public function refund($data): mixed
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['SessionId'])->first();
//        if($record){
//            return $this->error = RsgGameController::API_CODE_DUPLICATE_TRANSACTION;
//        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        //退款金额
        $amount = $data['Amount'];
        $beforeGameAmount = $machineWallet->money;
        $machineWallet->money = bcadd($machineWallet->money, $amount, 2);
        $machineWallet->save();

        $player = $this->player;
        $data['AfterBalance'] = $machineWallet->money;

//        //查询之前的同一交易记录
//        /** @var PlayGameRecord $beforeRecord */
//        $beforeRecord = PlayGameRecord::query()->where("original_data->SessionId", $data['SessionId'])->first();
        $win = $amount <= $record->bet ? 0 : bcsub($amount, $record->bet, 2);

        $record->win = $amount;
        $record->action_data = json_encode($data);
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->type = PlayGameRecord::TYPE_REFUND;
        $record->diff = bcsub($amount, $record->bet, 2);
        $record->platform_action_at = Carbon::now()->toDateTimeString();
        $record->save();
        //退款记录
//        $insert = [
//            'player_id' => $this->player->id,
//            'parent_player_id' => $player->recommend_id ?? 0,
//            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
//            'player_uuid' => $player->uuid,
//            'platform_id' => $this->platform->id,
//            'game_code' => $data['GameId'],
//            'department_id' => $player->department_id,
//            'bet' => $record->bet,
//            'win' => $win,
//            'diff' => bcsub($amount, $record->bet, 2),
//            'order_no' => $data['TransactionId'],
//            'original_data' => json_encode($data),
//            'order_time' => Carbon::now()->toDateTimeString(),
//            'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_SETTLED,
//            'type' => PlayGameRecord::TYPE_REFUND
//        ];
//
//        /** @var PlayGameRecord $record */
//        $record = PlayGameRecord::query()->create($insert);

        //用户交易记录  现在单一钱包没有转账的说法 暂不记录转账记录
        $playerDeliveryRecord = new PlayerDeliveryRecord;
        $playerDeliveryRecord->player_id = $player->id;
        $playerDeliveryRecord->department_id = $player->department_id;
        $playerDeliveryRecord->target = '';
        $playerDeliveryRecord->target_id = 0;
        $playerDeliveryRecord->platform_id = $this->platform->id;
        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_REFUND;
        $playerDeliveryRecord->source = 'player_refund';
        $playerDeliveryRecord->amount = $amount;
        $playerDeliveryRecord->amount_before = $beforeGameAmount;
        $playerDeliveryRecord->amount_after = $machineWallet->money;
        $playerDeliveryRecord->tradeno = $record->order_no ?? '';
        $playerDeliveryRecord->remark = '遊戲退款';
        $playerDeliveryRecord->user_id = 0;
        $playerDeliveryRecord->user_name = '';
        $playerDeliveryRecord->save();

        return ['Balance' => $machineWallet->money, 'Amount' => $amount];
    }

    /**
     * 打鱼机退款
     * @param $data
     * @return mixed
     */
    public function checkTransaction($data): mixed
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['TransactionId'])->first();
        if ($record) {
            return $this->error = RsgGameController::API_CODE_TRANSACTION_NOT_FOUND;
        }

        $originData = json_decode($data['OriginData'], true);

        return [
            'TransactionId' => $data['TransactionId'],
            'TransactionTime' => Carbon::now()->toDateTimeString(),
            'WebId' => $originData['WebId'],
            'UserId' => $originData['UserId'],
            'GameId' => $originData['GameId'],
            'Currency' => $originData['Currency'],
            'Action' => $record->type == PlayGameRecord::TYPE_PREPAY ? 1 : 2,
            'Amount' => $originData['Amount'],
            'AfterBalance' => $data['AfterBalance'] ?? 0,
        ];
    }


    public function decrypt($data)
    {
        $config = config('game_platform.RSG');
        $data = openssl_decrypt(base64_decode($data), 'DES-CBC', $config['DesKey'], OPENSSL_RAW_DATA, $config['DesIV']);
        $data = json_decode($data, true);

        if (empty($data)) {
            return $this->error = RsgGameController::API_CODE_DECRYPT_ERROR;
        }

        if (empty($data['SystemCode']) || $data['SystemCode'] != $config['SystemCode']) {
            return $this->error = RsgGameController::API_CODE_INVALID_PARAM;
        }

        $player = Player::query()->where('uuid', $data['UserId'])->first();
        if (!$player) {
            return $this->error = RsgGameController::API_CODE_PLAYER_NOT_EXIST;
        }

        $this->player = $player;

        return $data;
    }

    /**
     * 加密
     * @param $data
     * @return string
     */
    public function encrypt($data)
    {
        $encrypt_data = openssl_encrypt($data, 'DES-CBC', $this->config['DesKey'], OPENSSL_RAW_DATA, $this->config['DesIV']);
        return base64_encode($encrypt_data);
    }

}
