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

            var_dump($queryParams, $query);
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
    public function bet($data): mixed
    {

        /** @var Player $player */
        $player = $this->player;
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();
        $bet = $data['Bet'];

        if ($machineWallet->money < $bet) {
            $this->error = KTGameController::API_CODE_AMOUNT_OVER_BALANCE;
            return $player->machine_wallet->money;
        }

        //KT奖金游戏会把多次下注整合到一笔订单当中
        if (PlayGameRecord::query()->where('order_no', $data['MainTxID'])->exists()) {
            /** @var PlayGameRecord $originRecord */
            $originRecord = PlayGameRecord::query()->where('order_no', $data['MainTxID'])->first();
            $newOriginData = json_decode($originRecord->original_data, true);
            $newOriginData[] = $data;
            //需要对原订单进行追加下注
            $originRecord->bet += $bet;
            $originRecord->original_data = json_encode($newOriginData);
        }

        //如果是累计下注则不需要产生新记录
        if (isset($originRecord)) {
            $originRecord->save();
            $record = $originRecord;
        } else {
            $insert = [
                'player_id' => $player->id,
                'parent_player_id' => $player->recommend_id ?? 0,
                'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $player->uuid,
                'platform_id' => $this->platform->id,
                'game_code' => $data['GameID'],
                'department_id' => $player->department_id,
                'bet' => $bet,
                'win' => 0,
                'diff' => 0,
                'order_no' => $data['MainTxID'],
                'original_data' => json_encode([$data]),
                'order_time' => Carbon::createFromTimestamp($data['BetTimestamp'])->toDateTimeString(),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
            ];
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);
        }

        return $this->createBetRecord($machineWallet, $player, $record, $bet);
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
        $record = PlayGameRecord::query()->where('order_no', $data['MainTxID'])->first();

        $player = $this->player;
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();
        if (!$record) {
            $this->error = KTGameController::API_CODE_OTHER_ERROR;
            return $player->machine_wallet->money;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            $this->error = KTGameController::API_CODE_OTHER_ERROR;
            return $player->machine_wallet->money;
        }

        //根据多订单总和进行处理
        $originData = json_decode($record->original_data, true);

        $money = array_sum(array_column($originData, 'Win'));

        //有金额则为赢
        if ($money > 0) {
            $beforeGameAmount = $machineWallet->money;
            //处理用户金额记录
            // 更新玩家统计
            $machineWallet->money = bcadd($machineWallet->money, $money, 2);
            $machineWallet->save();
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
            $playerDeliveryRecord->remark = '遊戲結算';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();
        }

        $record->platform_action_at = Carbon::createFromTimestamp($data['Timestamp'])->toDateTimeString();
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->action_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $record->win = $money;
        $record->diff = $money - $record->bet;
        $record->save();

        //彩金记录
        Client::send('game-lottery', ['player_id' => $player->id, 'bet' => $record->bet, 'play_game_record_id' => $record->id]);

        return $machineWallet->money;
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
