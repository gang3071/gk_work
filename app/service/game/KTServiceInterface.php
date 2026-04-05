<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\wallet\controller\game\KTGameController;
use app\wallet\controller\game\TNineGameController;
use Exception;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class KTServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
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
        'zh-CN' => 'zh-cht',
        'zh-TW' => 'zh-chs',
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

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $this->config = config('game_platform.KT');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'KT')->first();
        $this->player = $player;
        $this->log = Log::channel('kt_server');
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
        $key = $this->config['hash_key'];
        $platform = $this->config['platform'];

        if ($method == 'get') {
            $queryParams = http_build_query($params);
            $hash_hmac = hash_hmac("sha256", $queryParams, $key);

            if (!empty($queryParams)) {
                $query = $this->config['api_domain'] . '/' . $platform . $url . '?' . $queryParams . '&Hash=' . $hash_hmac;
            } else {
                $query = $this->config['api_domain'] . '/' . $platform . $url;
            }

            $response = Http::timeout(7)
                ->asJson()
                ->get($query);
        } else {
            $hash_hmac = hash_hmac("sha256", json_encode($params), $key);

            $response = Http::timeout(7)
                ->asJson()
                ->post($this->config['api_domain'] . '/' . $platform . $url . '?' . "Hash=$hash_hmac", $params);
        }

//        $this->log->info($url, ['params' => $params, 'response' => $response->body()]);

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
        $params = [
            'Username' => $this->player->uuid,
            'Currency' => $this->currency[$this->player->currency],
            'AgentName' => $this->config['agent'],
        ];
        $response = $this->doCurl('/players', $params);
        $this->log->info('createPlayer', [$response]);
        return $response;
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
            'Token' => $this->player->uuid,
            'LoginHall' => true,
            'AgentName' => $this->config['agent'],
            'Lang' => 'zh-cht',
            'PlayerType' => 4,
        ];
        $res = $this->doCurl('/login', $params, 'get');
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
        $params = [];
        $res = $this->doCurl('/games', $params, 'get');
        $this->log->info('getGameList', [$res]);

        $insertData = [];
        if (!empty($res['Data'])) {
            foreach ($res['Data'] as $item) {
                $cate = GameType::CATE_SLO;
                $insertData[] = [
                    'game_id' => $item['ID'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => $cate,
                    'name' => $item['Name']['zh-cht'],
                    'code' => $item['ID'],
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
            'Token' => $this->player->uuid,
            'GameID' => $game->game_extend->code,
            'AgentName' => $this->config['agent'],
            'Lang' => 'zh-cht',
            'PlayerType' => 4,
        ];
        $res = $this->doCurl('/login', $params, 'get');
        $this->log->info('lobbyLogin', [$res]);

        return $res['URL'];
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
//            $result = $this->createPlayer();
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
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return KTGameController::API_CODE_AMOUNT_OVER_BALANCE;
    }

    /**
     * 重新结算
     * @return mixed
     */
    public function reBetResulet($data)
    {
        return '';
    }


    public function verifySign($data)
    {
        $agentId = $data['AgentId'];
        $time = $data['RequestTime'];
        $key = $this->config['api_key'];

        $sign = strtolower(md5("$agentId&$time&$key"));
        if ($sign !== $data['Sign']) {
            return $this->error = TNinegameController::API_CODE_SIGN_ERROR;
        }

        return true;
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

    public function verifyToken($params, $hash)
    {
        $key = $this->config['hash_key'];

        $verify = hash_hmac("sha256", json_encode($params), $key);

        if ($verify !== $hash) {
            return $this->error = KTGameController::API_CODE_TOKEN_DOES_NOT_EXIST;
        }

        return true;
    }


}
