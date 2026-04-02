<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\PlatformLimitGroupConfig;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\traits\AsyncGameRecordTrait;
use app\wallet\controller\game\ATGGameController;
use app\wallet\controller\game\RsgGameController;
use Carbon\Carbon;
use Exception;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class ATGServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    use AsyncGameRecordTrait;
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
                $this->log->error('❌ ATG未配置限红组', [
                    'player_id' => $player->id,
                    'store_admin_id' => $player->store_admin_id,
                ]);
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
                $this->log->error('❌ ATG限红组配置不完整', [
                    'player_id' => $player->id,
                    'missing_fields' => implode(', ', $missingFields),
                    'config' => $limitConfig,
                ]);
                throw new GameException('游戏平台配置不完整: 缺少 ' . implode(', ', $missingFields));
            }

            $this->config = [
                'api_domain' => $config['api_domain'],
                'operator' => $limitConfig['operator'],
                'providerId' => $limitConfig['providerId'],
                'key' => $limitConfig['key'],
            ];

            $this->log->info('✅ ATG使用数据库配置', [
                'player_id' => $player->id,
                'store_admin_id' => $player->store_admin_id,
                'operator' => $limitConfig['operator'],
                'providerId' => $this->config['providerId'],
            ]);
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
            $this->log->error('❌ ATG检查玩家失败：营运账号为空', [
                'player_id' => $this->player->id,
                'config' => $this->config,
            ]);
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

        // 未注册，调用ATG API注册玩家
        $this->log->info('🆕 ATG玩家在营运账号下首次注册', [
            'player_id' => $this->player->id,
            'player_uuid' => $this->player->uuid,
            'operator' => $operator,
            'store_admin_id' => $this->player->store_admin_id,
        ]);

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

        $this->log->info('✅ ATG玩家注册成功', [
            'player_id' => $this->player->id,
            'operator' => $operator,
            'record_id' => $playerGamePlatform->id,
        ]);

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
        if (empty($token) && $test !== 'doCurl') {
            $this->log->info('🔑 ATG获取Token', [
                'method' => $test,
                'operator' => $config['operator'],
                'api_domain' => $config['api_domain'],
            ]);
        }
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

        // 记录进入游戏时的配置参数
        $this->log->info('🎮 ATG玩家进入游戏', [
            'player_id' => $this->player->id,
            'player_uuid' => $this->player->uuid,
            'store_admin_id' => $this->player->store_admin_id,
            'game_code' => $game->game_extend->code,
            'game_name' => $game->name,
            'config' => [
                'operator' => $this->config['operator'],
                'providerId' => $this->config['providerId'],
                'api_domain' => $this->config['api_domain'],
            ],
        ]);

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
     * 下注
     * @return mixed
     */
    public function bet($data)
    {
        $player = $this->player;
        $bet = $data['amount'];
        $orderNo = $data['betId'];

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return $this->error;
        }

        // ✅ Redis预检查幂等性（在事务外，避免不必要的数据库锁）
        $betKey = "atg:bet:lock:{$orderNo}";
        $isLocked = \support\Redis::set($betKey, 1, ['NX', 'EX' => 300]);
        if (!$isLocked) {
            // 重复订单，直接返回当前余额
            return $this->error = ATGGameController::API_CODE_DUPLICATE_ORDER;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        if ($machineWallet->money < $bet) {
            return $this->error = ATGGameController::API_CODE_INSUFFICIENT_BALANCE;
        }

        $beforeBalance = $machineWallet->money;

        // ✅ 同步扣减余额（触发 updated 事件，自动更新 Redis 缓存）
        $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
        $machineWallet->save();

        // ⚡ 异步创建下注记录（不阻塞API响应）
        $this->asyncCreateBetRecord(
            playerId: $this->player->id,
            platformId: $this->platform->id,
            gameCode: $data['gameCode'],
            orderNo: $orderNo,
            bet: $bet,
            originalData: $data,
            orderTime: Carbon::now()->toDateTimeString()
        );

        // ✅ 立即从缓存读取余额
        $balance = \app\service\WalletService::getBalance($player->id);
        return ['balanceOld' => $beforeBalance, 'balance' => $balance];
    }


    /**
     * 打鱼机退款
     * @param $data
     * @return mixed
     */
    public function refund($data): mixed
    {
        //TODO 后期如果接入打鱼或者百家乐可能需要优化
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['betId'])->first();
        if ($record) {
            return $this->error = RsgGameController::API_CODE_DUPLICATE_TRANSACTION;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        //退款金额
        $amount = $data['amount'];
        $beforeGameAmount = $machineWallet->money;
        $machineWallet->money = bcadd($machineWallet->money, $amount, 2);
        $machineWallet->save();

        $player = $this->player;

        //用户交易记录  现在单一钱包没有转账的说法 暂不记录转账记录
        $playerDeliveryRecord = new PlayerDeliveryRecord;
        $playerDeliveryRecord->player_id = $player->id;
        $playerDeliveryRecord->department_id = $player->department_id;
        $playerDeliveryRecord->target = '';
        $playerDeliveryRecord->target_id = 0;
        $playerDeliveryRecord->platform_id = $this->platform->id;
        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_REFUND;
        $playerDeliveryRecord->source = 'player_refund';
        $playerDeliveryRecord->amount = $amount;
        $playerDeliveryRecord->amount_before = $beforeGameAmount;
        $playerDeliveryRecord->amount_after = $machineWallet->money;
        $playerDeliveryRecord->tradeno = $record->order_no ?? '';
        $playerDeliveryRecord->remark = '遊戲退款';
        $playerDeliveryRecord->user_id = 0;
        $playerDeliveryRecord->user_name = '';
        $playerDeliveryRecord->save();

        return ['balanceOld' => $beforeGameAmount, 'balance' => $machineWallet->money];
    }


    /**
     * 取消单
     * @return mixed
     */
    public function cancelBet($data)
    {
        // TODO: Implement cancelBet() method.
    }

    /**
     * 结算（异步优化版）
     * @return mixed
     */
    public function betResulet($data)
    {
        /** @var PlayGameRecord $record */
        // ✅ 加锁查询，防止并发重复派彩
        $record = PlayGameRecord::query()
            ->where('order_no', $data['betId'])
            ->lockForUpdate()
            ->first();

        if (!$record) {
            return $this->error = ATGGameController::API_CODE_ORDER_NOT_EXIST;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            return $this->error = ATGGameController::API_CODE_ORDER_SETTLED;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        $beforeGameAmount = $machineWallet->money;

        // ✅ 同步增加余额（有金额时，触发 updated 事件，自动更新 Redis 缓存）
        if ($data['amount'] > 0) {
            $machineWallet->money = bcadd($machineWallet->money, $data['amount'], 2);
            $machineWallet->save();
        }

        // ⚡ 异步更新结算记录（不阻塞API响应）
        $this->asyncUpdateSettleRecord(
            orderNo: $data['betId'],
            win: $data['amount'],
            diff: bcsub($data['amount'], $record->bet, 2)
        );

        //彩金记录
        Client::send('game-lottery', [
            'player_id' => $this->player->id,
            'bet' => $record->bet,
            'play_game_record_id' => $record->id
        ]);

        // ✅ 立即从缓存读取余额
        $balance = \app\service\WalletService::getBalance($this->player->id);
        return ['balanceOld' => $beforeGameAmount, 'balance' => $balance];
    }

    /**
     * 重新结算
     * @return mixed
     */
    public function reBetResulet($data)
    {
        // TODO: Implement reBetResulet() method.
    }

    /**
     * 送礼
     * @return mixed
     */
    public function gift($data)
    {
        // TODO: Implement gift() method.
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
        $usedConfig = null;

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

        // 2. 获取所有启用的ATG限红组配置
        $limitGroupConfigs = PlatformLimitGroupConfig::query()
            ->where('platform_id', $this->platform->id)
            ->where('status', 1)
            ->get();

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
                $usedConfig = $config;
                break;
            }
        }

        // 所有配置都尝试失败
        if (empty($result)) {
            $this->log->error('❌ ATG解密失败', [
                'tried_configs' => count($configsToTry),
                'token_prefix' => substr($token, 0, 20) . '...',
            ]);
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
            $this->log->error('❌ ATG玩家未配置限红组', [
                'player_id' => $player->id,
                'store_admin_id' => $player->store_admin_id,
            ]);
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
            $this->log->error('❌ ATG玩家限红组配置不完整', [
                'player_id' => $player->id,
                'missing_fields' => implode(', ', $missingFields),
                'config' => $playerLimitConfig,
            ]);
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

        // 验证解密使用的配置是否与玩家限红组匹配
        if ($usedConfig['operator'] !== $playerLimitConfig['operator']) {
            $this->log->warning('⚠️ ATG解密配置与玩家限红组不匹配', [
                'player_id' => $player->id,
                'store_admin_id' => $player->store_admin_id,
                'decrypt_operator' => $usedConfig['operator'],
                'expected_operator' => $playerLimitConfig['operator'],
            ]);
        } else {
            $this->log->info('✅ ATG应用玩家数据库配置', [
                'player_id' => $player->id,
                'store_admin_id' => $player->store_admin_id,
                'operator' => $playerLimitConfig['operator'],
            ]);
        }

        $this->apiDomain = $this->config['api_domain'];
        $this->providerId = $this->config['providerId'];

        return $result;
    }

}
