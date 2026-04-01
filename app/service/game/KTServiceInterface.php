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
use app\wallet\controller\game\KTGameController;
use app\wallet\controller\game\SAGameController;
use app\wallet\controller\game\TNineGameController;
use Carbon\Carbon;
use Exception;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class KTServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
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
        'zh-CN' => 'zh-cht',
        'zh-TW' => 'zh-chs',
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

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $this->config = config('game_platform.KT');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'KT')->first();
        $this->player = $player;
        $this->log = Log::channel('kt_server');
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
        $key = $this->config['hash_key'];
        $platform = $this->config['platform'];

        if ($method == 'get') {
            $queryParams = http_build_query($params);
            $hash_hmac = hash_hmac("sha256", $queryParams, $key);

            if (!empty($queryParams)) {
                $query = $this->config['api_domain'] . '/' . $platform . $url . '?' . $queryParams . '&Hash=' . $hash_hmac;
            } else {
                $query = $this->config['api_domain'] . '/' . $platform . $url;
            }

            $response = Http::timeout(7)
                ->asJson()
                ->get($query);
        } else {
            $hash_hmac = hash_hmac("sha256", json_encode($params), $key);

            $response = Http::timeout(7)
                ->asJson()
                ->post($this->config['api_domain'] . '/' . $platform . $url . '?' . "Hash=$hash_hmac", $params);
        }

//        $this->log->info($url, ['params' => $params, 'response' => $response->body()]);

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
        $params = [
            'Username' => $this->player->uuid,
            'Currency' => $this->currency[$this->player->currency],
            'AgentName' => $this->config['agent'],
        ];
        $response = $this->doCurl('/players', $params);
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
            'Token' => $this->player->uuid,
            'LoginHall' => true,
            'AgentName' => $this->config['agent'],
            'Lang' => 'zh-cht',
            'PlayerType' => 4,
        ];
        $res = $this->doCurl('/login', $params, 'get');
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
        $params = [];
        $res = $this->doCurl('/games', $params, 'get');
        $this->log->info('getGameList', [$res]);

        $insertData = [];
        if (!empty($res['Data'])) {
            foreach ($res['Data'] as $item) {
                $cate = GameType::CATE_SLO;
                $insertData[] = [
                    'game_id' => $item['ID'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => $cate,
                    'name' => $item['Name']['zh-cht'],
                    'code' => $item['ID'],
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
            'Token' => $this->player->uuid,
            'GameID' => $game->game_extend->code,
            'AgentName' => $this->config['agent'],
            'Lang' => 'zh-cht',
            'PlayerType' => 4,
        ];
        $res = $this->doCurl('/login', $params, 'get');
        $this->log->info('lobbyLogin', [$res]);

        return $res['URL'];
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
//            $result = $this->createPlayer();
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
        return KTGameController::API_CODE_AMOUNT_OVER_BALANCE;
    }

    public function bet($data): mixed
    {

        /** @var Player $player */
        $player = $this->player;

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return \app\service\WalletService::getBalance($player->id);
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();
        $bet = $data['Bet'];

        if ($machineWallet->money < $bet) {
            $this->error = KTGameController::API_CODE_AMOUNT_OVER_BALANCE;
            return \app\service\WalletService::getBalance($player->id);
        }

        // ✅ 同步扣减余额（触发 updated 事件，自动更新 Redis 缓存）
        $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
        $machineWallet->save();

        // ⚡ 异步创建/更新下注记录（累计下注场景）
        // KT奖金游戏会把多次下注整合到一笔订单中，需要在Consumer中合并处理
        $this->asyncCreateBetRecord(
            playerId: $player->id,
            platformId: $this->platform->id,
            gameCode: $data['GameID'],
            orderNo: $data['MainTxID'],
            bet: $bet,
            originalData: $data,
            orderTime: Carbon::createFromTimestamp($data['BetTimestamp'])->toDateTimeString()
        );

        // ✅ 立即从缓存返回余额
        return \app\service\WalletService::getBalance($player->id);
    }

    /**
     * 取消单
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

        //返还用户金钱  修改注单状态
        $bet = $data['amount'];
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        // 同步退款
        $machineWallet->money = bcadd($machineWallet->money, $bet, 2);
        $machineWallet->save();
        // 异步更新状态
        $this->asyncCancelBetRecord($record->order_no);
        return \app\service\WalletService::getBalance($this->player->id);
    }

    /**
     * 结算
     * @param $data
     * @return mixed
     */
    public function betResulet($data): mixed
    {
        /** @var PlayGameRecord $record */
        // ✅ 加锁查询record，防止并发重复派彩
        $record = PlayGameRecord::query()
            ->where('order_no', $data['MainTxID'])
            ->lockForUpdate()
            ->first();

        $player = $this->player;

        if (!$record) {
            $this->error = KTGameController::API_CODE_OTHER_ERROR;
            return \app\service\WalletService::getBalance($player->id);
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            $this->error = KTGameController::API_CODE_OTHER_ERROR;
            return \app\service\WalletService::getBalance($player->id);
        }

        // 锁钱包
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

        //根据多订单总和进行处理
        $originData = json_decode($record->original_data, true);

        $money = array_sum(array_column($originData, 'Win'));

        //有金额则为赢
        if ($money > 0) {
            // 同步增加余额
            $machineWallet->money = bcadd($machineWallet->money, $money, 2);
            $machineWallet->save();
        }

        // ⚡ 异步更新结算记录（不阻塞API响应）
        $this->asyncUpdateSettleRecord(
            orderNo: $record->order_no,
            win: $money,
            diff: $money - $record->bet
        );

        //彩金记录
        Client::send('game-lottery', [
            'player_id' => $player->id,
            'bet' => $record->bet,
            'play_game_record_id' => $record->id
        ]);

        return \app\service\WalletService::getBalance($player->id);
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

    public function verifyToken($params, $hash)
    {
        $key = $this->config['hash_key'];

        $verify = hash_hmac("sha256", json_encode($params), $key);

        if ($verify !== $hash) {
            return $this->error = KTGameController::API_CODE_TOKEN_DOES_NOT_EXIST;
        }

        return true;
    }


}
