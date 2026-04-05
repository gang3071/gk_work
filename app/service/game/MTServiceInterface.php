<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\wallet\controller\game\MtGameController;
use Exception;
use support\Cache;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class MTServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    public string $method = 'POST';
    public string $successCode = '0';
    private mixed $apiDomain = '';
    private array $lang = [
        'zh-CN' => 'zh-TW',
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

    public string $prefix = 'yjbmt';

    public const BET_STATUS_NOT = 2;  //未中奖
    public const BET_STATUS_YES = 3;  //中奖
    public const BET_STATUS_DRAW = 4;  //和局

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $this->config = config('game_platform.MT');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'MT')->first();
        $this->player = $player;
        $this->log = Log::channel('mt_server');
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
        $config = config('game_platform.MT');
        $encryptData = openssl_encrypt(json_encode($params), 'DES-CBC', $config['des_key'], OPENSSL_RAW_DATA,
            $config['des_iv']);
        $reqBase64 = base64_encode($encryptData);
        $timestamp = time();
        $response = Http::timeout(7)
            ->withHeaders([
                'APICI' => $config['client_id'],
                'APISI' => md5($timestamp . $config['client_secret'] . $config['client_id'] . $reqBase64),
                'APITS' => $timestamp
            ])
            ->asForm()
            ->post($url, ['msg' => $reqBase64]);


        if (!$response->ok()) {
            Log::channel('mt_server')->error($url, ['headers' => [
                'APICI' => $config['client_id'],
                'APISI' => md5($timestamp . $config['client_secret'] . $config['client_id'] . $reqBase64),
                'APITS' => $timestamp
            ], 'params' => ['msg' => $reqBase64, 'origin' => $params], 'config' => $config, 'response' => $response->body()]);
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
        $orderId = $this->config['system_code'] . $timestamp . $this->player->id;

        $params = [
            'system_code' => $this->config['system_code'],
            'web_id' => $this->getWebId(),
            'user_id' => $this->player->uuid,
            'balance' => $data['amount'] ?? 0,
            'transfer_id' => $orderId
        ];

        $res = $this->doCurl($this->apiDomain . '/Player/Deposit', $params);

        //上分失败进行状态查询处理
        if ($res['code'] != '00000') {
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
            $playerGamePlatform->player_code = $this->player->uuid;
            $playerGamePlatform->web_id = $this->getWebId();
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
            'system_code' => $this->config['system_code'],
            'web_id' => $this->getWebId(),
            'user_id' => $this->player->uuid,
            'user_name' => !empty($this->player->name) ? $this->player->name : $this->player->uuid,
            'currency' => $this->currency[$this->player->currency],
        ];
        $res = $this->doCurl($this->apiDomain . '/Player/CreateUser', $params);
        if ($res['code'] != '00000') {
            $this->log->info('createPlayer', [$res]);
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
            'system_code' => $this->config['system_code'],
            'web_id' => $this->getWebId(),
            'user_id' => $this->player->uuid,
            'language' => $this->lang[$data['lang']],
        ];
        $res = $this->doCurl($this->apiDomain . '/Player/GetURLToken', $params);
        if ($res['code'] != '00000') {
            $this->log->info('GetURLToken', [$res]);
            throw new GameException($res['message'], 0);
        }

        return $res['data']['url'] ?? '';
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
        $orderId = $this->config['system_code'] . $timestamp . $this->player->id;
        $params = [
            'system_code' => $this->config['system_code'],
            'web_id' => $this->getWebId(),
            'user_id' => $this->player->uuid,
            'balance' => !empty($data['amount']) ? (float)$data['amount'] : 0,
            'transfer_id' => $orderId
        ];
        $res = $this->doCurl($this->apiDomain . '/Player/Withdraw', $params);
        if ($res['code'] != '00000') {
            $this->log->info('GetURLToken', [$res]);
            throw new GameException($res['message'], 0);
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $res['data']['transfer_id'] ?? '',
            'amount' => $params['balance'],
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
            'system_code' => $this->config['system_code'],
            'web_id' => $this->getWebId(),
            'user_id' => $this->player->uuid,
        ];
        $res = $this->doCurl($this->apiDomain . '/Player/GetBalance', $params);
        if ($res['code'] != '00000') {
            $this->log->info('GetBalance', [$res]);
            throw new GameException($res['message'], 0);
        }

        return $res['data']['balance'] ?? 0;
    }

    /**
     * 取得區間內遊戲紀錄
     * @param $page
     * @return array
     * @throws GameException
     */
    public function getGameHistories($page): array
    {
        $params = [
            'system_code' => $this->config['system_code'],
            'web_id' => $this->getWebId(),
            'start_time' => date('Y-m-d H:i:s', strtotime('-1 minutes')),
            'end_time' => date('Y-m-d H:i:s'),
            'page' => $page,
            'page_size' => 100
        ];
        $res = $this->doCurl($this->apiDomain . '/Report/GetBetRecord', $params);
        if ($res['code'] != '00000') {
            $this->log->info('GetBetRecord', [$res]);
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
            $page = 1;
            $data = $this->getGameHistories($page);
            if (!empty($data)) {
                $totalPage = $data['total_page'];
                $currentPage = $data['current_page'];
                if ($data['list']) {
                    foreach ($data['list'] as $item) {
                        /** @var Player $player */
                        $player = Player::withTrashed()->where('uuid', $item['user_id'])->first();
                        $list[] = [
                            'player_id' => $player->id,
                            'parent_player_id' => $player->recommend_id ?? 0,
                            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                            'player_uuid' => $player->uuid,
                            'platform_id' => $this->platform->id,
                            'game_code' => $item['game_code'],
                            'department_id' => $player->department_id,
                            'bet' => $item['order_money'],
                            'win' => $item['win_money'],
                            'diff' => $item['profit'],
                            'order_no' => $item['sn'],
                            'original_data' => json_encode($item),
                            'platform_action_at' => $item['settle_time'],
                        ];
                    }
                }
                if ($totalPage > $currentPage) {
                    for ($page = 2; $page <= $totalPage; $page++) {
                        $nextData = $this->getGameHistories($page);
                        if ($nextData['list']) {
                            foreach ($nextData['list'] as $item) {
                                /** @var Player $player */
                                $player = Player::withTrashed()->where('uuid', $item['user_id'])->first();
                                $list[] = [
                                    'player_id' => $player->id,
                                    'parent_player_id' => $player->recommend_id ?? 0,
                                    'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                                    'player_uuid' => $player->uuid,
                                    'platform_id' => $this->platform->id,
                                    'game_code' => $item['game_code'],
                                    'department_id' => $player->department_id,
                                    'bet' => $item['order_money'],
                                    'win' => $item['win_money'],
                                    'diff' => $item['profit'],
                                    'order_no' => $item['sn'],
                                    'original_data' => json_encode($item),
                                    'platform_action_at' => $item['settle_time'],
                                ];
                            }
                        }
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
            'system_code' => $this->config['system_code'],
            'web_id' => $this->getWebId(),
            'user_id' => $this->player->uuid,
            'language' => $this->lang[$lang],
        ];
        $res = $this->doCurl($this->apiDomain . '/Player/GetURLToken', $params);
        if ($res['code'] != '00000') {
            $this->log->info('GetURLToken', [$res]);
            throw new GameException($res['message'], 0);
        }

        return $res['data']['url'] ?? '';
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



    /**
     * 解密数据
     * @param $data
     * @return string|void
     */
    public function decrypt($data)
    {
        $desKey = $this->config['des_key'];
        $desIv = $this->config['des_iv'];

        $data = json_decode(openssl_decrypt(base64_decode($data), 'DES-CBC', $desKey, OPENSSL_RAW_DATA, $desIv), true);

        if (empty($data)) {
            return $this->error = MtGameController::API_CODE_DECRYPT_ERROR;
        }

        if (empty($data['system_code']) || $data['system_code'] != $this->config['system_code']) {
            return $this->error = MtGameController::API_CODE_INVALID_PARAM;
        }

        $player = Player::query()->where('uuid', $data['user_id'])->first();

        if (!$player) {
            return $this->error = MtGameController::API_CODE_PLAYER_NOT_EXIST;
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
        $encrypt_data = openssl_encrypt($data, 'DES-CBC', $this->config['des_key'], OPENSSL_RAW_DATA, $this->config['des_iv']);
        return base64_encode($encrypt_data);
    }


    /**
     * 获取用户对应渠道的webid
     * @return string
     */
    public function getWebId()
    {
        //TODO 后期优化增加webid未注册的报错提示
        return 'yjbtest31';
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
