<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\wallet\controller\game\SAGameController;
use app\wallet\controller\game\TNineGameController;
use Exception;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class TNineServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
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
    public function bet($data): mixed
    {
        $orders = $data['OrderList'];

        $return = [];
        /** @var Player $player */
        $player = Player::query()->where('uuid', $data['MemberAccount'])->first();
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();
        $allAmount = array_sum(array_column($orders, 'BetAmount'));

        if ($machineWallet->money < $allAmount) {
            $this->error = TNineGameController::API_CODE_INSUFFICIENT_BALANCE;
            return $player->machine_wallet->money;
        }
        foreach ($orders as $order) {
            $bet = $order['BetAmount'];
            //下注记录  todo 暂时使用原表结构 待后续优化
            $insert = [
                'player_id' => $player->id,
                'parent_player_id' => $player->recommend_id ?? 0,
                'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $player->uuid,
                'platform_id' => $this->platform->id,
                'game_code' => $data['GameType'],
                'department_id' => $player->department_id,
                'bet' => $bet,
                'win' => 0,
                'diff' => 0,
                'order_no' => $order['OrderNumber'],
                'original_data' => json_encode($order),
                'order_time' => $order['BetTime'],
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
            ];
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

            $balance = $this->createBetRecord($machineWallet, $player, $record, $bet);

            $return['OrderList'][] = [
                'OrderNumber' => $order['OrderNumber'],
                'MerchantOrderNumber' => $record->id,
            ];

            $return['Balance'] = $balance;

        }

        $return['SyncTime'] = date('Y-m-d H:i:s');

        return $return;
    }

    /**
     * 取消单
     * @param $data
     * @return float|string
     */
    public function cancelBet($data): float|string
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['txn_reverse_id'])->first();

        if (!$record) {
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return $this->player->machine_wallet->money;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return $this->player->machine_wallet->money;
        }

        //返还用户金钱  修改注单状态
        $bet = $data['amount'];
        return $this->createCancelBetRecord($record, $data, $bet);
    }

    /**
     * 结算
     * @param $data
     * @return mixed
     */
    public function betResulet($data): mixed
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['OrderNumber'])->first();

        $return = [];
        /** @var Player $player */
        $player = Player::query()->where('uuid', $data['MemberAccount'])->first();
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();
        if (!$record) {
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return $player->machine_wallet->money;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return $player->machine_wallet->money;
        }

        $money = $data['GameAmount'];

        //有金额则为赢
        if ($data['WinAmount'] > 0) {
            $beforeGameAmount = $machineWallet->money;
            //处理用户金额记录
            // 更新玩家统计
            $machineWallet->money = bcadd($machineWallet->money, $money, 2);
            $machineWallet->save();
            //todo 语言文件后续处理
            //用户交易记录  现在单一钱包没有转账的说法 暂不记录转账记录
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $record->getTable();
            $playerDeliveryRecord->target_id = $record->id;
            $playerDeliveryRecord->platform_id = $this->platform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
            $playerDeliveryRecord->source = 'player_bet_settlement';
            $playerDeliveryRecord->amount = $money;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $record->order_no ?? '';
            $playerDeliveryRecord->remark = $target->remark ?? '';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();
        }

        $record->platform_action_at = $data['PayoutTime'];
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->action_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $record->win = $money;
        $record->diff = $data['WinAmount'];
        $record->save();

        $return = [
            'MerchantOrderNumber' => $record->id,
            'SyncTime' => date('Y-m-d H:i:s'),
            'Balance' => $machineWallet->money,
        ];

        //彩金记录
        Client::send('game-lottery', ['player_id' => $player->id, 'bet' => $record->bet, 'play_game_record_id' => $record->id]);

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

        //送礼记录
        $insert = [
            'player_id' => $player->id,
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $data['GameType'],
            'department_id' => $player->department_id,
            'bet' => $bet,
            'win' => 0,
            'diff' => 0,
            'order_no' => $data['RecordNumber'],
            'original_data' => json_encode($data),
            'order_time' => $data['GiftTime'],
            'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_SETTLED,
            'type' => PlayGameRecord::TYPE_GIFT
        ];

        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->create($insert);

        $beforeGameAmount = $machineWallet->money;
        $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
        $machineWallet->save();

        //todo 语言文件后续处理
        //用户交易记录  现在单一钱包没有转账的说法 暂不记录转账记录
        $playerDeliveryRecord = new PlayerDeliveryRecord;
        $playerDeliveryRecord->player_id = $player->id;
        $playerDeliveryRecord->department_id = $player->department_id;
        $playerDeliveryRecord->target = $record->getTable();
        $playerDeliveryRecord->target_id = $record->id;
        $playerDeliveryRecord->platform_id = $this->platform->id;
        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_GIFT;
        $playerDeliveryRecord->source = 'player_gift';
        $playerDeliveryRecord->amount = $bet;
        $playerDeliveryRecord->amount_before = $beforeGameAmount;
        $playerDeliveryRecord->amount_after = $machineWallet->money;
        $playerDeliveryRecord->tradeno = $record->order_no;
        $playerDeliveryRecord->remark = '';
        $playerDeliveryRecord->user_id = 0;
        $playerDeliveryRecord->user_name = '';
        $playerDeliveryRecord->save();

        return [
            'MerchantOrderNumber' => $record->id,
            'Balance' => $machineWallet->money,
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
        return $this->player->machine_wallet->money;
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
