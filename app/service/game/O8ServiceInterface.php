<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\wallet\controller\game\O8GameController;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use support\Cache;
use support\Log;
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
            'lang' => 'en-US',
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
    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return O8GameController::API_CODE_AMOUNT_OVER_BALANCE;
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
     * 重新结算
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
     * 送礼
     * @param $data
     * @return mixed
     * @deprecated 平台不支持送礼功能
     */
    public function gift($data): mixed
    {
        // 平台不支持送礼功能
        throw new \RuntimeException('平台不支持 gift() 功能');
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
        // ✅ 使用 Redis 缓存查询余额
        return \app\service\WalletService::getBalance($this->player->id);
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
