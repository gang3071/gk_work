<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\wallet\controller\game\SPSDYGameController;
use Carbon\Carbon;
use Exception;
use support\Cache;
use WebmanTech\LaravelHttpClient\Facades\Http;

class SPSDYServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
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


    /**
     * 查询余额
     * @return mixed
     * @deprecated 已迁移到 Redis Lua 原子脚本，此方法不再使用
     */
    public function balance(): mixed
    {
        // 使用单一钱包，余额统一管理
        return \app\service\WalletService::getBalance($this->player->id);
    }

    /**
     * 下注
     * @param $data
     * @return mixed
     * @deprecated 已迁移到 RedisLuaScripts::atomicBet，此方法不再使用
     */
    public function bet($data): mixed
    {
        // 已迁移到 Controller 中使用 RedisLuaScripts::atomicBet
        throw new \RuntimeException('bet() 方法已废弃，请使用 RedisLuaScripts::atomicBet');
    }

    /**
     * 取消下注
     * @param $data
     * @return mixed
     * @deprecated 已迁移到 RedisLuaScripts::atomicCancel，此方法不再使用
     */
    public function cancelBet($data): mixed
    {
        // 已迁移到 Controller 中使用 RedisLuaScripts::atomicCancel
        throw new \RuntimeException('cancelBet() 方法已废弃，请使用 RedisLuaScripts::atomicCancel');
    }

    /**
     * 结算
     * @param $data
     * @return mixed
     * @deprecated 已迁移到 RedisLuaScripts::atomicSettle，此方法不再使用
     */
    public function betResulet($data): mixed
    {
        // 已迁移到 Controller 中使用 RedisLuaScripts::atomicSettle
        throw new \RuntimeException('betResulet() 方法已废弃，请使用 RedisLuaScripts::atomicSettle');
    }

    /**
     * 重新结算
     * @param $data
     * @return mixed
     * @deprecated 已迁移到 RedisLuaScripts::atomicSettle，此方法不再使用
     */
    public function reBetResulet($data): mixed
    {
        // 已迁移到 Controller 中使用 RedisLuaScripts::atomicSettle
        throw new \RuntimeException('reBetResulet() 方法已废弃，请使用 RedisLuaScripts::atomicSettle');
    }

    /**
     * 送礼
     * @deprecated 平台不支持送礼功能
     * @param $data
     * @return mixed
     */
    public function gift($data): mixed
    {
        // 平台不支持送礼功能
        throw new \RuntimeException('平台不支持 gift() 功能');
    }

    /**
     * 解密数据
     * @deprecated 平台不需要解密
     * @param $data
     * @return mixed
     */
    public function decrypt($data): mixed
    {
        // 平台不需要解密
        return [];
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
