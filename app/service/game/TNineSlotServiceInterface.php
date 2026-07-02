<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\wallet\controller\game\TNineGameController;
use app\wallet\controller\game\TNineSlotGameController;
use Exception;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * T9电子平台
 */
class TNineSlotServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
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
        'zh-CN' => 'zh_CN',
        'zh-TW' => 'zh_TW',
        'en' => 'en',
        'th' => 'th',
        'vi' => 'vi-VN',
        'jp' => 'ja',
        'kr_ko' => 'ko',
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
    public function __construct(Player $player = null)
    {
        $this->config = config('game_platform.TNINE_SLOT');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'TNINE_SLOT')->first();
        $this->player = $player;
        $this->log = Log::channel('tnine_slot_server');
    }

    /**
     * 组装请求
     * @param string $url
     * @param array $params
     * @param string $method
     * @return array|mixed
     * @throws GameException
     * @throws Exception
     */
    public function doCurl(string $url, array $params = [], string $method = 'post', array $acceptCodes = []): mixed
    {
        $agentId = $this->config['agent_id'];
        $key = $this->config['api_key'];

        $params['gameAccount'] .= '_' . $agentId;
        $params['agentId'] = $agentId;
        $params['apiKey'] = $key;
        $params['platform'] = 'T9SlotSeamless';

        $fullUrl = $this->config['api_domain'] . $url;

        // 打印请求报文（隐藏敏感信息）
        $this->log->info('T9 API请求报文', [
            'url' => $fullUrl,
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'params' => array_merge($params, [
                'apiKey' => substr($key, 0, 10) . '...', // 只显示前10位
            ]),
        ]);

        $response = Http::timeout(10)
            ->asJson()
            ->post($fullUrl, $params);

        // 打印响应报文
        $this->log->info('T9 API响应报文', [
            'url' => $fullUrl,
            'status_code' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
        ]);

        if (!$response->ok()) {
            $errorMsg = 'T9 API请求失败 HTTP ' . $response->status() . ': ' . $response->body();
            $this->log->error('T9 API请求失败 - HTTP错误', [
                'url' => $fullUrl,
                'status' => $response->status(),
                'params' => $params,
                'response_body' => $response->body(),
                'response_headers' => $response->headers(),
            ]);
            throw new GameException($errorMsg);
        }

        $res = json_decode($response->body(), true);

        if (empty($res)) {
            $errorMsg = 'T9 API响应为空: ' . $response->body();
            $this->log->error('T9 API响应为空', [
                'url' => $fullUrl,
                'params' => $params,
                'response_body' => $response->body(),
            ]);
            throw new Exception($errorMsg);
        }

        $resultCode = $res['resultCode'] ?? '';
        if ($resultCode != 'OK' && !in_array($resultCode, $acceptCodes)) {
            $errorMsg = 'T9 API错误: ' . ($res['resultCode'] ?? '未知错误') . ' - ' . ($res['message'] ?? $response->body());

            // 详细记录错误
            $this->log->error('T9 API返回错误', [
                'url' => $fullUrl,
                'params' => $params,
                'result_code' => $res['resultCode'] ?? 'null',
                'error_message' => $res['message'] ?? '',
                'error_msg' => $res['errorMsg'] ?? '',
                'data' => $res['data'] ?? null,
                'full_response' => $response->body(),
            ]);

            throw new Exception($errorMsg);
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
            'gameAccount' => $this->player->uuid, // todo后期需要处理每个用户不同的agentid带入
            'currency' => 'TWD',
        ];
        $response = $this->doCurl('/CreatePlayer', $params, 'post', ['GameAccount_EXIST']);
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
            'MemberAccount' => $this->player->uuid,
            'MemberPassword' => $this->player->uuid,
        ];
        $res = $this->doCurl('/api/launch_game', $params);
        $this->log->info('lobbyLogin', [$res]);

        return $res['Data']['GameUrl'];
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        //只能根据文档手动配置
        //暂时写死文档配置方便流程处理
        $list = config('tnine-slot');
        $insertData = [];
        if (!empty($list)) {
            foreach ($list as $item) {
                $insertData[] = [
                    'game_id' => $item['game_id'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => GameType::CATE_SLO,
                    'name' => $item['name'],
                    'code' => $item['code'],
                    'table_name' => '',
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
            'gameCode' => $game->game_extend->code,
            'gameAccount' => $this->player->uuid,
            'gameCategory' => 1,
            'language' => 'zh_TW',
            'isMobileLogin' => true,
        ];
        $res = $this->doCurl('/Login', $params);
        $this->log->info('lobbyLogin', [$res]);

        return $res['data']['gameUrl'];
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
        return TNineSlotGameController::API_CODE_INSUFFICIENT_BALANCE;
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
     *@deprecated 平台不支持送礼功能
     */
    public function gift($data): mixed
    {
        // 平台不支持送礼功能
        throw new \RuntimeException('平台不支持 gift() 功能');
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
