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
use app\wallet\controller\game\MtGameController;
use Exception;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class MTServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    use AsyncGameRecordTrait;
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
        return MtGameController::API_CODE_INSUFFICIENT_BALANCE;
    }

    public function bet($data): mixed
    {
        $player = $this->player;
        $orderNo = $data['bet_sn'];
        $bet = $data['order_money'];

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return $this->error;
        }

        // 🚀 Redis 预检查幂等性
        $betKey = "mt:bet:lock:{$orderNo}";
        $isLocked = \support\Redis::set($betKey, 1, ['NX', 'EX' => 300]);
        if (!$isLocked) {
            $this->error = MtGameController::API_CODE_DUPLICATE_ORDER;
            return \app\service\WalletService::getBalance($player->id);
        }

        try {
            // 🚀 单次事务 + 原子操作
            $newBalance = \support\Db::transaction(function () use ($orderNo, $bet, $player) {
                /** @var PlayerPlatformCash $machineWallet */
                $machineWallet = PlayerPlatformCash::query()
                    ->where('player_id', $player->id)
                    ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
                    ->lockForUpdate()
                    ->first();

                if ($machineWallet->money < $bet) {
                    throw new \RuntimeException('INSUFFICIENT_BALANCE');
                }

                // 🚀 使用原生 SQL 更新余额
                $newBalance = bcsub($machineWallet->money, $bet, 2);
                \support\Db::table('player_platform_cash')
                    ->where('id', $machineWallet->id)
                    ->update([
                        'money' => $newBalance,
                        'updated_at' => Carbon::now()
                    ]);

                return $newBalance;
            });

            // 🚀 事务外异步创建记录 + 更新缓存
            $this->asyncCreateBetRecord(
                playerId: $player->id,
                platformId: $this->platform->id,
                gameCode: $data['game_code'],
                orderNo: $orderNo,
                bet: $bet,
                originalData: $data,
                orderTime: $data['order_time']
            );

            // 立即更新 Redis 缓存
            \app\service\WalletService::updateCache(
                $player->id,
                PlayerPlatformCash::PLATFORM_SELF,
                $newBalance
            );

            return $newBalance;

        } catch (\RuntimeException $e) {
            \support\Redis::del($betKey);
            if ($e->getMessage() === 'INSUFFICIENT_BALANCE') {
                return $this->error = MtGameController::API_CODE_INSUFFICIENT_BALANCE;
            }
            throw $e;
        } catch (\Throwable $e) {
            \support\Redis::del($betKey);
            throw $e;
        }
    }

    /**
     * 取消单（异步优化版）
     * @param $data
     * @return float|string
     */
    public function cancelBet($data): float|string
    {
        $orderNo = $data['bet_sn'];

        try {
            // 🚀 单次事务 + 合并锁查询
            $newBalance = \support\Db::transaction(function () use ($orderNo) {
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
                $refundAmount = $record->bet;
                $newBalance = bcadd($machineWallet->money, $refundAmount, 2);
                \support\Db::table('player_platform_cash')
                    ->where('id', $machineWallet->id)
                    ->update([
                        'money' => $newBalance,
                        'updated_at' => Carbon::now()
                    ]);

                return $newBalance;
            });

            // 🚀 事务外异步操作
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
                return $this->error = MtGameController::API_CODE_ORDER_NOT_EXIST;
            }
            if ($e->getMessage() === 'ORDER_CANCELLED') {
                return \app\service\WalletService::getBalance($this->player->id);
            }
            throw $e;
        }
    }

    /**
     * 结算（异步优化版）
     * @return mixed
     */
    public function betResulet($data)
    {
        $orderNo = $data['bet_sn'];
        $winAmount = $data['win_money'];

        // 🚀 Redis 预检查幂等性
        $settleKey = "mt:settle:lock:{$orderNo}";
        $isLocked = \support\Redis::set($settleKey, 1, ['NX', 'EX' => 10]);
        if (!$isLocked) {
            return \app\service\WalletService::getBalance($this->player->id);
        }

        try {
            // 🚀 单次事务 + 合并锁查询
            $newBalance = \support\Db::transaction(function () use ($orderNo, $winAmount, $data) {
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

                if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                    throw new \RuntimeException('ORDER_SETTLED');
                }

                // 🚀 使用原生 SQL 更新余额
                $newBalance = bcadd($machineWallet->money, $winAmount, 2);
                if ($data['status'] != self::BET_STATUS_NOT && $winAmount > 0) {
                    \support\Db::table('player_platform_cash')
                        ->where('id', $machineWallet->id)
                        ->update([
                            'money' => $newBalance,
                            'updated_at' => Carbon::now()
                        ]);
                }

                return ['balance' => $newBalance, 'record_id' => $record->id, 'bet' => $record->bet];
            });

            // 🚀 事务外异步操作
            $win = $winAmount <= ($data['order_money'] ?? 0) ? 0 : $data['profit'];
            $this->asyncUpdateSettleRecord(
                orderNo: $orderNo,
                win: $win,
                diff: $data['profit']
            );

            // 彩金记录会在Consumer中处理

            // 立即更新 Redis 缓存
            \app\service\WalletService::updateCache(
                $this->player->id,
                PlayerPlatformCash::PLATFORM_SELF,
                $newBalance['balance']
            );

            return $newBalance['balance'];

        } catch (\RuntimeException $e) {
            \support\Redis::del($settleKey);
            // 订单不存在或已结算，返回余额
            return \app\service\WalletService::getBalance($this->player->id);
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
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['bet_sn'])->first();

        if (!$record) {
            return $this->error = MtGameController::API_CODE_ORDER_NOT_EXIST;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        // ✅ 同步扣减余额（如需要扣回中奖金额，触发 updated 事件，自动更新 Redis 缓存）
        $actionData = json_decode($record->action_data, true);
        if ($actionData['status'] == self::BET_STATUS_YES && $data['status'] != self::BET_STATUS_NOT) {
            $money = $data['win_money'];

            if ($machineWallet->money < $money) {
                //重结算异常订单(结算错误 赢钱需要扣除 提现时处理)
                $record->is_rebet = 1;
                $record->save();
                return $this->error = MtGameController::API_CODE_INSUFFICIENT_BALANCE;
            }

            $machineWallet->money = bcsub($machineWallet->money, $money, 2);
            $machineWallet->save();
        }

        // ⚡ 异步更新重新结算记录（不阻塞API响应）
        $win = $data['win_money'] <= $record->bet ? 0 : $data['profit'];
        $this->asyncUpdateSettleRecord(
            orderNo: $data['bet_sn'],
            win: $win,
            diff: $data['profit']
        );

        //彩金记录
        Client::send('game-lottery', [
            'player_id' => $this->player->id,
            'bet' => $record->bet,
            'play_game_record_id' => $record->id
        ]);

        // ✅ 立即从缓存返回余额
        return \app\service\WalletService::getBalance($this->player->id);
    }

    /**
     * 送礼（异步优化版）
     * @return mixed
     */
    public function gift($data)
    {
        $player = $this->player;
        $bet = $data['money'];

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        if ($machineWallet->money < $bet) {
            return $this->error = MtGameController::API_CODE_INSUFFICIENT_BALANCE;
        }

        // ✅ 同步扣减余额（触发 updated 事件，自动更新 Redis 缓存）
        $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
        $machineWallet->save();

        // ⚡ 异步创建送礼记录（不阻塞API响应）
        $this->asyncCreateBetRecord(
            playerId: $this->player->id,
            platformId: $this->platform->id,
            gameCode: $data['game_code'],
            orderNo: $data['tip_sn'],
            bet: $bet,
            originalData: $data,
            orderTime: $data['tran_time'],
            updateStats: false // 送礼不更新统计
        );

        // ✅ 立即从缓存返回余额
        return \app\service\WalletService::getBalance($this->player->id);
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
