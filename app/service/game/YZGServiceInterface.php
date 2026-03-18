<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerGamePlatform;
use Exception;
use support\Cache;
use support\Log;

class YZGServiceInterface extends GameServiceFactory implements GameServiceInterface
{
    public $method = 'POST';
    public $successCode = '0';
    public $failCode = array(
        '3' => '缺少解密数据，请检查POST数据',
        '4' => '解析数据出错，请检查数据格式',
        '5' => '开始时间格式错误',
        '6' => '结束时间格式错误',
        '7' => '时间范围错误',
        '8' => '无效时间戳',
        '9' => '时间戳超出范围',
        '1000' => '未找到用户',
        '1001' => '用户未激活',
        '1003' => '获取用户余额错误',
        '1004' => '未找到代理',
        '1005' => '缺少代理数据',
        '1007' => '创建用户错误',
        '1008' => '更新用户状态错误',
        '1012' => '保存用户登录信息错误',
        '2001' => '存款失败',
        '2002' => '提现失败',
        '2003' => '余额不足',
        '2004' => '未找到交易',
        '2005' => '无效的金额格式',
        '2006' => '金额小于零',
        '2008' => '获取余额失败',
        '2009' => '代理余额不足',
        '2013' => '交易 ID 格式无效',
        '3000' => '未找到游戏',
        '3002' => '获取游戏列表错误',
        '3006' => '未找到游戏记录',
        '3009' => '踢出错误',
        '3010' => '游戏中的用户',
        '4002' => '提交交易失败',
        '5001' => '超时错误',
        '5003' => '缺少必需的参数',
        '5004' => '无效的域控制器',
        '5005' => '解密错误',
        '5006' => '常见数据解析错误',
        '5007' => '作错误',
        '6001' => '生成令牌错误',
        '6003' => '检查余额错误',
        '6004' => '处理游戏登录错误',
        '6005' => '加密令牌错误',
        '6006' => '生成游戏网址错误',
    );
    private $apiDomain;
    private $log;

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.YZG');
        $this->apiDomain = $config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'YZG')->first();
        $this->player = $player;
        $this->log = Log::channel('yzg_server');
    }

    /**
     * 充值（存款）
     * @param array $data
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function depositAmount(array $data = []): string
    {
        $this->checkPlayer();
        $config = config('game_platform.YZG');
        $api = '/transaction/deposit';
        $params = [
            'action' => '19',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $this->player->uuid,
            'amount' => (string)($data['amount'] ?? 0),
        ];

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }
        $this->log->info('depositAmount', [$res]);
        Cache::set('depositAmount_' . $this->player->id, $this->platform->id, 20 * 24 * 60 * 60);
        Cache::delete('withdrawAmount_' . $this->player->id);

        return $res['data']['transactionId'];
    }

    /**
     * 游戏回放记录
     * @param array $data
     * @return mixed|string
     * @throws GameException
     */
    public function replay(array $data = []): mixed
    {
        $original = json_decode($data['original_data'], true);

        $config = config('game_platform.YZG');
        $api = '/record/game-detail-record';
        $params = [
            'action' => '30',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $original['user'],
            'roundId' => $original['roundId'],
        ];

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            return '';
        }

        return $res['data']['url'];
    }

    /**
     * @return void
     * @throws GameException
     */
    private function checkPlayer(): void
    {
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if (empty($playerGamePlatform)) {
            $this->createPlayer();
            $playerGamePlatform = new PlayerGamePlatform();
            $playerGamePlatform->player_id = $this->player->id;
            $playerGamePlatform->platform_id = $this->platform->id;
            $playerGamePlatform->player_name = $this->player->name;
            $playerGamePlatform->player_code = $this->player->uuid;
            $playerGamePlatform->save();
        }
    }

    /**
     * 注册玩家
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function createPlayer(): array
    {
        $config = config('game_platform.YZG');
        $params = [
            'action' => '12',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $this->player->uuid,
        ];
        $api = '/user/create-user';

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }
        return $params;
    }

    /**
     * 组装请求
     * @param string $api
     * @param array $params
     * @return array|mixed
     * @throws GameException
     */
    public function doCurl(string $api, array $params = []): mixed
    {
        $config = config('game_platform.YZG');
        $key = $config['KEY'];
        $dc = $config['dc'];
        $iv = $config['IV'];
        $jsonData = json_encode($params);
        $encryptedData = self::aesEncrypt($jsonData, $key, $iv);
        $postFields = "dc=" . $dc . "&x=" . $encryptedData;

        $url = $config['api_domain'] . '/api/v1/game' . $api;

        try {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded'
            ));

            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
        } catch (\Exception $e) {
            // 统一处理异常（如超时、网络错误）
            throw new GameException("Request Failed: " . $e->getMessage());
        }

        if ($error) {
            throw new GameException("cURL Error: " . $error);
        }

        $res = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GameException('JSON解析错误: ' . json_last_error_msg());
        }
        if (!empty($res['error'])) {
            throw new GameException($res['error']);
        }

        if (empty($res)) {
            throw new GameException(trans('system_busy', [], 'message'));
        }
        return $res;
    }

    /**
     * 加密
     * @param $data
     * @param $key
     * @param $iv
     * @return string
     */
    public static function aesEncrypt($data, $key, $iv): string
    {
        $blockSize = 16;
        $padding = $blockSize - (strlen($data) % $blockSize);
        $padText = str_repeat(chr($padding), $padding);
        $data .= $padText;
        $encrypted = openssl_encrypt(
            $data,
            'AES-128-CBC',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv
        );

        return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
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
        $config = config('game_platform.YZG');
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();

        if (empty($playerGamePlatform)) {
            $this->createPlayer();
            $playerGamePlatform = new PlayerGamePlatform();
            $playerGamePlatform->player_id = $this->player->id;
            $playerGamePlatform->platform_id = $this->platform->id;
            $playerGamePlatform->player_name = $this->player->name;
            $playerGamePlatform->player_code = $this->player->uuid;
            $playerGamePlatform->save();
        }
        $this->checkPlayer();
        $gameExtend = GameExtend::query()->where('platform_id', $this->platform->id)->where('status', 1)->first();
        $api = '/user/game-url';    //游戏链接
        // $api = '/user/demo-game-url';    //测试游戏链接
        $params = [
            'action' => '11',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $this->player->uuid,
            'gameCode' => $gameExtend->code,
            'lang' => 'tw',
            'exitUrl' => 'N',
        ];

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }

        return $res['data']['path'] ?? '';
    }

    /**
     * 提现（提款）
     * @param array $data
     * @return array
     * @throws GameException
     */
    public function withdrawAmount(array $data = []): array
    {
        $this->checkPlayer();
        //提现之前把用户踢下线
        if ($this->checkPlay()) {
            $this->userLogout();
        }

        $config = config('game_platform.YZG');
        $api = '/transaction/withdraw';
        $params = [
            'action' => '20',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $this->player->uuid,
            'amount' => (string)(isset($data['amount']) ? $data['amount'] : 0),
        ];

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }
        $this->log->info('withdrawAmount', [$res]);
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 20 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $res['data']['transactionId'] ?? '',
            'amount' => $data['amount'] ?? 0,
        ];
    }

    /**
     * 玩家状态->查询余额
     * @return float
     * @throws GameException
     * @throws Exception
     */
    public function getBalance(): float
    {
        $config = config('game_platform.YZG');
        $this->checkPlayer();
        $api = '/user/check-user';
        $params = [
            'action' => '15',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $this->player->uuid,
        ];

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }

        return $res['data']['amount'] ?? 0; //amount包含游戏内的余额
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
                    $player = Player::withTrashed()->with('recommend_promoter')->where('uuid', $item['user'])->first();
                    if (!empty($player)) {
                        $list[] = [
                            'player_id' => $player->id,
                            'parent_player_id' => $player->recommend_id ?? 0,
                            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                            'player_uuid' => $player->uuid,
                            'platform_id' => $this->platform->id,
                            'game_code' => $item['gameCode'],
                            'department_id' => $player->department_id,
                            'bet' => abs($item['bet']),
                            'win' => max($item['win'], 0),
                            'diff' => bcsub($item['afterAmount'], $item['beforeAmount'], 2),
                            'order_no' => $item['roundId'],
                            'original_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
                            'platform_action_at' => date('Y-m-d H:i:s', floor($item['betTime'] / 1000)),
                        ];
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
        $config = config('game_platform.YZG');
        $api = '/record/game-record';
        $params = [
            'action' => '29',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'startTime' => (string)(strtotime('-5 minutes') * 1000),
            'endTime' => (string)(strtotime('-3 minutes') * 1000),
        ];
        $res = $this->doCurl($api, $params);
        $this->log->info('getGameHistories', [$res]);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }

        return $res['data'] ?? [];
    }

    /**
     * 获取游戏列表
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $params = [
            'action' => "70",
            'ts' => (string)round(microtime(true) * 1000),
        ];
        $api = '/game-list/get-game-list';
        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }
        $insertData = [];
        if (!empty($res['data'])) {
            foreach ($res['data'] as $item) {
                $insertData[] = [
                    'game_id' => $item['gameType'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => GameType::CATE_FISH,   //游戏类型：捕鱼
                    'name' => $item['gameName'],
                    'code' => $item['gameCode'],
                    // 'logo' => $item['image'],
                    'status' => $item['status'],
                    'org_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
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
        $config = config('game_platform.YZG');
        $api = '/user/game-url';    //游戏链接
        $params = [
            'action' => '11',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $this->player->uuid,
            'gameCode' => $game->game_extend->code,
            'lang' => 'tw',
            'exitUrl' => 'N',
        ];

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }

        return $res['data']['path'] ?? '';
    }

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }

    /**
     * 踢出玩家
     * @return true
     * @throws GameException
     */
    public function userLogout()
    {
        $config = config('game_platform.YZG');
        $api = '/user/kick-out';
        $params = [
            'action' => '17',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $this->player->uuid
        ];

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }

        return true;
    }

    /**
     * 玩家状态
     * @return array|mixed|null
     * @throws GameException
     */
    public function playerStatus(): mixed
    {
        $config = config('game_platform.YZG');
        $this->checkPlayer();
        $api = '/user/check-user';
        $params = [
            'action' => '15',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $this->player->uuid,
        ];

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }

        return $res ?? NULL;
    }

    /**
     * 检查玩家状态
     * @return bool
     * @throws GameException
     */
    public function checkPlay()
    {
        $config = config('game_platform.YZG');
        $this->checkPlayer();
        $api = '/user/check-user';
        $params = [
            'action' => '15',
            'ts' => (string)round(microtime(true) * 1000),
            'agent' => $config['AGENT'],
            'account' => $this->player->uuid,
        ];

        $res = $this->doCurl($api, $params);
        if ($res['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['code']], 0);
        }

        //游戏中
        if ($res['data']['status'] == 1 && $res['data']['operation'] == 1) {
            return true;
        }

        //不在游戏中
        if ($res['data']['status'] == 2 || $res['data']['operation'] == 2) {
            return false;
        }

        throw new GameException($this->failCode[$res['code']], 0);
    }
}
