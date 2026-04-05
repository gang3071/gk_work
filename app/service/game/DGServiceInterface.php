<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\wallet\controller\game\DGGameController;
use app\wallet\controller\game\O8GameController;
use Exception;
use support\Cache;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class DGServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    use LimitGroupTrait;

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
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }

        return $res['balance'] ?? 0;
    }

    /**
     * 获取玩家的DG限红组配置（通过店家）
     * @return array|null
     */
    private function getPlayerLimitConfig(): ?array
    {
        // 使用 Trait 中的通用方法获取限红组配置
        $limitGroupConfig = $this->getLimitGroupConfig('dg_server');

        // 如果没有配置数据，返回null
        if (!$this->hasLimitGroupConfigData($limitGroupConfig)) {
            return null;
        }

        // 从config_data中获取DG限红配置 {"max": 2, "min": 1}
        $configData = $limitGroupConfig->config_data;

        return [
            'max' => $configData['max'] ?? null,
            'min' => $configData['min'] ?? null,
            'limit_group_id' => $limitGroupConfig->limit_group_id,
        ];
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
            'currencyName' => $this->currency[$this->player->currency] ?? 'TWD',
            'winLimit' => 0,
        ];
        $res = $this->doCurl($this->createUrl('createPlayer'), $params);
        if ($res['codeId'] != $this->successCode && $res['codeId'] != '116') {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
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

        $params = [
            'username' => $this->player->uuid,
            'password' => md5($password),
            'currencyName' => $this->currency[$this->player->currency] ?? 'TWD',
            'language' => 'tw',
        ];

        // 获取限红组配置
        $limitConfig = $this->getPlayerLimitConfig();
        if ($limitConfig && isset($limitConfig['max']) && isset($limitConfig['min'])) {
            $params['limits'][] = [
                'max' => $limitConfig['max'],
                'min' => $limitConfig['min'],
            ];
        }

        Log::channel('dg_server')->info('lobbyLogin', ['params'=>$params]);
        $res = $this->doCurl($this->createUrl('lobbyLogin'), $params);
        Log::channel('dg_server')->info('lobbyLogin_response', [$res]);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
        }

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
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
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
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
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
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
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
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
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

        $params = [
            'username' => $this->player->uuid,
            'password' => md5($password),
            'currencyName' => $this->currency[$this->player->currency] ?? 'TWD',
            'language' => $this->lang[$lang] ?? 'tw',
        ];

        // 获取限红组配置
        $limitConfig = $this->getPlayerLimitConfig();
        if ($limitConfig && isset($limitConfig['max']) && isset($limitConfig['min'])) {
            $params['limits'][] = [
                'max' => $limitConfig['max'],
                'min' => $limitConfig['min'],
            ];
        }

        Log::channel('dg_server')->info('lobbyLogin', ['params'=>$params]);

        $res = $this->doCurl($this->createUrl('lobbyLogin'), $params);
        if ($res['codeId'] != $this->successCode) {
            throw new GameException($this->failCode[$res['codeId']] ?? '未知错误', 0);
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
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return DGGameController::API_CODE_INSUFFICIENT_BALANCE;
    }





    /**
     * 查询余额
     * @deprecated 已迁移到 Redis Lua 原子脚本，此方法不再使用
     * @return mixed
     */
    public function balance(): mixed
    {
        // 使用单一钱包，余额统一管理
        return \app\service\WalletService::getBalance($this->player->id);
    }

    /**
     * 下注
     * @deprecated 已迁移到 RedisLuaScripts::atomicBet，此方法不再使用
     * @param $data
     * @return mixed
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
     * @param $data
     * @return mixed
     * @deprecated 平台不支持送礼功能
     */
    public function gift($data): mixed
    {
        // 平台不支持送礼功能
        throw new \RuntimeException('平台不支持 gift() 功能');
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
