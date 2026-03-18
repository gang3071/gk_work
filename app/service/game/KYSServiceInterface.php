<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use Carbon\Carbon;
use Exception;
use support\Cache;
use WebmanTech\LaravelHttpClient\Facades\Http;

class KYSServiceInterface extends GameServiceFactory implements GameServiceInterface
{
    public $method = 'POST';
    public $successCode = '0';
    public $failCode = array(
        '-1' => '上下分時，資料庫異常回滾',
        '0' => '成功',
        '1' => 'TOKEN 丟失（重新調用登錄接口獲取）',
        '2' => '渠道不存在（請檢查渠道 ID 是否正確）',
        '3' => '驗證時間超時（請檢查 timestamp 是否正確） Authentication time timeout (check if timestamp is correct or not)',
        '4' => '驗證錯誤 Authentication error',
        '5' => '渠道⽩名單錯誤（請聯繫客服添加服務器⽩名單）',
        '6' => '驗證字段丟失（請檢查參數完整性）',
        '7' => 'TOKEN 驗證失敗（重新調用登錄界⾯）',
        '8' => '不存在的請求（請檢查⼦操作類型是否正確）',
        '11' => '玩家帳號不存在',
        '15' => '渠道驗證錯誤（1.MD5key 值是否正確；2.⽣成 key 值中的 timestamp 與參數中的是否⼀致；3. ⽣成 key 值中的 timestamp 與代理編號以字符串形式拼接）',
        '16' => '數據不存在（當前沒有注單）',
        '20' => '帳號禁⽤',
        '22' => 'DES 解密失敗',
        '24' => '渠道拉取數據超過時間範圍',
        '26' => '訂單號不存在',
        '27' => '資料庫異常',
        '28' => 'ip 禁⽤',
        '29' => '訂單號與訂單規則不符',
        '30' => '獲取玩家在線狀態失敗',
        '31' => '更新的分數⼩於或者等於',
        '32' => '更新玩家信息失敗',
        '33' => '更新玩家⾦幣失敗',
        '34' => '訂單重複',
        '35' => '獲取玩家信息失敗（請調用登錄接口創建帳號）',
        '36' => 'KindID 不存在',
        '38' => '餘額不⾜導致下分失敗',
        '39' => '禁⽌同⼀帳號登錄帶分、上分、下分並發請求，後⼀個請求被拒',
        '40' => '單次上下分數量不能超過⼀千萬',
        '41' => '拉取對局匯總統計時間範圍有誤',
        '42' => '代理被禁⽤',
        '43' => '拉單過於頻繁(兩次拉單時間間隔，外測服必須⼤於 10 秒，正式服必須⼤於 5 秒)',
        '44' => '訂單正在處理中',
        '45' => '參數錯誤',
        '46' => '時間範圍錯誤',
        '48' => '遊戲維護中',
        '49' => '語系參數錯誤',
        '50' => '幣別參數錯誤',
        '51' => '超過單次最多帳號查詢數量',
        '52' => '玩家有異常盈利或分數異常狀況時，呼叫 API s = 3 返回錯誤碼 52，當收到此響應再請洽詢我⽅客服⼈員',
        '89' => '代理餘額不⾜',
        '91' => '代理不存在',
        '98' => '進入遊戲發生錯誤',
        '99' => '系統非預期錯誤'
    );
    private $apiDomain;
    private $lang = [
        'zh-CN' => 'zh-CN',
        'zh-TW' => 'zh-CN',
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
        $this->config = config('game_platform.KYS');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'KYS')->first();
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
        $time_str = $this->timestamp_str();
        $orderId = $this->config['agent'] . $time_str . $this->player->id;

        $build = [
            's' => 2,
            'account' => $this->player->uuid,
            'money' => $data['amount'] ?? 0,
            'orderid' => $orderId
        ];

        $res = $this->doCurl($this->apiDomain . '/channelHandle', $build);

        //上分失败进行状态查询处理
        if ($res['d']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['d']['code']], 0);
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
            if (!$login) {
                return $this->createPlayer();
            }
            $playerGamePlatform = new PlayerGamePlatform;
            $playerGamePlatform->player_id = $this->player->id;
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
        return $this->lobbyLogin();
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
        // dump("KYS加密的params = ", $params);
        $config = config('game_platform.KYS');
        $build = self::desEncode(http_build_query($params), $config['des_key']);

        $time = round(microtime(true) * 1000);
        $params = [
            'agent' => $config['agent'],
            'timestamp' => $time
        ];
        $params['param'] = $build;
        $params['key'] = md5($config['agent'] . $time . $config['md5_key']);

        // dump("KYS 请求地址为：" , $url);
        // dump("KYS 请求参数params为：", $params);

        $response = Http::timeout(7)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->get($url . '?' . http_build_query($params));

        // dump("response = ", $response);
        if (!$response->ok()) {
            throw new GameException(trans('system_busy', [], 'message'));
        }

        $result = json_decode($response->body(), true);

        if (empty($result)) {
            throw new GameException(trans('system_busy', [], 'message'));
        }

        // dump("KYS-docurl请求接口返回参数为：", $result);

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
            'KindID' => 7470,
            'lang' => isset($data['lang']) ? $this->lang[$data['lang']] : 'zh-CN',
        ];

        $res = $this->doCurl($this->apiDomain . '/channelHandle', $build);

        if ($res['d']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['d']['code']], 0);
        }

        return $res['d']['url'] ?? '';
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
        $time_str = $this->timestamp_str();
        $orderId = $this->config['agent'] . $time_str . $this->player->id;

        $build = [
            's' => 3,
            'account' => $this->player->uuid,
            'money' => $data['amount'] ?? 0,
            'orderid' => $orderId
        ];

        $res = $this->doCurl($this->apiDomain . '/channelHandle', $build);

        //上分失败进行状态查询处理
        if ($res['d']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['d']['code']], 0);
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $orderId,
            'amount' => $data['amount'],
            'last_amount' => $res['d']['money'],
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
        $build = [
            's' => 7,
            'account' => $this->player->uuid
        ];

        $res = $this->doCurl($this->apiDomain . '/channelHandle', $build);

        if ($res['d']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['d']['code']], 0);
        }

        //可下分余额
        return $res['d']['freeMoney'] ?? '';
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
                $accounts = $data['list'];
                foreach ($accounts as $item) {
                    /** @var Player $player */
                    $uuid = str_replace($this->config['agent'] . '_', '', $item['account']);
                    $player = Player::withTrashed()->where('uuid', $uuid)->first();
                    if (!empty($player) && $item['status'] == 5) {   //只记录已经结算的
                        $list[] = [
                            'player_id' => $player->id,
                            'parent_player_id' => $player->recommend_id ?? 0,
                            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                            'player_uuid' => $player->uuid,
                            'platform_id' => $this->platform->id,
                            'game_code' => $item['gameID'],
                            'department_id' => $player->department_id,
                            'bet' => $item['stake'],
                            'win' => max(0, $item['settleAmount']),
                            'diff' => intval($item['winlost'] ?? 0),
                            'order_no' => $item['refNo'],
                            'original_data' => json_encode($item),
                            'platform_action_at' => $item['settleTime'],
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
        $endTime = Carbon::now()->subMinutes()->getTimestampMs();
        $startTime = Carbon::now()->subMinutes(6)->getTimestampMs();

        // dump("KYS记录时间为: ".date('Y-m-d H:i:s', floor($startTime / 1000)).' - '.date('Y-m-d H:i:s', floor($endTime / 1000)));
        $build = [
            's' => 0,
            'startTime' => $startTime,
            'endTime' => $endTime
        ];

        $res = $this->doCurl($this->config['api_record_domain'] . '/sportRecordHandle', $build);
        // dump("KYS 记录的返回数据为：" , $res);

        if ($res['d']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['d']['code']], 0);
        }


        return $res['d'] ?? [];
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        // try {
        //     $KYSData = $this->handleGameHistories();
        //     dump("KYS开始记录数据：", $KYSData);
        //     if (!empty($KYSData)) {
        //         PlayGameRecord::query()->upsert($KYSData, ['platform_id', 'order_no']);
        //         // dump("KYS记录完成");
        //     }
        // } catch (\Exception $e) {
        //     dump($e->getMessage());
        //     Log::error('KYSGamePlatformBill', [$e->getMessage(),$e->getLine(),$e->getFile()]);
        // }
        // dump("查看 KYS 游戏列表时，获取游戏记录: ", $this->getGameHistories());
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
