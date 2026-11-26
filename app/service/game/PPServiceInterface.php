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
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * Pragmatic Play（PP）平台服务
 *
 * 对接文档：Integration API Specification v3（Seamless Wallet HTTP 服务 3.1.2 / 3.2 / 3.4）
 *  - gameLogin()：出站 GameURL API 换取游戏启动 URL（亚洲区必须），签发一次性 token；
 *  - verifyHash()：回调签名校验（文档 3.2：全部参数按字母序拼 key=value&… 末尾拼 SECRET 后 MD5）；
 *  - verifyToken()：Authenticate 回调的 token 校验（Redis pp:token:{token}，同活动令牌允许重复认证）。
 * 资金操作统一走 RedisLuaScripts::atomicBet / atomicSettle / atomicCancel（见 PPGameController）。
 */
class PPServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    /** 一次性 token 有效期（秒），每次使用续期，覆盖长会话中途重连的重复 authenticate */
    public const TOKEN_TTL = 43200;

    /** CasinoGameAPI / ReplayAPI 端点（文档 2.1/2.2/2.3/2.4/2.7） */
    public const API_GET_CASINO_GAMES = '/IntegrationService/v3/http/CasinoGameAPI/getCasinoGames/';
    public const API_GET_LOBBY_GAMES = '/IntegrationService/v3/http/CasinoGameAPI/getLobbyGames/';
    public const API_CLOSE_SESSION = '/IntegrationService/v3/http/CasinoGameAPI/closeSession/';
    public const API_CANCEL_ROUND = '/IntegrationService/v3/http/CasinoGameAPI/cancelRound/';
    public const API_REPLAY_LINK = '/IntegrationService/v3/http/ReplayAPI/getSharedLink/';

    /** 游戏图标基址（文档 2.1.1 模板：{base}/{gameID}/{gameID}_{size}_{branding}_{language}.{filetype}） */
    public const ICON_BASE_URL = 'https://common-static.ppgames.net/gs2c/common/lobby/v1/apps/slots-lobby-assets';

    public string $method = 'POST';

    private array $config;

    /** @var mixed PP 通道日志 */
    public $log;

    public function __construct($player = null)
    {
        $this->config = config('game_platform.PP', []);
        $this->platform = GamePlatform::query()->where('code', 'PP')->first();
        $this->player = $player;
        $this->log = Log::channel('pp_server');
    }

    /**
     * 回调签名校验（文档 3.2）：
     * 1. 取全部 POST 参数（剔除 hash）按字母表顺序排序；
     * 2. 拼接为 key1=value1&key2=value2；
     * 3. 末尾直接附加密钥：key1=value1&key2=value2SECRET；
     * 4. MD5 后与 hash 参数（大小写不敏感）比对。
     */
    public function verifyHash(array $params): bool
    {
        $hash = strtolower((string)($params['hash'] ?? ''));
        if ($hash === '') {
            return false;
        }
        unset($params['hash']);
        return hash_equals($this->makeHash($params), $hash);
    }

    /** 出站请求签名（GameURL API），$params 不含 hash */
    public function makeHash(array $params): string
    {
        return md5($this->buildSignString($params) . (string)($this->config['secret'] ?? ''));
    }

    private function buildSignString(array $params): string
    {
        $flat = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                parse_str(http_build_query([$key => $value], '', '&'), $tmp);
                $flat += $tmp;
            } else {
                $flat[$key] = $value;
            }
        }
        ksort($flat, SORT_STRING);
        $pairs = [];
        foreach ($flat as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        return implode('&', $pairs);
    }

    // ==================== 一次性 token（GameURL → Authenticate） ====================

    /** 签发一次性 token 并缓存玩家上下文 */
    public function createToken(Player $player, string $gameCode = ''): string
    {
        $token = bin2hex(random_bytes(16));
        \support\Redis::connection()->setex(
            'pp:token:' . $token,
            self::TOKEN_TTL,
            json_encode(['player_id' => $player->id, 'game_id' => $gameCode, 'created_at' => time()])
        );
        return $token;
    }

    /**
     * 校验 token；成功返回 ['player_id' => int, 'game_id' => string] 并续期
     * （3.4 备注：运营商须接受同一活动令牌的额外 authenticate 请求），无效返回 false
     */
    public function verifyToken(string $token)
    {
        if ($token === '') {
            return false;
        }
        $redis = \support\Redis::connection();
        $raw = $redis->get('pp:token:' . $token);
        if (!$raw) {
            return false;
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || empty($data['player_id'])) {
            return false;
        }
        $redis->expire('pp:token:' . $token, self::TOKEN_TTL);
        return $data;
    }

    // ==================== GameURL API（3.1.2，亚洲区必须） ====================

    public function gameLogin(Game $game, string $lang = 'zh-tw'): mixed
    {
        $this->checkPlayer();
        return $this->launchGameUrl((string)($game->game_extend->code ?? ''), $lang);
    }

    /**
     * GameURL API 换取游戏启动 URL（文档 3.1.2，亚洲区必须），签发一次性 token
     * @param string $symbol 游戏符号 ID（如 vs20olympgate）
     */
    private function launchGameUrl(string $symbol, string $lang): string
    {
        $token = $this->createToken($this->player, $symbol);
        $params = [
            'secureLogin' => (string)($this->config['secure_login'] ?? ''),
            'symbol' => $symbol,
            'language' => $this->mapLanguage($lang),
            'token' => $token,
            'externalPlayerId' => (string)$this->player->uuid,
            'country' => (string)($this->config['country'] ?? 'TW'),
            'currency' => (string)$this->player->currency,
            'platform' => 'MOBILE',
            'technology' => 'H5',
            'promo' => (string)($this->config['promo_available'] ?? 'y'),
            'playMode' => 'REAL',
        ];
        if (($stylename = (string)($this->config['stylename'] ?? '')) !== '') {
            $params['stylename'] = $stylename;
        }
        $params['hash'] = $this->makeHash($params);
        $res = $this->doGameUrlRequest($params);
        if ((string)($res['error'] ?? '1') !== '0' || empty($res['gameURL'])) {
            throw new GameException('PP 游戏启动失败: ' . ($res['description'] ?? 'unknown error'));
        }
        return $res['gameURL'];
    }

    private function doGameUrlRequest(array $params): array
    {
        $path = (string)($this->config['game_url_path'] ?? '/IntegrationService/v3/http/CasinoGameAPI/game/url/');
        $url = rtrim((string)($this->config['api_domain'] ?? ''), '/') . $path;
        try {
            $response = Http::timeout(7)->asForm()->post($url, $params);
        } catch (Exception $e) {
            $this->log->error('PP GameURL 请求异常', ['url' => $url, 'error' => $e->getMessage()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }
        $res = json_decode((string)$response->body(), true);
        if (!is_array($res)) {
            $this->log->error('PP GameURL 响应解析失败', ['url' => $url, 'body' => (string)$response->body()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }
        return $res;
    }

    /**
     * PP language：ISO 639-1 标准（如 en、fr、it），带地区变体用 IETF 形态（如 pt-br，文档明确允许）。
     * 中文按简繁区分符号：zh-CN/zh-Hans/zh-SG → zh-cn（简体）；zh-TW/zh-HK/zh-MO/zh-Hant → zh-tw（繁体）。
     */
    private function mapLanguage(string $lang): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($lang)));
        // 内部语种码 / 中文简繁 → PP 符号
        $map = [
            'zh' => 'zh-cn', 'zh-cn' => 'zh-cn', 'zh-hans' => 'zh-cn', 'zh-sg' => 'zh-cn',
            'zh-tw' => 'zh-tw', 'zh-hant' => 'zh-tw', 'zh-hk' => 'zh-tw', 'zh-mo' => 'zh-tw',
            'jp' => 'ja', 'ja' => 'ja',
            'kr' => 'ko', 'kr-ko' => 'ko', 'ko' => 'ko',
            'km' => 'km', 'km-kh' => 'km',
        ];
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }
        // 带地区变体的 IETF 形态原样保留（如 pt-br），否则取 ISO 639-1 两字母
        return str_contains($normalized, '-') ? $normalized : substr($normalized, 0, 2);
    }

    /** PlayerGamePlatform 绑定（同 KT 范式） */
    protected function checkPlayer(): void
    {
        $record = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if ($record) {
            return;
        }
        $record = new PlayerGamePlatform();
        $record->player_id = $this->player->id;
        $record->platform_id = $this->platform->id;
        $record->player_name = $this->player->name;
        $record->player_code = $this->player->uuid;
        $record->web_id = $this->getWebId();
        $record->player_password = '';
        $record->save();
    }

    // ==================== GameServiceInterface ====================

    public function createPlayer()
    {
        return true;
    }

    public function getPlayer()
    {
        return $this->player;
    }

    /**
     * 进入大厅（游戏内置迷你大厅，文档 3.4）：
     * 优先 config lobby_symbol（env PP_LOBBY_SYMBOL），否则取 GetLobbyGames('all') 首个游戏打开
     */
    public function lobbyLogin(array $data = []): string
    {
        $this->checkPlayer();
        $lang = (string)($data['lang'] ?? 'zh-tw');
        $symbol = (string)($this->config['lobby_symbol'] ?? '');
        if ($symbol === '') {
            $lobby = $this->getLobbyGames('all');
            $symbol = (string)(($lobby['all'][0]['gameID'] ?? '') ?: '');
        }
        if ($symbol === '') {
            throw new GameException('PP 大厅游戏列表为空');
        }
        return $this->launchGameUrl($symbol, $lang);
    }

    /**
     * 登出即关闭活动游戏会话（文档 2.3 CloseSession）：clearHistory=0 允许完成最后一轮；
     * PP 侧失败仅告警，不阻塞登出
     */
    public function userLogout()
    {
        $player = $this->player;
        if (empty($player)) {
            return true;
        }
        try {
            $res = $this->doApiRequest(self::API_CLOSE_SESSION, [
                'secureLogin' => (string)($this->config['secure_login'] ?? ''),
                'externalPlayerId' => (string)$player->uuid,
                'clearHistory' => 0,
            ], 'CloseSession');
            if ((string)($res['error'] ?? '1') !== '0') {
                $this->log->warning('PP CloseSession 失败', ['player_id' => $player->id, 'res' => $res]);
            }
        } catch (Exception $e) {
            $this->log->warning('PP CloseSession 异常: ' . $e->getMessage(), ['player_id' => $player->id]);
        }
        return true;
    }

    /**
     * 同步游戏列表（文档 2.1 GetCasinoGames）→ game_extend upsert
     * cate_id 映射: gameTypeID vs→CATE_SLO、vp→CATE_COMPUTER_GAME、rl/tt→CATE_TABLE；
     * dataType LC→CATE_LIVE_VIDEO、VSB→CATE_SPORT 优先覆盖
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        $res = $this->doApiRequest(self::API_GET_CASINO_GAMES, [
            'secureLogin' => (string)($this->config['secure_login'] ?? ''),
            'options' => 'GetFrbDetails,GetLines,GetDataTypes,GetFeatures',
        ], 'GetCasinoGames');
        if ((string)($res['error'] ?? '1') !== '0') {
            throw new GameException('PP 游戏列表获取失败: ' . ($res['description'] ?? 'unknown error'));
        }

        $insertData = [];
        foreach (($res['gameList'] ?? []) as $item) {
            $code = (string)($item['gameID'] ?? '');
            if ($code === '') {
                continue;
            }
            $insertData[] = [
                'game_id' => (string)($item['gameIdNumeric'] ?? ($item['gameIDNumeric'] ?? '')),
                'platform_id' => $this->platform->id,
                'cate_id' => $this->mapCateId($item),
                'name' => (string)($item['gameName'] ?? $code),
                'code' => $code,
                'logo' => self::ICON_BASE_URL . '/' . $code . '/' . $code . '_260x350_NB.png',
                'status' => 1,
                'org_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
            ];
        }
        if (!empty($insertData)) {
            GameExtend::query()->upsert($insertData, ['platform_id', 'code']);
        }
        return true;
    }

    /**
     * 游戏回放链接（文档 2.7 ReplayAPI/getSharedLink，GET，仅 31 天内回合）
     * $data 为 play_game_record 行：original_data 内含 PP 回调原始参数（roundId / userId）
     */
    public function replay(array $data = [])
    {
        $original = $data['original_data'] ?? [];
        if (is_string($original)) {
            $original = json_decode($original, true) ?: [];
        }
        $roundId = (string)($original['roundId'] ?? '');
        $externalPlayerId = (string)($original['userId'] ?? '');
        if ($externalPlayerId === '' && !empty($data['player_id'])) {
            $externalPlayerId = (string)Player::query()->where('id', $data['player_id'])->value('uuid');
        }
        if ($roundId === '' || $externalPlayerId === '') {
            $this->log->warning('PP replay 缺少 roundId/userId', ['data' => $data]);
            return '';
        }
        try {
            $res = $this->doApiRequest(self::API_REPLAY_LINK, [
                'secureLogin' => (string)($this->config['secure_login'] ?? ''),
                'roundId' => $roundId,
                'externalPlayerId' => $externalPlayerId,
            ], 'getSharedLink', 'GET');
        } catch (Exception $e) {
            $this->log->warning('PP replay 请求失败: ' . $e->getMessage(), ['round_id' => $roundId]);
            return '';
        }
        if ((string)($res['error'] ?? '1') !== '0' || empty($res['url'])) {
            $this->log->warning('PP replay 失败', ['round_id' => $roundId, 'res' => $res]);
            return '';
        }
        return (string)$res['url'];
    }

    // ==================== 集成 API 辅助（文档 2.x） ====================

    /**
     * 大厅游戏列表（文档 2.2 GetLobbyGames）
     * @param string $categories all/new/hot（逗号分隔）
     * @return array ['all' => [...], 'new' => [...], 'hot' => [...]]（按请求类别返回）
     */
    public function getLobbyGames(string $categories = 'all', string $country = 'TW'): array
    {
        $params = [
            'secureLogin' => (string)($this->config['secure_login'] ?? ''),
            'categories' => $categories,
        ];
        if ($country !== '') {
            $params['country'] = $country;
        }
        $res = $this->doApiRequest(self::API_GET_LOBBY_GAMES, $params, 'GetLobbyGames');
        if ((string)($res['error'] ?? '1') !== '0') {
            throw new GameException('PP 大厅游戏获取失败: ' . ($res['description'] ?? 'unknown error'));
        }
        return (array)($res['games'] ?? []);
    }

    /**
     * 强制取消回合（文档 2.4 CancelRound）：PP 随后向我方钱包发起退款请求，回合标记已取消。
     * 注意：BT 集成仅 24 小时内可取消
     * @return bool
     */
    public function cancelRound(string $externalPlayerId, string $gameId, string $roundId): bool
    {
        $res = $this->doApiRequest(self::API_CANCEL_ROUND, [
            'secureLogin' => (string)($this->config['secure_login'] ?? ''),
            'externalPlayerId' => $externalPlayerId,
            'gameId' => $gameId,
            'roundId' => $roundId,
        ], 'CancelRound');
        return (string)($res['error'] ?? '1') === '0';
    }

    /** 游戏类型 → GameType cate 常量（dataType 优先覆盖 gameTypeID 映射） */
    private function mapCateId(array $item): int
    {
        $dataType = strtoupper((string)($item['dataType'] ?? ''));
        if ($dataType === 'LC') {
            return GameType::CATE_LIVE_VIDEO;
        }
        if ($dataType === 'VSB') {
            return GameType::CATE_SPORT;
        }
        switch (strtolower((string)($item['gameTypeID'] ?? ''))) {
            case 'vp':
                return GameType::CATE_COMPUTER_GAME;
            case 'rl':
            case 'tt':
                return GameType::CATE_TABLE;
            default:
                return GameType::CATE_SLO;
        }
    }

    /**
     * CasinoGameAPI / ReplayAPI 通用请求（表单编码；GET 时参数走 query）
     * @param string $path 端点路径（self::API_*）
     * @param array $params 请求参数（不含 hash，自动追加签名）
     * @param string $action 日志标识
     * @param string $method POST|GET
     */
    private function doApiRequest(string $path, array $params, string $action, string $method = 'POST'): array
    {
        $params['hash'] = $this->makeHash($params);
        $url = rtrim((string)($this->config['api_domain'] ?? ''), '/') . $path;
        try {
            $client = Http::timeout(7)->asForm();
            $response = strtoupper($method) === 'GET' ? $client->get($url, $params) : $client->post($url, $params);
        } catch (Exception $e) {
            $this->log->error('PP ' . $action . ' 请求异常', ['url' => $url, 'error' => $e->getMessage()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }
        $res = json_decode((string)$response->body(), true);
        if (!is_array($res)) {
            $this->log->error('PP ' . $action . ' 响应解析失败', ['url' => $url, 'body' => (string)$response->body()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }
        return $res;
    }

    // ==================== SingleWalletServiceInterface（资金已改走 RedisLuaScripts） ====================

    public function balance(): mixed
    {
        return \app\service\WalletService::getBalance($this->player->id);
    }

    public function bet($data): mixed
    {
        throw new Exception('PP bet() 已废弃，请使用 RedisLuaScripts::atomicBet');
    }

    public function cancelBet($data): mixed
    {
        throw new Exception('PP cancelBet() 已废弃，请使用 RedisLuaScripts::atomicCancel');
    }

    public function betResulet($data): mixed
    {
        throw new Exception('PP betResulet() 已废弃，请使用 RedisLuaScripts::atomicSettle');
    }

    public function reBetResulet($data): mixed
    {
        throw new Exception('PP reBetResulet() 已废弃，请使用 RedisLuaScripts::atomicSettle');
    }

    public function gift($data): mixed
    {
        throw new Exception('PP 平台不支持 gift() 功能');
    }

    public function decrypt($data): mixed
    {
        return $data; // PP 回调为明文表单，无解密
    }

    /** 余额不足错误码（文档 3.17 error=1） */
    protected function getInsufficientBalanceError(): mixed
    {
        return 1;
    }
}
