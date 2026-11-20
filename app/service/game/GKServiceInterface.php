<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerGamePlatform;
use Carbon\Carbon;
use Exception;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class GKServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    public $config;
    public $log;
    public $platform;
    public $player;

    public $language = [
        'zh-CN' => 'cn',
        'zh-TW' => 'tw',
        'en' => 'en',
        'vi' => 'vi'
    ];

    public $path = [
        'createPlayer' => '/api/Login2',
        'gameLogin' => '/api/PlayGame',
        'getGameHistories' => '/api/BetRecord',
        'getGameList' => '/api/GameList',
    ];

    /**
     * @param Player|null $player
     */
    public function __construct(Player $player = null)
    {
        $this->config = config('game_platform.GK');
        $this->log = Log::channel('gk_server');
        $this->platform = GamePlatform::query()->where('code', 'GK')->first();
        $this->player = $player;
    }

    /**
     * 組裝請求
     * @param $path
     * @param $params
     * @return array|mixed
     * @throws GameException
     */
    public function doCurl(string $path, array $params = [])
    {
        $url = $this->config['api_domain'] . $this->path[$path];

        $username = $params['username'] ?? '';
        $time = Carbon::now('-04:00')->format('Ymd');  // 美東時間 GMT-4
        $stringB = md5($this->config['website'] . $username . $this->config['key'] . $time);
        $stringA = $this->randomString();  // 8-16 位隨機字串
        $stringC = $this->randomString();  // 8-16 位隨機字串

        $params['website'] = $this->config['website'];
        $params['uppername'] = $this->config['uppername'];
        $params['key'] = $stringA . $stringB . $stringC;

        $response = Http::asForm()->timeout(30)->post($url, $params);

        if (! $response->ok()) {
            $this->log->error('GK 接口請求失敗', [
                'url' => $url,
                'params' => $params,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            throw new GameException(trans('system_busy', [], 'message'));
        }

        $result = $response->json();
        $success = $result['result'] ?? false;

        if (! $success) {
            $this->log->error('GK 返回數據無效', [
                'url' => $url,
                'params' => $params,
                'status' => $response->status(),
                'response' => $response->body(),
                'result' => $result
            ]);

            throw new GameException(trans('system_busy', [], 'message'));
        }

        if ($this->config['debug']) {
            $this->log->error('GK 測試回調全紀錄', [
                'url' => $url,
                'params' => $params,
                'result' => $result
            ]);
        }

        return $result;
    }

    /**
     * 進入遊戲
     * @param Game $game
     * @param string $lang
     * @return string|mixed
     * @throws GameException
     */
    public function gameLogin(Game $game, string $lang = 'zh-TW')
    {
        self::checkPlayer();
        // ---------------------------------------- 先登入 ----------------------------------------
        $params = [
            'username' => $this->player->uuid,
            'nickname' => $this->player->name  // 選填
        ];

        self::doCurl('createPlayer', $params);
        // ---------------------------------------- 再進遊戲 ----------------------------------------
        $params = [
            'username' => $this->player->uuid,
            'gamekind' => 5,  // 2:直播 3:指定賽事 ID 的直播 5:Slot Game
            'gametype' => $game->game_extend->code,
            'nickname' => $this->player->name,  // 選填
            'lang' => $this->language[$lang],  // 選填
        ];

        $result = self::doCurl('gameLogin', $params);

        if (empty($result['data']['url'])) {
            $this->log->error('GK 進入遊戲失敗', [
                'params' => $params,
                'result' => $result,
            ]);

            throw new GameException(trans('system_busy', [], 'message'));
        }

        return $result['data']['url'];
    }

    /**
     * 確認玩家資料
     */
    public function checkPlayer()
    {
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();

        if (empty($playerGamePlatform)) {
            self::createPlayer();

            $playerGamePlatform = new PlayerGamePlatform;
            $playerGamePlatform->player_id = $this->player->id;
            $playerGamePlatform->platform_id = $this->platform->id;
            $playerGamePlatform->player_name = $this->player->name;
            $playerGamePlatform->player_code = $this->player->uuid;
            $playerGamePlatform->web_id = $this->getWebId();
            $playerGamePlatform->save();
        }

        return $playerGamePlatform;
    }

    /**
     * 創建玩家
     * @return bool
     */
    public function createPlayer()
    {
        $params = [
            'username' => $this->player->uuid,
            'nickname' => $this->player->name  // 選填
        ];

        self::doCurl('createPlayer', $params);

        return true;
    }

    /**
     * 取得區間內遊戲紀錄
     * @return array
     * @throws Exception
     */
    public function handleGameHistories()
    {
        $list = [];

        try {
            $result = self::getGameHistories();

            if (! empty($result['data'])) {
                foreach ($result['data'] as $value) {
                    $player = Player::withTrashed()->where('uuid', $value['UserName'])->first();

                    if (empty($player)) {
                        continue;
                    }

                    $list[] = [
                        'player_id' => $player->id,
                        'parent_player_id' => $player->recommend_id ?? 0,
                        'player_uuid' => $player->uuid,
                        'platform_id' => $this->platform->id,
                        'game_code' => $value['GameID'],
                        'department_id' => $player->department_id,
                        'bet' => $value['BetAmount'],
                        'win' => max($value['Payoff'] - $value['BetAmount'], 0),
                        'diff' => $value['Payoff'] - $value['BetAmount'],
                        'order_no' => $value['WagersID'],
                        'original_data' => json_encode($value),
                        'platform_action_at' => date('Y-m-d H:i:s', strtotime($value['UpdatedAt']))
                    ];
                }
            }
        } catch (Exception $e) {
            $this->log->error('handleGameHistories异常', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return [];
        }

        return $list;
    }

    /**
     * 取得區間內遊戲紀錄
     * @return array
     */
    public function getGameHistories()
    {
        $params = [
            'date_start' => Carbon::now()->format('Y/m/d'),
            'date_end' => Carbon::now()->format('Y/m/d'),
            'gamekind' => 5,  // 2:直播 3:指定賽事 ID 的直播 5:Slot Game
            'subgamekind' => '0071',
            'start_hhmmss' => Carbon::now()->subMinutes(5)->format('H:i:s'),  // 選填
            'end_hhmmss' => Carbon::now()->format('H:i:s'),  // 選填
            'lang' => $this->language['zh-CN'],  // 選填
        ];

        return self::doCurl('getGameHistories', $params);
    }

    /**
     * 獲取遊戲列表
     * @param $lang
     * @return bool
     */
    public function getGameList(string $lang = 'zh-TW')
    {
        $result = self::doCurl('getGameList');

        if (empty($result['data']['gameList'])) {
            return true;
        }

        $insertData = [];

        foreach ($result['data']['gameList'] as $value) {
            $insertData[] = [
                'platform_id' => $this->platform->id,
                'logo' => '',
                'cate_id' => GameType::CATE_COMPUTER_GAME,
                'code' => $value['gameID'],
                'game_id' => $value['gameID'],
                'name' => $value['content'] . ' ' . $value['gameName'],
                'org_data' => json_encode($value),
                'status' => 1
            ];
        }

        if (! empty($insertData)) {
            GameExtend::query()->upsert($insertData, ['platform_id', 'code']);
        }

        return true;
    }

    /**
     * 產生隨機字串
     * @param $length
     * @return string
     */
    public function randomString(int $length = 10)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $result;
    }

    public function lobbyLogin()
    {
        // TODO: Implement lobbyLogin() method.
    }

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }

    public function userLogout()
    {
        // TODO: Implement userLogout() method.
    }

    public function replay()
    {
        // TODO: Implement replay() method.
    }

    public function bet($data)
    {
        // TODO: Implement bet() method.
    }

    public function cancelBet($data)
    {
        // TODO: Implement cancelBet() method.
    }

    public function betResulet($data)
    {
        // TODO: Implement betResulet() method.
    }

    public function reBetResulet($data)
    {
        // TODO: Implement reBetResulet() method.
    }

    public function gift($data)
    {
        // TODO: Implement gift() method.
    }

    public function decrypt($data)
    {
        // TODO: Implement decrypt() method.
    }
}
