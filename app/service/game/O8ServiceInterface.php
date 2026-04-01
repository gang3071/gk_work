<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\traits\AsyncGameRecordTrait;
use app\wallet\controller\game\O8GameController;
use Carbon\Carbon;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use support\Cache;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class O8ServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    use AsyncGameRecordTrait;
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
    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return O8GameController::API_CODE_AMOUNT_OVER_BALANCE;
    }

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

            // 临时设置player供爆机检查使用
            $this->player = $player;

            // 检查设备是否爆机
            if ($this->checkAndHandleMachineCrash()) {
                return \app\service\WalletService::getBalance($player->id);
            }

            $bet = $order['amt'];
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

            // ✅ 幂等性检查：防止重复下注（在锁保护下检查，防止TOCTOU竞态条件）
            $existingRecord = PlayGameRecord::query()
                ->where('order_no', $order['externalroundid'])
                ->first();

            if ($existingRecord) {
                // 重复订单，返回当前余额和dup=true
                $balance = \app\service\WalletService::getBalance($player->id);
                $return['transactions'][] = [
                    'txid' => $order['externalroundid'],
                    'ptxid' => $order['ptxid'],
                    'bal' => $balance,
                    'cur' => 'TWD',
                    'dup' => true  // ✅ 标记为重复订单
                ];
                continue;  // 跳过此订单，不重复扣款
            }

            if ($machineWallet->money < $bet) {
                $this->error = O8GameController::API_CODE_AMOUNT_OVER_BALANCE;
                return \app\service\WalletService::getBalance($player->id);
            }

            // ✅ 同步扣减余额（触发 updated 事件，自动更新 Redis 缓存）
            $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
            $machineWallet->save();

            // ⚡ 异步创建下注记录（不阻塞API响应）
            $platformId = GameExtend::query()
                ->where('code', $order['gamecode'])
                ->value('platform_id') ?? $this->platform->id;

            $this->asyncCreateBetRecord(
                playerId: $player->id,
                platformId: $platformId,
                gameCode: $order['gamecode'],
                orderNo: $order['externalroundid'],
                bet: $bet,
                originalData: $order,
                orderTime: Carbon::createFromTimestamp($order['timestamp'])->toDateTimeString()
            );

            // ✅ 立即从缓存读取余额
            $balance = \app\service\WalletService::getBalance($player->id);

            $return['transactions'][] = [
                'txid' => $order['externalroundid'], // ✅ 使用外部订单号
                'ptxid' => $order['ptxid'],
                'bal' => $balance,
                'cur' => 'TWD',
                'dup' => false
            ];
        }

        return $return;
    }

    /**
     * 取消单（异步优化版）
     * @param $data
     * @return float|string
     */
    public function cancelBet($data): float|string
    {
        // ✅ 同步退还用户金钱（触发 updated 事件，自动更新 Redis 缓存）
        $bet = $data['amount'];
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        $machineWallet->money = bcadd($machineWallet->money, $bet, 2);
        $machineWallet->save();

        // ⚡ 异步更新取消状态（不阻塞API响应）
        $this->asyncCancelBetRecord($data['txn_reverse_id']);

        // ✅ 立即从缓存返回余额
        return \app\service\WalletService::getBalance($this->player->id);
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

            $money = $order['turnover'] - $order['ggr'];

            // ✅ 同步增加余额（有金额时，触发 updated 事件，自动更新 Redis 缓存）
            if ($order['txtype'] == 510 && $money > 0) {
                $machineWallet->money = bcadd($machineWallet->money, $money, 2);
                $machineWallet->save();
            }

            // ⚡ 异步更新结算记录（不阻塞API响应）
            // 彩金记录会在Consumer中处理
            $this->asyncUpdateSettleRecord(
                orderNo: $order['externalroundid'],
                win: $money,
                diff: -$order['ggr']
            );

            // ✅ 立即从缓存读取余额
            $balance = \app\service\WalletService::getBalance($player->id);

            $return['transactions'][] = [
                'txid' => $order['externalroundid'], // ✅ 使用外部订单号
                'ptxid' => $order['ptxid'],
                'bal' => $balance,
                'cur' => 'TWD',
                'dup' => false
            ];

            Log::info('O8结算完成', [
                'player_id' => $player->id,
                'order_no' => $order['externalroundid'],
                'win' => $money
            ]);
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
