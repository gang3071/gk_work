<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
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
            throw new GameException($this->failCode[$res['codeId']], 0);
        }

        return $res['balance'] ?? 0;
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
            'currencyName' => $this->currency[$this->player->currency],
            'winLimit' => 0,
        ];
        $res = $this->doCurl($this->createUrl('createPlayer'), $params);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']], 0);
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
        $res = $this->doCurl($this->createUrl('lobbyLogin'), [
            'username' => $this->player->uuid,
            'password' => md5($password),
            'currencyName' => $this->currency[$this->player->currency],
            'language' => $this->lang[$data['lang']],
            'limitGroup' => 'C',
            'winLimit' => 0,
        ]);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']], 0);
        }

        Log::channel('dg_server')->info('ss', [$res]);
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
            throw new GameException($this->failCode[$res['codeId']], 0);
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
            throw new GameException($this->failCode[$res['codeId']], 0);
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
            throw new GameException($this->failCode[$res['codeId']], 0);
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
            throw new GameException($this->failCode[$res['codeId']], 0);
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
        $res = $this->doCurl($this->createUrl('lobbyLogin'), [
            'username' => $this->player->uuid,
            'password' => md5($password),
            'currencyName' => $this->currency[$this->player->currency],
            'language' => $this->lang[$lang],
            'limitGroup' => 'C',
            'winLimit' => 0,
        ]);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']], 0);
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
     * 下注
     * @return mixed
     */
    public function bet($data)
    {
        $return = [];
        $player = $this->player;
        $detail = json_decode($data['detail'], true);
        $bet = abs($data['member']['amount']);

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();
        if ($machineWallet->money < $bet) {
            $this->error = DGGameController::API_CODE_INSUFFICIENT_BALANCE;
            return $player->machine_wallet->money;
        }

        //DG单场下注记录是使用的同一个单号  需要合并处理
        if (PlayGameRecord::query()->where('order_no', $data['ticketId'])->exists()) {
            /** @var PlayGameRecord $originRecord */
            $originRecord = PlayGameRecord::query()->where('order_no', $data['ticketId'])->first();
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
            //下注记录  todo 暂时使用原表结构 待后续优化
            $insert = [
                'player_id' => $player->id,
                'parent_player_id' => $player->recommend_id ?? 0,
                'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $player->uuid,
                'platform_id' => $this->platform->id,
                'game_code' => $detail['gameId'],
                'department_id' => $player->department_id,
                'bet' => $bet,
                'win' => 0,
                'diff' => 0,
                'order_no' => $data['ticketId'],
                'original_data' => json_encode([$data]),
                'order_time' => Carbon::now()->toDateTimeString(),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
            ];
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

        }
        $beforeGameAmount = $machineWallet->money;

        $this->createBetRecord($machineWallet, $player, $record, $bet);
        $return['member'] = [
            'username' => $data['member']['username'],
            'balance' => $beforeGameAmount,
            'amount' => $data['member']['amount'],
        ];

        return $return;
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
        $return = [];

        $player = $this->player;
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

        //需要循环处理下注订单
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['ticketId'])->first();

        if (!$record) {
            $this->error = DGGameController::API_CODE_DECRYPT_ERROR;
            return $this->player->machine_wallet->money;
        }

        $detail = json_decode($data['detail'], true);
        $money = $data['member']['amount'];
        $beforeGameAmount = $machineWallet->money;
        //有金额则为赢
        if ($money > 0) {
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
            $playerDeliveryRecord->remark = '遊戲結算';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();
        }

        $record->platform_action_at = Carbon::now()->toDateTimeString();
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->action_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $record->win = $detail['winOrLoss'];
        $record->diff = $detail['winOrLoss'] - $record->bet;
        $record->save();

        $return['member'] = [
            'username' => $data['member']['username'],
            'balance' => $beforeGameAmount,
            'amount' => $money,
        ];

        //彩金记录
        Client::send('game-lottery', ['player_id' => $this->player->id, 'bet' => $record->bet, 'play_game_record_id' => $record->id]);

        return $return;
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
