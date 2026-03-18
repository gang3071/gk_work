<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerGamePlatform;
use DateTime;
use Exception;
use support\Cache;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class WMServiceInterface extends GameServiceFactory implements GameServiceInterface
{
    public $method = 'POST';
    public $successCode = '0';
    public $userError = '104';

    private $apiDomain;
    private $appId;
    private $appSecret;
    private $type = [
        '101' => 'onlybac',
        '102' => 'onlydgtg',
        '103' => 'onlyrou',
        '104' => 'onlysicbo',
        '105' => 'onlyniuniu',
        '107' => 'onlyfantan',
        '108' => 'onlysedie',
    ];
    private $lang = [
        'zh-CN' => [
            'lang' => 9,
            'voice' => 'cn'
        ],
        'zh-TW' => [
            'lang' => 9,
            'voice' => 'cn'
        ],
        'en' => [
            'lang' => 1,
            'voice' => 'en'
        ],
        'th' => [
            'lang' => 2,
            'voice' => 'th'
        ],
        'vi' => [
            'lang' => 3,
            'voice' => 'vi'
        ],
        'jp' => [
            'lang' => 4,
            'voice' => 'ja'
        ],
        'kr_ko' => [
            'lang' => 5,
            'voice' => 'ko'
        ],
        'hi_hi' => [
            'lang' => 6,
            'voice' => 'hi'
        ],
        'in' => [
            'lang' => 8,
            'voice' => 'in'
        ],
        'ms' => [
            'lang' => 7,
            'voice' => 'ms'
        ],
        'es' => [
            'lang' => 10,
            'voice' => 'es'
        ],
    ];

    public ?\Monolog\Logger $log = null;

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.WM');
        $this->appId = $config['app_id'];
        $this->apiDomain = $config['api_domain'];
        $this->appSecret = $config['app_secret'];
        $this->platform = GamePlatform::query()->where('code', 'WM')->first();
        $this->player = $player;
        $this->log = Log::channel('wm_server');
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
        $playerGamePlatform = $this->checkPlayer();
        $params = [
            'cmd' => 'ChangeBalance',
            'vendorId' => $this->appId,
            'signature' => $this->appSecret,
            'user' => $playerGamePlatform->player_code,
            'money' => $data['amount'] ?? 0,
            'order' => $data['order_no'] ?? '',
            'timestamp' => time(),
        ];
        $res = $this->doCurl($this->apiDomain, $params);
        $this->log->error('wm -> depositAmount', ['res' => $res]);
        if ($res['errorCode'] != $this->successCode) {
            throw new GameException(!empty($res['errorMessage']) ? $res['errorMessage'] : trans('system_busy', [],
                'message'), 0);
        }
        Cache::set('depositAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('withdrawAmount_' . $this->player->id);

        return $res['result']['orderId'];
    }

    /**
     * WM暂不支持回放
     * @return string
     */
    public function replay(): string
    {
        return '';
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
            $playerGamePlatform->player_code = $result['user'] ?? '';
            $playerGamePlatform->player_password = $result['password'] ?? '';
            $playerGamePlatform->save();
        }

        return $playerGamePlatform;
    }

    /**
     * 注册玩家
     * @param string $prefix
     * @param int $retryCount
     * @return array
     * @throws GameException
     */
    public function createPlayer(string $prefix = 'J', int $retryCount = 0): array
    {
        if ($retryCount > 10) {
            throw new GameException(trans('system_busy', [], 'message'), 0);
        }

        $params = [
            'cmd' => 'MemberRegister',
            'vendorId' => $this->appId,
            'signature' => $this->appSecret,
            'user' => $prefix . '_' . $this->player->uuid,
            'password' => $this->generateAlphanumericPassword(),
            'username' => $this->player->name ? $this->player->name : $this->player->uuid,
            'profile' => $this->player->avatar,
            'timestamp' => time(),
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        $this->log->info('wm -> createPlayer', ['res' => $res]);
        if ($res['errorCode'] != $this->successCode && $res['errorCode'] != $this->userError) {
            throw new GameException(trans('system_busy', [], 'message'), 0);
        }
        if ($res['errorCode'] == $this->userError) {
            return $this->createPlayer(generateRandomString(), $retryCount + 1); // 增加重试计数
        }

        return $params;
    }

    /**
     *生成6位密码
     * @param int $length
     * @return string
     */
    private function generateAlphanumericPassword(int $length = 6): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $characters[rand(0, $charactersLength - 1)];
        }
        return $password;
    }

    /**
     * 组装请求
     * @param string $url
     * @param array $params
     * @return array|mixed
     * @throws Exception
     */
    public function doCurl(string $url, array $params = []): mixed
    {
        $response = Http::timeout(10)
            ->withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ])
            ->post($url . '?' . http_build_query($params), $params);
        if (!$response->ok()) {
            throw new Exception(trans('system_busy', [], 'message'));
        }
        $data = $response->json();
        if (empty($data)) {
            throw new Exception(trans('system_busy', [], 'message'));
        }

        return $data;
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
        $playerGamePlatform = $this->checkPlayer();
        $params = [
            'cmd' => 'SigninGame',
            'vendorId' => $this->appId,
            'signature' => $this->appSecret,
            'user' => $playerGamePlatform->player_code,
            'password' => $playerGamePlatform->player_password,
            'lang' => $this->lang[$data['lang']]['lang'],
            'voice' => $this->lang[$data['lang']]['voice'],
            'timestamp' => time(),
            'returnurl' => '',
        ];
        $res = $this->doCurl($this->apiDomain, $params);
        $this->log->info('wm -> lobbyLogin', ['res' => $res]);
        if ($res['errorCode'] != $this->successCode) {
            throw new GameException(trans('system_busy', [], 'message'), 0);
        }

        return $res['result'] ?? 0;
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
        $playerGamePlatform = $this->checkPlayer();
        $amount = $data['amount'] ?? 0;
        if ($data['take_all'] == 'true') {
            $amount = $this->getBalance();
        }
        $params = [
            'cmd' => 'ChangeBalance',
            'vendorId' => $this->appId,
            'signature' => $this->appSecret,
            'user' => $playerGamePlatform->player_code,
            'money' => -$amount,
            'order' => $data['order_no'] ?? '',
            'timestamp' => time(),
        ];
        $res = $this->doCurl($this->apiDomain, $params);
        $this->log->info('wm -> withdrawAmount', ['res' => $res]);
        if ($res['errorCode'] != $this->successCode) {
            throw new GameException(trans('system_busy', [], 'message'), 0);
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $res['result']['orderId'],
            'amount' => $data['amount'],
        ];
    }

    /**
     * 查詢玩家餘額
     * @return float
     * @throws GameException
     * @throws Exception
     */
    public function getBalance(): float
    {
        $playerGamePlatform = $this->checkPlayer();
        $params = [
            'cmd' => 'GetBalance',
            'vendorId' => $this->appId,
            'signature' => $this->appSecret,
            'user' => $playerGamePlatform->player_code,
            'timestamp' => time(),
        ];
        $res = $this->doCurl($this->apiDomain, $params);
        $this->log->error('wm -> getBalance', [$res]);
        if ($res['errorCode'] != $this->successCode) {
            throw new GameException('操作失败', 0);
        }

        return $res['result'] ?? 0;
    }

    /**
     * 取得區間內遊戲紀錄
     * @return array
     * @throws Exception
     */
    public function handleGameHistories(): array
    {
        try {
            $list = [];
            $date = new DateTime('today');
            $date->setTime(0, 0, 0);
            $startFormatted = $date->format('YmdHis');
            $endFormatted = $date->setTime(23, 59, 59);
            $data = $this->getGameHistories([
                'startTime' => $startFormatted,
                'endTime' => $endFormatted,
            ]);
            if (!empty($data['result'])) {
                foreach ($data['result'] as $item) {
                    /** @var PlayerGamePlatform $playerGamePlatform */
                    $playerGamePlatform = PlayerGamePlatform::withTrashed()
                        ->with(['player', 'player.recommend_promoter'])
                        ->where('player_code', $item['user'])
                        ->first();
                    if (!empty($playerGamePlatform)) {
                        $list[] = [
                            'player_id' => $playerGamePlatform->player->id,
                            'parent_player_id' => $playerGamePlatform->player->recommend_id ?? 0,
                            'agent_player_id' => $playerGamePlatform->player->recommend_promoter->recommend_id ?? 0,
                            'player_uuid' => $playerGamePlatform->player->uuid,
                            'platform_id' => $this->platform->id,
                            'game_code' => $item['gid'],
                            'department_id' => $playerGamePlatform->player->department_id,
                            'bet' => $item['validbet'],
                            'win' => $item['water'],
                            'diff' => $item['winLoss'],
                            'order_no' => $item['betId'],
                            'original_data' => json_encode($item),
                            'platform_action_at' => $item['settime'],
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            $this->log->error('wm -> handleGameHistories', [$e->getMessage(), $e->getFile(), $e->getLine()]);
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
            'cmd' => 'GetDateTimeReport',
            'vendorId' => $this->appId,
            'signature' => $this->appSecret,
            'startTime' => $data['startTime'],
            'endTime' => $data['endTime'],
            'timetype' => 1,
            'datatype' => 2,
            'timestamp' => time(),
        ];
        $res = $this->doCurl($this->apiDomain, $params);
        $this->log->info('wm -> getGameHistories', [$res['errorCode'], $params]);
        if ($res['errorCode'] != $this->successCode) {
            if ($res['errorCode'] == '107') {
                return [];
            }
            throw new GameException(trans('system_busy', [], 'message'), 0);
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
    public function gameLogin(Game $game, string $lang = 'zh-CN'): mixed
    {
        $playerGamePlatform = $this->checkPlayer();
        $params = [
            'cmd' => 'SigninGame',
            'vendorId' => $this->appId,
            'signature' => $this->appSecret,
            'user' => $playerGamePlatform->player_code,
            'password' => $playerGamePlatform->player_password,
            'lang' => $this->lang[$lang]['lang'],
            'voice' => $this->lang[$lang]['voice'],
            'timestamp' => time(),
        ];
        if ($game->game_extend->cate_id == GameType::CATE_LIVE_VIDEO) {
            $params['mode'] = $this->type[$game->game_extend->code];
        }
        if ($game->game_extend->cate_id == GameType::CATE_SLO) {
            $params['slot'] = 1;
            $params['slotCode'] = $game->game_extend->code;
            $params['slotGameId'] = $game->game_extend->game_id;
        }
        $res = $this->doCurl($this->apiDomain, $params);
        $this->log->info('wm -> gameLogin', [$res]);
        if ($res['errorCode'] != $this->successCode) {
            throw new GameException(trans('system_busy', [], 'message'), 0);
        }

        return $res['result'] ?? 0;
    }

    /**
     * 获取游戏列表
     * @throws Exception
     */
    public function getGameList()
    {
        // TODO: Implement getPlayer() method.
    }

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }

    public function userLogout()
    {
        // TODO: Implement userLogout() method.
    }
}
