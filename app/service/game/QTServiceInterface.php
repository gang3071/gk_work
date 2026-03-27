<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use Carbon\Carbon;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Client;

class QTServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    public $method = 'POST';
    public string $error = '';

    private $apiDomain;
    private $username;
    private $password;
    private $passkey;

    private $path = [
        'token' => '/v1/auth/token',
        'lobbyLogin' => '/v1/games/lobby-url',
        'gameList' => '/v1/games',
    ];

    private $lang = [
        'zh-CN' => 'zh_CN',
        'zh-TW' => 'zh_TW',
        'jp' => 'ja_JP',
        'en' => 'en_US',
        'th' => 'th_TH',
        'vi' => 'vi_VN',
        'my' => 'my_MM',
        'id' => 'id_ID',
        'hi_hi' => 'hi_IN',
        'kr_ko' => 'ko_KR',
    ];

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $config = config('game_platform.QT');
        $this->apiDomain = $config['api_domain'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->passkey = $config['passkey'];
        $this->platform = GamePlatform::query()->where('code', 'QT')->first();
        $this->player = $player;
    }

    /**
     * 获取玩家信息（预留接口）
     */
    public function getPlayer()
    {
        // QT中心钱包不需要单独创建玩家
    }

    /**
     * 创建玩家（预留接口）
     */
    public function createPlayer()
    {
        // QT中心钱包不需要单独创建玩家
    }

    /**
     * 获取访问令牌
     * @return string
     * @throws Exception
     */
    private function getAccessToken(): string
    {
        $cacheKey = 'qt_access_token';

        // 尝试从缓存获取token
        $token = Cache::get($cacheKey);
        if ($token) {
            Log::channel('qt_server')->info('QT使用缓存的access_token');
            return $token;
        }

        Log::channel('qt_server')->info('QT开始获取新的access_token');

        // 构建请求URL
        $url = $this->apiDomain . $this->path['token'] . '?' . http_build_query([
            'grant_type' => 'password',
            'response_type' => 'token',
            'username' => $this->username,
            'password' => $this->password,
        ]);

        try {
            $response = \WebmanTech\LaravelHttpClient\Facades\Http::timeout(10)
                ->post($url);

            if (!$response->ok()) {
                Log::channel('qt_server')->error('QT获取access_token失败：HTTP状态异常', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new Exception('获取QT access_token失败');
            }

            $data = $response->json();

            if (empty($data['access_token'])) {
                Log::channel('qt_server')->error('QT获取access_token失败：响应数据异常', ['data' => $data]);
                throw new Exception('获取QT access_token失败');
            }

            $accessToken = $data['access_token'];
            $expiresIn = $data['expires_in'] ?? 21600000; // 默认6小时（毫秒）

            // 转换为秒并缓存（提前5分钟过期以确保安全）
            $cacheSeconds = intval($expiresIn / 1000) - 300;
            Cache::set($cacheKey, $accessToken, $cacheSeconds);

            Log::channel('qt_server')->info('QT成功获取access_token', [
                'expires_in' => $expiresIn,
                'cache_seconds' => $cacheSeconds
            ]);

            return $accessToken;
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT获取access_token异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * 查詢玩家餘額（预留接口，QT中心钱包通过单一钱包接口的balance方法实现）
     * @param array $data
     * @return float
     * @throws GameException
     */
    public function getBalance(array $data = []): float
    {
        return (float)$this->player->machine_wallet->money ?? 0;
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
        Log::channel('qt_server')->info('QT进入游戏大厅开始', [
            'player_id' => $this->player->id
        ]);

        try {
            // 获取Access Token
            $accessToken = $this->getAccessToken();

            // 构建请求URL
            $url = $this->apiDomain . $this->path['lobbyLogin'];

            // 从player获取默认语言（如果有的话）
            $defaultLang = 'zh-TW'; // 默认繁体中文

            // 构建请求payload（参数从player和platform获取，不使用$data）
            $payload = [
                'playerId' => $this->player->uuid,
                'currency' => 'TWD', // 默认台币
                'country' => 'TW', // 默认台湾
                'lang' => $this->lang[$defaultLang] ?? 'zh_TW',
                'mode' => 'real', // real | fun
                'device' => 'DESKTOP', // 默认手机端
                'walletSessionId' => $accessToken,
                'config' => [
                    'singleUseUrl' => false
                ]
            ];



            // 可选参数（从player获取）
            if (!empty($this->player->nickname)) {
                $payload['displayName'] = $this->player->nickname;
            }

            Log::channel('qt_server')->info('QT发送游戏大厅请求', [
                'url' => $url,
                'payload' => $payload
            ]);

            // 发送POST请求，使用Bearer token认证
            $response = \WebmanTech\LaravelHttpClient\Facades\Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);

            if (!$response->ok()) {
                Log::channel('qt_server')->error('QT游戏大厅请求失败：HTTP状态异常', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new GameException('进入游戏大厅失败', 0);
            }

            $result = $response->json();

            Log::channel('qt_server')->info('QT游戏大厅响应', [
                'result' => $result
            ]);

            // 检查响应是否包含url
            if (empty($result['url'])) {
                Log::channel('qt_server')->error('QT游戏大厅响应缺少url', [
                    'result' => $result
                ]);
                throw new GameException('获取游戏大厅URL失败', 0);
            }

            Log::channel('qt_server')->info('QT进入游戏大厅成功', [
                'player_id' => $this->player->id,
                'url' => $result['url'],
                'sessionId' => $result['sessionId'] ?? null
            ]);

            return $result['url'];
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT进入游戏大厅异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }



    /**
     * 单一钱包 - 查询余额
     * @return float
     */
    public function balance(): float
    {
        try {
            if (!$this->player) {
                Log::channel('qt_server')->error('QT balance: 玩家不存在');
                return 0;
            }

            return (float)$this->player->machine_wallet->money;
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT balance异常', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 单一钱包 - 解密（QT不需要解密）
     * @param $data
     * @return array|null
     */
    public function decrypt($data)
    {
        return $data;
    }

    /**
     * 单一钱包 - 下注（QT使用中心钱包debit方法，不使用此接口）
     * @param $data
     * @return array|float
     */
    public function bet($data): array|float
    {
        Log::channel('qt_server')->warning('QT bet方法被调用，但QT应使用中心钱包debit方法');
        $this->error = 'QT_USE_CENTRAL_WALLET';
        return 0;
    }

    /**
     * 单一钱包 - 取消下注（QT使用中心钱包rollback方法，不使用此接口）
     * @param $data
     * @return array|float
     */
    public function cancelBet($data): array|float
    {
        Log::channel('qt_server')->warning('QT cancelBet方法被调用，但QT应使用中心钱包rollback方法');
        $this->error = 'QT_USE_CENTRAL_WALLET';
        return 0;
    }

    /**
     * 单一钱包 - 结算（QT使用中心钱包credit方法，不使用此接口）
     * @param $data
     * @return array|float
     */
    public function betResulet($data): array|float
    {
        Log::channel('qt_server')->warning('QT betResulet方法被调用，但QT应使用中心钱包credit方法');
        $this->error = 'QT_USE_CENTRAL_WALLET';
        return 0;
    }

    /**
     * 单一钱包 - 重新结算（QT不支持）
     * @param $data
     * @return array
     */
    public function reBetResulet($data): array
    {
        Log::channel('qt_server')->warning('QT reBetResulet方法被调用，但QT不支持此功能');
        $this->error = 'QT_NOT_SUPPORTED';
        return [];
    }

    /**
     * 单一钱包 - 送礼（QT不支持）
     * @param $data
     * @return array
     */
    public function gift($data): array
    {
        Log::channel('qt_server')->warning('QT gift方法被调用，但QT不支持此功能');
        $this->error = 'QT_NOT_SUPPORTED';
        return [];
    }

    /**
     * 玩家登出（QT不需要显式登出）
     * @return bool
     */
    public function userLogout(): bool
    {
        Log::channel('qt_server')->info('QT userLogout方法被调用，QT不需要显式登出');
        return true;
    }

    /**
     * 获取游戏列表
     * @param string $lang
     * @return bool
     * @throws Exception
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        return true;
    }

    /**
     * 游戏回放记录（QT通过原始数据获取）
     * @param array $data
     * @return mixed
     */
    public function replay(array $data = [])
    {
        if (!empty($data['original_data'])) {
            $origin = json_decode($data['original_data'], true);
            return $origin['replay_url'] ?? '';
        }
        return '';
    }

    /**
     * 进入游戏（QT直接进入游戏大厅）
     * @param Game $game
     * @param string $lang
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function gameLogin(Game $game, string $lang = 'zh-CN'): string
    {
        Log::channel('qt_server')->info('QT gameLogin调用，进入游戏大厅', [
            'game_code' => $game->game_extend->code ?? '',
            'game_name' => $game->game_extend->name ?? ''
        ]);

        // QT平台通过游戏大厅统一进入，不支持单独进入某个游戏
        return $this->lobbyLogin();
    }

    /**
     * Transfer - start (下注扣款)
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @return array
     */
    public function transferStart(array $params, array $transDetails): array
    {
        try {
            $amount = abs((float)$params['amount']); // amount 为负数，取绝对值
            $orderId = $transDetails['order_id'] ?? '';
            $roundId = $transDetails['round_id'] ?? '';

            Log::channel('qt_server')->info('QT transferStart 开始处理', [
                'order_id' => $orderId,
                'round_id' => $roundId,
                'amount' => $amount,
                'player_id' => $this->player->id
            ]);

            if ($amount <= 0) {
                Log::channel('qt_server')->warning('QT transferStart 金额无效', [
                    'amount' => $amount,
                    'original_amount' => $params['amount']
                ]);
                $this->error = 'BAD_FORMAT_PARAMS';
                return ['balance' => $this->player->machine_wallet->money ?? 0];
            }

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 检查余额
            if ($machineWallet->money < $amount) {
                $this->error = 'INSUFFICIENT_BALANCE';
                return ['balance' => $machineWallet->money];
            }

            // 检查订单是否已存在（使用 order_id）
            $existingRecord = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if ($existingRecord) {
                // 订单已存在，返回当前余额（幂等性）
                $this->error = 'DUPLICATE_TRAN_ID';
                return ['balance' => $machineWallet->money];
            }

            // 创建游戏记录
            $insert = [
                'player_id' => $this->player->id,
                'parent_player_id' => $this->player->recommend_id ?? 0,
                'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $this->player->uuid,
                'platform_id' => $this->platform->id,
                'game_code' => $params['game_code'] ?? '',
                'department_id' => $this->player->department_id,
                'bet' => $amount,
                'win' => 0,
                'diff' => -$amount,
                'order_no' => $orderId,
                'round_no' => $roundId,
                'original_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'order_time' => Carbon::now()->toDateTimeString(),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
            ];

            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

            // 扣款并创建交易记录
            $afterBalance = $this->createBetRecord($machineWallet, $this->player, $record, $amount);

            return ['balance' => (float)$afterBalance];
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT transferStart error', ['error' => $e->getMessage(), 'params' => $params]);
            $this->error = 'SOMETHING_WRONG';
            return ['balance' => $this->player->machine_wallet->money ?? 0];
        }
    }

    /**
     * Transfer - end (结算派彩)
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @param array $betformDetails 注单详情
     * @return array
     */
    public function transferEnd(array $params, array $transDetails, array $betformDetails): array
    {
        try {
            $winAmount = (float)$params['amount']; // amount 为正数（派彩金额）
            $orderId = $transDetails['order_id'] ?? '';
            $roundId = $transDetails['round_id'] ?? '';

            Log::channel('qt_server')->info('QT transferEnd 开始处理', [
                'order_id' => $orderId,
                'round_id' => $roundId,
                'win_amount' => $winAmount,
                'player_id' => $this->player->id
            ]);

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 查找对应的下注记录
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

            // 如果找不到下注记录，根据betform_details自动创建
            if (!$record) {
                Log::channel('qt_server')->warning('QT transferEnd 找不到下注记录，尝试自动创建', [
                    'order_id' => $orderId,
                    'platform_id' => $this->platform->id,
                    'player_id' => $this->player->id,
                    'betform_details' => $betformDetails
                ]);

                // 从 betform_details 获取下注金额
                $betAmount = (float)($betformDetails['bet'] ?? 0);

                // 如果有下注金额，需要先扣款
                if ($betAmount > 0) {
                    // 检查余额
                    if ($machineWallet->money < $betAmount) {
                        Log::channel('qt_server')->error('QT transferEnd 自动创建下注记录失败：余额不足', [
                            'order_id' => $orderId,
                            'bet_amount' => $betAmount,
                            'balance' => $machineWallet->money
                        ]);
                        $this->error = 'INSUFFICIENT_BALANCE';
                        return ['balance' => $machineWallet->money];
                    }

                    // 创建下注记录
                    $insert = [
                        'player_id' => $this->player->id,
                        'parent_player_id' => $this->player->recommend_id ?? 0,
                        'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                        'player_uuid' => $this->player->uuid,
                        'platform_id' => $this->platform->id,
                        'game_code' => $params['game_code'] ?? '',
                        'department_id' => $this->player->department_id,
                        'bet' => $betAmount,
                        'win' => 0,
                        'diff' => -$betAmount,
                        'order_no' => $orderId,
                        'round_no' => $roundId,
                        'original_data' => json_encode(['auto_created' => true, 'from' => 'end'], JSON_UNESCAPED_UNICODE),
                        'order_time' => Carbon::now()->toDateTimeString(),
                        'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
                    ];

                    $record = PlayGameRecord::query()->create($insert);

                    // 扣款
                    $this->createBetRecord($machineWallet, $this->player, $record, $betAmount);

                    // 重新获取钱包（因为扣款后余额变了）
                    $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

                    Log::channel('qt_server')->info('QT transferEnd 自动创建下注记录成功', [
                        'record_id' => $record->id,
                        'bet_amount' => $betAmount,
                        'balance_after_bet' => $machineWallet->money
                    ]);
                } else {
                    // 没有下注金额（免费游戏或奖励），直接创建记录
                    $insert = [
                        'player_id' => $this->player->id,
                        'parent_player_id' => $this->player->recommend_id ?? 0,
                        'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                        'player_uuid' => $this->player->uuid,
                        'platform_id' => $this->platform->id,
                        'game_code' => $params['game_code'] ?? '',
                        'department_id' => $this->player->department_id,
                        'bet' => 0,
                        'win' => 0,
                        'diff' => 0,
                        'order_no' => $orderId,
                        'round_no' => $roundId,
                        'original_data' => json_encode(['auto_created' => true, 'from' => 'end', 'type' => 'free_game'], JSON_UNESCAPED_UNICODE),
                        'order_time' => Carbon::now()->toDateTimeString(),
                        'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
                    ];

                    $record = PlayGameRecord::query()->create($insert);

                    Log::channel('qt_server')->info('QT transferEnd 创建免费游戏记录', [
                        'record_id' => $record->id,
                        'order_id' => $orderId
                    ]);
                }
            } else {
                Log::channel('qt_server')->info('QT transferEnd 找到下注记录', [
                    'record_id' => $record->id,
                    'order_no' => $record->order_no,
                    'settlement_status' => $record->settlement_status,
                    'bet' => $record->bet
                ]);
            }

            // 检查是否已结算
            if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                // 已结算，返回当前余额（幂等性）
                $this->error = 'TRANSACTION_SETTLED';
                return ['balance' => $machineWallet->money];
            }

            // 派彩加款
            if ($winAmount > 0) {
                $beforeBalance = $machineWallet->money;
                $machineWallet->money = bcadd($machineWallet->money, $winAmount, 2);
                $machineWallet->save();

                // 创建派彩交易记录
                $playerDeliveryRecord = new PlayerDeliveryRecord();
                $playerDeliveryRecord->player_id = $this->player->id;
                $playerDeliveryRecord->department_id = $this->player->department_id;
                $playerDeliveryRecord->target = $record->getTable();
                $playerDeliveryRecord->target_id = $record->id;
                $playerDeliveryRecord->platform_id = $this->platform->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
                $playerDeliveryRecord->source = 'player_bet_settlement';
                $playerDeliveryRecord->amount = $winAmount;
                $playerDeliveryRecord->amount_before = $beforeBalance;
                $playerDeliveryRecord->amount_after = $machineWallet->money;
                $playerDeliveryRecord->tradeno = $orderId;
                $playerDeliveryRecord->remark = '遊戲結算';
                $playerDeliveryRecord->user_id = 0;
                $playerDeliveryRecord->user_name = '';
                $playerDeliveryRecord->save();
            }

            // 更新游戏记录
            $record->platform_action_at = Carbon::now()->toDateTimeString();
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
            $record->action_data = json_encode($betformDetails, JSON_UNESCAPED_UNICODE);
            $record->win = $winAmount;
            $record->diff = $winAmount - $record->bet;

            // 有效投注保存在 action_data 中
            if (!empty($betformDetails)) {
                Log::channel('qt_server')->info('QT transferEnd 有效投注', [
                    'order_id' => $record->order_no,
                    'valid_bet' => $betformDetails['valid_bet'] ?? $record->bet
                ]);
            }

            $record->save();

            // 彩金记录
            Client::send('game-lottery', [
                'player_id' => $this->player->id,
                'bet' => $record->bet,
                'play_game_record_id' => $record->id
            ]);

            return ['balance' => (float)$machineWallet->money];
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT transferEnd error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $params,
                'order_id' => $orderId ?? null,
                'player_id' => $this->player->id ?? null
            ]);
            $this->error = 'SOMETHING_WRONG';
            return ['balance' => $this->player->machine_wallet->money ?? 0];
        }
    }

    /**
     * Transfer - refund (退款)
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @return array
     */
    public function transferRefund(array $params, array $transDetails): array
    {
        try {
            $refundAmount = (float)$params['amount']; // amount 为正数（退款金额）
            $orderId = $transDetails['order_id'] ?? '';

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 查找对应的下注记录
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if (!$record) {
                $this->error = 'TRANSACTION_NOT_EXIST';
                return ['balance' => $machineWallet->money];
            }

            // 检查是否已取消
            if ($record->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_CANCELLED) {
                // 已退款，返回当前余额（幂等性）
                $this->error = 'TRANSACTION_SETTLED';
                return ['balance' => $machineWallet->money];
            }

            // 退款加款
            $beforeBalance = $machineWallet->money;
            $machineWallet->money = bcadd($machineWallet->money, $refundAmount, 2);
            $machineWallet->save();

            // 创建退款交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $this->player->id;
            $playerDeliveryRecord->department_id = $this->player->department_id;
            $playerDeliveryRecord->target = $record->getTable();
            $playerDeliveryRecord->target_id = $record->id;
            $playerDeliveryRecord->platform_id = $this->platform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_CANCEL_BET;
            $playerDeliveryRecord->source = 'player_bet_refund';
            $playerDeliveryRecord->amount = $refundAmount;
            $playerDeliveryRecord->amount_before = $beforeBalance;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $orderId;
            $playerDeliveryRecord->remark = '下注退款';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            // 更新游戏记录状态
            $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_CANCELLED;
            $record->platform_action_at = Carbon::now()->toDateTimeString();
            $record->action_data = json_encode($params, JSON_UNESCAPED_UNICODE);
            $record->save();

            return ['balance' => (float)$machineWallet->money];
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT transferRefund error', ['error' => $e->getMessage(), 'params' => $params]);
            $this->error = 'SOMETHING_WRONG';
            return ['balance' => $this->player->machine_wallet->money ?? 0];
        }
    }

    /**
     * Transfer - adjust (调整金额)
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @param array $betformDetails 注单详情
     * @return array
     */
    public function transferAdjust(array $params, array $transDetails, array $betformDetails): array
    {
        try {
            $adjustAmount = (float)$params['amount']; // amount 可正可负
            $orderId = $transDetails['order_id'] ?? '';

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 如果调整金额为0，直接返回
            if ($adjustAmount == 0) {
                return ['balance' => (float)$machineWallet->money];
            }

            // 查找对应的游戏记录
            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if (!$record) {
                $this->error = 'TRANSACTION_NOT_EXIST';
                return ['balance' => $machineWallet->money];
            }

            // 调整余额
            $beforeBalance = $machineWallet->money;
            if ($adjustAmount > 0) {
                // 加款
                $machineWallet->money = bcadd($machineWallet->money, $adjustAmount, 2);
            } else {
                // 扣款
                $deductAmount = abs($adjustAmount);
                if ($machineWallet->money < $deductAmount) {
                    $this->error = 'INSUFFICIENT_BALANCE';
                    return ['balance' => $machineWallet->money];
                }
                $machineWallet->money = bcsub($machineWallet->money, $deductAmount, 2);
            }
            $machineWallet->save();

            // 创建调整交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $this->player->id;
            $playerDeliveryRecord->department_id = $this->player->department_id;
            $playerDeliveryRecord->target = $record->getTable();
            $playerDeliveryRecord->target_id = $record->id;
            $playerDeliveryRecord->platform_id = $this->platform->id;
            $playerDeliveryRecord->type = $adjustAmount > 0 ? PlayerDeliveryRecord::TYPE_SETTLEMENT : PlayerDeliveryRecord::TYPE_BET;
            $playerDeliveryRecord->source = 'player_bet_adjust';
            $playerDeliveryRecord->amount = abs($adjustAmount);
            $playerDeliveryRecord->amount_before = $beforeBalance;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $orderId;
            $playerDeliveryRecord->remark = '金額調整';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            // 更新游戏记录（使用新的betform_details）
            if (!empty($betformDetails)) {
                $record->win = (float)($betformDetails['win'] ?? $record->win);
                $record->diff = (float)($betformDetails['diff'] ?? $record->diff);
                $record->action_data = json_encode($betformDetails, JSON_UNESCAPED_UNICODE);
                $record->platform_action_at = Carbon::now()->toDateTimeString();

                // 有效投注保存在 action_data 中
                Log::channel('qt_server')->info('QT transferAdjust 更新记录', [
                    'order_id' => $record->order_no,
                    'valid_bet' => $betformDetails['valid_bet'] ?? null,
                    'win' => $record->win,
                    'diff' => $record->diff
                ]);

                $record->save();
            }

            return ['balance' => (float)$machineWallet->money];
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT transferAdjust error', ['error' => $e->getMessage(), 'params' => $params]);
            $this->error = 'SOMETHING_WRONG';
            return ['balance' => $this->player->machine_wallet->money ?? 0];
        }
    }

    /**
     * Transfer - reward (额外奖金)
     * @param array $params 请求参数
     * @param array $transDetails 交易详情
     * @param array $betformDetails 注单详情
     * @return array
     */
    public function transferReward(array $params, array $transDetails, array $betformDetails): array
    {
        try {
            $rewardAmount = (float)$params['amount']; // amount 为正数（奖励金额）
            $orderId = $transDetails['order_id'] ?? '';
            $roundId = $transDetails['round_id'] ?? '';

            if ($rewardAmount <= 0) {
                $this->error = 'BAD_FORMAT_PARAMS';
                return ['balance' => $this->player->machine_wallet->money ?? 0];
            }

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 检查订单是否已存在（使用 order_id，避免重复派发）
            $existingRecord = PlayGameRecord::query()
                ->where('order_no', $orderId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if ($existingRecord) {
                // 订单已存在，返回当前余额（幂等性）
                $this->error = 'DUPLICATE_TRAN_ID';
                return ['balance' => $machineWallet->money];
            }

            // 奖励加款
            $beforeBalance = $machineWallet->money;
            $machineWallet->money = bcadd($machineWallet->money, $rewardAmount, 2);
            $machineWallet->save();

            // 创建游戏记录（奖励记录）
            $insert = [
                'player_id' => $this->player->id,
                'parent_player_id' => $this->player->recommend_id ?? 0,
                'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $this->player->uuid,
                'platform_id' => $this->platform->id,
                'game_code' => $params['game_code'] ?? '',
                'department_id' => $this->player->department_id,
                'bet' => 0,
                'win' => $rewardAmount,
                'diff' => $rewardAmount,
                'order_no' => $orderId,
                'round_no' => $roundId,
                'original_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'action_data' => json_encode($betformDetails, JSON_UNESCAPED_UNICODE),
                'order_time' => Carbon::now()->toDateTimeString(),
                'platform_action_at' => Carbon::now()->toDateTimeString(),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_SETTLED
            ];

            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

            // 创建奖励交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $this->player->id;
            $playerDeliveryRecord->department_id = $this->player->department_id;
            $playerDeliveryRecord->target = $record->getTable();
            $playerDeliveryRecord->target_id = $record->id;
            $playerDeliveryRecord->platform_id = $this->platform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
            $playerDeliveryRecord->source = 'player_bet_reward';
            $playerDeliveryRecord->amount = $rewardAmount;
            $playerDeliveryRecord->amount_before = $beforeBalance;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $orderId;
            $playerDeliveryRecord->remark = '額外獎金';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            return ['balance' => (float)$machineWallet->money];
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT transferReward error', ['error' => $e->getMessage(), 'params' => $params]);
            $this->error = 'SOMETHING_WRONG';
            return ['balance' => $this->player->machine_wallet->money ?? 0];
        }
    }

    /**
     * 单一钱包 - 验证签名
     * @param array $params
     * @return bool
     */
    public function verifyAuthCode(array $params): bool
    {
        if (!isset($params['sign'])) {
            Log::channel('qt_server')->error('QT验证失败：缺少sign参数');
            $this->error = 'AUTHORIZATION_INVALID';
            return false;
        }

        // 移除 sign 参数
        $checkParams = $params;
        $receivedSign = $checkParams['sign'];
        unset($checkParams['sign']);

        // 按字母顺序排序
        ksort($checkParams);

        // 拼接参数字符串：key=value&key=value 格式
        $paramsArray = [];
        foreach ($checkParams as $key => $value) {
            // 跳过空值
            if ($value === '' || $value === null) {
                continue;
            }
            $paramsArray[] = $key . '=' . $value;
        }
        $paramsString = implode('&', $paramsArray);

        // 生成签名字符串：params_string + passkey
        $signStr = $paramsString . $this->passkey;

        // MD5加密生成签名
        $expectedSign = md5($signStr);

        // 验证签名
        if (strtolower($receivedSign) !== strtolower($expectedSign)) {
            Log::channel('qt_server')->error('QT签名验证失败', [
                'params' => $params,
                'expected' => $expectedSign,
                'received' => $receivedSign,
                'params_string' => $paramsString,
                'sign_string' => $signStr
            ]);
            $this->error = 'AUTHORIZATION_INVALID';
            return false;
        }

        Log::channel('qt_server')->info('QT签名验证成功');
        return true;
    }

    /**
     * 中心钱包 - 验证Pass-Key
     * @param string|null $passKey
     * @return bool
     */
    public function verifyPassKey(?string $passKey): bool
    {
        if (!$passKey) {
            Log::channel('qt_server')->error('QT Pass-Key缺失');
            return false;
        }

        if ($passKey !== $this->passkey) {
            Log::channel('qt_server')->error('QT Pass-Key验证失败', [
                'expected' => substr($this->passkey, 0, 10) . '...',
                'received' => substr($passKey, 0, 10) . '...'
            ]);
            return false;
        }

        return true;
    }

    /**
     * 中心钱包 - DEBIT (下注扣款)
     * @param array $params
     * @return array
     */
    public function debit(array $params): array
    {
        try {
            $txnId = $params['txnId'];
            $playerId = $params['playerId'];
            $roundId = $params['roundId'];
            $amount = (float)$params['amount'];
            $currency = $params['currency'];
            $gameId = $params['gameId'];

            Log::channel('qt_server')->info('QT DEBIT开始处理', [
                'txnId' => $txnId,
                'roundId' => $roundId,
                'amount' => $amount,
                'player_id' => $this->player->id
            ]);

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 幂等性检查 - 根据txnId查找是否已存在交易
            $existingRecord = PlayGameRecord::query()
                ->where('order_no', $txnId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if ($existingRecord) {
                // 已存在，返回原响应（幂等性）
                Log::channel('qt_server')->info('QT DEBIT交易已存在（幂等）', [
                    'txnId' => $txnId,
                    'record_id' => $existingRecord->id
                ]);

                // 查找对应的交易记录
                $deliveryRecord = PlayerDeliveryRecord::query()
                    ->where('target_id', $existingRecord->id)
                    ->where('type', PlayerDeliveryRecord::TYPE_BET)
                    ->first();

                return [
                    'balance' => number_format($machineWallet->money, 2, '.', ''),
                    'referenceId' => $deliveryRecord ? (string)$deliveryRecord->id : (string)$existingRecord->id
                ];
            }

            // 检查余额（如果不是奖金回合）
            $bonusType = $params['bonusType'] ?? null;
            if (!$bonusType && $machineWallet->money < $amount) {
                $this->error = 'INSUFFICIENT_BALANCE'; // 会被映射为INSUFFICIENT_FUNDS
                Log::channel('qt_server')->error('QT DEBIT余额不足', [
                    'balance' => $machineWallet->money,
                    'amount' => $amount
                ]);
                return ['balance' => number_format($machineWallet->money, 2, '.', '')];
            }

            // TODO: 检查游戏限制（根据业务需求实现）
            // 示例：检查玩家的单次下注限额、日限额等
            // if ($amount > $player->bet_limit) {
            //     $this->error = 'LIMIT_EXCEEDED';
            //     return ['balance' => number_format($machineWallet->money, 2, '.', '')];
            // }

            // 创建游戏记录
            $insert = [
                'player_id' => $this->player->id,
                'parent_player_id' => $this->player->recommend_id ?? 0,
                'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                'player_uuid' => $this->player->uuid,
                'platform_id' => $this->platform->id,
                'game_code' => $gameId,
                'department_id' => $this->player->department_id,
                'bet' => $amount,
                'win' => 0,
                'diff' => -$amount,
                'order_no' => $txnId,
                'round_no' => $roundId,
                'original_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'order_time' => Carbon::now()->toDateTimeString(),
                'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED
            ];

            /** @var PlayGameRecord $record */
            $record = PlayGameRecord::query()->create($insert);

            // 扣款（如果不是奖金回合）
            if (!$bonusType) {
                $afterBalance = $this->createBetRecord($machineWallet, $this->player, $record, $amount);
            } else {
                // 奖金回合，不扣款
                $afterBalance = $machineWallet->money;
                Log::channel('qt_server')->info('QT DEBIT奖金回合，不扣款', [
                    'bonusType' => $bonusType,
                    'bonusAmount' => $params['bonusBetAmount'] ?? 0
                ]);

                // 仍然创建交易记录以便追踪
                $playerDeliveryRecord = new PlayerDeliveryRecord();
                $playerDeliveryRecord->player_id = $this->player->id;
                $playerDeliveryRecord->department_id = $this->player->department_id;
                $playerDeliveryRecord->target = $record->getTable();
                $playerDeliveryRecord->target_id = $record->id;
                $playerDeliveryRecord->platform_id = $this->platform->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_BET;
                $playerDeliveryRecord->source = 'qt_bonus_bet';
                $playerDeliveryRecord->amount = 0;
                $playerDeliveryRecord->amount_before = $afterBalance;
                $playerDeliveryRecord->amount_after = $afterBalance;
                $playerDeliveryRecord->tradeno = $txnId;
                $playerDeliveryRecord->remark = '奖金回合下注';
                $playerDeliveryRecord->user_id = 0;
                $playerDeliveryRecord->user_name = '';
                $playerDeliveryRecord->save();
            }

            // 重新获取钱包以确保余额准确
            $machineWallet = $this->player->machine_wallet()->first();

            Log::channel('qt_server')->info('QT DEBIT成功', [
                'txnId' => $txnId,
                'record_id' => $record->id,
                'balance' => $machineWallet->money
            ]);

            return [
                'balance' => number_format($machineWallet->money, 2, '.', ''),
                'referenceId' => (string)$record->id
            ];
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT DEBIT异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error = 'INTERNAL_ERROR';
            return ['balance' => number_format($this->player->machine_wallet->money ?? 0, 2, '.', '')];
        }
    }

    /**
     * 中心钱包 - CREDIT (派彩加款)
     * @param array $params
     * @return array
     */
    public function credit(array $params): array
    {
        try {
            $txnId = $params['txnId'];
            $betId = $params['betId'] ?? null;
            $playerId = $params['playerId'];
            $roundId = $params['roundId'];
            $amount = (float)$params['amount'];
            $currency = $params['currency'];
            $gameId = $params['gameId'];

            Log::channel('qt_server')->info('QT CREDIT开始处理', [
                'txnId' => $txnId,
                'betId' => $betId,
                'roundId' => $roundId,
                'amount' => $amount,
                'player_id' => $this->player->id
            ]);

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 幂等性检查 - 根据txnId查找是否已存在交易
            $existingCreditRecord = PlayGameRecord::query()
                ->where('order_no', $txnId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if ($existingCreditRecord) {
                // 已存在，返回原响应（幂等性）
                Log::channel('qt_server')->info('QT CREDIT交易已存在（幂等）', [
                    'txnId' => $txnId,
                    'record_id' => $existingCreditRecord->id
                ]);

                // 查找对应的交易记录
                $deliveryRecord = PlayerDeliveryRecord::query()
                    ->where('target_id', $existingCreditRecord->id)
                    ->where('type', PlayerDeliveryRecord::TYPE_SETTLEMENT)
                    ->first();

                return [
                    'balance' => number_format($machineWallet->money, 2, '.', ''),
                    'referenceId' => $deliveryRecord ? (string)$deliveryRecord->id : (string)$existingCreditRecord->id
                ];
            }

            // 查找对应的下注记录
            $betRecord = null;
            if ($betId) {
                $betRecord = PlayGameRecord::query()
                    ->where('order_no', $betId)
                    ->where('platform_id', $this->platform->id)
                    ->first();
            }

            // 如果找不到下注记录，尝试根据roundId查找
            if (!$betRecord) {
                $betRecord = PlayGameRecord::query()
                    ->where('round_no', $roundId)
                    ->where('platform_id', $this->platform->id)
                    ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_UNSETTLED)
                    ->orderBy('id', 'desc')
                    ->first();
            }

            // 派彩加款
            $beforeBalance = $machineWallet->money;
            if ($amount > 0) {
                $machineWallet->money = bcadd($machineWallet->money, $amount, 2);
                $machineWallet->save();
            }

            // 如果找到了下注记录，更新它
            if ($betRecord) {
                $betRecord->win = $amount;
                $betRecord->diff = $amount - $betRecord->bet;
                $betRecord->platform_action_at = Carbon::now()->toDateTimeString();
                $betRecord->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_SETTLED;
                $betRecord->action_data = json_encode($params, JSON_UNESCAPED_UNICODE);
                $betRecord->save();

                $recordForDelivery = $betRecord;

                Log::channel('qt_server')->info('QT CREDIT更新下注记录', [
                    'bet_record_id' => $betRecord->id,
                    'win' => $amount,
                    'diff' => $betRecord->diff
                ]);

                // 彩金记录
                Client::send('game-lottery', [
                    'player_id' => $this->player->id,
                    'bet' => $betRecord->bet,
                    'play_game_record_id' => $betRecord->id
                ]);
            } else {
                // 没有找到下注记录，创建新的派彩记录（可能是奖金回合的派彩）
                Log::channel('qt_server')->warning('QT CREDIT未找到下注记录，创建新记录', [
                    'betId' => $betId,
                    'roundId' => $roundId
                ]);

                $insert = [
                    'player_id' => $this->player->id,
                    'parent_player_id' => $this->player->recommend_id ?? 0,
                    'agent_player_id' => $this->player->recommend_promoter->recommend_id ?? 0,
                    'player_uuid' => $this->player->uuid,
                    'platform_id' => $this->platform->id,
                    'game_code' => $gameId,
                    'department_id' => $this->player->department_id,
                    'bet' => 0,
                    'win' => $amount,
                    'diff' => $amount,
                    'order_no' => $txnId,
                    'round_no' => $roundId,
                    'original_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'order_time' => Carbon::now()->toDateTimeString(),
                    'platform_action_at' => Carbon::now()->toDateTimeString(),
                    'settlement_status' => PlayGameRecord::SETTLEMENT_STATUS_SETTLED
                ];

                $recordForDelivery = PlayGameRecord::query()->create($insert);
            }

            // 创建派彩交易记录
            if ($amount > 0) {
                $playerDeliveryRecord = new PlayerDeliveryRecord();
                $playerDeliveryRecord->player_id = $this->player->id;
                $playerDeliveryRecord->department_id = $this->player->department_id;
                $playerDeliveryRecord->target = $recordForDelivery->getTable();
                $playerDeliveryRecord->target_id = $recordForDelivery->id;
                $playerDeliveryRecord->platform_id = $this->platform->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_SETTLEMENT;
                $playerDeliveryRecord->source = 'qt_credit';
                $playerDeliveryRecord->amount = $amount;
                $playerDeliveryRecord->amount_before = $beforeBalance;
                $playerDeliveryRecord->amount_after = $machineWallet->money;
                $playerDeliveryRecord->tradeno = $txnId;
                $playerDeliveryRecord->remark = '派彩加款';
                $playerDeliveryRecord->user_id = 0;
                $playerDeliveryRecord->user_name = '';
                $playerDeliveryRecord->save();

                $referenceId = (string)$playerDeliveryRecord->id;
            } else {
                // 0金额的派彩（游戏局结束标记）
                $referenceId = (string)$recordForDelivery->id;
            }

            // 重新获取钱包以确保余额准确
            $machineWallet = $this->player->machine_wallet()->first();

            Log::channel('qt_server')->info('QT CREDIT成功', [
                'txnId' => $txnId,
                'amount' => $amount,
                'balance' => $machineWallet->money
            ]);

            return [
                'balance' => number_format($machineWallet->money, 2, '.', ''),
                'referenceId' => $referenceId
            ];
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT CREDIT异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error = 'INTERNAL_ERROR';
            return ['balance' => number_format($this->player->machine_wallet->money ?? 0, 2, '.', '')];
        }
    }

    /**
     * 中心钱包 - Rollback (回滚交易)
     * @param array $params
     * @return array
     */
    public function rollback(array $params): array
    {
        try {
            $betId = $params['betId'];
            $txnId = $params['txnId'];
            $playerId = $params['playerId'];
            $roundId = $params['roundId'];
            $amount = (float)$params['amount'];

            Log::channel('qt_server')->info('QT ROLLBACK开始处理', [
                'betId' => $betId,
                'txnId' => $txnId,
                'roundId' => $roundId,
                'amount' => $amount,
                'player_id' => $this->player->id
            ]);

            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

            // 幂等性检查 - 根据txnId查找是否已处理过该回滚
            $existingRollback = PlayGameRecord::query()
                ->where('order_no', $txnId)
                ->where('platform_id', $this->platform->id)
                ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_CANCELLED)
                ->first();

            if ($existingRollback) {
                // 已回滚过，返回原响应（幂等性）
                Log::channel('qt_server')->info('QT ROLLBACK已处理（幂等）', [
                    'txnId' => $txnId,
                    'record_id' => $existingRollback->id
                ]);

                $deliveryRecord = PlayerDeliveryRecord::query()
                    ->where('target_id', $existingRollback->id)
                    ->where('type', PlayerDeliveryRecord::TYPE_CANCEL_BET)
                    ->first();

                return [
                    'balance' => number_format($machineWallet->money, 2, '.', ''),
                    'referenceId' => $deliveryRecord ? (string)$deliveryRecord->id : null
                ];
            }

            // 查找原始下注记录
            $betRecord = PlayGameRecord::query()
                ->where('order_no', $betId)
                ->where('platform_id', $this->platform->id)
                ->first();

            if (!$betRecord) {
                // 未找到原始交易，按文档说明返回成功
                Log::channel('qt_server')->warning('QT ROLLBACK未找到原始交易，视为成功', [
                    'betId' => $betId
                ]);

                return [
                    'balance' => number_format($machineWallet->money, 2, '.', '')
                    // 不返回referenceId
                ];
            }

            // 检查是否已结算
            if ($betRecord->settlement_status == PlayGameRecord::SETTLEMENT_STATUS_SETTLED) {
                Log::channel('qt_server')->warning('QT ROLLBACK交易已结算，无法回滚', [
                    'betId' => $betId,
                    'settlement_status' => $betRecord->settlement_status
                ]);
                $this->error = 'TRANSACTION_ALREADY_SETTLED';
                return ['balance' => number_format($machineWallet->money, 2, '.', '')];
            }

            // 执行回滚 - 退还下注金额
            $beforeBalance = $machineWallet->money;
            $machineWallet->money = bcadd($machineWallet->money, $amount, 2);
            $machineWallet->save();

            // 更新游戏记录状态
            $betRecord->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_CANCELLED;
            $betRecord->platform_action_at = Carbon::now()->toDateTimeString();
            $betRecord->action_data = json_encode($params, JSON_UNESCAPED_UNICODE);
            $betRecord->save();

            // 创建回滚交易记录
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $this->player->id;
            $playerDeliveryRecord->department_id = $this->player->department_id;
            $playerDeliveryRecord->target = $betRecord->getTable();
            $playerDeliveryRecord->target_id = $betRecord->id;
            $playerDeliveryRecord->platform_id = $this->platform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_CANCEL_BET;
            $playerDeliveryRecord->source = 'qt_rollback';
            $playerDeliveryRecord->amount = $amount;
            $playerDeliveryRecord->amount_before = $beforeBalance;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $txnId;
            $playerDeliveryRecord->remark = '回滚交易';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            // 重新获取钱包以确保余额准确
            $machineWallet = $this->player->machine_wallet()->first();

            Log::channel('qt_server')->info('QT ROLLBACK成功', [
                'betId' => $betId,
                'txnId' => $txnId,
                'amount' => $amount,
                'balance' => $machineWallet->money
            ]);

            return [
                'balance' => number_format($machineWallet->money, 2, '.', ''),
                'referenceId' => (string)$playerDeliveryRecord->id
            ];
        } catch (Exception $e) {
            Log::channel('qt_server')->error('QT ROLLBACK异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error = 'INTERNAL_ERROR';
            return ['balance' => number_format($this->player->machine_wallet->money ?? 0, 2, '.', '')];
        }
    }
}
