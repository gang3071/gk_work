<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
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

    public function gameLogin(Game $game, string $lang = 'zh-CN'): mixed
    {
        $this->checkPlayer();
        $symbol = (string)($game->game_extend->code ?? '');
        $token = $this->createToken($this->player, $symbol);
        $params = [
            'secureLogin' => (string)($this->config['secure_login'] ?? ''),
            'symbol' => $symbol,
            'language' => $this->mapLanguage($lang),
            'token' => $token,
            'externalPlayerId' => (string)$this->player->uuid,
            'country' => (string)($this->config['country'] ?? 'CN'),
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

    /** PP language 为 String(2)：zh-CN → zh */
    private function mapLanguage(string $lang): string
    {
        return substr(strtolower(str_replace('_', '-', $lang)), 0, 2);
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

    public function lobbyLogin(array $data = []): string
    {
        return '';
    }

    public function userLogout()
    {
        return true;
    }

    public function getGameList(string $lang = 'zh-CN'): bool
    {
        return false; // PP 游戏列表由后台 game/game_extend 配置维护，无需从平台同步
    }

    public function replay(array $data = [])
    {
        return '';
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
