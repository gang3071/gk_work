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
use app\wallet\controller\game\ATGGameController;
use app\wallet\controller\game\RsgGameController;
use Carbon\Carbon;
use Exception;
use support\Cache;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class ATGServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    public $method = 'POST';

    private $apiDomain;
    private $providerId;

    private $path = [
        'getToken' => '/token',
        'createPlayer' => '/register',
        'getBalance' => '/game-providers/{providerId}/balance',
        'depositAmount' => '/game-providers/{providerId}/balance',
        'withdrawAmount' => '/game-providers/{providerId}/balance',
        'lobbyLogin' => '/game-providers/{providerId}/lobby',
        'getGameHistories' => '/transaction',
        'gameLogin' => '/game-providers/{providerId}/play',
        'getGameKey' => '/game-providers/{providerId}/games/{gameCode}/key',
        'getGameList' => '/games',
    ];

    private $lang = [
        'zh-CN' => 'zh-cn',
        'zh-TW' => 'zh-tw',
        'jp' => 'jp',
        'en' => 'en',
    ];

    private array $config = [];


    public ?\Monolog\Logger $log = null;
    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.ATG');
        $this->config = $config;
        $this->apiDomain = $config['api_domain'];
        $this->providerId = $config['providerId'];
        $this->platform = GamePlatform::query()->where('code', 'ATG')->first();
        $this->player = $player;
        $this->log = Log::channel('rsg_server');
    }

    /**
     * 查詢玩家餘額
     * @return float
     * @throws GameException
     */
    public function getBalance(): float
    {
        $this->checkPlayer();
        $res = $this->doCurl($this->createUrl('getBalance'), [
            'username' => $this->player->uuid,
        ], 'get');

        return $res['data']['balance'] ?? 0;
    }

    /**
     * 检查玩家
     * @throws GameException
     */
    private function checkPlayer()
    {
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if (!empty($playerGamePlatform)) {
            return $this->lobbyLogin();
        }
        $this->createPlayer();
        $playerGamePlatform = new PlayerGamePlatform();
        $playerGamePlatform->player_id = $this->player->id;
        $playerGamePlatform->web_id = $this->getWebId();
        $playerGamePlatform->platform_id = $this->platform->id;
        $playerGamePlatform->player_name = $this->player->name;
        $playerGamePlatform->player_code = $this->player->uuid;
        $playerGamePlatform->save();

        return true;
    }

    /**
     * 进入游戏大厅
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function lobbyLogin(): string
    {
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if (empty($playerGamePlatform)) {
            $this->createPlayer();
            $playerGamePlatform = new PlayerGamePlatform();
            $playerGamePlatform->player_id = $this->player->id;
            $playerGamePlatform->platform_id = $this->platform->id;
            $playerGamePlatform->player_name = $this->player->name;
            $playerGamePlatform->player_code = $this->player->uuid;
            $playerGamePlatform->save();
        }
        $req = $this->doCurl($this->createUrl('lobbyLogin'), [
            'username' => $this->player->uuid,
            'headless' => 0,
            'dark' => 1,
        ], 'get');

        return $req['data']['url'] ?? '';
    }

    /**
     * @return array
     * @throws GameException
     */
    public function createPlayer(): array
    {
        return $this->doCurl($this->createUrl('createPlayer'), [
            'username' => $this->player->uuid,
        ]);
    }

    /**
     * 组装请求
     * @param string $url
     * @param array $params
     * @param string $mode
     * @return array|mixed
     * @throws GameException
     */
    public function doCurl(string $url, array $params = [], string $mode = 'post')
    {
        $config = config('game_platform.ATG');
        $cacheKey = 'game_platform_token_atg';
        $token = Cache::get($cacheKey);
        if (empty($token)) {
            $tokenResponse = Http::timeout(7)
                ->withHeaders([
                    'X-Operator' => $config['operator'],
                    'X-key' => $config['key'],
                ])
                ->get($config['api_domain'] . '/token');
            if (!$tokenResponse->ok()) {
                $this->log->info('doCurl', ['params' => $params, 'response' => $tokenResponse]);
                throw new GameException(trans('system_busy', [], 'message'));
            }
            $data = $tokenResponse->json();
            if (empty($data['data']['token'])) {
                $this->log->info('doCurl', ['params' => $params, 'response' => $data]);
                throw new GameException(trans('system_busy', [], 'message'));
            }
            $token = $data['data']['token'];
            Cache::set($cacheKey, $token, 4 * 60);
        }
        $request = Http::timeout(7)
            ->withHeaders([
                'X-Token' => $token,
            ]);
        if ($mode == 'post') {
            $response = $request->asJson()->post($url, $params);
        } else {
            $response = $request->get($url . '?' . http_build_query($params));
        }
        if (!$response->ok()) {
            $res = $response->json();
            if ($res['status'] == '400' && $res['message'] == 'user exists') {
                return [];
            }
            $this->log->info('doCurl', ['params' => $params, 'response' => $response->json()]);
            throw new GameException(empty($res['message']) ? trans('system_busy', [], 'message') : $res['message']);
        }

        return $response->json();
    }

    /**
     * 生成请求url
     * @param $method
     * @return string
     */
    public function createUrl($method): string
    {
        return $this->apiDomain . str_replace('{providerId}', $this->providerId, $this->path[$method]);
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
        $this->checkPlayer();
        $res = $this->doCurl($this->createUrl('getBalance'), [
            'username' => $this->player->uuid,
            'balance' => $data['amount'],
            'action' => 'IN',
            'transferId' => $data['order_no'] ?? '',
        ]);
        if ($res['status'] != 'success') {
            throw new GameException(trans('system_busy', [], 'message'));
        }
        Cache::set('depositAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('withdrawAmount_' . $this->player->id);

        return $data['order_no'];
    }

    /**
     * 游戏重播
     * @param array $data
     * @return mixed
     */
    public function replay(array $data = [])
    {
        $original = json_decode($data['original_data'], true);
        if (isset($original['replayurl'])) {
            return $original['replayurl'];
        }
        return '';
    }

    /**
     * 提領玩家額度
     * @param array $data
     * @return array
     * @throws GameException
     */
    public function withdrawAmount(array $data = []): array
    {
        $this->checkPlayer();
        $res = $this->doCurl($this->createUrl('getBalance'), [
            'username' => $this->player->uuid,
            'balance' => $data['amount'],
            'action' => 'OUT',
            'transferId' => $data['order_no'] ?? '',
        ]);
        if ($res['status'] != 'success') {
            throw new GameException(trans('system_busy', [], 'message'));
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $data['order_no'],
            'amount' => $data['amount'],
        ];
    }

    /**
     * 取得區間內遊戲紀錄
     * @return array
     * @throws Exception
     */
    public function handleGameHistories(): array
    {
        $list = [];
        try {
            $data = $this->getGameHistories();
            if (!empty($data)) {
                foreach ($data as $item) {
                    /** @var Player $player */
                    $player = Player::withTrashed()->with('recommend_promoter')->where('uuid',
                        $item['membername'])->first();
                    if (!empty($player)) {
                        if ($item['status'] == 'close') {
                            $list[] = [
                                'player_id' => $player->id,
                                'parent_player_id' => $player->recommend_id ?? 0,
                                'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                                'player_uuid' => $player->uuid,
                                'platform_id' => $this->platform->id,
                                'game_code' => $item['gamecode'],
                                'department_id' => $player->department_id,
                                'bet' => $item['validbet'],
                                'win' => $item['validbet'] + ($item['winloseamount']),
                                'diff' => $item['winloseamount'],
                                'order_no' => $item['bettingId'],
                                'original_data' => json_encode($item),
                                'platform_action_at' => date('Y-m-d H:i:s', $item['settledate']),
                            ];
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
     * @return array
     * @throws GameException
     */
    public function getGameHistories(): array
    {
        $config = config('game_platform.ATG');
        $params = [
            'Operator' => $config['operator'],
            'Key' => $config['key'],
            'SDate' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
            'EDate' => date('Y-m-d H:i:s'),
        ];

        return $this->doCurl($this->createUrl('getGameHistories'), $params);
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
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if (empty($playerGamePlatform)) {
            $this->createPlayer();
            $playerGamePlatform = new PlayerGamePlatform();
            $playerGamePlatform->player_id = $this->player->id;
            $playerGamePlatform->platform_id = $this->platform->id;
            $playerGamePlatform->player_name = $this->player->name;
            $playerGamePlatform->player_code = $this->player->uuid;
            $playerGamePlatform->save();
        }

        $params = [
            'key' => $this->getGameKey($game->game_extend->code),
            'type' => 'mobile',
            'locale' => $this->lang[$lang],
        ];

        $req = $this->doCurl($this->createUrl('gameLogin'), $params, 'get');
        if (empty($req['data']['url'])) {
            throw new GameException(trans('system_busy', [], 'message'));
        }
        $url = $req['data']['url'] . '&uniwebview=1&view_mode=portrait';
//        if ($game->display_mode == 3 || $game->display_mode == 1) {
//            $url .= '&view_mode=portrait';
//        }

//        echo $url;

        return $url;
    }

    /**
     * 取得遊戲金鑰
     * @param $gameCode
     * @return mixed|string
     * @throws GameException
     */
    public function getGameKey($gameCode)
    {
        $this->checkPlayer();
        $params = [
            'username' => $this->player->uuid
        ];

        $url = str_replace('{gameCode}', $gameCode, $this->createUrl('getGameKey'));

        $req = $this->doCurl($url, $params, 'get');

        return $req['data']['key'] ?? '';
    }

    /**
     * 获取平台游戏列表
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $this->checkPlayer();
        $params = [
            'provider' => 4,
            'locale' => $this->lang[$lang],
        ];
        $insertData = [];
        $res = $this->doCurl($this->createUrl('getGameList'), $params, 'get');
        if (!empty($res['data']['games'])) {
            foreach ($res['data']['games'] as $item) {
                $insertData[] = [
                    'platform_id' => $this->platform->id,
                    'cate_id' => 7,
                    'name' => $item['name'],
                    'code' => $item['code'],
                    'logo' => $item['url'],
                    'is_new' => $item['isNew'],
                    'is_hot' => $item['isHot'],
                    'status' => $item['actived'] ? 1 : 0,
                    'org_data' => json_encode($item),
                ];
            }
        }
        if (!empty($insertData)) {
            GameExtend::query()->upsert($insertData, ['platform_id', 'code']);
        }

        return true;
    }

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }

    public function userLogout()
    {
        // TODO: Implement getPlayer() method.
    }

    /**
     * 下注
     * @return mixed
     */
    public function bet($data)
    {
        if (PlayGameRecord::query()->where('order_no', $data['betId'])->exists()) {
            return $this->error = ATGGameController::API_CODE_INSUFFICIENT_BALANCE;
        }

        $player = $this->player;
        $bet = $data['amount'];

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        if ($machineWallet->money < $bet) {
            return $this->error = ATGGameController::API_CODE_INSUFFICIENT_BALANCE;
        }

        $beforeBalance = $machineWallet->money;
        //下注记录
        $insert = [
            'player_id' => $this->player->id,
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $data['gameCode'],
            'department_id' => $player->department_id,
            'bet' => $bet,
            'win' => 0,
            'diff' => 0,
            'order_no' => $data['betId'],
            'original_data' => json_encode($data),
            'order_time' => Carbon::now()->toDateTimeString(),
            'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
        ];
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->create($insert);
        $balance = $this->createBetRecord($machineWallet, $player, $record, $bet);
        return ['balanceOld' => $beforeBalance, 'balance' => $balance];
    }


    /**
     * 打鱼机退款
     * @param $data
     * @return mixed
     */
    public function refund($data): mixed
    {
        //TODO 后期如果接入打鱼或者百家乐可能需要优化
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['betId'])->first();
        if ($record) {
            return $this->error = RsgGameController::API_CODE_DUPLICATE_TRANSACTION;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        //退款金额
        $amount = $data['amount'];
        $beforeGameAmount = $machineWallet->money;
        $machineWallet->money = bcadd($machineWallet->money, $amount, 2);
        $machineWallet->save();

        $player = $this->player;

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
        $playerDeliveryRecord->remark = $target->remark ?? '';
        $playerDeliveryRecord->user_id = 0;
        $playerDeliveryRecord->user_name = '';
        $playerDeliveryRecord->save();

        return ['balanceOld' => $beforeGameAmount, 'balance' => $machineWallet->money];
    }


    /**
     * 取消单
     * @return mixed
     */
    public function cancelBet($data)
    {
        // TODO: Implement cancelBet() method.
    }

    /**
     * 结算
     * @return mixed
     */
    public function betResulet($data)
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['betId'])->first();

        if (!$record) {
            return $this->error = ATGGameController::API_CODE_ORDER_NOT_EXIST;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            return $this->error = ATGGameController::API_CODE_ORDER_SETTLED;
        }
        //处理用户中奖金额
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        $beforeGameAmount = $machineWallet->money;
        //判断输赢
        if ($data['amount'] > 0) {
            //赢
            $money = $data['amount'];
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
            $playerDeliveryRecord->remark = $target->remark ?? '';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();
        }

        $record->platform_action_at = Carbon::now()->toDateTimeString();
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->action_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $record->win = $data['amount'];
        $record->diff = bcsub($data['amount'], $record->bet, 2);
        $record->save();

        //彩金记录
        Client::send('game-lottery', ['player_id' => $this->player->id, 'bet' => $record->bet, 'play_game_record_id' => $record->id]);

        return ['balanceOld' => $beforeGameAmount, 'balance' => $machineWallet->money];
    }

    /**
     * 重新结算
     * @return mixed
     */
    public function reBetResulet($data)
    {
        // TODO: Implement reBetResulet() method.
    }

    /**
     * 送礼
     * @return mixed
     */
    public function gift($data)
    {
        // TODO: Implement gift() method.
    }

    /**
     * 解密
     * @return mixed
     */
    public function decrypt($data)
    {
        //token验证
        $token = $data['token'];
        $key = $this->config['key'];
        $iv = $this->config['operator'];

        if ($token !== md5($iv . $data['timestamp'] . $data['data'])) {
            return $this->error = ATGGameController::API_CODE_DECRYPT_ERROR;
        }

        $key2 = strlen($key) > 16 ? substr($key, 0, 16) : str_pad($key, 16, '0');

        $iv2 = strlen($iv) > 16 ? substr($iv, 0, 16) : str_pad($iv, 16, '0');

        // 將 base64 字符串轉換為二進制數據
        $crypted = base64_decode($data['data']);

        // 使用 openssl_decrypt 進行解密
        $decode = openssl_decrypt($crypted, 'AES-128-CBC', $key2, OPENSSL_RAW_DATA, $iv2);
        $result = json_decode($decode, true);

        if (empty($result)) {
            return $this->error = ATGGameController::API_CODE_DECRYPT_ERROR;
        }

        $player = Player::query()->where('uuid', $result['username'])->first();
        if (!$player) {
            return $this->error = ATGGameController::API_CODE_PLAYER_NOT_EXIST;
        }

        $this->player = $player;

        return $result;
    }

}
