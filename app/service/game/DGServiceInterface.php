<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\traits\AsyncGameRecordTrait;
use app\wallet\controller\game\DGGameController;
use app\wallet\controller\game\O8GameController;
use Carbon\Carbon;
use Exception;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class DGServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    use AsyncGameRecordTrait;
    use LimitGroupTrait;

    public $method = 'POST';
    public $successCode = '0';
    public $failCode = [
        '1' => '参数错误',
        '2' => 'Token验证失败',
        '4' => '非法操作',
        '10' => '日期格式错误',
        '11' => '数据格式错误',
        '97' => '没有权限',
        '98' => '操作失败',
        '99' => '未知错误',
        '100' => '账号被锁定',
        '101' => '账号格式错误',
        '103' => '此账号被占用',
        '104' => '密码格式错误',
        '105' => '密码错误',
        '106' => '新旧密码相同',
        '107' => '会员账号不可用',
        '108' => '登入失败',
        '109' => '注册失败',
        '113' => '传入的代理账号不是代理',
        '114' => '找不到会员',
        '116' => '账号已占用',
        '118' => '找不到指定的代理',
        '119' => '存取款操作时代理点数不足',
        '120' => '余额不足',
        '121' => '盈利限制必须大于或等于0',
        '150' => '免费试玩账号用完',
        '188' => '注册新会员超出,请联系客服',
        '300' => '系统维护',
        '301' => '代理账号找不到',
        '321' => '找不到相应的限红组',
        '322' => '找不到指定的货币类型',
        '323' => '转账流水号占用',
        '324' => '转账失败',
        '325' => '代理状态不可用',
        '400' => '客户端IP 受限',
        '401' => '网络延迟',
        '403' => '客户端来源受限',
        '404' => '请求的资源不存在',
        '405' => '请求太频繁',
        '406' => '请求超时',
        '407' => '找不到游戏地址',
        '500' => '系统异常',
        '501' => '系统异常',
        '502' => '系统异常',
        '503' => '系统异常',
    ];

    private $apiDomain;
    private $path = [
        'createPlayer' => '/v2/wallet/signup',
        'getBalance' => '/v2/api/balance',
        'depositAmount' => '/v2/api/transfer',
        'withdrawAmount' => '/v2/api/transfer',
        'lobbyLogin' => '/v2/wallet/login',
        'freeLobbyLogin' => '/v2/wallet/free',
        'getGameHistories' => '/v2/wallet/report',
        'markGameHistories' => '/v2/wallet/markReport',
        'gameLogin' => '/v2/wallet/login',
    ];

    private $currency = [
        'TWD' => 'TWD',
        'CNY' => 'TWD',
        'JPY' => 'JPY',
        'USD' => 'USD',
    ];

    private $lang = [
        'zh-CN' => 'cn',
        'zh-TW' => 'tw',
        'jp' => 'jp',
        'en' => 'en',
        'th' => 'th',
        'vi' => 'vi',
        'kr_ko' => 'ko',
        'id' => 'id',
    ];

    private $config = [];

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.DG');
        $this->config = $config;
        $this->apiDomain = $config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'DG')->first();
        $this->player = $player;
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
        ]);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }

        return $res['balance'] ?? 0;
    }

    /**
     * 获取玩家的DG限红组配置（通过店家）
     * @return array|null
     */
    private function getPlayerLimitConfig(): ?array
    {
        // 使用 Trait 中的通用方法获取限红组配置
        $limitGroupConfig = $this->getLimitGroupConfig('dg_server');

        // 如果没有配置数据，返回null
        if (!$this->hasLimitGroupConfigData($limitGroupConfig)) {
            return null;
        }

        // 从config_data中获取DG限红配置 {"max": 2, "min": 1}
        $configData = $limitGroupConfig->config_data;

        return [
            'max' => $configData['max'] ?? null,
            'min' => $configData['min'] ?? null,
            'limit_group_id' => $limitGroupConfig->limit_group_id,
        ];
    }

    /**
     * 检查玩家
     * @throws GameException
     */
    private function checkPlayer()
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
            $playerGamePlatform->player_password = $result['password'] ?? '';

            $playerGamePlatform->save();
        }

        return $playerGamePlatform;
    }

    /**
     * @return array
     * @throws GameException
     */
    public function createPlayer(): array
    {
        $password = $this->generateAlphanumericPassword();
        $params = [
            'username' => $this->player->uuid,
            'password' => md5($password),
            'currencyName' => $this->currency[$this->player->currency] ?? 'TWD',
            'winLimit' => 0,
        ];
        $res = $this->doCurl($this->createUrl('createPlayer'), $params);
        if ($res['codeId'] != $this->successCode && $res['codeId'] != '116') {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }

        return [
            'username' => $this->player->uuid,
            'password' => $password,
        ];
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
     * @throws GameException
     */
    public function doCurl(string $url, array $params = [])
    {
        $config = config('game_platform.DG');
        $time = round(microtime(true) * 1000);
        $response = Http::timeout(7)
            ->withHeaders([
                'agent' => $config['app_id'],
                'sign' => md5($config['app_id'] . $config['app_secret'] . $time),
                'time' => $time,
            ])
            ->asJson()
            ->withBody(json_encode($params), 'application/json')
            ->post($url);
        if (!$response->ok()) {
            throw new GameException(trans('system_busy', [], 'message'));
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
        return $this->apiDomain . $this->path[$method];
    }

    /**
     * 进入游戏大厅
     * @param array $data
     * @return string
     * @throws GameException
     */
    public function lobbyLogin(array $data = []): string
    {
        $playerGamePlatform = $this->checkPlayer();
        $password = $playerGamePlatform->player_password;

        $params = [
            'username' => $this->player->uuid,
            'password' => md5($password),
            'currencyName' => $this->currency[$this->player->currency] ?? 'TWD',
            'language' => 'tw',
        ];

        // 获取限红组配置
        $limitConfig = $this->getPlayerLimitConfig();
        if ($limitConfig && isset($limitConfig['max']) && isset($limitConfig['min'])) {
            $params['limits'][] = [
                'max' => $limitConfig['max'],
                'min' => $limitConfig['min'],
            ];
        }

        Log::channel('dg_server')->info('lobbyLogin', ['params'=>$params]);
        $res = $this->doCurl($this->createUrl('lobbyLogin'), $params);
        Log::channel('dg_server')->info('lobbyLogin_response', [$res]);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }

        return $res['list'][0] . '&showapp=off' . '&isapp=1';
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
        $res = $this->doCurl($this->createUrl('depositAmount'), [
            'username' => $this->player->uuid,
            'amount' => $data['amount'],
            'serial' => $data['order_no'] ?? '',
        ]);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }
        Cache::set('depositAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('withdrawAmount_' . $this->player->id);

        return $data['order_no'];
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
        $res = $this->doCurl($this->createUrl('withdrawAmount'), [
            'username' => $this->player->uuid,
            'amount' => -$data['amount'],
            'serial' => $data['order_no'] ?? '',
        ]);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $data['order_no'],
            'amount' => $data['amount'],
        ];
    }

    /**
     * 标记注单已抓取
     * @param array $data
     * @return true
     * @throws GameException
     */
    public function markGameHistories(array $data = []): bool
    {
        $res = $this->doCurl($this->createUrl('markGameHistories'), [
            'list' => $data,
        ]);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }

        return true;
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
                    $player = Player::withTrashed()->where('uuid', $item['userName'])->first();
                    if (!empty($player)) {
                        if ($item['isRevocation'] == 1) {
                            $list[] = [
                                'player_id' => $player->id,
                                'parent_player_id' => $player->recommend_id ?? 0,
                                'player_uuid' => $player->uuid,
                                'platform_id' => $this->platform->id,
                                'game_code' => $item['gameId'],
                                'department_id' => $player->department_id,
                                'bet' => $item['betPoints'],
                                'win' => max($item['winOrLoss'] - ($item['betPoints']), 0),
                                'diff' => $item['winOrLoss'] - $item['betPoints'],
                                'order_no' => $item['id'],
                                'original_data' => json_encode($item),
                                'platform_action_at' => $item['calTime'],
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
        $res = $this->doCurl($this->createUrl('getGameHistories'));
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }

        return $res['list'] ?? [];
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
        $playerGamePlatform = $this->checkPlayer();
        $password = $playerGamePlatform->player_password;

        $params = [
            'username' => $this->player->uuid,
            'password' => md5($password),
            'currencyName' => $this->currency[$this->player->currency] ?? 'TWD',
            'language' => $this->lang[$lang] ?? 'tw',
        ];

        // 获取限红组配置
        $limitConfig = $this->getPlayerLimitConfig();
        if ($limitConfig && isset($limitConfig['max']) && isset($limitConfig['min'])) {
            $params['limits'][] = [
                'max' => $limitConfig['max'],
                'min' => $limitConfig['min'],
            ];
        }

        Log::channel('dg_server')->info('lobbyLogin', ['params'=>$params]);

        $res = $this->doCurl($this->createUrl('lobbyLogin'), $params);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }

        Log::channel('dg_server')->info('ss', [$res]);
        return $res['list'][1] . '&showapp=off' . '&isapp=1' . '&tableId=' . $game->game_extend->code;
    }

    /**
     * 获取游戏录像地址
     * @param array $data
     * @return string
     */
    public function replay(array $data = []): string
    {
        $config = config('game_platform.DG');

        $domain = $config['admin_url'];
        $url = '/ag/result/result.html';

        $params = [
            'language' => 'cn',
            'agentFix' => $config['agent_fix'],
            'id' => $data['order_no']
        ];

        return $domain . $url . '?' . http_build_query($params);
    }


    /**
     * token验证
     * @param $data
     * @param $agentName
     * @return bool|int
     */
    public function verifyToken($data, $agentName): bool|int
    {
        $key = $this->config['app_secret'];
        $token = $data['token'];

        if (md5($agentName . $key) !== $token) {
            return $this->error = O8GameController::API_CODE_CERTIFICATE_ERROR;
        }

        return true;
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
        // TODO: Implement getPlayer() method.
    }

    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return DGGameController::API_CODE_INSUFFICIENT_BALANCE;
    }

    /**
     * 下注
     * @return mixed
     */
    public function bet($data)
    {
        $player = $this->player;
        $detail = json_decode($data['detail'], true);
        $orderNo = $data['ticketId'];
        $bet = abs($data['member']['amount']);

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return $player->machine_wallet->money;
        }

        // 🚀 Redis 预检查幂等性（DG可能重复下注，使用累计逻辑）
        $betKey = "dg:bet:lock:{$orderNo}";
        $isLocked = \support\Redis::set($betKey, 1, ['NX', 'EX' => 300]);

        try {
            // 🚀 单次事务 + 原子操作
            $result = \support\Db::transaction(function () use ($orderNo, $bet, $player, $detail, $isLocked) {
                /** @var PlayerPlatformCash $machineWallet */
                $machineWallet = PlayerPlatformCash::query()
                    ->where('player_id', $player->id)
                    ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
                    ->lockForUpdate()
                    ->first();

                if ($machineWallet->money < $bet) {
                    throw new \RuntimeException('INSUFFICIENT_BALANCE');
                }

                $beforeBalance = $machineWallet->money;

                // 🚀 使用原生 SQL 更新余额
                $newBalance = bcsub($machineWallet->money, $bet, 2);
                \support\Db::table('player_platform_cash')
                    ->where('id', $machineWallet->id)
                    ->update([
                        'money' => $newBalance,
                        'updated_at' => Carbon::now()
                    ]);

                return ['balance' => $beforeBalance, 'new_balance' => $newBalance];
            });

            // 🚀 事务外异步操作（只在首次下注时创建记录）
            if ($isLocked) {
                $this->asyncCreateBetRecord(
                    playerId: $player->id,
                    platformId: $this->platform->id,
                    gameCode: $detail['gameId'],
                    orderNo: $orderNo,
                    bet: $bet,
                    originalData: $data,
                    orderTime: Carbon::now()->toDateTimeString()
                );
            }

            // 立即更新 Redis 缓存
            \app\service\WalletService::updateCache(
                $player->id,
                PlayerPlatformCash::PLATFORM_SELF,
                $result['new_balance']
            );

            return [
                'member' => [
                    'username' => $data['member']['username'],
                    'balance' => $result['balance'],
                    'amount' => $data['member']['amount'],
                ]
            ];

        } catch (\RuntimeException $e) {
            if ($isLocked) {
                \support\Redis::del($betKey);
            }
            if ($e->getMessage() === 'INSUFFICIENT_BALANCE') {
                $this->error = DGGameController::API_CODE_INSUFFICIENT_BALANCE;
                return $player->machine_wallet->money;
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($isLocked) {
                \support\Redis::del($betKey);
            }
            throw $e;
        }
    }

    /**
     * 通知接口 - 处理各种类型的通知
     * @param $data
     * @return array
     */
    public function inform($data): array
    {
        $player = $this->player;
        $type = $data['type']; // 通知类型
        $orderNo = $data['ticketId'];
        $amount = abs($data['member']['amount']);
        $detail = json_decode($data['detail'], true);

        Log::channel('dg_server')->info('DG inform处理', [
            'type' => $type,
            'order_no' => $orderNo,
            'amount' => $amount,
            'detail' => $detail
        ]);

        // 根据不同的type处理不同的业务逻辑
        switch ($type) {
            case 4: // 取消投注/撤销
                return $this->handleCancelBet($data, $orderNo, $amount);
            case 7: // 补偿
                return $this->handleCompensation($data, $orderNo, $amount);
            default:
                Log::channel('dg_server')->warning('DG inform未知类型', ['type' => $type, 'data' => $data]);
                $this->error = DGGameController::API_CODE_DECRYPT_ERROR;
                return [
                    'member' => [
                        'username' => $data['member']['username'],
                        'balance' => \app\service\WalletService::getBalance($player->id),
                        'amount' => 0,
                    ]
                ];
        }
    }

    /**
     * 处理取消投注
     * @param $data
     * @param $orderNo
     * @param $amount
     * @return array
     */
    private function handleCancelBet($data, $orderNo, $amount): array
    {
        $player = $this->player;

        // Redis预检查幂等性
        $cancelKey = "dg:cancel:lock:{$orderNo}";
        $isLocked = \support\Redis::set($cancelKey, 1, ['NX', 'EX' => 300]);
        if (!$isLocked) {
            $this->error = DGGameController::API_CODE_DUPLICATE_TRANSACTION;
            Log::channel('dg_server')->warning('DG 取消投注重复', ['order_no' => $orderNo]);
            return [
                'member' => [
                    'username' => $data['member']['username'],
                    'balance' => \app\service\WalletService::getBalance($player->id),
                    'amount' => 0,
                ]
            ];
        }

        try {
            $result = \support\Db::transaction(function () use ($orderNo, $amount, $player) {
                // 查询订单
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

                // 锁定钱包
                /** @var PlayerPlatformCash $machineWallet */
                $machineWallet = PlayerPlatformCash::query()
                    ->where('player_id', $player->id)
                    ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
                    ->lockForUpdate()
                    ->first();

                $beforeBalance = $machineWallet->money;

                // 退款
                $newBalance = bcadd($machineWallet->money, $record->bet, 2);
                \support\Db::table('player_platform_cash')
                    ->where('id', $machineWallet->id)
                    ->update([
                        'money' => $newBalance,
                        'updated_at' => Carbon::now()
                    ]);

                return [
                    'before_balance' => $beforeBalance,
                    'new_balance' => $newBalance,
                    'refund_amount' => $record->bet
                ];
            });

            // 异步更新订单状态为已取消
            $this->asyncCancelBetRecord($orderNo);

            // 更新Redis缓存
            \app\service\WalletService::updateCache(
                $player->id,
                PlayerPlatformCash::PLATFORM_SELF,
                $result['new_balance']
            );

            Log::channel('dg_server')->info('DG 取消投注成功', [
                'order_no' => $orderNo,
                'refund_amount' => $result['refund_amount']
            ]);

            return [
                'member' => [
                    'username' => $data['member']['username'],
                    'balance' => $result['before_balance'],
                    'amount' => $result['refund_amount'],
                ]
            ];

        } catch (\RuntimeException $e) {
            \support\Redis::del($cancelKey);
            if ($e->getMessage() === 'ORDER_NOT_EXIST') {
                $this->error = DGGameController::API_CODE_DECRYPT_ERROR;
            } elseif ($e->getMessage() === 'ORDER_CANCELLED') {
                $this->error = DGGameController::API_CODE_DUPLICATE_TRANSACTION;
            }
            return [
                'member' => [
                    'username' => $data['member']['username'],
                    'balance' => \app\service\WalletService::getBalance($player->id),
                    'amount' => 0,
                ]
            ];
        } catch (\Throwable $e) {
            \support\Redis::del($cancelKey);
            throw $e;
        }
    }

    /**
     * 处理补偿
     * @param $data
     * @param $orderNo
     * @param $amount
     * @return array
     */
    private function handleCompensation($data, $orderNo, $amount): array
    {
        $player = $this->player;

        // Redis预检查幂等性
        $compensationKey = "dg:compensation:lock:{$orderNo}";
        $isLocked = \support\Redis::set($compensationKey, 1, ['NX', 'EX' => 300]);
        if (!$isLocked) {
            $this->error = DGGameController::API_CODE_DUPLICATE_TRANSACTION;
            Log::channel('dg_server')->warning('DG 补偿重复', ['order_no' => $orderNo]);
            return [
                'member' => [
                    'username' => $data['member']['username'],
                    'balance' => \app\service\WalletService::getBalance($player->id),
                    'amount' => 0,
                ]
            ];
        }

        try {
            $result = \support\Db::transaction(function () use ($amount, $player) {
                // 锁定钱包
                /** @var PlayerPlatformCash $machineWallet */
                $machineWallet = PlayerPlatformCash::query()
                    ->where('player_id', $player->id)
                    ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
                    ->lockForUpdate()
                    ->first();

                $beforeBalance = $machineWallet->money;

                // 增加补偿金额
                $newBalance = bcadd($machineWallet->money, $amount, 2);
                \support\Db::table('player_platform_cash')
                    ->where('id', $machineWallet->id)
                    ->update([
                        'money' => $newBalance,
                        'updated_at' => Carbon::now()
                    ]);

                return [
                    'before_balance' => $beforeBalance,
                    'new_balance' => $newBalance
                ];
            });

            // 更新Redis缓存
            \app\service\WalletService::updateCache(
                $player->id,
                PlayerPlatformCash::PLATFORM_SELF,
                $result['new_balance']
            );

            Log::channel('dg_server')->info('DG 补偿成功', [
                'order_no' => $orderNo,
                'amount' => $amount
            ]);

            return [
                'member' => [
                    'username' => $data['member']['username'],
                    'balance' => $result['before_balance'],
                    'amount' => $amount,
                ]
            ];

        } catch (\Throwable $e) {
            \support\Redis::del($compensationKey);
            throw $e;
        }
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
     * 结算
     * @return mixed
     */
    public function betResulet($data)
    {
        $player = $this->player;
        $orderNo = $data['ticketId'];
        $detail = json_decode($data['detail'], true);
        $winAmount = $data['member']['amount'];

        // 🚀 Redis 预检查幂等性
        $settleKey = "dg:settle:lock:{$orderNo}";
        $isLocked = \support\Redis::set($settleKey, 1, ['NX', 'EX' => 10]);
        if (!$isLocked) {
            $this->error = DGGameController::API_CODE_DUPLICATE_TRANSACTION;
            return $this->player->machine_wallet->money;
        }

        try {
            // 🚀 单次事务 + 合并锁查询
            $result = \support\Db::transaction(function () use ($orderNo, $winAmount, $player, $detail) {
                // ✅ 统一锁顺序：wallet → record
                /** @var PlayerPlatformCash $machineWallet */
                $machineWallet = PlayerPlatformCash::query()
                    ->where('player_id', $player->id)
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

                if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                    throw new \RuntimeException('ORDER_SETTLED');
                }

                $beforeBalance = $machineWallet->money;

                // 🚀 使用原生 SQL 更新余额
                $newBalance = bcadd($machineWallet->money, $winAmount, 2);
                if ($winAmount > 0) {
                    \support\Db::table('player_platform_cash')
                        ->where('id', $machineWallet->id)
                        ->update([
                            'money' => $newBalance,
                            'updated_at' => Carbon::now()
                        ]);
                }

                return [
                    'before_balance' => $beforeBalance,
                    'new_balance' => $newBalance,
                    'record_id' => $record->id,
                    'bet' => $record->bet
                ];
            });

            // 🚀 事务外异步操作
            $this->asyncUpdateSettleRecord(
                orderNo: $orderNo,
                win: $detail['winOrLoss'],
                diff: $detail['winOrLoss'] - $result['bet']
            );

            // 彩金记录
            if ($result['bet'] > 0) {
                Client::send('game-lottery', [
                    'player_id' => $player->id,
                    'bet' => $result['bet'],
                    'play_game_record_id' => $result['record_id']
                ]);
            }

            // 立即更新 Redis 缓存
            \app\service\WalletService::updateCache(
                $player->id,
                PlayerPlatformCash::PLATFORM_SELF,
                $result['new_balance']
            );

            return [
                'member' => [
                    'username' => $data['member']['username'],
                    'balance' => $result['before_balance'],
                    'amount' => $winAmount,
                ]
            ];

        } catch (\RuntimeException $e) {
            \support\Redis::del($settleKey);
            if ($e->getMessage() === 'ORDER_NOT_EXIST') {
                $this->error = DGGameController::API_CODE_DECRYPT_ERROR;
                return $this->player->machine_wallet->money;
            }
            if ($e->getMessage() === 'ORDER_SETTLED') {
                $this->error = DGGameController::API_CODE_DUPLICATE_TRANSACTION;
                return $this->player->machine_wallet->money;
            }
            throw $e;
        } catch (\Throwable $e) {
            \support\Redis::del($settleKey);
            throw $e;
        }
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
     * @param $data
     * @return mixed
     */
    public function decrypt($data): mixed
    {
        $player = Player::query()->where('uuid', $data['member']['username'])->first();
        if (!$player) {
            return $this->error = DGGameController::API_CODE_DECRYPT_ERROR;
        }

        $this->player = $player;

        return $data;
    }

}
