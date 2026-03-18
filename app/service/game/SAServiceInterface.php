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
use app\wallet\controller\game\MtGameController;
use app\wallet\controller\game\SAGameController;
use Carbon\Carbon;
use Exception;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class SAServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
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
        return $this->player->machine_wallet->money;
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
     * 下注
     * @param $data
     * @return mixed
     */
    public function bet($data): mixed
    {
        if (PlayGameRecord::query()->where('order_no', $data['txnid'])->exists()) {
            $this->error = SAGameController::API_CODE_GENERAL_ERROR;
            return $this->player->machine_wallet->money;
        }

        $player = $this->player;

        $bet = $data['amount'];
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        if ($machineWallet->money < $bet) {
            $this->error = MtGameController::API_CODE_INSUFFICIENT_BALANCE;
            return $this->player->machine_wallet->money;
        }
        //下注记录  todo 暂时使用原表结构 待后续优化
        $insert = [
            'player_id' => $this->player->id,
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $data['hostid'],
            'department_id' => $player->department_id,
            'bet' => $data['amount'],
            'win' => 0,
            'diff' => 0,
            'order_no' => $data['txnid'],
            'original_data' => json_encode($data),
            'order_time' => $data['timestamp'],
            'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
        ];
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->create($insert);

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
        $detail = json_decode($data['payoutdetails'], true);
        $betList = $detail['betlist'];
        //处理用户中奖金额
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        foreach ($betList as $betInfo) {
            $orderNo = $betInfo['txnid'];
            //判断是否打鱼机游戏 需要分别处理数据
//            if($betInfo['bettype'] == self::BET_TYPE_FISH){
//                $orderNo =
//            }

            //需要循环处理下注订单
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->where('order_no', $orderNo)->first();

            if (!$record) {
                $this->error = SAGameController::API_CODE_GENERAL_ERROR;
                return $this->player->machine_wallet->money;
            }

            if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                $this->error = SAGameController::API_CODE_GENERAL_ERROR;
                return $this->player->machine_wallet->money;
            }

            //有金额则为赢
            if (isset($data['amount'])) {
                $money = $data['amount'];
                $beforeGameAmount = $machineWallet->money;
                //处理用户金额记录
                // 更新玩家统计
                $machineWallet->money = bcadd($machineWallet->money, $money, 2);
                $machineWallet->save();

                $player = $this->player;
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

            $record->platform_action_at = $data['Payouttime'];
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $record->action_data = json_encode($betInfo, JSON_UNESCAPED_UNICODE);
            $record->win = max($betInfo['resultamount'], 0);
            $record->diff = $betInfo['resultamount'];
            $record->save();

            //彩金记录
//            Client::send('game-lottery', ['player_id' => $this->player->id, 'bet' => $record->bet, 'play_game_record_id' => $record->id]);
        }

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
