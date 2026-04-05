<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\model\PlayGameRecord;
use app\wallet\controller\game\RsgGameController;
use app\wallet\controller\game\RsgLiveGameController;
use Carbon\Carbon;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * rsg真人平台
 */
class RSGLiveServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    public $method = 'POST';
    public $successCode = '0';
    private $systemCode;
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
        'createPlayer' => '/api/Player/CreateOrSetUser',
        'userLogout' => '/SingleWallet/Player/Kickout',
        'getGameHistories' => '/SingleWallet/History/GetGameDetail',
        'lobbyLogin' => '/api/Player/Login',
        'getGameList' => '/SingleWallet/Game/GameList',
        'gameLogin' => '/SingleWallet/Player/GetURLToken',
        'replay' => '/SingleWallet/Player/GetSlotGameRecordURLToken',
    ];

    private $currency = [
        'TWD' => 'TWD',
        'CNY' => 'TWD',
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
        $config = config('game_platform.RSG_LIVE');
        $this->config = $config;
        $this->systemCode = $config['SystemCode'];
        $this->apiDomain = $config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'RSGLIVE')->first();
        $this->player = $player;
        $this->log = Log::channel('rsg_live_server');
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
            'MemberAccount' => $this->player->uuid,
            'MemberName' => $this->player->name ?: $this->player->uuid,
            'StopBalance' => -1,
            'BetLimitGroup' => '1,2,3',
            'Currency' => $this->currency[$this->player->currency],
            'Language' => 'zh-TW',
            'OpenGameList' => 'ALL',
        ];

        $res = $this->doCurl($this->createUrl('createPlayer'), $params);
        if ($res['msgId'] != $this->successCode) {
            $this->log->info('createPlayer', ['params' => $params, 'response' => $res]);
            if ($res['msgId'] == '3010') {
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
        $config = $this->config;
        $encryptData = openssl_encrypt(json_encode($params), 'DES-CBC', $config['DesKey'], OPENSSL_RAW_DATA,
            $config['DesIV']);

        $reqBase64 = base64_encode($encryptData);
        $clientSecret = $config['client_secret'];
        $clientID = $config['client_id'];
        $timestamp = time();
        $sourceString = base64_encode(hex2bin(md5($clientID . $clientSecret . $timestamp . $reqBase64)));

        $response = Http::timeout(7)
            ->withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'X-API-ClientID' => $clientID,
                'X-API-Signature' => $sourceString,
                'X-API-Timestamp' => $timestamp
            ])
            ->withBody(urlencode($reqBase64), 'application/json')
            ->post($url);


        $data = $response->body();
        $data = openssl_decrypt(base64_decode($data), 'DES-CBC', $config['DesKey'], OPENSSL_RAW_DATA,
            $config['DesIV']);

        if (!$response->ok()) {
            $this->log->error($url, ['config' => $config, 'params' => $params, 'response' => $data]);
            throw new GameException(trans('system_busy', [], 'message'));
        }

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
            'MemberAccount' => $this->player->uuid,
            'Lang' => 'zh-TW',
        ];
        $res = $this->doCurl($this->createUrl('lobbyLogin'), $params);
        $this->log->info('lobbyLogin', ['params' => $params, $res]);
        if ($res['msgId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['msgId']], 0);
        }

        return $res['data']['url'] ?? '';
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
        $res = $this->doCurl($this->createUrl('gameLogin'), $params);
        $this->log->info('gameLogin', [$res]);
        Log::error('RSG -> gameLogin', [$res]);
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
        return '';
    }

    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return RsgLiveGameController::API_CODE_AMOUNT_OVER_BALANCE;
    }




    public function reBetResulet($data)
    {
        // TODO: Implement reBetResulet() method.
    }

    public function gift($data)
    {
        // TODO: Implement gift() method.
    }

    /**
     * 检查订单
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


    /**
     * 刷新token
     * @param $data
     * @return int|string
     */
    public function refresh($data): int|string
    {
        $key = $this->config['client_id'] . $this->config['client_secret'];
        [$type, $token] = explode(' ', $data);
        try {
            //没有过期重新生成
            $data = json_decode(json_encode(JWT::decode($token, new Key($key, 'HS256'))), true);
            $player = Player::query()->where('uuid', $data['memberaccount'])->first();
            if (!$player) {
                return $this->error = RsgLiveGameController::API_CODE_TOKEN_DOES_NOT_EXIST;
            }
            return $this->getToken('sessionToken', $data['memberaccount']);
        } catch (Exception $e) {
            return $this->error = RsgLiveGameController::API_CODE_CERTIFICATE_ERROR;
        }
    }

    public function decrypt($data)
    {
        $key = $this->config['client_id'] . $this->config['client_secret'];
        [$type, $token] = explode(' ', $data);
        try {
            $data = json_decode(json_encode(JWT::decode($token, new Key($key, 'HS256'))), true);
        } catch (Exception $e) {
            return $this->error = RsgLiveGameController::API_CODE_CERTIFICATE_ERROR;
        }
        $config = $this->config;

        if (empty($data['systemcode']) || $data['systemcode'] != $config['SystemCode']) {
            return $this->error = RsgLiveGameController::API_CODE_CERTIFICATE_ERROR;
        }

        $player = Player::query()->where('uuid', $data['memberaccount'])->first();
        if (!$player) {
            return $this->error = RsgLiveGameController::API_CODE_CERTIFICATE_ERROR;
        }

        $this->player = $player;

        return $data;
    }

    public function getWebId()
    {
        //todo 后续需要去后台手动创建每个渠道各自的webid  暂时写死方便测试
        return 'gk198';
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

    public function getToken($type, $account, $expire = 3600): string
    {
        $SessionTokenPayload = [
            'systemcode' => $this->config['SystemCode'],
            'webId' => $this->getWebId(),
            'memberaccount' => $account,
            'tokentype' => $type,
            'iat' => time(), // 签发时间
            'nbf' => time(), // 某个时间点后才能访问
            'exp' => time() + $expire, // 过期时间
        ];

        $key = $this->config['client_id'] . $this->config['client_secret'];
        return JWT::encode($SessionTokenPayload, $key, 'HS256');
    }

}
