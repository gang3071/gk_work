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
use app\traits\AsyncGameRecordTrait;
use app\wallet\controller\game\TNineGameController;
use Exception;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class TNineServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
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
    public function __construct(Player $player = null)
    {
        $this->config = config('game_platform.TNINE');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'TNINE')->first();
        $this->player = $player;
        $this->log = Log::channel('tnine_server');
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
        $agentId = $this->config['agent_id'];
        $time = date('YmdHis');
        $key = $this->config['api_key'];

        $params['Sign'] = strtolower(md5("$agentId&$time&$key"));
        $params['AgentId'] = $agentId;
        $params['RequestTime'] = $time;

        if ($method == 'get') {
            $response = Http::timeout(7)
                ->asJson()
                ->get($this->config['api_domain'] . $url . '?' . http_build_query($params));
        } else {
            $response = Http::timeout(7)
                ->asJson()
                ->post($this->config['api_domain'] . $url, $params);
        }

        if (!$response->ok()) {
            $errorMsg = 'T9 API请求失败 HTTP ' . $response->status() . ': ' . $response->body();
            $this->log->error($url, ['params' => $params, 'response' => $response->body(), 'status' => $response->status()]);
            throw new GameException($errorMsg);
        }

        $res = json_decode($response->body(), true);

        if (empty($res)) {
            $errorMsg = 'T9 API响应为空: ' . $response->body();
            $this->log->error($url, ['params' => $params, 'response' => $response->body()]);
            throw new Exception($errorMsg);
        }

        if ($res['Error']['Code'] != 0) {
            $errorCode = $res['Error']['Code'] ?? '未知错误码';
            $errorMessage = $res['Error']['Message'] ?? $response->body();
            $errorMsg = "T9 API错误 Code:{$errorCode} - {$errorMessage}";
            $this->log->error($url, ['params' => $params, 'response' => $response->body(), 'error_code' => $errorCode]);
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
            'MemberAccount' => $this->player->uuid,
            'MemberPassword' => $this->player->uuid,
            'NickName' => $this->player->uuid,
            'MiniBetLimit' => 1,
            'MaxBetLimit' => 1000000,
        ];
        $response = $this->doCurl('/api/create_account', $params);
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
        $params = [
            'lang' => 'zh-TW',
        ];
        $res = $this->doCurl('/api/games', $params, 'get');
//        $this->log->info('gameLogin', [$res]);

        $insertData = [];
        if (!empty($res['games'])) {
            foreach ($res['games'] as $item) {
                if (!in_array($item['type'], [0, 12])) {
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
        return TNineGameController::API_CODE_INSUFFICIENT_BALANCE;
    }

    public function bet($data): mixed
    {
        $orders = $data['OrderList'];

        $return = [];
        /** @var Player $player */
        $player = Player::query()->where('uuid', $data['MemberAccount'])->first();

        // 临时设置player供爆机检查使用
        $this->player = $player;

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return \app\service\GameRecordCacheService::getCachedBalance($player->id);
        }

        // Redis 缓存处理（批量订单，检查第一个订单）
        if (count($orders) > 0) {
            $firstOrder = $orders[0];
            $lockKey = "order:bet:lock:{$firstOrder['OrderNumber']}";

            if (!\support\Redis::set($lockKey, 1, 'EX', 300, 'NX')) {
                // 整个批次已处理，返回当前余额
                $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

                // 返回所有订单号（避免游戏平台报错）
                foreach ($orders as $order) {
                    $return['OrderList'][] = [
                        'OrderNumber' => $order['OrderNumber'],
                        'MerchantOrderNumber' => $order['OrderNumber'],
                    ];
                }

                $return['Balance'] = $balance;
                $return['SyncTime'] = date('Y-m-d H:i:s');

                return $return;
            }
        }

        try {
            // 获取当前余额
            $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

            $allAmount = array_sum(array_column($orders, 'BetAmount'));

            // 余额预检查
            if ($currentBalance < $allAmount) {
                if (count($orders) > 0) {
                    \support\Redis::del("order:bet:lock:{$orders[0]['OrderNumber']}");
                }
                $this->error = TNineGameController::API_CODE_INSUFFICIENT_BALANCE;
                return $currentBalance;
            }

            // 写入批量下注记录
            foreach ($orders as $order) {
                $bet = $order['BetAmount'];

                \app\service\GameRecordCacheService::saveBet('TNINE', [
                    'order_no' => $order['OrderNumber'],
                    'player_id' => $player->id,
                    'platform_id' => $this->platform->id,
                    'amount' => $bet,
                    'game_code' => $data['GameType'],
                    'original_data' => $order,
                ]);

                $return['OrderList'][] = [
                    'OrderNumber' => $order['OrderNumber'],
                    'MerchantOrderNumber' => $order['OrderNumber'],
                ];
            }

            // 更新余额缓存（扣减总金额）
            $newBalance = bcsub($currentBalance, $allAmount, 2);
            \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);

        } catch (\Throwable $e) {
            if (count($orders) > 0) {
                \support\Redis::del("order:bet:lock:{$orders[0]['OrderNumber']}");
            }
            throw $e;
        }

        $return['Balance'] = $newBalance;
        $return['SyncTime'] = date('Y-m-d H:i:s');

        return $return;
    }

    /**
     * 取消单（Redis 缓存版）
     * @param $data
     * @return float|string
     */
    public function cancelBet($data): float|string
    {
        $orderNo = $data['txn_reverse_id'];
        $bet = $data['amount'];

        // 幂等性检查
        $lockKey = "order:cancel:lock:{$orderNo}";
        if (!\support\Redis::set($lockKey, 1, 'EX', 300, 'NX')) {
            // 重复取消
            return \app\service\GameRecordCacheService::getCachedBalance($this->player->id);
        }

        try {
            // 获取当前余额
            $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($this->player->id);

            // 写入取消记录
            \app\service\GameRecordCacheService::saveCancel('TNINE', [
                'order_no' => $orderNo,
                'player_id' => $this->player->id,
                'platform_id' => $this->platform->id,
                'cancel_type' => 'cancel',
                'original_data' => $data,
            ]);

            // 更新余额缓存（退款）
            $newBalance = bcadd($currentBalance, $bet, 2);
            \app\service\GameRecordCacheService::updateCachedBalance($this->player->id, (float)$newBalance);

        } catch (\Throwable $e) {
            \support\Redis::del($lockKey);
            throw $e;
        }

        return $newBalance;
    }

    /**
     * 结算（Redis 缓存版）
     * @param $data
     * @return mixed
     */
    public function betResulet($data): mixed
    {
        $return = [];
        /** @var Player $player */
        $player = Player::query()->where('uuid', $data['MemberAccount'])->first();

        $orderNo = $data['OrderNumber'];
        $money = $data['GameAmount'];
        $winAmount = $data['WinAmount'];

        // 幂等性检查
        $lockKey = "order:settle:lock:{$orderNo}";
        if (!\support\Redis::set($lockKey, 1, 'EX', 300, 'NX')) {
            // 重复结算
            $balance = \app\service\GameRecordCacheService::getCachedBalance($player->id);
            return [
                'MerchantOrderNumber' => $orderNo,
                'SyncTime' => date('Y-m-d H:i:s'),
                'Balance' => $balance,
            ];
        }

        try {
            // 获取当前余额
            $currentBalance = \app\service\GameRecordCacheService::getCachedBalance($player->id);

            // 写入结算记录
            \app\service\GameRecordCacheService::saveSettle('TNINE', [
                'order_no' => $orderNo,
                'player_id' => $player->id,
                'platform_id' => $this->platform->id,
                'amount' => max($money, 0),
                'diff' => $winAmount,
                'original_data' => $data,
            ]);

            // 更新余额缓存（加上中奖金额）
            $newBalance = $currentBalance;
            if ($winAmount > 0) {
                $newBalance = bcadd($currentBalance, $money, 2);
                \app\service\GameRecordCacheService::updateCachedBalance($player->id, (float)$newBalance);
            }

        } catch (\Throwable $e) {
            \support\Redis::del($lockKey);
            throw $e;
        }

        $return = [
            'MerchantOrderNumber' => $orderNo,
            'SyncTime' => date('Y-m-d H:i:s'),
            'Balance' => $newBalance,
        ];

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
     * 送礼（异步优化版）
     * @param $data
     * @return array|int
     */
    public function gift($data): array|int
    {
        /** @var Player $player */
        $player = Player::query()->where('uuid', $data['MemberAccount'])->first();

        $bet = $data['Value'];

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();
        if ($machineWallet->money < $bet) {
            return $this->error = TNineGameController::API_CODE_INSUFFICIENT_BALANCE;
        }

        // ✅ 同步扣减余额（触发 updated 事件，自动更新 Redis 缓存）
        $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
        $machineWallet->save();

        // ⚡ 异步创建送礼记录（不阻塞API响应）
        $this->asyncCreateBetRecord(
            playerId: $player->id,
            platformId: $this->platform->id,
            gameCode: $data['GameType'],
            orderNo: $data['RecordNumber'],
            bet: $bet,
            originalData: $data,
            orderTime: $data['GiftTime'],
            updateStats: false // 送礼不更新统计
        );

        // ✅ 立即从缓存读取余额
        $balance = \app\service\WalletService::getBalance($player->id);

        return [
            'MerchantOrderNumber' => $data['RecordNumber'], // ✅ 使用外部订单号
            'Balance' => $balance,
            'SyncTime' => date('Y-m-d H:i:s'),
        ];
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
