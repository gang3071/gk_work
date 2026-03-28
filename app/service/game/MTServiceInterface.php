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
use app\wallet\controller\game\MtGameController;
use Exception;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Client;
use WebmanTech\LaravelHttpClient\Facades\Http;

class MTServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
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
        if (PlayGameRecord::query()->where('order_no', $data['bet_sn'])->exists()) {
            return $this->error = MtGameController::API_CODE_DUPLICATE_ORDER;
        }

        $player = $this->player;
        $bet = $data['order_money'];

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return $this->error;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        if ($machineWallet->money < $bet) {
            return $this->error = MtGameController::API_CODE_INSUFFICIENT_BALANCE;
        }

        $insert = [
            'player_id' => $this->player->id,
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $data['game_code'],
            'department_id' => $player->department_id,
            'bet' => $data['order_money'],
            'win' => 0,
            'diff' => 0,
            'order_no' => $data['bet_sn'],
            'original_data' => json_encode($data),
            'order_time' => $data['order_time'],
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
        $record = PlayGameRecord::query()->where('order_no', $data['bet_sn'])->first();

        if (!$record) {
            return $this->error = MtGameController::API_CODE_ORDER_NOT_EXIST;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
            return $this->error = MtGameController::API_CODE_ORDER_CANCELLED;
        }

        //返还用户金钱  修改注单状态
        $bet = $record['bet'];
        return $this->createCancelBetRecord($record, $data, $bet);
    }

    /**
     * 结算
     * @return mixed
     */
    public function betResulet($data)
    {
        /** @var PlayGameRecord $record */
        $record = PlayGameRecord::query()->where('order_no', $data['bet_sn'])->first();

        if (!$record) {
            return $this->error = MtGameController::API_CODE_ORDER_NOT_EXIST;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            return $this->error = MtGameController::API_CODE_ORDER_SETTLED;
        }
        //处理用户中奖金额
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();


        //中奖金额包含本金 除了未中奖 其他都是直接相加处理
        if ($data['status'] != self::BET_STATUS_NOT) {
            $money = $data['win_money'];
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
            $playerDeliveryRecord->remark = '遊戲結算';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();
        }

        $record->platform_action_at = $data['settle_time'];
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->action_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $record->win = $data['win_money'] <= $record->bet ? 0 : $data['profit'];
        $record->diff = $data['profit'];
        $record->save();


        //彩金记录
//        Client::send('game-lottery', ['player_id' => $this->player->id, 'bet' => $record->bet, 'play_game_record_id' => $record->id]);

        return $this->player->machine_wallet->money;
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

        //如會員重新結算前輸贏結果為【中獎】，但因重新結算後為【未中獎】，需扣回已派發的彩金與下注金額，若這中間會員將餘額轉出或下注其他桌號，可能會發生餘額不足的情況
        $actionData = json_decode($record->action_data, true);
        if ($actionData['status'] == self::BET_STATUS_YES && $data['status'] != self::BET_STATUS_NOT) {
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
            $money = $data['win_money'];
            $beforeGameAmount = $machineWallet->money;
            $machineWallet->money = bcsub($machineWallet->money, $money, 2);
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
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_RE_SETTLEMENT;
            $playerDeliveryRecord->source = 'player_re_bet_settlement';
            $playerDeliveryRecord->amount = $money;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $record->order_no ?? '';
            $playerDeliveryRecord->remark = '重新結算';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            if ($machineWallet->money < $money) {
                //重结算异常订单(结算错误 赢钱需要扣除 提现时处理)
                $record->is_rebet = 1;
                return $this->error = MtGameController::API_CODE_INSUFFICIENT_BALANCE;
            }
        }

        $record->platform_action_at = $data['settle_time'];
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
        $record->action_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $record->win = $data['win_money'] <= $record->bet ? 0 : $data['profit'];
        $record->diff = $data['profit'];
        $record->save();

        //彩金记录
        Client::send('game-lottery', ['player_id' => $this->player->id, 'bet' => $record->bet, 'play_game_record_id' => $record->id]);
        return $this->player->machine_wallet->money;
    }

    /**
     * 送礼
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

        //下注记录  todo 暂时使用原表结构 待后续优化
        $insert = [
            'player_id' => $this->player->id,
            'parent_player_id' => $player->recommend_id ?? 0,
            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
            'player_uuid' => $player->uuid,
            'platform_id' => $this->platform->id,
            'game_code' => $data['game_code'],
            'department_id' => $player->department_id,
            'bet' => $data['money'],
            'win' => 0,
            'diff' => 0,
            'order_no' => $data['tip_sn'],
            'original_data' => json_encode($data),
            'order_time' => $data['tran_time'],
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

        return $machineWallet->money;
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
