<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\traits\AsyncGameRecordTrait;
use app\wallet\controller\game\SAGameController;
use app\wallet\controller\game\SPSDYGameController;
use Carbon\Carbon;
use Exception;
use support\Cache;
use WebmanTech\LaravelHttpClient\Facades\Http;

class SPSDYServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    use AsyncGameRecordTrait;
    public $method = 'POST';
    public $successCode = 200;
    public $failCode = [
        200 => '成功',
        -101 => '商户号验签错误',
        -103 => '账号不存在',
        -106 => '已删除账号',
        -107 => '输入转点额度资讯不符格式(正整数)',
        -112 => '账号已存在',
        -115 => '操作过于频繁，请稍后再试',
        -116 => 'IP未加白',
        -125 => '尚未输入商户号',
        -126 => '尚未输入请求资料',
        -142 => '错误的SiteID',
        -143 => '帐密错误',
        -144 => '阶层关系错误',
        -145 => '会员找不到上层代理',
        -146 => '修改对象的上层代理找不到限红资料',
        -147 => '超过上层代理限红金额',
        -148 => '代理账号不能为会员账号',
        -149 => '没有权限修改单边限红',
        -150 => '代理名称重覆',
        -151 => '会员名称名称重覆',
        -152 => '创造会员帐户失败',
        -153 => '上层代理的UpMemName不能为L3(代理),至少为L5(总代理)',
        -154 => '创造代理帐户失败',
        -155 => '某些阶层的代理名称不存在',
        -156 => '创造会员的上层代理阶级错误',
        -157 => '帐户已被停用',
        -158 => '更新限红金额失败',
        -888 => '进入维护讯号',
        -999 => '发生错误/指令错误'
    ];

    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return SPSDYGameController::API_CODE_INSUFFICIENT_BALANCE;
    }

    public function bet($data)
    {
        $player = $this->player;
        $bet = $data['Point'];
        $orderNo = $data['TransferCode'];

        $data['TicketData'] = json_decode($data['TicketData'], true);

        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return $this->player->machine_wallet->money;
        }

        // ✅ Redis预检查幂等性（在事务外，避免不必要的数据库锁）
        $betKey = "spsdy:bet:lock:{$orderNo}";
        $isLocked = \support\Redis::set($betKey, 1, ['NX', 'EX' => 300]);
        if (!$isLocked) {
            // 重复订单，直接返回当前余额
            $this->error = SPSDYGameController::API_CODE_DECRYPT_ERROR;
            return $this->player->machine_wallet->money;
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        if ($machineWallet->money < $bet) {
            $this->error = SPSDYGameController::API_CODE_INSUFFICIENT_BALANCE;
            return $this->player->machine_wallet->money;
        }

        $beforeBalance = $machineWallet->money;

        // 同步扣款
        $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
        $machineWallet->save();

        // ⚡ 异步创建下注记录（不阻塞API响应）
        $this->asyncCreateBetRecord(
            playerId: $player->id,
            platformId: $this->platform->id,
            gameCode: '',
            orderNo: $orderNo,
            bet: $bet,
            originalData: $data,
            orderTime: Carbon::createFromTimestampMs($data['Timestamp'])->toDateTimeString()
        );

        return [
            'TransferCode' => $data['TransferCode'],
            'User' => $data['User'],
            'BeforeBalance' => $beforeBalance,
            'Balance' => \app\service\WalletService::getBalance($player->id),
        ];
    }

    public function cancelBet($data)
    {
        // TODO: Implement cancelBet() method.
    }

    public function betResulet($data)
    {
        /** @var PlayGameRecord $record */
        // ✅ 加锁查询record，防止并发重复派彩
        $record = PlayGameRecord::query()
            ->where('order_no', $data['TransferCode'])
            ->lockForUpdate()
            ->first();

        $player = $this->player;

        if (!$record) {
            $this->error = SPSDYGameController::API_CODE_DECRYPT_ERROR;
            return $player->machine_wallet->money;
        }

        if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
            $this->error = SAGameController::API_CODE_DECRYPT_ERROR;
            return $player->machine_wallet->money;
        }

        // 锁钱包
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

        $money = $data['Point'];

        $beforeGameAmount = $machineWallet->money;
        //有金额则为赢
        if ($data['Point'] > 0) {
            // 同步增加余额
            $machineWallet->money = bcadd($machineWallet->money, $money, 2);
            $machineWallet->save();
        }

        // ⚡ 异步更新结算记录（不阻塞API响应）
        // 彩金记录会在Consumer中处理
        $this->asyncUpdateSettleRecord(
            orderNo: $record->order_no,
            win: $money,
            diff: $record->bet - $money
        );

        return [
            'TransferCode' => $data['TransferCode'],
            'User' => $data['User'],
            'BeforeBalance' => $beforeGameAmount,
            'Balance' => \app\service\WalletService::getBalance($player->id),
        ];
    }

    public function reBetResulet($data)
    {
        // TODO: Implement reBetResulet() method.
    }

    public function gift($data)
    {
        // TODO: Implement gift() method.
    }

    public function decrypt($data)
    {
        // TODO: Implement decrypt() method.
    }

    private $apiDomain;
    private $lang = [
        'zh-CN' => 'zh-CN',
        'zh-TW' => 'zh-TW',
        'en' => 'en-US',
        'th' => 'th-TH',
        'vi' => 'vi-VN',
        'jp' => 'en-US',
        'kr_ko' => 'ko-KR',
        'my' => 'en-MY',
        'id' => 'id-ID',
    ];

    private array $config;

    private $currency = [
        'TWD' => 'TWD',
        'CNY' => 'CNY',
        'JPY' => 'JPY',
        'USD' => 'USA',
    ];

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $this->config = config('game_platform.SPSDY');
        $this->apiDomain = $this->config['api_domain'] . '/api/Sport';
        $this->platform = GamePlatform::query()->where('code', 'SPS_DY')->first();
        $this->player = $player;
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
        $params = [
            'Cmd' => 'TransferPoint',
            'Lang' => 'zh-CN',
            'User' => $this->player->uuid,
            'Point' => $data['amount'] ?? 0,
            'TType' => 1,
            'OrderId' => $data['order_no']
        ];

        $res = $this->doCurl($this->apiDomain, $params);

        if ($res['Code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['Code']], 0);
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
        $params = [
            'Cmd' => 'TransferPoint',
            'Lang' => 'zh-CN',
            'User' => $this->player->uuid,
            'Point' => $data['amount'] ?? 0,
            'TType' => 0,
            'OrderId' => $data['order_no']
        ];

        $res = $this->doCurl($this->apiDomain, $params);

        if ($res['Code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['Code']], 0);
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $data['order_no'],
            'amount' => $data['amount'],
            'last_amount' => $res['Data']['Balance'],
        ];
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
            $playerGamePlatform->web_id = $this->getWebId();
            $playerGamePlatform->platform_id = $this->platform->id;
            $playerGamePlatform->player_name = $this->player->name;
            $playerGamePlatform->player_code = $this->player->uuid;
            $playerGamePlatform->save();
        }

        return $playerGamePlatform;
    }

    /**
     * 注册玩家
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function createPlayer()
    {
        //测试
        $params = [
            'Cmd' => 'CreateUser',
            'Lang' => 'zh-CN',
            'User' => $this->player->uuid,
            'Password' => $this->player->uuid,
            'Name' => $this->player->name ? $this->player->name : $this->player->uuid,
            'UpAccount' => $this->getWebId() ?? 'bi310'  //后台创建 默认使用手动创建测试上级
        ];

        $res = $this->doCurl($this->apiDomain, $params);

        if ($res['Code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['Code']], 0);
        }

        return true;
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
        $config = config('game_platform.SPSDY');


        $params['VendorId'] = $config['vendor_id'];
        $params['Signature'] = $config['signature'];


        $response = Http::timeout(7)
            ->asForm()
            ->post($url, $params);

        if (!$response->ok()) {
            throw new GameException(trans('system_busy', [], 'message'));
        }

        $result = json_decode($response->body(), true);
        dump('result', $params, $result);

        if (empty($result)) {
            throw new GameException(trans('system_busy', [], 'message'));
        }

        return $result;
    }

    /**
     * 进入游戏大厅(体育)
     * @param array $data
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function lobbyLogin(array $data = []): string
    {
        $this->checkPlayer();
        //测试
        $params = [
            'Cmd' => 'LoginGame',
            'User' => $this->player->uuid,
            'Password' => $this->player->uuid,
            'Lang' => 'zh-TW',
        ];

        $res = $this->doCurl($this->apiDomain, $params);

        if ($res['Code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['Code']], 0);
        }

        return $res['Data']['RedirectUrl'] ?? '';
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
        //测试
        $params = [
            'Cmd' => 'GetUserBalance',
            'User' => $this->player->uuid,
            'Lang' => isset($data['lang']) ? $this->lang[$data['lang']] : 'zh-CN',
        ];

        $res = $this->doCurl($this->apiDomain, $params);

        if ($res['Code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['Code']], 0);
        }

        //可下分余额
        return $res['Data']['Balance'] ?? '';
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
                $accounts = $data['List'];
                foreach ($accounts as $item) {
                    /** @var Player $player */
                    $uuid = $item['User'];
                    $player = Player::withTrashed()->where('uuid', $uuid)->first();
                    if (!empty($player) && $item['IsPayout']) {   //只记录已经结算的
                        $list[] = [
                            'player_id' => $player->id,
                            'parent_player_id' => $player->recommend_id ?? 0,
                            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                            'player_uuid' => $player->uuid,
                            'platform_id' => $this->platform->id,
                            'game_code' => $item['gameID'],
                            'department_id' => $player->department_id,
                            'bet' => $item['Amount'],
                            'win' => max(0, $item['ResultAmount']),
                            'diff' => $item['ResultAmount'] - $item['Amount'],
                            'order_no' => $item['TicketID'],
                            'original_data' => json_encode($item),
                            'platform_action_at' => $item['UpdateTimeStr'],
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            throw new GameException($e->getMessage());
        }
        return $list;
    }

    /**
     * 取得區間內遊戲紀錄
     * @param int $gameType
     * @return array
     * @throws GameException
     */
    public function getGameHistories(): array
    {
        $endTime = Carbon::now()->subMinutes()->toDateTimeString();
        $startTime = Carbon::now()->subMinutes(6)->toDateTimeString();

        //测试
        $params = [
            'Cmd' => 'GetUserBalance',
            'StartTime' => $startTime,
            'EndTime' => $endTime,
        ];

        $res = $this->doCurl($this->apiDomain, $params);

        if ($res['Code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['Code']], 0);
        }


        return $res['Data'] ?? [];
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
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
    public function gameLogin(Game $game, string $lang = 'zh-CN')
    {
        $this->checkPlayer(true);
        $time_str = $this->timestamp_str();
        $ip = request()->getRealIp();
        $orderId = $this->config['agent'] . $time_str . $this->player->id;

        $build = [
            's' => 0,
            'account' => $this->player->uuid,
            'money' => 0,
            'orderid' => $orderId,
            'ip' => $ip,
            'lineCode' => $this->config['line_code'],
            'KindID' => $game->game_extend->code,
            'lang' => isset($data['lang']) ? $this->lang[$data['lang']] : 'zh-CN',
        ];

        $res = $this->doCurl($this->apiDomain . '/channelHandle', $build);

        if ($res['d']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['d']['code']], 0);
        }

        return $res['d']['url'] ?? '';
    }

    private static function desEncode($str, $key)
    {
        $pad = 16 - (strlen(trim($str)) % 16);
        $str = $str . str_repeat(chr($pad), $pad);
        $encrypt_str = openssl_encrypt($str, 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        return base64_encode($encrypt_str);
    }

    private static function timestamp_str()
    {
        $format = 'YmdHis';
        $timezone = 'Asia/Chongqing';

        // On many systems (Mac, for instance) "/etc/localtime" is a symlink
        // to the file with the timezone info
        if (is_link("/etc/localtime")) {

            // If it is, that file's name is actually the "Olsen" format timezone
            $filename = readlink("/etc/localtime");

            $pos = strpos($filename, "zoneinfo");
            if ($pos) {
                // When it is, it's in the "/usr/share/zoneinfo/" folder
                $timezone = substr($filename, $pos + strlen("zoneinfo/"));
            }
        } else {
            // On other systems, like Ubuntu, there's file with the Olsen time
            // right inside it.
            $timezone = file_get_contents("/etc/timezone");
        }
        date_default_timezone_set($timezone);

        return date($format);
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
}
