<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\wallet\controller\game\O8GameController;
use app\wallet\controller\game\SAGameController;
use Carbon\Carbon;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class O8ServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    public string $method = 'POST';
    public string $successCode = '0';
    private mixed $apiDomain;

    public $failCode = [
        '108' => '用户名长度或者格式错误',
        '113' => '用户名已存在',
        '114' => '币种不存在',
        '116' => '用户名不存在',
        '133' => '建立帐户失败',
    ];
    private array $lang = [
        'zh-CN' => 'zh-CN',
        'zh-TW' => 'zh-TW',
        'en' => 'en-US',
        'th' => 'th-TH',
        'vi' => 'vi-VN',
        'jp' => 'ja-JP',
        'kr_ko' => 'ko-KR',
        'km_KH' => 'km_KH',
    ];

    private array $currency = [
        'TWD' => 'TWD',
        'CNY' => 'TWD',
        'JPY' => 'JPY',
        'USD' => 'USD',
    ];

    private array $config;

    public $log;

    public const BET_TYPE_FISH = 255;  //打鱼机
    public const BET_TYPE_TIGER = 1; //老虎机

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null, $code = '08')
    {
        $this->config = config('game_platform.O8');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', $code)->first();
        $this->player = $player;
        $this->log = Log::channel('o8_server');
    }

    /**
     * 组装请求
     * @param string $url
     * @param array $params
     * @param string $method
     * @return array|mixed
     * @throws GameException
     */
    public function doCurl(string $url, array $params = [], string $method = 'post')
    {
        $token = $this->getToken();

        if ($method == 'get') {
            $response = Http::timeout(7)
                ->withToken($token)
                ->asJson()
                ->get($this->config['api_domain'] . $url . '?' . http_build_query($params));
        } else {
            $response = Http::timeout(7)
                ->withToken($token)
                ->asJson()
                ->post($this->config['api_domain'] . $url, $params);
        }

        if (!$response->ok()) {
            $this->log->error($url, ['params' => $params, 'response' => $response->body()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }

        $res = json_decode($response->body(), true);


        if (empty($res)) {
            throw new Exception(trans('system_busy', [], 'message'));
        }
        return $res;
    }

    /**
     * 注册玩家
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function createPlayer()
    {
//        if (Cache::has('O8_SERVICE_ACCOUNT_TOKEN_' . $this->player->uuid)) {
//            return Cache::get('O8_SERVICE_ACCOUNT_TOKEN_' . $this->player->uuid);
//        }

        $params = [
            'ipaddress' => request()->getRemoteIp(),
            'username' => $this->player->uuid,
            'userid' => $this->player->uuid,
            'lang' => 'zh-TW',
            'cur' => $this->currency[$this->player->currency],
            'betlimitid' => 1,
            'platformtype' => 'Mobile',
        ];
        $res = $this->doCurl('/api/player/authorize', $params);

        $token = $res['authtoken'];

        Cache::set('O8_SERVICE_ACCOUNT_TOKEN_' . $this->player->uuid, $token, 86400);

        return $token;
    }

    /**
     * 获取请求token
     * @return mixed
     * @throws GameException
     * @throws Exception
     */
    public function getToken(): mixed
    {
        if (Cache::has('O8_SERVICE_ACCESS_TOKEN')) {
            return Cache::get('O8_SERVICE_ACCESS_TOKEN');
        }

        $params = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'grant_type' => 'client_credentials',
            'scope' => 'playerapi',
        ];

        $response = Http::timeout(7)
            ->asForm()
            ->post($this->config['api_domain'] . '/api/oauth/token', $params);

        if (!$response->ok()) {
            throw new GameException(trans('system_busy', [], 'message'));
        }

        $res = json_decode($response->body(), true);


        if (empty($res)) {
            throw new Exception(trans('system_busy', [], 'message'));
        }

        $accessToken = $res['access_token'];

        Cache::set('O8_SERVICE_ACCESS_TOKEN', $accessToken, $res['expires_in']);

        return $accessToken;
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
            'ipaddress' => request()->getRemoteIp(),
            'gameprovidercode' => 'EEAI',
            'gamecode' => 'Lobby',
            'authtoken' => $this->createPlayer(),
        ];
        $res = $this->doCurl('/api/game/url', $params);
        $this->log->info('lobbyLogin', [$res]);

        return $res['url'];
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $params = [
            'lang' => 'zh-TW',
        ];

        $res = $this->doCurl('/api/games', $params, 'get');
//        $this->log->info('gameLogin', [$res]);

        $insertData = [];
        if (!empty($res['games'])) {
            $code = $this->platform->code;

            foreach ($res['games'] as $item) {
//                if (!in_array($item['providercode'], ['STM', 'HS'])) {
//                    continue;
//                }

                if ($item['providercode'] != $code) {
                    continue;
                }

                if ($item['type'] == 0) {
                    $cate = GameType::CATE_SLO;
                } else {
                    $cate = GameType::CATE_FISH;
                }

                $insertData[] = [
                    'game_id' => $item['externalid'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => $cate,
                    'name' => $item['name'],
                    'code' => $item['code'],
                    'table_name' => $item['providercode'],
                    'logo' => '',
                    'status' => 1,
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
            'ipaddress' => request()->getRemoteIp(),
            'gameprovidercode' => $game->game_extend->table_name,
            'gamecode' => $game->game_extend->code,
            'authtoken' => $this->createPlayer(),
        ];
        $res = $this->doCurl('/api/game/url', $params);
        $this->log->info('lobbyLogin', [$res]);

        return $res['url'];
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

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }

    public function userLogout()
    {
        // TODO: Implement userLogout() method.
    }

    public function replay(array $data = [])
    {
        return '';
    }

    /**
     * 下注
     * @param $data
     * @return mixed
     */
    public function bet($data): mixed
    {
        $orders = $data['transactions'];

        $return = [];

        foreach ($orders as $order) {
            /** @var Player $player */
            $player = Player::query()->where('uuid', $order['userid'])->first();
            if (empty($player)) {
                continue;
            }
            $bet = $order['amt'];
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $player->machine_wallet()->lockForUpdate()->first();
            if ($machineWallet->money < $bet) {
                $this->error = O8GameController::API_CODE_AMOUNT_OVER_BALANCE;
                return $player->machine_wallet->money;
            }
            //下注记录

            //根据游戏code查找当前游戏所属的游戏平台
            $platformId = GameExtend::query()->where('code',$order['gamecode'])->value('code')??'';
            $insert = [
                'player_id' => $player->id,
                'parent_player_id' => $player->recommend_id ?? 0,
                'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $player->uuid,
                'platform_id' => $platformId?:$this->platform->id,
                'game_code' => $order['gamecode'],
                'department_id' => $player->department_id,
                'bet' => $bet,
                'win' => 0,
                'diff' => 0,
                'order_no' => $order['externalroundid'],
                'original_data' => json_encode($order),
                'order_time' => Carbon::createFromTimestamp($order['timestamp']),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
            ];
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

            $balance = $this->createBetRecord($machineWallet, $player, $record, $bet);

            $return['transactions'][] = [
                'txid' => $record->id,
                'ptxid' => $order['ptxid'],
                'bal' => $balance,
                'cur' => 'TWD',
                'dup' => false
            ];
        }

        return $return;
    }

    /**
     * 取消单
     * @param $data
     * @return float|string
     */
    public function cancelBet($data): float|string
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['txn_reverse_id'])->first();

        if (!$record) {
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return $this->player->machine_wallet->money;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return $this->player->machine_wallet->money;
        }

        //返还用户金钱  修改注单状态
        $bet = $data['amount'];
        return $this->createCancelBetRecord($record, $data, $bet);
    }

    /**
     * 结算
     * @param $data
     * @return mixed
     */
    public function betResulet($data): mixed
    {
        $orders = $data['transactions'];

        $return = [];

        foreach ($orders as $order) {
            /** @var Player $player */
            $player = Player::query()->where('uuid', $order['userid'])->first();
            if (empty($player)) {
                continue;
            }
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

            //需要循环处理下注订单
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->where('order_no', $order['externalroundid'])->first();

            if (!$record) {
                $this->error = SAGameController::API_CODE_GENERAL_ERROR;
                return $this->player->machine_wallet->money;
            }

            if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                $this->error = SAGameController::API_CODE_GENERAL_ERROR;
                return $this->player->machine_wallet->money;
            }

            $money = $order['turnover'] - $order['ggr'];

            //有金额则为赢  todo 需要额外优化O8不同状态时候的余额处理
            if ($order['txtype'] == 510) {
                $beforeGameAmount = $machineWallet->money;
                //处理用户金额记录
                // 更新玩家统计
                $machineWallet->money = bcadd($machineWallet->money, $money, 2);
                $machineWallet->save();
                //todo 语言文件后续处理
                //用户交易记录  现在单一钱包没有转账的说法 暂不记录转账记录
                $platformId = GameExtend::query()->where('code',$order['gamecode'])->value('code')??'';
                $playerDeliveryRecord = new PlayerDeliveryRecord;
                $playerDeliveryRecord->player_id = $player->id;
                $playerDeliveryRecord->department_id = $player->department_id;
                $playerDeliveryRecord->target = $record->getTable();
                $playerDeliveryRecord->target_id = $record->id;
                $playerDeliveryRecord->platform_id = $platformId?:$this->platform->id;
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

            $record->platform_action_at = Carbon::createFromTimeString($order['timestamp']);
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $record->action_data = json_encode($order, JSON_UNESCAPED_UNICODE);
            $record->win = $money;
            $record->diff = -$order['ggr'];
            $record->save();

            $return['transactions'][] = [
                'txid' => $record->id,
                'ptxid' => $order['ptxid'],
                'bal' => $machineWallet->money,
                'cur' => 'TWD',
                'dup' => false
            ];

            //彩金记录
            Client::send('game-lottery', ['player_id' => $this->player->id, 'bet' => $record->bet, 'play_game_record_id' => $record->id]);
        }

        return $return;
    }

    /**
     * 重新结算
     * @return mixed
     */
    public function reBetResulet($data)
    {
        return '';
    }

    /**
     * 送礼
     * @return mixed
     */
    public function gift($data)
    {
        return '';
    }


    public function verifyToken($token)
    {
        $key = $this->config['client_id'] . $this->config['client_secret'];
        [$type, $token] = explode(' ', $token);
        try {
            return json_decode(json_encode(JWT::decode($token, new Key($key, 'HS256'))), true);
        } catch (Exception $e) {
            return $this->error = O8GameController::API_CODE_CERTIFICATE_ERROR;
        }
    }

    /**
     * 解密数据
     * @param $data
     * @return mixed
     */
    public function decrypt($data): mixed
    {
        return [];
    }

    public function balance(): mixed
    {
        return $this->player->machine_wallet->money;
    }

    /**
     * 加密
     * @param $data
     * @return string
     */
    public function encrypt($data): string
    {
        return base64_encode(openssl_encrypt($data, 'DES-CBC', $this->config['des_key'], OPENSSL_RAW_DATA, $this->config['des_key']));
    }


    /**
     * 加密验证
     * @param $data
     * @param $timestamp
     * @return string
     */
    public function signatureData($data, $timestamp): string
    {
        $clientSecret = $this->config['client_secret'];
        $clientID = $this->config['client_id'];


        $xdata = $timestamp . $clientSecret . $clientID . $data;

        return md5($xdata);
    }
}
