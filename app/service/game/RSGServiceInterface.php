<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\traits\AsyncGameRecordTrait;
use app\wallet\controller\game\RsgGameController;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class RSGServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    use AsyncGameRecordTrait;
    use LimitGroupTrait;

    public $method = 'POST';
    public $successCode = '0';
    public $failCode = [
        '1001' => '執行失敗',
        '1002' => '系統維護中',
        '2001' => '無效的參數',
        '2002' => '解密失敗',
        '3005' => '餘額不足',
        '3006' => '找不到交易結果',
        '3008' => '此玩家帳戶不存在',
        '3010' => '此玩家帳戶已存在',
        '3011' => '系統商權限不足',
        '3012' => '遊戲權限不足',
        '3014' => '重複的 TransactionID',
        '3015' => '時間不在允許的範圍內',
        '3016' => '拒絕提點，玩家正在遊戲中',
        '3018' => '此幣別不被允許',
    ];

    private $apiDomain;
    private $systemCode;
    private $lang = [
        'zh-CN' => 'zh-TW',
        'zh-TW' => 'zh-TW',
        'en' => 'en-US',
        'th' => 'th-TH',
        'vi' => 'vi-VN',
        'jp' => 'ja-JP',
        'kr_ko' => 'ko-KR',
        'my' => 'en-MY',
        'id' => 'id-ID',
    ];
    private $path = [
        'createPlayer' => '/SingleWallet/Player/CreatePlayer',
        'userLogout' => '/SingleWallet/Player/Kickout',
        'getGameHistories' => '/SingleWallet/History/GetGameDetail',
        'lobbyLogin' => '/SingleWallet/Player/GetLobbyURLToken',
        'getGameList' => '/SingleWallet/Game/GameList',
        'gameLogin' => '/SingleWallet/Player/GetURLToken',
        'replay' => '/SingleWallet/Player/GetSlotGameRecordURLToken',
    ];

    private $currency = [
        'TWD' => 'NT',
        'CNY' => 'NT',
        'JPY' => 'JPY',
        'USD' => 'USA',
    ];

    public ?\Monolog\Logger $log = null;

    private array $config;

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.RSG');
        $this->config = $config;
        $this->apiDomain = $config['api_domain'];
        $this->systemCode = $config['SystemCode'];
        $this->platform = GamePlatform::query()->where('code', 'RSG')->first();
        $this->player = $player;
        $this->log = Log::channel('rsg_server');
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

    /**
     * 注册玩家
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function createPlayer(): array
    {
        $params = [
            'SystemCode' => $this->systemCode,
            'WebId' => $this->getWebId(),
            'UserId' => $this->player->uuid,
            'Currency' => $this->currency[$this->player->currency],
        ];
        $res = $this->doCurl($this->createUrl('createPlayer'), $params);
        if ($res['ErrorCode'] != $this->successCode) {
            $this->log->info('createPlayer', ['params' => $params, 'response' => $res]);
            if ($res['ErrorCode'] == '3010') {
                return $params;
            }
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $params;
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
        $config = config('game_platform.RSG');
        $encryptData = openssl_encrypt(json_encode($params), 'DES-CBC', $config['DesKey'], OPENSSL_RAW_DATA,
            $config['DesIV']);
        $reqBase64 = base64_encode($encryptData);
        $timestamp = time();
        $response = Http::timeout(7)
            ->withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'X-API-ClientID' => $config['app_id'],
                'X-API-Signature' => md5($config['app_id'] . $config['app_secret'] . $timestamp . $reqBase64),
                'X-API-Timestamp' => $timestamp
            ])
            ->withBody('Msg=' . $reqBase64, 'application/json')
            ->post($url);

        if (!$response->ok()) {
            Log::channel('rsg_server')->error($url, ['config' => $config, 'params' => $params, 'response' => $response->body()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }
        $data = openssl_decrypt(base64_decode($response->body()), 'DES-CBC', $config['DesKey'], OPENSSL_RAW_DATA,
            $config['DesIV']);
        if (empty($data)) {
            throw new GameException(trans('system_busy', [], 'message'));
        }

        return json_decode($data, true);
    }

    /**
     * 生成请求url
     * @param $method
     * @return string
     */
    public function createUrl($method): string
    {
        return $this->apiDomain . $this->path[$method];
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
            'SystemCode' => $this->systemCode,
            'WebId' => $this->getWebId(),
            'UserId' => $this->player->uuid,
            'UserName' => $this->player->uuid,
            'Currency' => $this->currency[$this->player->currency],
            'Language' => $this->lang[$data['lang']],
        ];
        $res = $this->doCurl($this->createUrl('lobbyLogin'), $params);
        $this->log->info('lobbyLogin', ['params' => $params, $res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $res['Data']['URL'] ?? '';
    }

    /**
     * 取得區間內遊戲紀錄
     * @return array
     * @throws Exception
     */
    public function handleGameHistories(): array
    {
        $slotData = $this->getGameHistories(1);
        $fishData = $this->getGameHistories(2);
        $list = [];
        if (!empty($slotData)) {
            $list = array_merge($list, $this->processPlayerData($slotData));
        }
        if (!empty($fishData)) {
            $list = array_merge($list, $this->processPlayerData($fishData));
        }

        return $list;
    }

    /**
     * 取得區間內遊戲紀錄
     * @param int $gameType
     * @return array
     * @throws GameException
     */
    public function getGameHistories(int $gameType): array
    {
        $params = [
            'SystemCode' => $this->systemCode,
            'WebId' => $this->getWebId(),
            'GameType' => $gameType,
            'TimeStart' => date('Y-m-d H:i', strtotime('-7 minutes')),
            'TimeEnd' => date('Y-m-d H:i', strtotime('-3 minutes')),
        ];
        $res = $this->doCurl($this->createUrl('getGameHistories'), $params);
        $this->log->info('getGameHistories', [$res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $res['Data']['GameDetail'] ?? [];
    }

    /**
     * @param $data
     * @return array
     */
    public function processPlayerData($data): array
    {
        $list = [];
        foreach ($data as $item) {
            /** @var Player $player */
            $player = Player::withTrashed()->with('recommend_promoter')->where('uuid', $item['UserId'])->first();
            if (!empty($player)) {
                $list[] = [
                    'player_id' => $player->id,
                    'parent_player_id' => $player->recommend_id ?? 0,
                    'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                    'player_uuid' => $player->uuid,
                    'platform_id' => $this->platform->id,
                    'game_code' => $item['GameId'],
                    'department_id' => $player->department_id,
                    'bet' => $item['BetAmt'],
                    'win' => $item['WinAmt'],
                    'diff' => ($item['WinAmt']) - ($item['BetAmt']),
                    'reward' => $item['JackpotContribution'],
                    'order_no' => $item['SequenNumber'],
                    'original_data' => json_encode($item),
                    'platform_action_at' => $item['PlayTime'],
                ];
            }
        }

        return $list;
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $params = [
            'SystemCode' => $this->systemCode,
        ];
        $res = $this->doCurl($this->createUrl('getGameList'), $params);
        $this->log->info('getGameList', [$res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }
        $insertData = [];
        $langKey = Str::replace('-', '_', $this->lang[$lang]);
        if (!empty($res['Data']['GameList'])) {
            foreach ($res['Data']['GameList'] as $item) {
                $insertData[] = [
                    'game_id' => $item['GameId'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => 7,
                    'name' => $item['GameName'][$langKey],
                    'code' => $item['GameId'],
                    'logo' => $item['GamePicUrl'],
                    'status' => $item['GameStatus'] == 2 ? 0 : 1,
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
     * 获取玩家的限红配置
     * @return array|null 返回限红配置数组，包含MinBetAmount和MaxBetAmount，如果没有配置则返回null
     */
    private function getLimitRedConfig(): ?array
    {
        // 使用 Trait 中的通用方法获取限红组配置
        $limitGroupConfig = $this->getLimitGroupConfig('rsg_server');

        // 如果没有配置数据，返回null
        if (!$this->hasLimitGroupConfigData($limitGroupConfig)) {
            return null;
        }

        $configData = $limitGroupConfig->config_data;

        // 构建限红参数（RSG支持MinBetAmount和MaxBetAmount）
        $limitConfig = [];

        if (isset($configData['min_bet_amount']) && $configData['min_bet_amount'] > 0) {
            $limitConfig['MinBetAmount'] = $configData['min_bet_amount'];
        }

        if (isset($configData['max_bet_amount']) && $configData['max_bet_amount'] > 0) {
            $limitConfig['MaxBetAmount'] = $configData['max_bet_amount'];
        }

        return !empty($limitConfig) ? $limitConfig : null;
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
            'SystemCode' => $this->systemCode,
            'WebId' => $this->getWebId(),
            'UserId' => $this->player->uuid,
            'UserName' => $this->player->uuid,
            'GameId' => (int)$game->game_extend->code,
            'Currency' => $this->currency[$this->player->currency],
            'Language' => $this->lang[$lang],
            'ExitAction' => '',
        ];

        // 获取并应用限红配置
        $limitConfig = $this->getLimitRedConfig();
        if ($limitConfig) {
            $params = array_merge($params, $limitConfig);
            $this->log->info('RSG应用限红配置', [
                'player_id' => $this->player->id,
                'store_admin_id' => $this->player->store_admin_id,
                'limit_config' => $limitConfig
            ]);
        }

        $res = $this->doCurl($this->createUrl('gameLogin'), $params);
        $this->log->info('gameLogin', [$res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $res['Data']['URL'] ?? '';
    }

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }

    public function userLogout()
    {
        // TODO: Implement userLogout() method.
    }

    /**
     * 播放地址
     * @param array $data
     * @return mixed|string
     * @throws GameException
     */
    public function replay(array $data = [])
    {
        $origin = json_decode($data['original_data'], true);
        $params = [
            'SystemCode' => $this->systemCode,
            'WebId' => $origin['WebId'],
            'UserId' => $origin['UserId'],
            'Currency' => $origin['Currency'],
            'GameId' => $origin['GameId'],
            'SequenNumber' => $origin['SequenNumber'],
            'Language' => 'zh-TW',
        ];
        $this->log->info('replay',$params);
        $res = $this->doCurl($this->createUrl('replay'), $params);
        $this->log->info('replay', [$res]);
        if ($res['ErrorCode'] != $this->successCode) {
            throw new GameException($this->failCode[$res['ErrorCode']], 0);
        }

        return $res['Data']['URL'] ?? '';
    }

    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return RsgGameController::API_CODE_INSUFFICIENT_BALANCE;
    }

    public function bet($data)
    {
        $orderNo = $data['SequenNumber'];
        $bet = $data['Amount'];

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return $this->error;
        }

        // 🚀 优化 #1: Redis 预检查幂等性（避免不必要的数据库查询）
        $betKey = "rsg:bet:lock:{$orderNo}";
        $isLocked = \support\Redis::set($betKey, 1, ['NX', 'EX' => 300]); // 5分钟过期
        if (!$isLocked) {
            return $this->error = RsgGameController::API_CODE_DUPLICATE_ORDER;
        }

        try {
            // 🚀 优化 #2: 单次事务 + 原子操作
            $newBalance = \support\Db::transaction(function () use ($orderNo, $bet) {
                /** @var PlayerPlatformCash $machineWallet */
                $machineWallet = PlayerPlatformCash::query()
                    ->where('player_id', $this->player->id)
                    ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
                    ->lockForUpdate()
                    ->first();

                if ($machineWallet->money < $bet) {
                    throw new \RuntimeException('INSUFFICIENT_BALANCE');
                }

                // 🚀 使用原生 SQL 更新余额（避免 Model Event 开销）
                $newBalance = bcsub($machineWallet->money, $bet, 2);
                \support\Db::table('player_platform_cash')
                    ->where('id', $machineWallet->id)
                    ->update([
                        'money' => $newBalance,
                        'updated_at' => Carbon::now()
                    ]);

                return $newBalance;
            });

            // 🚀 优化 #3: 事务外异步创建记录 + 更新缓存
            $this->asyncCreateBetRecord(
                playerId: $this->player->id,
                platformId: $this->platform->id,
                gameCode: $data['GameId'],
                orderNo: $orderNo,
                bet: $bet,
                originalData: $data,
                orderTime: Carbon::now()->toDateTimeString()
            );

            // 立即更新 Redis 缓存
            \app\service\WalletService::updateCache(
                $this->player->id,
                PlayerPlatformCash::PLATFORM_SELF,
                $newBalance
            );

            return $newBalance;

        } catch (\RuntimeException $e) {
            \support\Redis::del($betKey);
            if ($e->getMessage() === 'INSUFFICIENT_BALANCE') {
                return $this->error = RsgGameController::API_CODE_INSUFFICIENT_BALANCE;
            }
            throw $e;
        } catch (\Throwable $e) {
            \support\Redis::del($betKey);
            $this->log->error('RSG下注异常（优化版）', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * 取消下注（异步优化版）
     * @param $data
     * @return float|mixed|string
     */
    public function cancelBet($data): mixed
    {
        $orderNo = $data['SequenNumber'];
        $refundAmount = $data['BetAmount'];

        try {
            // 🚀 优化 #1: 单次事务 + 合并锁查询
            $newBalance = \support\Db::transaction(function () use ($orderNo, $refundAmount) {
                // ✅ 统一锁顺序：wallet → record
                /** @var PlayerPlatformCash $machineWallet */
                $machineWallet = PlayerPlatformCash::query()
                    ->where('player_id', $this->player->id)
                    ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
                    ->lockForUpdate()
                    ->first();

                /** @var PlayGameRecord $record */
                $record = PlayGameRecord::query()
                    ->where('order_no', $orderNo)
                    ->lockForUpdate()
                    ->first();

                if (!$record) {
                    throw new \RuntimeException('ORDER_NOT_EXIST');
                }

                if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
                    throw new \RuntimeException('ORDER_CANCELLED');
                }

                // 🚀 使用原生 SQL 更新余额
                $newBalance = bcadd($machineWallet->money, $refundAmount, 2);
                \support\Db::table('player_platform_cash')
                    ->where('id', $machineWallet->id)
                    ->update([
                        'money' => $newBalance,
                        'updated_at' => Carbon::now()
                    ]);

                return $newBalance;
            });

            // 🚀 优化 #2: 事务外异步操作
            $this->asyncCancelBetRecord($orderNo);

            // 立即更新 Redis 缓存
            \app\service\WalletService::updateCache(
                $this->player->id,
                PlayerPlatformCash::PLATFORM_SELF,
                $newBalance
            );

            return $newBalance;

        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'ORDER_NOT_EXIST') {
                return $this->error = RsgGameController::API_CODE_ORDER_NOT_EXIST;
            }
            if ($e->getMessage() === 'ORDER_CANCELLED') {
                return $this->error = RsgGameController::API_CODE_ORDER_CANCELLED;
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->log->error('RSG取消下注异常（优化版）', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function betResulet($data)
    {
        $orderNo = $data['SequenNumber'];
        $winAmount = $data['Amount'];

        // 🚀 优化 #1: Redis 预检查幂等性（避免不必要的数据库查询）
        $settleKey = "rsg:settle:lock:{$orderNo}";
        $isLocked = \support\Redis::set($settleKey, 1, ['NX', 'EX' => 10]);
        if (!$isLocked) {
            return $this->error = RsgGameController::API_CODE_ORDER_SETTLED;
        }

        try {
            // 🚀 优化 #2: 单次事务 + 合并锁查询（从 2次锁 → 1次锁）
            $newBalance = \support\Db::transaction(function () use ($orderNo, $winAmount, $data) {
                // ✅ 先锁钱包（始终按相同顺序加锁，避免死锁）
                /** @var PlayerPlatformCash $machineWallet */
                $machineWallet = PlayerPlatformCash::query()
                    ->where('player_id', $this->player->id)
                    ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
                    ->lockForUpdate()
                    ->first();

                // ✅ 再锁游戏记录（统一锁顺序：wallet → record）
                /** @var PlayGameRecord $record */
                $record = PlayGameRecord::query()
                    ->where('order_no', $orderNo)
                    ->lockForUpdate()
                    ->first();

                if (!$record) {
                    throw new \RuntimeException('ORDER_NOT_EXIST');
                }

                if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                    throw new \RuntimeException('ORDER_SETTLED');
                }

                // 🚀 优化 #3: 使用原生 SQL 更新余额（避免 Model Event 开销）
                $oldBalance = $machineWallet->money;
                $newBalance = bcadd($oldBalance, $winAmount, 2);

                if ($winAmount > 0) {
                    \support\Db::table('player_platform_cash')
                        ->where('id', $machineWallet->id)
                        ->update([
                            'money' => $newBalance,
                            'updated_at' => Carbon::now()
                        ]);
                }

                // 🚀 优化 #4: 批量返回数据，稍后异步处理（不在事务内发送队列）
                return [
                    'balance' => $newBalance,
                    'record_id' => $record->id,
                    'bet' => $record->bet,
                ];
            });

            // 🚀 优化 #5: 事务外异步操作（Redis Pipeline 批量发送）
            $pipeline = \support\Redis::pipeline();

            // 异步更新结算记录
            $this->asyncUpdateSettleRecord(
                orderNo: $orderNo,
                win: $winAmount,
                diff: bcsub($winAmount, $data['BetAmount'], 2)
            );

            // 异步彩金记录
            if ($newBalance['bet'] > 0) {
                Client::send('game-lottery', [
                    'player_id' => $this->player->id,
                    'bet' => $newBalance['bet'],
                    'play_game_record_id' => $newBalance['record_id']
                ]);
            }

            // 立即更新 Redis 缓存（不等数据库触发器）
            \app\service\WalletService::updateCache(
                $this->player->id,
                PlayerPlatformCash::PLATFORM_SELF,
                $newBalance['balance']
            );

            $pipeline->exec();

            return $newBalance['balance'];

        } catch (\RuntimeException $e) {
            \support\Redis::del($settleKey);

            if ($e->getMessage() === 'ORDER_NOT_EXIST') {
                return $this->error = RsgGameController::API_CODE_ORDER_NOT_EXIST;
            }
            if ($e->getMessage() === 'ORDER_SETTLED') {
                return $this->error = RsgGameController::API_CODE_ORDER_SETTLED;
            }
            throw $e;
        } catch (\Throwable $e) {
            \support\Redis::del($settleKey);
            $this->log->error('RSG结算异常（优化版）', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function reBetResulet($data)
    {
        // TODO: Implement reBetResulet() method.
    }

    public function gift($data)
    {
        // TODO: Implement gift() method.
    }


    public function jackpotResult($data)
    {
        //单独使用（没有成对的 Bet，直接创建记录并结算）

        $player = $this->player;
        $money = $data['Amount'];

        // 锁定玩家钱包
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        // ✅ 幂等性检查：防止重复（在锁保护下检查，防止TOCTOU竞态条件）
        if (PlayGameRecord::query()->where('order_no', $data['SequenNumber'])->exists()) {
            return $this->error = RsgGameController::API_CODE_DUPLICATE_ORDER;
        }

        // ✅ 同步增加余额（触发 updated 事件，自动更新 Redis 缓存）
        if ($money > 0) {
            $machineWallet->money = bcadd($machineWallet->money, $money, 2);
            $machineWallet->save();
        }

        // ⚡ 异步创建彩池结算记录（不阻塞API响应）
        $this->asyncCreateBetRecord(
            playerId: $this->player->id,
            platformId: $this->platform->id,
            gameCode: $data['GameId'],
            orderNo: $data['SequenNumber'],
            bet: 0, // JackpotResult 没有下注金额
            originalData: $data,
            orderTime: Carbon::now()->toDateTimeString()
        );

        // 异步更新为已结算状态（包含win和diff）
        $this->asyncUpdateSettleRecord(
            orderNo: $data['SequenNumber'],
            win: $money,
            diff: $money
        );

        // ✅ 立即从缓存读取余额
        return \app\service\WalletService::getBalance($player->id);
    }

    /**
     * 打鱼机预扣金额（异步优化版）
     * @param $data
     * @return mixed
     */
    public function prepay($data): mixed
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['SessionId'])->first();
        if ($record) {
            return $this->error = RsgGameController::API_CODE_DUPLICATE_TRANSACTION;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        $player = $this->player;
        //需要扣除金额
        $money = $data['Amount'];

        // ✅ 同步扣减余额（触发 updated 事件，自动更新 Redis 缓存）
        if ($money > $machineWallet->money) {
            //余额不足
            $this->error = RsgGameController::API_CODE_INSUFFICIENT_BALANCE;
            //扣除现有所有金额进入游戏
            $amount = $machineWallet->money;
            $machineWallet->money = 0;
            $machineWallet->save();
        } else {
            $machineWallet->money = bcsub($machineWallet->money, $money, 2);
            $machineWallet->save();
            $amount = $money;
        }

        // ⚡ 异步创建预扣款记录（不阻塞API响应）
        $this->asyncCreateBetRecord(
            playerId: $this->player->id,
            platformId: $this->platform->id,
            gameCode: $data['GameId'],
            orderNo: $data['SessionId'],
            bet: $money,
            originalData: $data,
            orderTime: Carbon::now()->toDateTimeString()
        );

        // ✅ 立即从缓存读取余额
        $balance = \app\service\WalletService::getBalance($player->id);

        return ['Balance' => $balance, 'Amount' => $amount];
    }


    /**
     * 打鱼机退款（异步优化版）
     * @param $data
     * @return mixed
     */
    public function refund($data): mixed
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['SessionId'])->first();

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        //退款金额
        $amount = $data['Amount'];

        // ✅ 同步退还金额（触发 updated 事件，自动更新 Redis 缓存）
        $machineWallet->money = bcadd($machineWallet->money, $amount, 2);
        $machineWallet->save();

        // ⚡ 异步更新退款记录（不阻塞API响应）
        $this->asyncUpdateSettleRecord(
            orderNo: $data['SessionId'],
            win: $amount,
            diff: bcsub($amount, $record->bet, 2)
        );

        // ✅ 立即从缓存读取余额
        $balance = \app\service\WalletService::getBalance($this->player->id);

        return ['Balance' => $balance, 'Amount' => $amount];
    }

    /**
     * 打鱼机退款
     * @param $data
     * @return mixed
     */
    public function checkTransaction($data): mixed
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['TransactionId'])->first();
        if ($record) {
            return $this->error = RsgGameController::API_CODE_TRANSACTION_NOT_FOUND;
        }

        $originData = json_decode($data['OriginData'], true);

        return [
            'TransactionId' => $data['TransactionId'],
            'TransactionTime' => Carbon::now()->toDateTimeString(),
            'WebId' => $originData['WebId'],
            'UserId' => $originData['UserId'],
            'GameId' => $originData['GameId'],
            'Currency' => $originData['Currency'],
            'Action' => $record->type == PlayGameRecord::TYPE_PREPAY ? 1 : 2,
            'Amount' => $originData['Amount'],
            'AfterBalance' => $data['AfterBalance'] ?? 0,
        ];
    }


    public function decrypt($data)
    {
        $config = config('game_platform.RSG');
        $data = openssl_decrypt(base64_decode($data), 'DES-CBC', $config['DesKey'], OPENSSL_RAW_DATA, $config['DesIV']);
        $data = json_decode($data, true);

        if (empty($data)) {
            return $this->error = RsgGameController::API_CODE_DECRYPT_ERROR;
        }

        if (empty($data['SystemCode']) || $data['SystemCode'] != $config['SystemCode']) {
            return $this->error = RsgGameController::API_CODE_INVALID_PARAM;
        }

        $player = Player::query()->where('uuid', $data['UserId'])->first();
        if (!$player) {
            return $this->error = RsgGameController::API_CODE_PLAYER_NOT_EXIST;
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
        $encrypt_data = openssl_encrypt($data, 'DES-CBC', $this->config['DesKey'], OPENSSL_RAW_DATA, $this->config['DesIV']);
        return base64_encode($encrypt_data);
    }

}
