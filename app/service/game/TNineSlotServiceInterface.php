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
use app\wallet\controller\game\TNineGameController;
use app\wallet\controller\game\TNineSlotGameController;
use Carbon\Carbon;
use Exception;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * T9电子平台
 */
class TNineSlotServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
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
        'zh-CN' => 'zh_CN',
        'zh-TW' => 'zh_TW',
        'en' => 'en',
        'th' => 'th',
        'vi' => 'vi-VN',
        'jp' => 'ja',
        'kr_ko' => 'ko',
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
        $this->config = config('game_platform.TNINE_SLOT');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'TNINE_SLOT')->first();
        $this->player = $player;
        $this->log = Log::channel('tnine_slot_server');
    }

    /**
     * 组装请求
     * @param string $url
     * @param array $params
     * @param string $method
     * @return array|mixed
     * @throws GameException
     * @throws Exception
     */
    public function doCurl(string $url, array $params = [], string $method = 'post'): mixed
    {
        $agentId = $this->config['agent_id'];
        $key = $this->config['api_key'];

        $params['gameAccount'] .= '_' . $agentId;
        $params['agentId'] = $agentId;
        $params['apiKey'] = $key;
        $params['platform'] = 'T9SlotSeamless';

        $response = Http::timeout(7)
            ->asJson()
            ->post($this->config['api_domain'] . $url, $params);


        if (!$response->ok()) {
            $this->log->error($url, ['params' => $params, 'response' => $response->body()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }

        $res = json_decode($response->body(), true);


        if (empty($res)) {
            throw new Exception(trans('system_busy', [], 'message'));
        }

        if ($res['resultCode'] != 'OK') {
            $this->log->error($url, ['params' => $params, 'response' => $response->body()]);
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
            'gameAccount' => $this->player->uuid, // todo后期需要处理每个用户不同的agentid带入
            'currency' => 'TWD',
        ];
        $response = $this->doCurl('/CreatePlayer', $params);
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
        //只能根据文档手动配置
        //暂时写死文档配置方便流程处理
        $list = config('tnine-slot');
        $insertData = [];
        if (!empty($list)) {
            foreach ($list as $item) {
                $insertData[] = [
                    'game_id' => $item['game_id'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => GameType::CATE_SLO,
                    'name' => $item['name'],
                    'code' => $item['code'],
                    'table_name' => '',
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
            'gameCode' => $game->game_extend->code,
            'gameAccount' => $this->player->uuid,
            'gameCategory' => 1,
            'language' => 'zh_TW',
            'isMobileLogin' => true,
        ];
        $res = $this->doCurl('/Login', $params);
        $this->log->info('lobbyLogin', [$res]);

        return $res['data']['gameUrl'];
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
        $player = $this->player;
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

        $bet = $data['betAmount'];

        if ($machineWallet->money < $bet) {
            $this->error = TNineSlotGameController::API_CODE_ERROR;
            return $player->machine_wallet->money;
        }

        $beforeBalance = $machineWallet->money;
        //下注记录  todo 暂时使用原表结构 待后续优化
        $insert = [
            'player_id' => $player->id,
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $data['betInfoData']['SlotsFishing']['GameCode'],
            'department_id' => $player->department_id,
            'bet' => $bet,
            'win' => 0,
            'diff' => 0,
            'order_no' => $data['gameOrderNumber'],
            'original_data' => json_encode([$data], JSON_UNESCAPED_UNICODE),
            'order_time' => Carbon::createFromTimeString($data['betTime'], 'UTC')->setTimezone('Asia/Shanghai')->toDateTimeString(),
            'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
        ];
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->create($insert);

        $balance = $this->createBetRecord($machineWallet, $player, $record, $bet);

        return [
            'afterBalance' => $balance,
            'beforeBalance' => $beforeBalance,
        ];

    }

    /**
     * 取消单
     * @param $data
     * @return array
     */
    public function cancelBet($data): array
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['gameOrderNumber'])->first();

        if (!$record) {
            $this->error = TNineSlotGameController::API_CODE_ERROR;
            return [];
        }

        //返还用户金钱  修改注单状态
        $bet = $data['payoutAmount'];
        $beforeBalance = $this->player->machine_wallet->money;
        $balance = $this->createCancelBetRecord($record, $data, $bet);

        return [
            'afterBalance' => $balance,
            'beforeBalance' => $beforeBalance,
        ];
    }

    /**
     * 结算
     * @param $data
     * @return mixed
     */
    public function betResulet($data): mixed
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['gameOrderNumber'])->first();

        /** @var Player $player */
        $player = $this->player;
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();
        if (!$record) {
            $this->error = TNineGameController::API_CODE_ERROR;
            return $player->machine_wallet->money;
        }


        $money = $data['winlose'];
        $beforeGameAmount = $machineWallet->money;
        //有金额则为赢
        if ($money > 0) {
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
            $playerDeliveryRecord->remark = $target->remark ?? '';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();
        }

        $record->platform_action_at = Carbon::createFromTimeString($data['betTime'], 'UTC')
            ->setTimezone('Asia/Shanghai')
            ->toDateTimeString();
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->action_data = json_encode([$data], JSON_UNESCAPED_UNICODE);


        //免费次数则把订单合并处理
        if ($data['betKind'] == 3) {
            $newOriginData = json_decode($record->original_data, true);
            $newOriginData[] = $data;
            //需要对原订单进行追加下注
            $record->original_data = json_encode($newOriginData, JSON_UNESCAPED_UNICODE);
            $record->save();
            $record->win += $data['winlose'];
            $record->diff += $data['winlose'];
            $record->save();
        } else {
            $record->win = $data['betAmount'] + $data['winlose'];
            $record->diff = $data['winlose'];
            $record->save();
        }


        $return = [
            'afterBalance' => $machineWallet->money,
            'beforeBalance' => $beforeGameAmount,
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
        return '';
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
