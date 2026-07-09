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

    protected $apiDomain;
    protected $providerId;

    protected $path = [
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

    protected $lang = [
        'zh-CN' => 'zh-cn',
        'zh-TW' => 'zh-tw',
        'jp' => 'jp',
        'en' => 'en',
    ];

    protected array $config = [];


    public ?\Monolog\Logger $log = null;

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        // ✅ 使用缓存避免重复查询（1小时过期）
        $cacheKey = 'game_platform:ATG';
        $this->platform = \support\Cache::get($cacheKey);

        if (!$this->platform) {
            $this->platform = GamePlatform::query()->where('code', 'ATG')->first();
            if ($this->platform) {
                \support\Cache::set($cacheKey, $this->platform, 3600);
            }
        }

        $this->player = $player;
        $this->log = Log::channel('atg_server');

        $config = config('game_platform.ATG');

        // 验证配置文件完整性
        $requiredConfigFields = ['api_domain', 'operator', 'key', 'providerId'];
        $missingConfigFields = [];
        foreach ($requiredConfigFields as $field) {
            if (empty($config[$field])) {
                $missingConfigFields[] = $field;
            }
        }

        if (!empty($missingConfigFields)) {
            $this->log->error('ATG 配置文件不完整，请检查 .env 文件', [
                'missing_fields' => $missingConfigFields,
                'config' => $config,
            ]);
            throw new GameException('ATG 平台配置缺失，请检查 .env 文件中的 ATG_* 配置项: ' . implode(', ', $missingConfigFields));
        }

        // 如果有玩家，优先从数据库获取限红组配置，没有则 fallback 到配置文件
        if ($player) {
            $limitConfig = $this->getLimitRedConfig();

            if (!$limitConfig) {
                // 如果数据库没有配置限红组，fallback 到配置文件
                $this->config = $config;
            } else {
                // 验证配置完整性（必须包含所有字段）
                $requiredFields = ['operator', 'key', 'providerId'];
                $missingFields = [];
                foreach ($requiredFields as $field) {
                    if (empty($limitConfig[$field])) {
                        $missingFields[] = $field;
                    }
                }

                if (!empty($missingFields)) {
                    throw new GameException(trans('platform_config_incomplete', ['fields' => implode(', ', $missingFields)], 'admin_game_platform'));
                }

                // 使用数据库的限红组配置
                $this->config = [
                    'api_domain' => $config['api_domain'],
                    'operator' => $limitConfig['operator'],
                    'providerId' => $limitConfig['providerId'],
                    'key' => $limitConfig['key'],
                ];
            }

        } else {
            // player=null时（控制器初始化或公共API调用），使用配置文件
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
    protected function getLimitRedConfig(): ?array
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
            $this->log->error('ATG 平台配置错误：operator 为空', [
                'config' => $this->config,
                'player_id' => $this->player->id ?? null,
            ]);
            throw new GameException('游戏平台配置错误：运营商账号（operator）未配置');
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
        if (empty($token)) {
            $tokenUrl = $config['api_domain'] . '/token';

            $this->log->info('ATG 获取Token - 请求报文', [
                'url' => $tokenUrl,
                'headers' => [
                    'X-Operator' => $config['operator'],
                    'X-key' => substr($config['key'], 0, 10) . '...' // 只显示前10位，保护密钥
                ],
            ]);

            $tokenResponse = Http::timeout(7)
                ->withHeaders([
                    'X-Operator' => $config['operator'],
                    'X-key' => $config['key'],
                ])
                ->get($tokenUrl);

            $this->log->info('ATG 获取Token - 响应报文', [
                'url' => $tokenUrl,
                'status_code' => $tokenResponse->status(),
                'body' => $tokenResponse->body(),
            ]);

            if (!$tokenResponse->ok()) {
                $this->log->error('ATG 获取Token失败 - HTTP错误', [
                    'url' => $tokenUrl,
                    'status_code' => $tokenResponse->status(),
                    'response_body' => $tokenResponse->body(),
                    'operator' => $config['operator'],
                ]);
                throw new GameException('ATG获取Token失败: HTTP ' . $tokenResponse->status());
            }

            $data = $tokenResponse->json();
            if (empty($data['data']['token'])) {
                $this->log->error('ATG 获取Token失败 - 响应无token', [
                    'url' => $tokenUrl,
                    'response' => $data,
                    'operator' => $config['operator'],
                ]);
                throw new GameException('ATG获取Token失败: 响应中没有token');
            }
            $token = $data['data']['token'];
            Cache::set($cacheKey, $token, 4 * 60);

            $this->log->info('ATG Token缓存成功', [
                'operator' => $config['operator'],
                'cache_key' => $cacheKey,
            ]);
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

            // 记录详细错误信息
            $this->log->error('ATG API请求失败', [
                'url' => $url,
                'method' => strtoupper($mode),
                'status_code' => $response->status(),
                'params' => $params,
                'response' => $res,
                'operator' => $config['operator'],
            ]);

            if ($res['status'] == '400' && $res['message'] == 'user exists') {
                return [];
            }
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
     * 尝试快速提取username（用于username→operator映射）
     *
     * @param array $data 请求数据
     * @return string|null 提取到的username，失败返回null
     */
    /**
     * 尝试用指定配置解密
     *
     * @param array $config 配置数组（必须包含 operator, key）
     * @param string $token 令牌
     * @param string $timestampStr 时间戳字符串
     * @param string $dataStr 加密数据字符串
     * @param string $crypted base64解码后的加密数据
     * @return array|null 解密成功返回数据数组，失败返回null
     */
    private function tryDecrypt(array $config, string $token, string $timestampStr, string $dataStr, string $crypted): ?array
    {
        $operator = $config['operator'] ?? null;
        $key = $config['key'] ?? null;

        if (!$operator || !$key) {
            return null;
        }

        // ⚡ 快速验证：先做最快的token验证，快速排除不匹配的配置
        // token = md5(operator + timestamp + data)
        if ($token !== md5($operator . $timestampStr . $dataStr)) {
            return null; // token不匹配
        }

        // Token匹配，继续解密
        $key2 = strlen($key) > 16 ? substr($key, 0, 16) : str_pad($key, 16, '0');
        $iv2 = strlen($operator) > 16 ? substr($operator, 0, 16) : str_pad($operator, 16, '0');

        // 使用 openssl_decrypt 进行解密
        $decode = openssl_decrypt($crypted, 'AES-128-CBC', $key2, OPENSSL_RAW_DATA, $iv2);

        if ($decode === false) {
            return null; // 解密失败
        }

        $decryptResult = json_decode($decode, true);

        if (!empty($decryptResult) && isset($decryptResult['username'])) {
            return $decryptResult; // 解密成功
        }

        return null;
    }

    /**
     * 保存username到operator的映射缓存
     *
     * @param string $username 玩家username
     * @param string $operator 对应的operator
     */
    private function cacheUsernameOperatorMapping(string $username, string $operator): void
    {
        if (empty($username) || empty($operator)) {
            return;
        }

        $cacheKey = "atg:player_operator:{$username}";
        // 缓存24小时（玩家切换限红组的频率很低）
        \support\Cache::set($cacheKey, $operator, 86400);
    }

    /**
     * 记录成功的operator使用（用于统计和优化顺序）
     *
     * @param string $operator 成功的operator
     */
    private function recordOperatorUsage(string $operator): void
    {
        if (empty($operator)) {
            return;
        }

        $statsKey = "atg:operator_stats:{$operator}";
        $currentCount = (int)\support\Cache::get($statsKey, 0);
        \support\Cache::set($statsKey, $currentCount + 1, 86400 * 7);  // 保留7天统计
    }

    /**
     * 解密
     * 由于解密前不知道玩家信息，需要尝试所有可能的配置进行解密
     * @return mixed
     */
    public function decrypt($data)
    {
        $token = $data['token'];
        $timestamp = $data['timestamp'] ?? 0;

        // ✅ 优化1: timestamp过期验证（根据API文档要求）
        if (!$timestamp || time() >= $timestamp) {
            return $this->error = ATGGameController::API_CODE_DECRYPT_ERROR;
        }

        // ✅ 优化2: 基于token缓存解密结果（利用重试机制token相同的特性）
        // 三方重试3次使用相同token，缓存可避免重复解密
        $tokenCacheKey = 'atg:decrypt:' . md5($token);
        $cachedResult = \support\Cache::get($tokenCacheKey);

        if ($cachedResult !== null) {
            // 恢复玩家和配置信息
            $this->player = Player::query()->find($cachedResult['player_id']);
            if ($this->player) {
                $this->config = $cachedResult['config'];
                $this->apiDomain = $this->config['api_domain'];
                $this->providerId = $this->config['providerId'];
                return $cachedResult['decrypt_data'];
            }
        }

        // ✅ 优化3: 提前计算固定值（减少循环内重复计算）
        $timestampStr = $data['timestamp'];
        $dataStr = $data['data'];
        $crypted = base64_decode($dataStr); // base64解码只需一次

        // 初始化变量
        $result = null;
        $successConfig = null;
        $usedOperator = null;

        // ✅ 优化4: 快速路径 - 先用 .env 配置尝试（最常见的配置，避免查询数据库）
        $result = $this->tryDecrypt($this->config, $token, $timestampStr, $dataStr, $crypted);
        if ($result !== null) {
            // ✅ .env 配置解密成功！直接使用，跳过数据库查询
            $successConfig = $this->config;
            $usedOperator = $this->config['operator'];
        } else {
            // ✅ 优化5: 慢速路径 - .env 失败，查询数据库所有限红组配置
            $cacheKey = 'platform_limit_configs:' . $this->platform->id;
            $limitGroupConfigs = \support\Cache::get($cacheKey);

            if ($limitGroupConfigs === null) {
                $limitGroupConfigs = PlatformLimitGroupConfig::query()
                    ->where('platform_id', $this->platform->id)
                    ->where('status', 1)
                    ->get();
                \support\Cache::set($cacheKey, $limitGroupConfigs, 1800);
            }

            // 遍历所有限红组配置尝试解密
            foreach ($limitGroupConfigs as $limitGroupConfig) {
                if (empty($limitGroupConfig->config_data)) {
                    continue;
                }

                $configData = $limitGroupConfig->config_data;
                $operator = $configData['operator'] ?? null;
                $key = $configData['key'] ?? $configData['operator_key'] ?? null;
                $providerId = $configData['providerId'] ?? $configData['provider_id'] ?? null;

                if (!$operator || !$key) {
                    continue;
                }

                $config = [
                    'operator' => $operator,
                    'key' => $key,
                    'providerId' => $providerId,
                    'api_domain' => $this->config['api_domain'], // api_domain 固定使用 .env
                ];

                $result = $this->tryDecrypt($config, $token, $timestampStr, $dataStr, $crypted);
                if ($result !== null) {
                    // 解密成功
                    $successConfig = $config;
                    $usedOperator = $operator;
                    break;
                }
            }
        }

        // 所有配置都尝试失败
        if ($result === null) {
            return $this->error = ATGGameController::API_CODE_DECRYPT_ERROR;
        }

        // 从解密数据中获取玩家（使用缓存减少数据库查询）
        $player = \app\service\PlayerCacheService::getByUuid($result['username']);
        if (!$player) {
            return $this->error = ATGGameController::API_CODE_PLAYER_NOT_EXIST;
        }

        $this->player = $player;

        // ✅ 直接使用解密成功的配置（不要重新查询）
        // 原因：解密成功 = 配置正确，游戏平台回调基于此配置生成，响应也必须用同一配置
        $this->config = $successConfig;
        $this->apiDomain = $this->config['api_domain'];
        $this->providerId = $this->config['providerId'];

        // ✅ 优化4: 解密成功后缓存结果（用于重试请求）
        // 缓存时间设置为timestamp的剩余有效期，最多60秒
        $cacheTTL = min($timestamp - time(), 60);
        if ($cacheTTL > 0) {
            \support\Cache::set($tokenCacheKey, [
                'player_id' => $player->id,
                'config' => $this->config,
                'decrypt_data' => $result,
            ], $cacheTTL);
        }

        // ✅ 优化5: 记录operator使用统计（用于动态优化配置顺序）
        if (!empty($usedOperator)) {
            $this->recordOperatorUsage($usedOperator);
        }

        // ✅ 新优化6: 保存username→operator映射缓存（加速后续请求）
        if (!empty($result['username']) && !empty($this->config['operator'])) {
            $this->cacheUsernameOperatorMapping($result['username'], $this->config['operator']);
        }

        return $result;
    }

}
