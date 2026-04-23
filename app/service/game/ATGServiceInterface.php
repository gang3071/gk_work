<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\PlatformLimitGroupConfig;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\wallet\controller\game\ATGGameController;
use Exception;
use support\Cache;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class ATGServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    use LimitGroupTrait;

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
        $this->platform = GamePlatform::query()->where('code', 'ATG')->first();
        $this->player = $player;
        $this->log = Log::channel('atg_server');

        $config = config('game_platform.ATG');

        // 如果有玩家，必须从数据库获取配置
        if ($player) {
            $limitConfig = $this->getLimitRedConfig();

            if (!$limitConfig) {
                throw new GameException('游戏平台未配置');
            }

            // 验证配置完整性（必须包含所有字段）
            $requiredFields = ['operator', 'key', 'providerId'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (empty($limitConfig[$field])) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                throw new GameException('游戏平台配置不完整: 缺少 ' . implode(', ', $missingFields));
            }

            $this->config = [
                'api_domain' => $config['api_domain'],
                'operator' => $limitConfig['operator'],
                'providerId' => $limitConfig['providerId'],
                'key' => $limitConfig['key'],
            ];

        } else {
            // player=null时（控制器初始化或公共API调用），使用配置文件作为fallback
            // decrypt方法会在解密成功后从数据库重新获取配置
            $this->config = $config;
        }

        $this->apiDomain = $this->config['api_domain'] ?? '';
        $this->providerId = $this->config['providerId'] ?? '';
    }

    /**
     * 获取玩家的限红配置（ATG使用营运账号分组）
     * 完全参考RSG平台的实现逻辑
     * @return array|null 返回限红配置数组，包含ATG营运账号信息，如果没有配置则返回null
     */
    private function getLimitRedConfig(): ?array
    {
        // 使用 Trait 中的通用方法获取限红组配置
        $limitGroupConfig = $this->getLimitGroupConfig('atg_server');

        // 如果没有配置数据，返回null
        if (!$this->hasLimitGroupConfigData($limitGroupConfig)) {
            return null;
        }

        $configData = $limitGroupConfig->config_data;

        // 构建ATG限红参数（ATG使用营运账号：operator, key, providerId）
        // 支持多种字段命名方式：key/operator_key, providerId/provider_id
        // 注意：api_domain 固定使用配置文件，不从数据库读取
        $limitConfig = [];

        if (!empty($configData['operator'])) {
            $limitConfig['operator'] = $configData['operator'];
        }

        // 支持 key 或 operator_key
        if (!empty($configData['key'])) {
            $limitConfig['key'] = $configData['key'];
        } elseif (!empty($configData['operator_key'])) {
            $limitConfig['key'] = $configData['operator_key'];
        }

        // 支持 providerId 或 provider_id
        if (!empty($configData['providerId'])) {
            $limitConfig['providerId'] = $configData['providerId'];
        } elseif (!empty($configData['provider_id'])) {
            $limitConfig['providerId'] = $configData['provider_id'];
        }

        return !empty($limitConfig) ? $limitConfig : null;
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
     * 检查玩家（支持多营运账号）
     *
     * ATG平台特性：
     * - 每个营运账号(operator)下的玩家数据是独立的
     * - 玩家切换限红组 = 切换营运账号
     * - 需要在每个营运账号下单独注册
     *
     * @throws GameException
     */
    private function checkPlayer()
    {
        $operator = $this->config['operator'] ?? null;

        if (empty($operator)) {
            throw new GameException('游戏平台配置错误');
        }

        // 检查玩家在当前营运账号下是否已注册
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->where('operator', $operator)
            ->first();

        if (!empty($playerGamePlatform)) {
            // 已在当前营运账号下注册
            return true;
        }

        $this->createPlayer();

        // 记录玩家在当前营运账号下的注册信息
        $playerGamePlatform = new PlayerGamePlatform();
        $playerGamePlatform->player_id = $this->player->id;
        $playerGamePlatform->web_id = $this->getWebId();
        $playerGamePlatform->platform_id = $this->platform->id;
        $playerGamePlatform->operator = $operator;
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
        $this->checkPlayer();

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
        // 使用 $this->config 而不是重新读取配置，以支持限红组的动态配置
        $config = $this->config;

        // 为不同的营运账号使用不同的缓存key，避免混用token
        $cacheKey = 'game_platform_token_atg_' . md5($config['operator'] . $config['key']);
        $token = Cache::get($cacheKey);

        // 记录实际使用的营运账号（仅在获取token时记录，避免日志过多）
        $trace = debug_backtrace();
        $test = $trace[1]['function'];

        if (empty($token)) {
            $tokenResponse = Http::timeout(7)
                ->withHeaders([
                    'X-Operator' => $config['operator'],
                    'X-key' => $config['key'],
                ])
                ->get($config['api_domain'] . '/token');
            if (!$tokenResponse->ok()) {
                $this->log->info($test, ['params' => $params, 'response' => $tokenResponse,'url'=>$url,'header'=>[
                    'X-Operator' => $config['operator'],
                    'X-key' => $config['key'],
                ]]);
                throw new GameException(trans('system_busy', [], 'message'));
            }
            $data = $tokenResponse->json();
            if (empty($data['data']['token'])) {
                $this->log->info($test, ['params' => $params, 'response' => $tokenResponse,'url'=>$url,'header'=>[
                    'X-Operator' => $config['operator'],
                    'X-key' => $config['key'],
                ]]);
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
            $this->log->info($test, ['params' => $params, 'response' => $response,'url'=>$url,'header'=>[
                'X-Token' => $token,
            ]]);
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
        // 使用 $this->config 以支持限红组配置
        $params = [
            'Operator' => $this->config['operator'],
            'Key' => $this->config['key'],
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
        $this->checkPlayer();
        $params = [
            'key' => $this->getGameKey($game->game_extend->code),
            'type' => 'mobile',
            'locale' => $this->lang[$lang],
        ];

        $req = $this->doCurl($this->createUrl('gameLogin'), $params, 'get');
        if (empty($req['data']['url'])) {
            throw new GameException(trans('system_busy', [], 'message'));
        }
        return $req['data']['url'] . '&uniwebview=1&view_mode=portrait';
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
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return ATGGameController::API_CODE_INSUFFICIENT_BALANCE;
    }

    /**
     * 查询余额
     * @return mixed
     * @deprecated 已迁移到 Redis Lua 原子脚本，此方法不再使用
     */
    public function balance(): mixed
    {
        // 使用单一钱包，余额统一管理
        return \app\service\WalletService::getBalance($this->player->id);
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

    /**
     * 解密
     * 由于解密前不知道玩家信息，需要尝试所有可能的配置进行解密
     * @return mixed
     */
    public function decrypt($data)
    {
        $token = $data['token'];
        $result = null;
        // 准备所有可能的配置
        $configsToTry = [];

        // 1. 先尝试当前实例的配置（可能是默认配置或已有的限红组配置）
        $configsToTry[] = [
            'operator' => $this->config['operator'],
            'key' => $this->config['key'],
            'providerId' => $this->config['providerId'],
            'api_domain' => $this->config['api_domain'],
            'source' => 'current',
        ];

        // 2. 获取所有启用的限红组配置（✅ 缓存优化：30分钟）
        $cacheKey = 'platform_limit_configs:' . $this->platform->id;
        $limitGroupConfigs = \support\Cache::get($cacheKey);

        if ($limitGroupConfigs === null) {
            $limitGroupConfigs = PlatformLimitGroupConfig::query()
                ->where('platform_id', $this->platform->id)
                ->where('status', 1)
                ->get();
            \support\Cache::set($cacheKey, $limitGroupConfigs, 1800);
        }

        foreach ($limitGroupConfigs as $limitGroupConfig) {
            if (!empty($limitGroupConfig->config_data)) {
                $configData = $limitGroupConfig->config_data;
                $operator = $configData['operator'] ?? null;
                $key = $configData['key'] ?? $configData['operator_key'] ?? null;

                if ($operator && $key) {
                    // 避免重复添加相同的配置
                    $isDuplicate = false;
                    foreach ($configsToTry as $existing) {
                        if ($existing['operator'] === $operator && $existing['key'] === $key) {
                            $isDuplicate = true;
                            break;
                        }
                    }

                    if (!$isDuplicate) {
                        $configsToTry[] = [
                            'operator' => $operator,
                            'key' => $key,
                            'providerId' => $configData['providerId'] ?? $configData['provider_id'] ?? null,
                            'limit_group_id' => $limitGroupConfig->limit_group_id,
                            'source' => 'limit_group',
                        ];
                    }
                }
            }
        }

        // 逐个尝试配置进行解密
        foreach ($configsToTry as $index => $config) {
            $key = $config['key'];
            $iv = $config['operator'];

            // token验证
            if ($token !== md5($iv . $data['timestamp'] . $data['data'])) {
                continue; // token不匹配，尝试下一个配置
            }

            $key2 = strlen($key) > 16 ? substr($key, 0, 16) : str_pad($key, 16, '0');
            $iv2 = strlen($iv) > 16 ? substr($iv, 0, 16) : str_pad($iv, 16, '0');

            // 將 base64 字符串轉換為二進制數據
            $crypted = base64_decode($data['data']);

            // 使用 openssl_decrypt 進行解密
            $decode = openssl_decrypt($crypted, 'AES-128-CBC', $key2, OPENSSL_RAW_DATA, $iv2);
            $decryptResult = json_decode($decode, true);

            if (!empty($decryptResult)) {
                // 解密成功
                $result = $decryptResult;
                break;
            }
        }

        // 所有配置都尝试失败
        if (empty($result)) {
            return $this->error = ATGGameController::API_CODE_DECRYPT_ERROR;
        }

        // 从解密数据中获取玩家
        $player = Player::query()->where('uuid', $result['username'])->first();
        if (!$player) {
            return $this->error = ATGGameController::API_CODE_PLAYER_NOT_EXIST;
        }

        $this->player = $player;

        // 重新获取玩家的限红组配置（参考RSG逻辑）
        $playerLimitConfig = $this->getLimitRedConfig();

        // 玩家必须有限红组配置
        if (!$playerLimitConfig || !isset($playerLimitConfig['operator']) || !isset($playerLimitConfig['key'])) {
            return $this->error = ATGGameController::API_CODE_FAIL;
        }

        // 验证配置完整性（必须包含所有字段）
        $requiredFields = ['operator', 'key', 'providerId'];
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (empty($playerLimitConfig[$field])) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            return $this->error = ATGGameController::API_CODE_FAIL;
        }

        // 获取配置文件中的 api_domain（固定使用配置文件，不使用数据库）
        $configFile = config('game_platform.ATG');

        $this->config = [
            'api_domain' => $configFile['api_domain'],  // 固定使用配置文件的 api_domain
            'operator' => $playerLimitConfig['operator'],
            'providerId' => $playerLimitConfig['providerId'],
            'key' => $playerLimitConfig['key'],
        ];

        $this->apiDomain = $this->config['api_domain'];
        $this->providerId = $this->config['providerId'];

        return $result;
    }

}
