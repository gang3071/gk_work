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
 * T9电子平台 V2 服务接口
 *
 * ============================================================
 * V2 升级变更说明（2026-09-23）
 * ============================================================
 * 与 V1（TNineSlotServiceInterface）并列，同样直接继承 GameServiceFactory
 *
 * 变更项：
 *   doCurl()       : 响应解析 resultCode→statusCode
 *   createPlayer() : 路径 /CreatePlayer→/create-player，重复码 'GameAccount_EXIST'→103
 *   gameLogin()    : 路径 /Login→/t9-slot/login，gameCategory 整数→字符串
 *
 * 未变更（与 V1 逻辑相同）：
 *   balance()、getGameList()、verifySign()、checkAndHandleMachineCrash() 等
 *
 * V1 保留：TNineSlotServiceInterface（路由 /tnine-solt-channel 继续有效）
 * ============================================================
 */
class TNineSlotV2ServiceInterface extends GameServiceFactory implements GameServiceInterface, SingleWalletServiceInterface
{
    public string $method = 'POST';
    public string $successCode = '0';

    // V2 gameCategory 字符串常量（V1 用整数 1）
    private const GAME_CATEGORY_SLOTS = 'Slots';
    private const GAME_CATEGORY_FISH = 'Fish';

    private array $config;

    public $log;

    public function __construct(Player $player = null)
    {
        $this->config = config('game_platform.TNINE_SLOT');
        $this->platform = GamePlatform::query()->where('code', 'TNINE_SLOT')->first();
        $this->player = $player;
        $this->log = Log::channel('tnine_slot_server');
    }

    // ================================================================
    // doCurl — V2 响应格式
    // V1: 检查 $res['resultCode'] != 'OK'
    // V2: 检查 $res['statusCode'] != 0
    // ================================================================

    public function doCurl(string $url, array $params = [], string $method = 'post', array $acceptCodes = []): mixed
    {
        $agentId = $this->config['agent_id'];
        $key = $this->config['api_key'];

        $params['gameAccount'] = ($params['gameAccount'] ?? '') . '_' . $agentId;
        $params['agentId'] = $agentId;
        $params['apiKey'] = $key;
        $params['platform'] = 'T9SlotSeamless';

        $fullUrl = $this->config['api_domain'] . $url;

        $this->log->info('[V2] T9 API请求', [
            'url' => $fullUrl,
            'params' => array_merge($params, ['apiKey' => substr($key, 0, 10) . '...']),
        ]);

        $response = Http::timeout(10)->asJson()->post($fullUrl, $params);

        $this->log->info('[V2] T9 API响应', [
            'url' => $fullUrl,
            'status_code' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->ok()) {
            $msg = '[V2] T9 API HTTP错误 ' . $response->status() . ': ' . $response->body();
            $this->log->error($msg, ['url' => $fullUrl, 'params' => $params]);
            throw new GameException($msg);
        }

        $res = json_decode($response->body(), true);

        if (empty($res)) {
            $msg = '[V2] T9 API响应为空: ' . $response->body();
            $this->log->error($msg, ['url' => $fullUrl]);
            throw new Exception($msg);
        }

        // V2 响应格式：{"statusCode": 0, "data": {...}}
        $statusCode = $res['statusCode'] ?? -1;

        if ($statusCode !== 0 && !in_array($statusCode, $acceptCodes, true)) {
            $msg = '[V2] T9 API错误: statusCode=' . $statusCode . ' message=' . ($res['message'] ?? $response->body());
            $this->log->error('[V2] T9 API返回错误', [
                'url' => $fullUrl,
                'params' => $params,
                'status_code' => $statusCode,
                'message' => $res['message'] ?? '',
                'data' => $res['data'] ?? null,
                'full_body' => $response->body(),
            ]);
            throw new Exception($msg);
        }

        return $res;
    }

    // ================================================================
    // createPlayer — V2 路径 + 错误码
    // V1: /CreatePlayer，重复用户 acceptCode = 'GameAccount_EXIST'
    // V2: /create-player，重复用户 acceptCode = 103
    // ================================================================

    public function createPlayer(): array
    {
        $params = [
            'gameAccount' => $this->player->uuid,
            'currency' => 'TWD',
        ];
        $response = $this->doCurl('/create-player', $params, 'post', [103]);
        $this->log->info('[V2] createPlayer', ['response' => $response]);
        return $response['data'] ?? [];
    }

    // ================================================================
    // gameLogin — V2 路径 + gameCategory 字符串
    // V1: /Login，gameCategory = 1（整数）
    // V2: /t9-slot/login，gameCategory = 'Slots'（字符串）
    //
    // ⚠️ 登入路径依游戏品牌：T9电子统一用 /t9-slot/login
    //    如未来接入其他 T9 品牌需扩展 resolveGameCategory()
    // ================================================================

    public function gameLogin(Game $game, string $lang = 'zh-CN'): mixed
    {
        $this->checkPlayer();

        $params = [
            'gameCode' => $game->game_extend->code ?? '',
            'gameAccount' => $this->player->uuid,
            'gameCategory' => $this->resolveGameCategory($game), // V2: 字符串
            'language' => $this->resolveLang($lang),
            'isMobileLogin' => true,  // TODO: 由调用方传入设备类型
        ];

        $res = $this->doCurl('/t9-slot/login', $params); // V2 路径
        $this->log->info('[V2] gameLogin', ['response' => $res]);

        return $res['data']['gameUrl'] ?? '';
    }

    // ================================================================
    // 以下方法与 V1 逻辑相同
    // ================================================================

    public function balance(): mixed
    {
        return \app\service\WalletService::getBalance($this->player->id);
    }

    public function getGameList(string $lang = 'zh-CN'): bool
    {
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

    public function lobbyLogin(array $data = []): string
    {
        return '';
    }

    public function getPlayer()
    {
    }

    public function userLogout()
    {
    }

    public function replay(array $data = [])
    {
        return '';
    }

    /** @deprecated 已迁移到 RedisLuaScripts::atomicBet */
    public function bet($data): mixed
    {
        throw new \RuntimeException('bet() 已废弃，使用 RedisLuaScripts::atomicBet');
    }

    /** @deprecated 已迁移到 RedisLuaScripts::atomicCancel */
    public function cancelBet($data): mixed
    {
        throw new \RuntimeException('cancelBet() 已废弃，使用 RedisLuaScripts::atomicCancel');
    }

    /** @deprecated 已迁移到 RedisLuaScripts::atomicSettle */
    public function betResulet($data): mixed
    {
        throw new \RuntimeException('betResulet() 已废弃，使用 RedisLuaScripts::atomicSettle');
    }

    /** @deprecated 已迁移到 RedisLuaScripts::atomicSettle */
    public function reBetResulet($data): mixed
    {
        throw new \RuntimeException('reBetResulet() 已废弃，使用 RedisLuaScripts::atomicSettle');
    }

    /** @deprecated 平台不支持 */
    public function gift($data): mixed
    {
        throw new \RuntimeException('平台不支持 gift() 功能');
    }

    public function decrypt($data): mixed
    {
        return [];
    }

    /**
     * 爆机余额不足错误码（V2 状态码 101）
     * V1 使用 108
     */
    protected function getInsufficientBalanceError(): mixed
    {
        return 101;
    }

    // ================================================================
    // 私有辅助方法
    // ================================================================

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

    /**
     * 从游戏记录解析 V2 gameCategory 字符串
     * 优先读 org_data 中 T9 返回的原始值，降级按 cate_id 映射
     */
    private function resolveGameCategory(Game $game): string
    {
        $orgData = json_decode($game->game_extend->org_data ?? '{}', true);
        if (!empty($orgData['gameCategory']) && is_string($orgData['gameCategory'])) {
            return $orgData['gameCategory'];
        }

        $cateId = $game->game_extend->cate_id ?? 0;
        $map = [
            GameType::CATE_SLO => self::GAME_CATEGORY_SLOTS,
            // 如有 CATE_FISH 常量则在此补充
        ];

        return $map[$cateId] ?? self::GAME_CATEGORY_SLOTS;
    }

    private function resolveLang(string $lang): string
    {
        $langMap = [
            'zh-CN' => 'zh_CN',
            'zh-TW' => 'zh_TW',
            'en' => 'en',
            'th' => 'th',
            'vi' => 'vi-VN',
            'jp' => 'ja',
            'kr_ko' => 'ko',
            'km_KH' => 'km_KH',
        ];
        return $langMap[$lang] ?? 'zh_TW';
    }
}
