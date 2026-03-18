<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use Exception;
use support\Cache;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class OBServiceInterface extends GameServiceFactory implements GameServiceInterface
{
    public $method = 'POST';
    public $successCode = '0';
    private $apiDomain;
    private $agent;
    private $suffix;
    private $operator_id;
    private $lang = [
        'zh-CN' => 'zh_CN',
        'zh-TW' => 'zh_TW',
        'en' => 'en',
        'th' => 'th',
        'vi' => 'vi',
        'jp' => 'ja',
        'kr_ko' => 'ko',
    ];

    private $currency = [
        'TWD' => 'CNY',
        'CNY' => 'CNY',
        'JPY' => 'JPY',
        'USD' => 'USD',
    ];

    public $log;

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.OB');
        $this->apiDomain = $config['api_domain'];
        $this->agent = $config['agent'];
        $this->suffix = $config['suffix'];
        $this->operator_id = $config['operator_id'];
        $this->platform = GamePlatform::query()->where('code', 'OB')->first();
        $this->player = $player;
        $this->log = Log::channel('ob_server');
    }

    /**
     * 组装请求
     * @param string $path
     * @param array $params
     * @return array|mixed
     * @throws Exception
     */
    public function doCurl(string $path, array $params = [])
    {
        $config = config('game_platform.OB');
        $requestTime = gmdate('D, d M Y H:i:s') . ' UTC';
        $requestBodyString = json_encode($params);
        $contentMD5 = base64_encode(pack('H*', md5($requestBodyString)));

        $stringToSign = 'POST' . "\n"
            . $contentMD5 . "\n"
            . 'application/json' . "\n"
            . $requestTime . "\n"
            . $path;

        $deKey = base64_decode($config['key']);
        $hash_hmac = hash_hmac("sha1", $stringToSign, $deKey, true);
        $encrypted = base64_encode($hash_hmac);
        $authorization = "AB" . " " . $config['operator_id'] . ":" . $encrypted;

        $url = $config['api_domain'] . $path;
        $headers = [
            'Authorization' => $authorization,
            'Date' => $requestTime,
            'Content-MD5' => $contentMD5,
        ];
        $response = Http::timeout(7)
            ->asJson()
            ->withHeaders($headers)
            ->post($url, $params);

        if (!$response->ok()) {
            throw new GameException(trans('system_busy', [], 'message'));
        }
        $res = $response->json();
        if (empty($res)) {
            throw new Exception(trans('system_busy', [], 'message'));
        }
        return $res;
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
        $timestamp = time();
        $orderId = $this->operator_id . $timestamp . mt_rand(100, 999);

        $params = [
            'sn' => $orderId,
            'agent' => $this->agent,
            'player' => $this->player->uuid . $this->suffix,
            'type' => 1,
            'amount' => $data['amount'] ?? 0,
        ];

        $res = $this->doCurl('/Transfer', $params);

        //上分失败进行状态查询处理
        if ($res['resultCode'] != 'OK') {
            $this->log->info('Transfer', [$res]);
            throw new GameException($res['message'], 0);
        }
        Cache::set('depositAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('withdrawAmount_' . $this->player->id);

        return $orderId;
    }

    /**
     * @param bool $login 是否登录
     * @throws GameException
     */
    private function checkPlayer(bool $login = false)
    {
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if (empty($playerGamePlatform)) {
            $this->createPlayer();
            $playerGamePlatform = new PlayerGamePlatform;
            $playerGamePlatform->player_id = $this->player->id;
            $playerGamePlatform->platform_id = $this->platform->id;
            $playerGamePlatform->player_name = $this->player->name;
            $playerGamePlatform->player_code = $this->player->uuid . $this->suffix;
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
    public function createPlayer()
    {
        $params = [
            'agent' => $this->agent,
            'player' => $this->player->uuid . $this->suffix,
        ];
        $res = $this->doCurl('/CheckOrCreate', $params);
        if ($res['resultCode'] != 'OK') {
            $this->log->info('CheckOrCreate', [$res]);
            throw new GameException($res['message'], 0);
        }

        return $params;
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
            'player' => $this->player->uuid . $this->suffix,
            'language' => $this->lang[$data['lang']] ?? 'en',
        ];
        $res = $this->doCurl('/Login', $params);
        if ($res['resultCode'] != 'OK') {
            $this->log->info('Login', [$res]);
            throw new GameException($res['message'], 0);
        }

        return $res['data']['gameLoginUrl'] ?? '';
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
        $timestamp = time();
        $orderId = $this->operator_id . $timestamp . mt_rand(100, 999);

        $params = [
            'sn' => $orderId,
            'agent' => $this->agent,
            'player' => $this->player->uuid . $this->suffix,
            'type' => 0,
            'amount' => $data['amount'] ?? 0,
        ];

        $res = $this->doCurl('/Transfer', $params);

        //下分失败进行状态查询处理
        if ($res['resultCode'] != 'OK') {
            $this->log->info('Transfer', [$res]);
            throw new GameException($res['message'], 0);
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);
        return [
            'order_id' => $orderId,
            'amount' => $params['amount'],
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
        $this->checkPlayer();
        $params = [
            'agent' => $this->agent,
            'pageSize' => 1,
            'pageIndex' => 1,
            'recursion' => 0,
            'players' => [$this->player->uuid . $this->suffix],
        ];

        $res = $this->doCurl('/GetBalances', $params);
        $this->log->info('getBalance', [$res]);

        //上分失败进行状态查询处理
        if ($res['resultCode'] != 'OK') {
            throw new GameException($res['message'], 0);
        }

        return $res['data']['list'][0]['amount'] ?? 0;
    }

    /**
     * 取得區間內遊戲紀錄
     * @return array
     * @throws GameException
     */
    public function getGameHistories(): array
    {
        $params = [
            'agent' => $this->agent,
            'startDateTime' => date('Y-m-d H:i:s', strtotime('-1 minutes')),
        ];
        $res = $this->doCurl('/QuickQueryBetRecords', $params);
        if ($res['resultCode'] != 'OK') {
            $this->log->info('QuickQueryBetRecords', [$res]);
            throw new GameException($res['message'], 0);
        }

        return $res['data'] ?? [];
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
                if ($data['list']) {
                    foreach ($data['list'] as $item) {
                        /** @var Player $player */
                        $player = Player::withTrashed()->where('uuid', str_replace($this->suffix, '', $item['player']))->first();
                        $list[] = [
                            'player_id' => $player->id,
                            'parent_player_id' => $player->recommend_id ?? 0,
                            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                            'player_uuid' => $player->uuid,
                            'platform_id' => $this->platform->id,
                            'game_code' => $item['gameType'],
                            'department_id' => $player->department_id,
                            'bet' => $item['betAmount'],
                            'win' => bcadd($item['betAmount'], $item['winOrLossAmount'], 2),
                            'diff' => $item['winOrLossAmount'],
                            'order_no' => $item['betNum'],
                            'original_data' => json_encode($item),
                            'platform_action_at' => $item['gameRoundEndTime'],
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            throw new GameException($e->getMessage());
        }
        return $list;
    }

    /**
     * @param string $lang
     * @return true
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
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
            'player' => $this->player->uuid . $this->suffix,
            'language' => $this->lang[$data['lang']] ?? 'en',
        ];
        $res = $this->doCurl('/Login', $params);
        if ($res['resultCode'] != 'OK') {
            $this->log->info('GetURLToken', [$res]);
            throw new GameException($res['message'], 0);
        }

        return $res['data']['gameLoginUrl'] ?? '';
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
        return '';
    }
}
