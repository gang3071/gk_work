<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use Carbon\Carbon;
use Exception;
use support\Cache;
use WebmanTech\LaravelHttpClient\Facades\Http;

class JDBServiceInterface extends GameServiceFactory implements GameServiceInterface
{
    public $method = 'POST';
    public $successCode = '0000';
    public $failCode = [
        '0000' => '成功',
        '9999' => '失敗',
        '9001' => '未授權訪問',
        '9002' => '域名為空或域名長度小於 2',
        '9003' => '域名驗證失敗。',
        '9004' => '加密數據為空或加密數據的長度等於 0。',
        '9005' => '斷言（SAML）未通過時間戳驗證。',
        '9006' => '從加密數據中提取 SAML 參數失敗。',
        '9007' => '未知操作。',
        '9008' => '與之前的值相同。',
        '9009' => '超時。',
        '9010' => '讀取超時。',
        '9011' => '重複交易。',
        '9012' => '請稍後再試。',
        '9013' => '系統正在維護。',
        '9014' => '檢測到多帳戶登錄。',
        '9015' => '數據不存在。',
        '9016' => '無效令牌。',
        '9019' => '請求速率限制超過。',
        '9020' => '每次登錄只能獲得一次遊戲票。',
        '9021' => '違反一次性會話策略。',
        '9022' => '遊戲正在維護。',
        '9023' => '不支持的貨幣。',
        '9024' => '贏取倍數必須大於或等於 10 倍。',
        '9025' => '不支持重放遊戲。',
        '9026' => '获胜金额应大于0。',
        '9027' => '不支持演示。',
        '8000' => '輸入參數錯誤，請檢查您的參數是否正確。',
        '8001' => '參數不能為空。',
        '8002' => '參數必須是正整數。',
        '8003' => '參數不能為負數。',
        '8005' => '日期秒格式錯誤',
        '8006' => '時間不符合。',
        '8007' => '參數只能使用數字。',
        '8008' => '找不到參數。',
        '8009' => '時間間隔超過允許範圍。',
        '8010' => '參數長度太長。',
        '8013' => '日期分鐘格式參數錯誤。',
        '8014' => '參數不得超過指定的小數位。',
        '7001' => '找不到指定的父 ID。',
        '7002' => '父級已暫停。',
        '7003' => '父級已鎖定。',
        '7004' => '父級已關閉。',
        '7405' => '您已登出！',
        '7501' => '找不到用戶 ID。',
        '7502' => '用戶已暫停。',
        '7503' => '用戶已鎖定。',
        '7504' => '用戶已關閉。',
        '7505' => '用戶未在玩遊戲。',
        '7506' => '演示帳戶已滿。',
        '7601' => '無效的用戶 ID。請僅使用 a-z、0-9 之間的字符。',
        '7602' => '帳戶已存在。請選擇其他用戶 ID。',
        '7603' => '無效的用戶名。',
        '7604' => '密碼必須至少 6 個字符，包含 1 個字母和 1 個數字。',
        '7605' => '無效的操作代碼。請僅使用數字 2、3、4、5。',
        '6001' => '您的現金餘額不足以取款。',
        '6002' => '用戶餘額為零。',
        '6003' => '取款金額為負。',
        '6004' => '重複轉帳。',
        '6005' => '重複的序列號。',
        '6009' => '存款金額超過上限。',
        '6010' => '餘額超過上限。',
        '6011' => '分配的信用額超過上限。',
        '6012' => '序列號正在進行中。',
        '6901' => '用戶正在玩遊戲，不允許轉移餘額。'
    ];
    private $apiDomain;
    private $lang = [
        'zh-CN' => 'cn',
        'zh-TW' => 'cn',
        'jp' => 'jpn',
        'en' => 'en',
        'th' => 'th',
        'vi' => 'vi',
        'kr_ko' => 'ko',
        'id' => 'id',
    ];

    private $currency = [
        'TWD' => 'NT',
        'CNY' => 'RMB',
        'JPY' => 'JPY',
        'USD' => 'USA',
    ];

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.JDB');
        $this->apiDomain = $config['api_domain'] . '/apiRequest.do';
        $this->platform = GamePlatform::query()->where('code', 'JDB')->first();
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
            'action' => 19,
            'ts' => round(microtime(true) * 1000),
            'parent' => 'apisrmbag',
            'uid' => $this->player->uuid,
            'serialNo' => $data['order_no'] ?? '',
            'amount' => $data['amount'] ?? 0,
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }
        Cache::set('depositAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('withdrawAmount_' . $this->player->id);

        return $res['serialNo'];
    }

    /**
     * 游戏回放记录
     * @param array $data
     * @return mixed|string
     * @throws GameException
     */
    public function replay(array $data = [])
    {
        $original = json_decode($data['original_data'], true);

        $params = [
            'action' => 69,
            'ts' => round(microtime(true) * 1000),
            'lang' => 'cn',
            'parent' => 'apisrmbag',
            'uid' => $original['playerId'],
            'historyId' => $original['historyId'],
            'gType' => $original['gType']
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            return '';
        }

        return $res['url'];
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
        $params = [
            'action' => 12,
            'ts' => round(microtime(true) * 1000),
            'lang' => 'cn',
            'parent' => 'apisrmbag',
            'uid' => $this->player->uuid,
            'name' => $this->player->name ?: $this->player->uuid
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }

        return $params;
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
        $config = config('game_platform.JDB');
        $data = self::padString(json_encode($params));
        $encryptData = openssl_encrypt($data, 'AES-128-CBC', $config['KEY'], OPENSSL_NO_PADDING,
            $config['IV']);
        $reqBase64 = base64_encode($encryptData);
        $reqBase64 = str_replace(array('+', '/', '='), array('-', '_', ''), $reqBase64);

        $response = Http::timeout(15)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post($url . '?' . http_build_query(['dc' => $config['dc'], 'x' => $reqBase64]));


        if (!$response->ok()) {
            throw new GameException(trans('system_busy', [], 'message'));
        }
        $result = $response->body();
        $data = json_decode($result, true);


        if (empty($data)) {
            throw new GameException(trans('system_busy', [], 'message'));
        }

        return $data;
    }


    private static function padString($source)
    {
        $paddingChar = ' ';
        $size = 16;
        $x = strlen($source) % $size;
        $padLength = $size - $x;
        for ($i = 0; $i < $padLength; $i++) {
            $source .= $paddingChar;
        }
        return $source;
    }

    /**
     * 生成请求url
     * @param $method
     * @return string
     */
    public function createUrl($method): string
    {
        return $this->apiDomain;
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
            'action' => 11,
            'ts' => round(microtime(true) * 1000),
            'lang' => $this->lang[$data['lang']] ?? 'cn',
            'uid' => $this->player->uuid,
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }

        return $res['path'] ?? '';
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
        //提现之前把用户踢下线
        if ($this->checkPlay()) {
            $this->userLogout();
        }

        $params = [
            'action' => 19,
            'ts' => round(microtime(true) * 1000),
            'parent' => 'apisrmbag',
            'uid' => $this->player->uuid,
            'serialNo' => $data['order_no'] ?? '',
            'amount' => isset($data['amount']) ? -$data['amount'] : 0,
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $res['serialNo'] ?? '',
            'amount' => $data['amount'] ?? 0,
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
            'action' => 15,
            'ts' => round(microtime(true) * 1000),
            'parent' => 'apisrmbag',
            'uid' => $this->player->uuid
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }

        return $res['data'][0]['balance'] ?? 0;
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
                    $player = Player::withTrashed()->with('recommend_promoter')->where('uuid',
                        $item['playerId'])->first();
                    if (!empty($player)) {
                        $list[] = [
                            'player_id' => $player->id,
                            'parent_player_id' => $player->recommend_id ?? 0,
                            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                            'player_uuid' => $player->uuid,
                            'platform_id' => $this->platform->id,
                            'game_code' => $item['mtype'],
                            'department_id' => $player->department_id,
                            'bet' => abs($item['bet']),
                            'win' => max($item['win'], 0),
                            'diff' => $item['total'],
                            'order_no' => $item['historyId'],
                            'original_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
                            'platform_action_at' => Carbon::createFromTimeString($item['lastModifyTime'])->format('Y-m-d H:i:s'),
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
     * @param int $gameType
     * @return array
     * @throws GameException
     */
    public function getGameHistories(): array
    {
        $params = [
            'action' => 29,
            'ts' => round(microtime(true) * 1000),
            'parent' => 'apisrmbag',
            'starttime' => date('d-m-Y H:i:00', strtotime('-5 minutes')),
            'endtime' => date('d-m-Y H:i:00', strtotime('-3 minutes')),
        ];
        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }

        return $res['data'] ?? [];
    }


    /**
     * 取得區間內遊戲紀錄
     * @param $startTime
     * @param $endTime
     * @return array
     * @throws GameException
     */
    public function getGameHistoriesDetail($startTime, $endTime): array
    {
        $params = [
            'action' => 64,
            'ts' => round(microtime(true) * 1000),
            'parent' => 'apisrmbag',
            'starttime' => $startTime,
            'endtime' => $endTime,
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }

        return $res['data'] ?? [];
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $params = [
            'action' => 49,
            'ts' => round(microtime(true) * 1000),
            'parent' => 'apisrmbag',
            'lang' => $this->lang[$lang] ?? 'cn'
        ];
        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }
        $insertData = [];
        if (!empty($res['data'])) {
            foreach ($res['data'] as $data) {
                foreach ($data['list'] as $item) {
                    $insertData[] = [
                        'table_name' => $data['gType'],
                        'game_id' => $item['mType'],
                        'platform_id' => $this->platform->id,
                        'cate_id' => 7,
                        'name' => $item['name'],
                        'code' => $item['mType'],
                        'logo' => $item['image'],
                        'status' => 1,
                        'org_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
                    ];
                }
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
    public function gameLogin(Game $game, string $lang = 'zh-CN')
    {
        $this->checkPlayer();
        $params = [
            'action' => 11,
            'ts' => round(microtime(true) * 1000),
            'lang' => $this->lang[$lang] ?? 'cn',
            'uid' => $this->player->uuid,
            'gType' => $game->game_extend->table_name,
            'mType' => $game->game_extend->code,
            'windowMode' => 2,
            'isAPP' => true,
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }

        return $res['path'] ?? '';
    }

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }

    public function userLogout()
    {
        $params = [
            'action' => 17,
            'ts' => round(microtime(true) * 1000),
            'parent' => 'apisrmbag',
            'uid' => $this->player->uuid
        ];

        $res = $this->doCurl($this->apiDomain, $params);
        if ($res['status'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']], 0);
        }

        return true;
    }

    public function checkPlay()
    {
        $params = [
            'action' => 52,
            'ts' => round(microtime(true) * 1000),
            'parent' => 'apisrmbag',
            'uid' => $this->player->uuid
        ];

        $res = $this->doCurl($this->apiDomain, $params);


        //游戏中
        if ($res['status'] == $this->successCode) {
            return true;
        }

        //不在游戏中
        if ($res['status'] == 7505) {
            return false;
        }

        throw new GameException($this->failCode[$res['status']], 0);
    }
}
