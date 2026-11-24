<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GameExtend;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Player;
use app\model\PlayerGamePlatform;
use app\wallet\controller\game\ASGameController;
use Carbon\Carbon;
use Exception;
use support\Log;
use support\Redis;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * AS电子（AS Gaming）对接服务
 *
 * 出站：合作商API（我们调 AS 服务器）
 *   正式站 https://ex.as568.com  测试站 https://ex.as88.online
 *   回应格式 {error_code, error_text, data}，error_code == "0" 成功
 *
 * 入站：Seamless Wallet（AS 调我们）见 ASGameController
 *
 * 参数型态严格对照 docs/ASgaming 合作商API單一錢包介接文件(合作商).docx
 *   （bool 用 true/false、int 用数字，勿以 0/1 或字符串顶替，AS 网关会严格校验型态）
 *
 * 签名规则（出入站一致）：
 *   1. 参数按 key 升序（a~z）排序，hash/ts 不参与签名，数组类型值不参与签名
 *   2. 以 key=value 用 & 串接（布尔值按 JSON 字面 true/false 串接）
 *   3. 尾部追加 shared_key={share_key}
 *   4. 取 MD5（小写）
 *   范例：md5(user_id=1&username=test&shared_key=token)
 */
class ASServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    public string $method = 'POST';
    public string $successCode = '0';
    private mixed $apiDomain;

    /** 语系映射（AS 语系代码：en,zh-tw,zh-cn,vi,th,ja,ko,id,pt,tr,es,bn） */
    private array $lang = [
        'zh-CN' => 'zh-cn',
        'zh-TW' => 'zh-tw',
        'en'    => 'en',
        'th'    => 'th',
        'vi'    => 'vi',
        'jp'    => 'ja',
        'kr_ko' => 'ko',
        'km_KH' => 'en',
        'id'    => 'id',
    ];

    /** 币种映射（站内币种 => AS 币种 ISO4217） */
    private array $currency = [
        'TWD' => 'TWD',
        'CNY' => 'CNY',
        'JPY' => 'JPY',
        'USD' => 'USD',
    ];

    private array $config;

    public $log;

    /** 出站 API 路径 */
    public const API_GAME_LIST = '/api/slot/game/list';
    public const API_WAGER_TOKEN = '/api/slot/wager/token';
    public const API_WAGER_RECORD = '/api/slot/partner/wager/record';
    public const API_WAGER_EVENT_RECORD = '/api/slot/partner/wager/event/record';
    public const API_WAGER_PENDING = '/api/slot/wager/pending';

    /** 游戏种类 */
    public const KIND_SLOT = 'slot';
    public const KIND_CRUSH = 'crush';
    public const KIND_FISHING = 'fishing';
    public const KIND_ARCADE = 'arcade';

    /** 游戏种类 => 本地游戏分类 */
    public const KIND_CATE_MAP = [
        self::KIND_SLOT    => GameType::CATE_SLO,
        self::KIND_CRUSH   => GameType::CATE_SLO,
        self::KIND_FISHING => GameType::CATE_FISH,
        self::KIND_ARCADE  => GameType::CATE_ARCADE,
    ];

    /** 游戏连结 sid 有效期（秒） */
    public const SESSION_TTL = 28800; // 8 小时

    /** 入站 ts 有效窗口（秒）。文档仅要求 10 位时间戳；结合「重试一律返回原始的响应」放宽到 300 秒 */
    public const TS_MAX_WINDOW = 300;

    /** 出站签名布尔值拼串风格（文档未明示，按 JSON 字面 true/false） */
    public const BOOL_SIGN_STYLE = 'json';

    /** 入站验签布尔值候选拼串风格（文档未明示，多风格回退兼容 AS 实现） */
    private const BOOL_SIGN_STYLES = ['json', 'num', 'php', 'cs'];

    /** 已记录过的入站布尔值拼串风格（每进程每风格只记一次日志） */
    private static array $boolStyleLogged = [];

    /** 验签环境基线日志是否已记录（每进程一次） */
    private static bool $signEnvLogged = false;

    /** 移动端进入（is_mobile） */
    public int $isMobile = 0;

    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $this->config = config('game_platform.AS');
        $this->apiDomain = $this->config['api_domain'];
        $this->platform = GamePlatform::query()->where('code', 'AS')->first();
        $this->player = $player;
        $this->log = Log::channel('as_server');
    }

    /**
     * AS 签名：参数按 key 升序（a~z），key=value 以 & 串接，尾部追加 shared_key=token 后取 MD5
     * ts 与 hash 不参与签名；数组类型值不参与签名；布尔值按 JSON 字面 true/false 串接
     * @param array $params
     * @return string
     */
    public function makeSign(array $params): string
    {
        return md5($this->makeSignSource($params));
    }

    /**
     * hash 加密前的字串（AS 疑难排查要求提供的资讯之一）
     * @param array $params
     * @param string $boolStyle 布尔值拼串风格
     * @return string
     */
    public function makeSignSource(array $params, string $boolStyle = self::BOOL_SIGN_STYLE): string
    {
        unset($params['hash'], $params['ts']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $this->renderBool($value, $boolStyle);
            }
            $pairs[] = $key . '=' . $value;
        }
        $str = implode('&', $pairs);
        return ($str === '' ? '' : $str . '&') . 'shared_key=' . trim((string)($this->config['share_key'] ?? ''));
    }

    /**
     * 布尔值拼串（文档未明示）：json=true/false，num=1/0，php=1/空串，cs=True/False
     * @param bool $value
     * @param string $style
     * @return string
     */
    private function renderBool(bool $value, string $style): string
    {
        return match ($style) {
            'num' => $value ? '1' : '0',
            'php' => $value ? '1' : '',
            'cs' => $value ? 'True' : 'False',
            default => $value ? 'true' : 'false',
        };
    }

    /**
     * 校验入站钱包请求签名（与出站同一规则；布尔值多拼串风格回退）
     * 文档未明示布尔值拼串方式，若仅按出站风格校验，AS 风格不同时会拒绝其
     * withdraw/deposit（携带 completed 布尔值）导致卡单，故逐风格尝试。
     * @param array $params
     * @return bool
     */
    public function verifySign(array $params): bool
    {
        $this->logSignEnvOnce();
        $hash = strtolower((string)($params['hash'] ?? ''));
        if ($hash === '') {
            return false;
        }
        foreach (self::BOOL_SIGN_STYLES as $style) {
            if (hash_equals(md5($this->makeSignSource($params, $style)), $hash)) {
                if ($style !== self::BOOL_SIGN_STYLE) {
                    $this->logBoolStyleOnce($style);
                }
                return true;
            }
        }
        return false;
    }

    /**
     * 验签环境基线日志（每进程一次）：key 指纹用于跨环境比对，不暴露密钥本身
     * @return void
     */
    private function logSignEnvOnce(): void
    {
        if (self::$signEnvLogged) {
            return;
        }
        self::$signEnvLogged = true;
        $key = trim((string)($this->config['share_key'] ?? ''));
        $this->log->info('AS验签环境', [
            'key_configured' => $key !== '',
            'key_fingerprint' => substr(md5($key), 0, 8),
            'bool_sign_style' => self::BOOL_SIGN_STYLE,
            'ts_window' => self::TS_MAX_WINDOW,
        ]);
    }

    /**
     * sign 原串脱敏（shared_key 部分遮蔽，供日志排查/提供给 AS 客服）
     * @param string $signSource
     * @return string
     */
    public function maskSignSource(string $signSource): string
    {
        return preg_replace('/shared_key=[^&]*$/', 'shared_key=***', $signSource);
    }

    /**
     * 记录与出站不同布尔值拼串风格（学习 AS 的实际约定）
     * @param string $style
     * @return void
     */
    private function logBoolStyleOnce(string $style): void
    {
        if (isset(self::$boolStyleLogged[$style])) {
            return;
        }
        self::$boolStyleLogged[$style] = true;
        $this->log->info('AS入站布尔值拼串风格与出站不同', ['style' => $style]);
    }

    /**
     * 组装并请求 AS 出站接口（POST JSON，Header: partner/lang，ts 10 位）
     * @param string $url
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function doCurl(string $url, array $params = [])
    {
        $params['ts'] = time();
        $signSource = $this->makeSignSource($params);
        $params['hash'] = md5($signSource);
        $signSourceMasked = $this->maskSignSource($signSource);

        $response = Http::timeout(7)
            ->withHeaders([
                'partner' => (string)($this->config['partner_id'] ?? ''),
                'lang' => (string)($params['lang'] ?? 'zh-cn'),
            ])
            ->asJson()
            ->post($this->apiDomain . $url, $params);

        if (!$response->ok()) {
            $this->log->error("AS请求失败 {$url}", ['params' => $params, 'sign' => $signSourceMasked, 'response' => $response->body()]);
            throw new GameException(trans('system_busy', [], 'message'));
        }

        $res = json_decode($response->body(), true);
        if (empty($res)) {
            throw new Exception(trans('system_busy', [], 'message'));
        }
        if (($res['error_code'] ?? '') != $this->successCode) {
            $this->log->info("AS业务失败 {$url}", ['params' => $params, 'sign' => $signSourceMasked, 'res' => $res]);
            throw new GameException((($res['error_text'] ?? '') ?: trans('system_busy', [], 'message')), 0);
        }

        return $res;
    }

    /**
     * 本地档案检查（AS 无注册端点，会员随游戏连结 sid 创建）
     * @return PlayerGamePlatform
     * @throws GameException
     */
    private function checkPlayer()
    {
        $playerGamePlatform = PlayerGamePlatform::query()
            ->where('platform_id', $this->platform->id)
            ->where('player_id', $this->player->id)
            ->first();
        if (empty($playerGamePlatform)) {
            $this->createPlayer();
            $playerGamePlatform = new PlayerGamePlatform;
            $playerGamePlatform->player_id = $this->player->id;
            $playerGamePlatform->platform_id = $this->platform->id;
            $playerGamePlatform->player_name = $this->player->name;
            $playerGamePlatform->player_code = $this->player->uuid;
            $playerGamePlatform->web_id = $this->getWebId();
            $playerGamePlatform->save();
        }

        return $playerGamePlatform;
    }

    /**
     * 创建玩家（AS 合作商API 无独立注册端点，仅落本地档案）
     * @return array
     */
    public function createPlayer()
    {
        return [
            'username' => $this->player->uuid,
            'currency' => $this->walletCurrency(),
        ];
    }

    /**
     * 生成游戏连结 sid 并绑定会员（AS 以此登入会员，钱包 /verify-session 以此验证）
     * @return string
     */
    public function generateSession(): string
    {
        $sid = md5($this->player->id . ':' . uniqid('as', true) . ':' . mt_rand());
        Redis::setex('as:session:' . $sid, self::SESSION_TTL, (string)$this->player->uuid);
        return $sid;
    }

    /**
     * 解析游戏连结 sid => 会员
     * @param string $session
     * @return Player|null
     */
    public function getPlayerBySession(string $session): ?Player
    {
        if ($session === '') {
            return null;
        }
        $uuid = Redis::get('as:session:' . $session);
        if (empty($uuid)) {
            return null;
        }
        return Player::query()->where('uuid', $uuid)->first();
    }

    /**
     * 当前会员钱包币种（ISO4217）
     * @return string
     */
    public function walletCurrency(): string
    {
        $currency = 'TWD';
        return strtoupper($this->currency[$currency] ?? $currency);
    }

    /**
     * 游戏清单（/api/slot/game/list）
     * @param string $session 游戏连结 sid，AS 以此登入会员（可空：仅同步目录）
     * @param string $lang
     * @param int $isMobile 是否手机版（出站按文档转为 bool）
     * @param string $gameId 游戏 ID（出站按文档转为 int）
     * @param string $kind
     * @return array
     * @throws Exception
     */
    private function gameList(string $session = '', string $lang = 'zh-CN', int $isMobile = 0, string $gameId = '', string $kind = ''): array
    {
        $params = [
            'lang' => $this->lang[$lang] ?? 'zh-cn',
            'is_mobile' => (bool)$isMobile,
            'brand_id' => (int)($this->config['brand_id'] ?? 0),
            'is_thumbnail_required' => true,
            'ip' => (string)request()->getRealIp(),
        ];
        if ($session !== '') {
            $params['session'] = (string)$session;
        }
        if ($gameId !== '') {
            $params['game_id'] = (int)$gameId;
        }
        if ($kind !== '') {
            $params['kind'] = (string)$kind;
        }

        return $this->doCurl(self::API_GAME_LIST, $params);
    }

    /**
     * 进入游戏大厅（AS 无独立大厅入口，返回游戏清单第一款游戏连结）
     * @param array $data ['lang' => ..., 'is_mobile' => ..., 'kind' => ...]
     * @return string
     * @throws GameException
     * @throws Exception
     */
    public function lobbyLogin(array $data = []): string
    {
        $this->checkPlayer();
        $session = $this->generateSession();
        $this->isMobile = (int)($data['is_mobile'] ?? 0);
        $res = $this->gameList($session, (string)($data['lang'] ?? 'zh-CN'), $this->isMobile, '', (string)($data['kind'] ?? self::KIND_SLOT));
        $games = $res['data'] ?? [];
        if (empty($games)) {
            $this->log->info('lobbyLogin 游戏清单为空', ['player_id' => $this->player->id]);
            throw new GameException(trans('system_busy', [], 'message'));
        }

        return (string)$games[0]['url'];
    }

    /**
     * 进入指定游戏（url 已带 sid）
     * @param Game $game
     * @param string $lang
     * @return mixed
     * @throws GameException
     * @throws Exception
     */
    public function gameLogin(Game $game, string $lang = 'zh-CN'): mixed
    {
        $this->checkPlayer();
        $session = $this->generateSession();
        $res = $this->gameList($session, $lang, $this->isMobile, (string)($game->game_extend->code ?? ''));
        $games = $res['data'] ?? [];
        if (empty($games)) {
            $this->log->info('gameLogin 未取得游戏连结', ['game' => $game->game_extend->code ?? '']);
            throw new GameException(trans('system_busy', [], 'message'));
        }

        return (string)$games[0]['url'];
    }

    /**
     * 同步游戏清单到 GameExtend
     * @param string $lang
     * @return bool
     * @throws Exception
     */
    public function getGameList(string $lang = 'zh-CN'): bool
    {
        foreach (array_keys(self::KIND_CATE_MAP) as $kind) {
            $res = $this->gameList('', $lang, 0, '', $kind);
            $insertData = [];
            foreach (($res['data'] ?? []) as $item) {
                $name = $item['name'] ?? [];
                $insertData[] = [
                    'game_id' => (string)$item['id'],
                    'platform_id' => $this->platform->id,
                    'cate_id' => self::KIND_CATE_MAP[$kind],
                    'name' => $name['zh-cn'] ?? ($name['zh-tw'] ?? ($name['en'] ?? '')),
                    'code' => (string)$item['id'],
                    'logo' => $item['thumbnail']['square_url'] ?? ($item['thumbnail']['horizontal_url'] ?? ''),
                    'status' => 1,
                    'org_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
                ];
            }
            if (!empty($insertData)) {
                GameExtend::query()->upsert($insertData, ['platform_id', 'code']);
            }
        }

        return true;
    }

    /**
     * 游戏回放/细单连结（/api/slot/wager/token，url 仅能开一次）
     * @param array $data ['wager_id' => ..., 'game_type' => ..., 'username' => ...]
     * @return mixed
     * @throws GameException
     * @throws Exception
     */
    public function replay(array $data = []): mixed
    {
        $params = [
            'wager_id' => (int)($data['wager_id'] ?? 0),
            'game_type' => (int)($data['game_type'] ?? 0),
            'username' => (string)($data['username'] ?? ($this->player->uuid ?? '')),
        ];
        if ($params['wager_id'] <= 0 || $params['game_type'] <= 0) {
            $this->log->info('replay 缺少参数', ['data' => $data]);
            throw new GameException(trans('system_busy', [], 'message'));
        }
        if ($params['username'] !== '') {
            $params['brand_domain_code'] = (string)($this->config['brand_domain_code'] ?? '');
        }

        $res = $this->doCurl(self::API_WAGER_TOKEN, $params);

        return (string)($res['data']['url'] ?? '');
    }

    /**
     * 区间注单查询（/api/slot/partner/wager/record，仅最近 90 天、单次区间 ≤ 24 小时）
     * @param string $startTime YYYY-mm-dd H:i:s（北京时区）
     * @param string $endTime
     * @param int $page
     * @param int $perPage 上限 1000
     * @return array {total,page,per_page,wager_info[]}
     * @throws Exception
     */
    public function wagerRecord(string $startTime, string $endTime, int $page = 1, int $perPage = 200): array
    {
        $res = $this->doCurl(self::API_WAGER_RECORD, [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'page' => max($page, 1),
            'per_page' => min(max($perPage, 1), 1000),
        ]);

        return $res['data'] ?? [];
    }

    /**
     * 抄单增量查询（/api/slot/partner/wager/event/record，频率 5 秒/次，下次带 last_version_id）
     * @param int $versionId 首调可传 0
     * @param int $maxNum 默认 500，最多 5000
     * @return array {last_version_id,total,wager_info[]}
     * @throws Exception
     */
    public function wagerEventRecord(int $versionId = 0, int $maxNum = 500): array
    {
        $params = ['max_num' => min(max($maxNum, 1), 5000)];
        if ($versionId > 0) {
            $params['version_id'] = $versionId;
        }
        $res = $this->doCurl(self::API_WAGER_EVENT_RECORD, $params);

        return $res['data'] ?? [];
    }

    /**
     * 未处理注单（/api/slot/wager/pending，4 天内）
     * @param string $username 会员帐号（player uuid）
     * @return array {wager_info[]}
     * @throws Exception
     */
    public function wagerPending(string $username): array
    {
        $res = $this->doCurl(self::API_WAGER_PENDING, ['username' => $username]);

        return $res['data'] ?? [];
    }

    public function getPlayer()
    {
        // AS 无远程会员查询端点
        return $this->player;
    }

    public function userLogout()
    {
        // 游戏连结 sid 由 Redis TTL 自然过期
    }

    /**
     * 获取爆机时的余额不足错误码
     * @return mixed
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return ASGameController::CODE_INSUFFICIENT_FUNDS;
    }

    /**
     * 查询余额
     * @return mixed
     * @deprecated 已迁移到 Redis Lua 原子脚本，此方法仅查询场景使用
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
     * 解析并验证入站钱包请求（JSON + md5 签名 + ts 窗口），绑定会员
     * 成功返回参数数组；失败返回错误码字符串并置 $this->error
     * @param $data 原始请求体
     * @return mixed
     */
    public function decrypt($data): mixed
    {
        $params = json_decode((string)$data, true);
        if (empty($params) || !is_array($params)) {
            $this->log->warning('AS入站请求体解析失败', ['raw' => mb_substr((string)$data, 0, 500)]);
            return $this->error = ASGameController::CODE_INVALID_TOKEN;
        }

        // 签名校验（出入站同一规则）
        if (!$this->verifySign($params)) {
            // 完整判定信息：脱敏签名原串、各布尔风格期望 hash、key 指纹、时间基准
            $styleHashes = [];
            foreach (self::BOOL_SIGN_STYLES as $s) {
                $styleHashes[$s] = md5($this->makeSignSource($params, $s));
            }
            $key = trim((string)($this->config['share_key'] ?? ''));
            $this->log->warning('AS入站签名校验失败', [
                'params' => $params,
                'sign_source' => $this->maskSignSource($this->makeSignSource($params)),
                'received_hash' => strtolower((string)($params['hash'] ?? '')),
                'style_hashes' => $styleHashes,
                'key_configured' => $key !== '',
                'key_fingerprint' => substr(md5($key), 0, 8),
                'server_time' => time(),
            ]);
            return $this->error = ASGameController::CODE_INVALID_TOKEN;
        }

        // ts 窗口校验（文档仅要求 10 位时间戳；结合「重试一律返回原始的响应」放宽到 300 秒）
        $ts = (int)($params['ts'] ?? 0);
        if ($ts <= 0 || abs(time() - $ts) > self::TS_MAX_WINDOW) {
            $this->log->warning('AS入站时间戳过期', [
                'ts' => $ts,
                'server_time' => time(),
                'drift' => time() - $ts,
                'window' => self::TS_MAX_WINDOW,
            ]);
            return $this->error = ASGameController::CODE_INVALID_TOKEN;
        }

        // 会员解析：verify-session 走 session，其余走 playerId
        if (isset($params['session']) && !isset($params['playerId'])) {
            $player = $this->getPlayerBySession((string)$params['session']);
            if (empty($player)) {
                // 文档：会话令牌缺失、无效或已过期 => INVALID_TOKEN
                $this->log->warning('AS入站会话无效或已过期', ['session' => (string)($params['session'] ?? '')]);
                return $this->error = ASGameController::CODE_INVALID_TOKEN;
            }
        } else {
            $player = Player::query()->where('uuid', (string)($params['playerId'] ?? ''))->first();
            if (empty($player)) {
                $this->log->warning('AS入站玩家不存在', ['playerId' => (string)($params['playerId'] ?? '')]);
                return $this->error = ASGameController::CODE_LOGIN_FAILED;
            }
        }

        // 帐号状态
        if ($player->trashed() || (int)$player->status !== 1) {
            $this->log->warning('AS入站帐号状态异常', [
                'playerId' => (string)$player->uuid,
                'status' => $player->status,
                'trashed' => $player->trashed(),
            ]);
            return $this->error = ASGameController::CODE_ACCOUNT_BLOCKED;
        }

        // 币种校验（ISO4217）
        if (isset($params['currency'])) {
            $this->player = $player;
            $expect = $this->walletCurrency();
            if (strtoupper((string)$params['currency']) !== $expect) {
                $this->player = null;
                $this->log->warning('AS入站币种不匹配', [
                    'playerId' => (string)$player->uuid,
                    'expect' => $expect,
                    'got' => strtoupper((string)$params['currency']),
                ]);
                return $this->error = ASGameController::CODE_REQUEST_DECLINED;
            }
        }

        $this->player = $player;

        return $params;
    }
}
