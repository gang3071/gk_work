<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use app\traits\AsyncGameRecordTrait;
use app\wallet\controller\game\TNineGameController;
use app\wallet\controller\game\TNineSlotGameController;
use Carbon\Carbon;
use Exception;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * T9电子平台
 */
class TNineSlotServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    use AsyncGameRecordTrait;
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

        if ($res['resultCode'] != 'OK') {
            $errorMsg = 'T9 API错误: ' . ($res['resultCode'] ?? '未知错误') . ' - ' . ($res['message'] ?? $response->body());
            $this->log->error($url, ['params' => $params, 'response' => $response->body(), 'result_code' => $res['resultCode'] ?? 'null']);
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
    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return TNineSlotGameController::API_CODE_INSUFFICIENT_BALANCE;
    }

    public function bet($data): mixed
    {
        // 检查设备是否爆机
        if ($this->checkAndHandleMachineCrash()) {
            return \app\service\WalletService::getBalance($this->player->id);
        }

        $player = $this->player;
        $bet = $data['betAmount'];
        $orderNo = $data['gameOrderNumber'];

        // ✅ Redis预检查幂等性（在事务外，避免不必要的数据库锁）
        $betKey = "tnine_slot:bet:lock:{$orderNo}";
        $isLocked = \support\Redis::set($betKey, 1, ['NX', 'EX' => 300]);
        if (!$isLocked) {
            // 重复订单，返回当前余额（不重复扣款）
            $currentBalance = \app\service\WalletService::getBalance($this->player->id);
            return [
                'afterBalance' => $currentBalance,
                'beforeBalance' => $currentBalance,
            ];
        }

        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

        if ($machineWallet->money < $bet) {
            $this->error = TNineSlotGameController::API_CODE_INSUFFICIENT_BALANCE;
            return \app\service\WalletService::getBalance($player->id);
        }

        $beforeBalance = $machineWallet->money;

        // 同步扣款
        $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
        $machineWallet->save();

        // ⚡ 异步创建下注记录（不阻塞API响应）
        $this->asyncCreateBetRecord(
            playerId: $player->id,
            platformId: $this->platform->id,
            gameCode: $data['betInfoData']['SlotsFishing']['GameCode'],
            orderNo: $orderNo,
            bet: $bet,
            originalData: [$data],
            orderTime: Carbon::createFromTimeString($data['betTime'], 'UTC')->setTimezone('Asia/Shanghai')->toDateTimeString()
        );

        return [
            'afterBalance' => \app\service\WalletService::getBalance($player->id),
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
        // ✅ 加锁查询，防止并发重复退款
        $record = PlayGameRecord::query()
            ->where('order_no', $data['gameOrderNumber'])
            ->lockForUpdate()
            ->first();

        if (!$record) {
            $this->error = TNineSlotGameController::API_CODE_ERROR;
            return [];
        }

        //返还用户金钱  修改注单状态
        $bet = $data['payoutAmount'];
        $beforeBalance = \app\service\WalletService::getBalance($this->player->id);
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();
        // 同步退款
        $machineWallet->money = bcadd($machineWallet->money, $bet, 2);
        $machineWallet->save();
        // 异步更新状态
        $this->asyncCancelBetRecord($record->order_no);

        return [
            'afterBalance' => \app\service\WalletService::getBalance($this->player->id),
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
        // ✅ 加锁查询record，防止并发重复派彩
        $record = PlayGameRecord::query()
            ->where('order_no', $data['gameOrderNumber'])
            ->lockForUpdate()
            ->first();

        /** @var Player $player */
        $player = $this->player;

        if (!$record) {
            $this->error = TNineGameController::API_CODE_ERROR;
            return \app\service\WalletService::getBalance($player->id);
        }

        // 锁钱包
        $machineWallet = $player->machine_wallet()->lockForUpdate()->first();

        $money = $data['winlose'];
        $beforeGameAmount = $machineWallet->money;
        //有金额则为赢
        if ($money > 0) {
            // 同步增加余额
            $machineWallet->money = bcadd($machineWallet->money, $money, 2);
            $machineWallet->save();
        }

        $platformActionAt = Carbon::createFromTimeString($data['betTime'], 'UTC')
            ->setTimezone('Asia/Shanghai')
            ->toDateTimeString();

        // ⚡ 异步更新结算记录（不阻塞API响应）
        // 免费次数(betKind==3)的累计订单处理交由Consumer合并（类似DG/KT/RSGLive）
        // 彩金记录会在Consumer中处理
        $win = $data['betAmount'] + $data['winlose'];
        $diff = $data['winlose'];
        $this->asyncUpdateSettleRecord(
            orderNo: $record->order_no,
            win: $win,
            diff: $diff
        );

        return [
            'afterBalance' => \app\service\WalletService::getBalance($player->id),
            'beforeBalance' => $beforeGameAmount,
        ];
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
        // ✅ 使用 Redis 缓存查询余额
        return \app\service\WalletService::getBalance($this->player->id);
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
