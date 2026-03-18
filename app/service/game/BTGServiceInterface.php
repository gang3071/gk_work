<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\model\PlayGameRecord;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use support\Cache;
use support\Log;

class BTGServiceInterface extends GameServiceFactory implements GameServiceInterface
{
    public $method = 'POST';
    public $successCode = '1000';
    public $failCode = [
        '2001' => '發生預期外錯誤',
        '4001' => '該遊戲目前維護中',
        '4002' => '該遊戲不存在',
        '4003' => '操作頻繁，請稍後再試(間隔1秒以上)',
        '4004' => '請使用美東時間格式',
        '4102' => '不被允許訪問的ip',
        '4103' => '錯誤的驗證碼',
        '4104' => '該代理商不存在',
        '4201' => '玩家帳號或密碼錯誤',
        '4202' => '該玩家不存在',
        '4203' => '該玩家已註冊',
        '4204' => '欲查詢之遊戲紀錄不存在',
        '4206' => '該玩家被鎖定',
        '4302' => '特定參數(arg)格式錯誤',
        '4303' => '特定參數(arg)值錯誤',
        '6101' => '提款交易執行失敗',
        '6102' => '存款交易執行失敗',
        '6104' => '該外部交易流水號已存在',
        '6105' => '查無該交易紀錄',
        '6107' => '存款金額數值錯誤',
        '6108' => '提款金額數值錯誤',
        '6109' => 'take_all=true與withdraw_amount 參數衝突',
        '6110' => '玩家交易狀態被鎖定',
        '6111' => '取餘額失敗',
        '6112' => '交易過於頻繁'
    ];

    private $apiDomain;
    private $appId;
    private $appSecret;

    private $path = [
        'getBalance' => '/user/get_balance',
        'depositAmount' => '/user/deposit_amount',
        'withdrawAmount' => '/user/withdraw_amount',
        'getTransactionStatus' => '/user/get_transaction_status',
        'gameLogin' => '/agent/user_login',
        'lobbyLogin' => '/agent/lobby_login',
        'userLogout' => '/agent/user_logout',
        'getGameList' => '/agent/get_gamelist',
        'getGameHistories' => '/record/get_game_histories',
        'getPlayerHistories' => '/record/get_player_histories',
        'getPlayerFinances' => '/record/get_player_finances',
        'getFinances' => '/record/get_finances',
        'getSingleBettingRecord' => '/record/get_single_betting_record',
        'getEventRecords' => '/event/get_event_records',
    ];

    private $lang = [
        'zh-CN' => 'zh-cn',
        'zh-TW' => 'zh-tw',
        'jp' => 'ja',
        'en' => 'en',
        'th' => 'th',
        'vi' => 'vi',
        'my' => 'my',
        'id' => 'id',
        'hi_hi' => 'hi',
        'kr_ko' => 'ko',
    ];

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.BTG');
        $this->appId = $config['app_id'];
        $this->apiDomain = $config['api_domain'];
        $this->appSecret = $config['app_secret'];
        $this->platform = GamePlatform::query()->where('code', 'BTG')->first();
        $this->player = $player;
    }

    /**
     * 查詢玩家餘額
     * @param array $data
     * @return float
     * @throws GameException
     */
    public function getBalance(array $data = []): float
    {
        $this->checkPlayer($data['lang']);
        $params = [
            'account_id' => $this->appId,
            'username' => $this->player->uuid,
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('getBalance'), $params);
        if ($res['status']['code'] != $this->successCode) {
            if ($res['status']['code'] == '4202') {
                $this->lobbyLogin($data);
                return 0;
            }
            throw new GameException($this->failCode[$res['status']['code']], 0);
        }

        return $res['data']['balance'] ?? 0;
    }

    /**
     * 检查玩家
     * @throws GameException
     */
    private function checkPlayer($lang)
    {
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if (!empty($playerGamePlatform)) {
            return $this->lobbyLogin([
                'lang' => $lang
            ]);
        }
        $playerGamePlatform = new PlayerGamePlatform();
        $playerGamePlatform->player_id = $this->player->id;
        $playerGamePlatform->platform_id = $this->platform->id;
        $playerGamePlatform->player_name = $this->player->name;
        $playerGamePlatform->player_code = $this->player->uuid;
        $playerGamePlatform->save();

        return true;
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
        $params = [
            'account_id' => $this->appId,
            'username' => $this->player->uuid,
            'lang' => $this->lang[$data['lang']],
            'routing' => 'zh-cn',
            'device' => 'mobile',
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('lobbyLogin'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']['code']], 0);
        }

        return $res['data']['lobby_url'] ?? '';
    }

    /**
     * @param $params
     * @return string
     */
    public function createSign($params): string
    {
        ksort($params);
        $queryString = '';
        foreach ($params as $key => $value) {
            if ($key != 'back_url') {
                $queryString .= urlencode($key) . '=' . str_replace('%3A', ':', urlencode($value)) . '&';
            } else {
                $queryString .= urlencode($key) . '=' . $value . '&';
            }
        }
        $queryString = rtrim($queryString, '&');
        return md5('security_code=' . $this->appSecret . '&' . $queryString);
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
     * 儲值玩家額度
     * @param array $data
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function depositAmount(array $data = []): string
    {
        $this->checkPlayer($data['lang']);
        $params = [
            'account_id' => $this->appId,
            'username' => $this->player->uuid,
            'deposit_amount' => $data['amount'] ?? 0,
            'external_order_id' => $data['order_no'] ?? '',
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('depositAmount'), $params);
        if ($res['status']['code'] != $this->successCode) {
            Log::error($this->failCode[$res['status']['code']], ['res' => $res]);
            throw new GameException($this->failCode[$res['status']['code']], 0);
        }
        if (empty($res['data']['order_id'])) {
            throw new GameException(trans('transfer_fail', [], 'message'), 0);
        }
        Cache::set('depositAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('withdrawAmount_' . $this->player->id);

        return $res['data']['order_id'];
    }

    /**
     * 回放记录
     * @param array $data
     * @return mixed
     */
    public function replay(array $data = [])
    {
        $origin = json_decode($data['original_data'], true);
        return $origin['cn_detail_url'];
    }

    /**
     * 提領玩家額度
     * @param array $data
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function withdrawAmount(array $data = []): array
    {
        $this->checkPlayer($data['lang']);
        $params['account_id'] = $this->appId;
        $params['username'] = $this->player->uuid;
        $params['external_order_id'] = $data['order_no'] ?? '';
        $params['take_all'] = $data['take_all'];
        if ($data['take_all'] == 'false') {
            $params['withdraw_amount'] = $data['amount'] ?? 0;
        }
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('withdrawAmount'), $params);
        if ($res['status']['code'] != $this->successCode) {
            Log::error($this->failCode[$res['status']['code']], ['res' => $res]);
            throw new GameException($this->failCode[$res['status']['code']], 0);
        }
        if (empty($res['data']['order_id'])) {
            throw new GameException(trans('transfer_fail', [], 'message'), 0);
        }
        if ($res['data']['amount'] <= 0) {
            throw new GameException(trans('withdraw_amount_zero', [], 'message'), 0);
        }
        Cache::set('withdrawAmount_' . $this->player->id, $this->platform->id, 3 * 24 * 60 * 60);
        Cache::delete('depositAmount_' . $this->player->id);

        return [
            'order_id' => $res['data']['order_id'],
            'amount' => $res['data']['amount'],
        ];
    }

    /**
     * 登出游戏
     * @return true
     * @throws GameException
     * @throws Exception
     */
    public function userLogout(): bool
    {
        $params = [
            'account_id' => $this->appId,
            'username' => $this->player->uuid,
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('userLogout'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']['code']], 0);
        }

        return true;
    }

    /**
     * 查詢交易狀態
     * @param string $orderNo
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function getTransactionStatus(string $orderNo = ''): array
    {
        $params = [
            'account_id' => $this->appId,
            'external_order_id' => $orderNo,
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('getTransactionStatus'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']['code']], 0);
        }

        return $res;
    }

    /**
     * 取得區間內遊戲紀錄
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function handleGameHistories(): array
    {
        try {
            $page = 1;
            $list = [];
            $startTime = (new DateTime('now', new DateTimeZone('Etc/GMT+4')))
                ->setTime(00, 00, 00);
            $endFormatted = (new DateTime('now', new DateTimeZone('Etc/GMT+4')))
                ->setTime(23, 59, 59)
                ->format(DateTimeInterface::RFC3339);
            /** @var PlayGameRecord $playGameRecord */
            $playGameRecord = PlayGameRecord::query()
                ->where('platform_id', '31')
                ->orderBy('platform_action_at', 'desc')
                ->first();
            $startFormatted = $startTime->format(DateTimeInterface::RFC3339);
            if (!empty($playGameRecord)) {
                $playGameRecordDate = new DateTime($playGameRecord->platform_action_at);
                if ($startTime->format('Y-m-d') === $playGameRecordDate->format('Y-m-d')) {
                    $startFormatted = $playGameRecord->platform_action_at;
                }
            }
            $data = $this->getGameHistories([
                'start_time' => $startFormatted,
                'end_time' => $endFormatted,
                'page' => $page,
                'lang' => 'en',
            ]);

            if (!empty($data['data'])) {
                $total = $data['data']['total'] ?? 0;
                if ($total > 0) {
                    $pageSize = 200;
                    if (!empty($data['data']['histories'])) {
                        foreach ($data['data']['histories'] as $item) {
                            /** @var Player $player */
                            $player = Player::withTrashed()->with('recommend_promoter')->where('uuid',
                                $item['player_name'])->first();
                            if (!empty($player)) {
                                $list[] = [
                                    'player_id' => $player->id,
                                    'parent_player_id' => $player->recommend_id ?? 0,
                                    'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                                    'player_uuid' => $player->uuid,
                                    'platform_id' => $this->platform->id,
                                    'game_code' => $item['game_code'],
                                    'department_id' => $player->department_id,
                                    'bet' => $item['valid_bet'],
                                    'win' => $item['win'],
                                    'diff' => $item['diff'],
                                    'order_no' => $item['order_id'],
                                    'original_data' => json_encode($item),
                                    'platform_action_at' => $item['result_time'],
                                ];
                            }
                        }
                    }
                    if ($total > $pageSize) {
                        $totalPages = ceil($total / $pageSize);
                        for ($page = 2; $page <= $totalPages; $page++) {
                            $nextData = $this->getGameHistories([
                                'start_time' => $startFormatted,
                                'end_time' => $endFormatted,
                                'page' => $page,
                                'lang' => 'en',
                            ]);
                            if (!empty($nextData['data']['histories'])) {
                                foreach ($nextData['data']['histories'] as $item) {
                                    /** @var Player $player */
                                    $player = Player::withTrashed()->with('recommend_promoter')->where('uuid',
                                        $item['player_name'])->first();
                                    if (!empty($player)) {
                                        $list[] = [
                                            'player_id' => $player->id,
                                            'parent_player_id' => $player->recommend_id ?? 0,
                                            'agent_player_id' => $player->recommend_promoter->recommend_id ?? 0,
                                            'player_uuid' => $player->uuid,
                                            'platform_id' => $this->platform->id,
                                            'game_code' => $item['game_code'],
                                            'department_id' => $player->department_id,
                                            'bet' => $item['valid_bet'],
                                            'win' => $item['win'],
                                            'diff' => $item['diff'],
                                            'order_no' => $item['order_id'],
                                            'original_data' => json_encode($item),
                                            'platform_action_at' => $item['result_time'],
                                        ];
                                    }
                                }
                            }
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
     * @param array $data
     * @return array
     * @throws GameException
     * @throws Exception
     */
    public function getGameHistories(array $data = []): array
    {
        $params = [
            'account_id' => $this->appId,
            'datetime_from' => $data['start_time'] ?? '',
            'datetime_to' => $data['end_time'] ?? '',
            'page' => $data['page'] ?? 1,
            'page_count' => $data['size'] ?? 200,
            'game_type' => 'ALL',
            'lang' => $this->lang[$data['lang']],
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('getGameHistories'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']['code']], 0);
        }

        return $res;
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
        $this->checkPlayer($lang);
        $params = [
            'account_id' => $this->appId,
            'back_url' => '',
            'username' => $this->player->uuid,
            'lang' => $this->lang[$lang],
            'game_code' => $game->game_extend->code,
            'routing' => 'zh-cn',
            'device' => 'mobile',
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('gameLogin'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']['code']], 0);
        }

        return $res['data']['game_url'] ?? '';
    }

    /**
     * @param string $lang
     * @return true
     * @throws GameException
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $params = [
            'account_id' => $this->appId,
        ];
        $params['check_code'] = $this->createSign($params);
        $res = $this->doCurl($this->createUrl('getGameList'), $params);
        if ($res['status']['code'] != $this->successCode) {
            throw new GameException($this->failCode[$res['status']['code']], 0);
        }
        $insertData = [];
        $langList = [
            'zh-CN' => 'cn',
            'zh-TW' => 'tw',
            'en' => 'en',
        ];
        if (!empty($res['data'])) {
            foreach ($res['data'] as $item) {
                foreach ($item as $game) {
                    $insertData[] = [
                        'platform_id' => $this->platform->id,
                        'cate_id' => 7,
                        'name' => $game[$langList[$lang]] ?? '',
                        'code' => $game['game_code'],
                        'status' => $game['maintain'] ? 0 : 1,
                        'org_data' => json_encode($item),
                    ];
                }
            }
        }
        if (!empty($insertData)) {
            GameExtend::query()->upsert($insertData, ['platform_id', 'code']);
        }

        return true;
    }

    public function createPlayer()
    {
        // TODO: Implement createPlayer() method.
    }

    public function getPlayer()
    {
        // TODO: Implement getPlayer() method.
    }
}
