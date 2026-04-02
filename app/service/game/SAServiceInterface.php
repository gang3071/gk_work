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
use app\wallet\controller\game\MtGameController;
use app\wallet\controller\game\SAGameController;
use Carbon\Carbon;
use Exception;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class SAServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
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
        //TODO数据库增加SP平台
        $this->config = config('game_platform.SA');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'SA')->first();
        $this->player = $player;
        $this->log = Log::channel('sa_server');
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
        $time = Carbon::now()->format('YmdHis');
        $key = $this->config['secret'];
        $params['Time'] = $time;
        $params['Key'] = $key;

        $qs = http_build_query($params);
        $requestStr = $this->encrypt($qs);
        $md5 = md5($qs . $this->config['md5_key'] . $time . $key);

        $response = Http::timeout(7)
            ->asForm()
            ->post($this->config['api_domain'], ['q' => $requestStr, 's' => $md5]);

        if (!$response->ok()) {
            $this->log->error($this->config['api_domain'] . "/$url", ['params' => $params, 'body' => ['q' => $requestStr, 's' => $md5], 'response' => $response->body()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }

        $xml = simplexml_load_string($response->body());
        $res = json_decode(json_encode($xml), true);

        if (empty($res)) {
            throw new Exception(trans('system_busy', [], 'message'));
        }
        return $res;
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
     * 查詢玩家餘額
     * @return float
     * @throws GameException
     */
    public function getBalance(): float
    {
        // ✅ 使用 Redis 缓存查询余额
        return \app\service\WalletService::getBalance($this->player->id);
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
            'method' => 'RegUserInfo',
            'Username' => $this->player->uuid,
            'CurrencyType' => $this->currency[$this->player->currency],
        ];
        $res = $this->doCurl('RegUserInfo', $params);
        if ($res['ErrorMsgId'] != '0') {
            $this->log->info('createPlayer', [$res]);
            throw new GameException($res['ErrorMsg'], 0);
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
            'method' => 'LoginRequest',
            'Username' => $this->player->uuid,
            'CurrencyType' => $this->currency[$this->player->currency],
            'Lang' => $this->lang[$data['lang']],
        ];
        $res = $this->doCurl('LoginRequest', $params);
        if ($res['ErrorMsgId'] != '0') {
            $this->log->info('lobbyLogin', [$res]);
            throw new GameException($res['ErrorMsg'], 0);
        }

        return $res['GameLaunchURL'];
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $params = [
            'method' => 'GetActiveHostList',
        ];
        $res = $this->doCurl('GetActiveGameList ', $params);
        if ($res['ErrorMsgId'] != '0') {
            $this->log->info('lobbyLogin', [$res]);
            throw new GameException($res['ErrorMsg'], 0);
        }

        $insertData = [];
        if (!empty($res['HostList']['Host'])) {
            foreach ($res['HostList']['Host'] as $item) {
//                if($item['GameType'] == 'slot'){
                $cate = GameType::CATE_SLO;
//                }elseif($item['GameType'] == 'game'){
//                    $cate = GameType::CATE_TABLE;
//                }else{
                $cate = GameType::CATE_LIVE_VIDEO;
//                }
                $insertData[] = [
                    'game_id' => $item['HostID'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => $cate,
                    'name' => $item['HostName'],
                    'code' => $item['HostID'],
                    'logo' => current($item['GameIcon']),
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
            'method' => 'LoginRequest',
            'Username' => $this->player->uuid,
            'CurrencyType' => $this->currency[$this->player->currency],
            'Lang' => $this->lang[$lang],
            'GameCode' => $game->game_extend->code,
        ];
        $res = $this->doCurl('LoginRequest', $params);
        if ($res['ErrorMsgId'] != '0') {
            $this->log->info('lobbyLogin', [$res]);
            throw new GameException($res['ErrorMsg'], 0);
        }

        return $res['GameLaunchURL'];
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
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return SAGameController::API_CODE_INSUFFICIENT_BALANCE;
    }

    /**
     * 下注（异步优化版）
     * @param $data
     * @return mixed
     */
    public function bet($data): mixed
    {
        $player = $this->player;

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return \app\service\WalletService::getBalance($this->player->id);
        }

        $bet = $data['amount'];
        $orderNo = $data['txnid'];

        // ✅ Redis预检查幂等性（在事务外，避免不必要的数据库锁）
        $betKey = "sa:bet:lock:{$orderNo}";
        $isLocked = \support\Redis::set($betKey, 1, ['NX', 'EX' => 300]);
        if (!$isLocked) {
            // 重复订单，直接返回当前余额
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return \app\service\WalletService::getBalance($this->player->id);
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        if ($machineWallet->money < $bet) {
            $this->error = SAGameController::API_CODE_INSUFFICIENT_BALANCE;
            return \app\service\WalletService::getBalance($this->player->id);
        }

        // ✅ 同步扣减余额（触发 updated 事件，自动更新 Redis 缓存）
        $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
        $machineWallet->save();

        // ⚡ 异步创建下注记录（不阻塞API响应）
        $this->asyncCreateBetRecord(
            playerId: $this->player->id,
            platformId: $this->platform->id,
            gameCode: $data['hostid'],
            orderNo: $orderNo,
            bet: $bet,
            originalData: $data,
            orderTime: $data['timestamp']
        );

        // ✅ 立即从缓存读取余额
        return \app\service\WalletService::getBalance($player->id);
    }

    /**
     * 取消单（异步优化版）
     * @param $data
     * @return float|string
     */
    public function cancelBet($data): float|string
    {
        /** @var PlayGameRecord $record */
        // ✅ 加锁查询，防止并发重复退款
        $record = PlayGameRecord::query()
            ->where('order_no', $data['txn_reverse_id'])
            ->lockForUpdate()
            ->first();

        if (!$record) {
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return \app\service\WalletService::getBalance($this->player->id);
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return \app\service\WalletService::getBalance($this->player->id);
        }

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
     * 结算（异步优化版）
     * @param $data
     * @return mixed
     */
    public function betResulet($data): mixed
    {
        $detail = json_decode($data['payoutdetails'], true);
        $betList = $detail['betlist'];

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        // ✅ 同步增加余额（有金额时，触发 updated 事件，自动更新 Redis 缓存）
        if (isset($data['amount']) && $data['amount'] > 0) {
            $machineWallet->money = bcadd($machineWallet->money, $data['amount'], 2);
            $machineWallet->save();
        }

        foreach ($betList as $betInfo) {
            $orderNo = $betInfo['txnid'];

            // ⚡ 异步更新结算记录（不阻塞API响应）
            // 记录检查交由Consumer处理（如果不存在会先创建，如果已结算会跳过）
            $this->asyncUpdateSettleRecord(
                orderNo: $orderNo,
                win: max($betInfo['resultamount'], 0),
                diff: $betInfo['resultamount']
            );
        }

        // ✅ 立即从缓存读取余额
        return \app\service\WalletService::getBalance($this->player->id);
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

    /**
     * 解密数据
     * @param $data
     * @return mixed
     */
    public function decrypt($data): mixed
    {
        $desKey = $this->config['des_key'];
        $str = openssl_decrypt(base64_decode(urldecode($data)), 'DES-CBC', $desKey, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $desKey);
        $str = rtrim($str, "\x01..\x1F");
        parse_str($str, $array);

        if (empty($array)) {
            return $this->error = SAGameController::API_CODE_DECRYPT_ERROR;
        }

        $player = Player::query()->where('uuid', $array['username'])->first();
        if (!$player) {
            return $this->error = MtGameController::API_CODE_PLAYER_NOT_EXIST;
        }

        $this->player = $player;

        return $array;
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
