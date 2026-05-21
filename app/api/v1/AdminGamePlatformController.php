<?php

namespace app\api\v1;

use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\service\game\GameServiceFactory;
use Exception;
use Illuminate\Support\Str;
use support\Log;
use support\Request;
use support\Response;

/**
 * 管理后台游戏平台控制器
 * 专门处理来自管理后台（gk_admin）的请求
 * 使用 X-Player-Id header 认证，不需要 JWT Token
 */
class AdminGamePlatformController
{
    /**
     * 从请求中获取玩家信息
     * @param Request $request
     * @return Player|null
     */
    private function getPlayer(Request $request): ?Player
    {
        try {
            // 从 X-Player-Id header 获取玩家ID
            $playerId = $request->header('X-Player-Id', '');
            if (empty($playerId)) {
                return null;
            }

            // 查询玩家
            $player = Player::query()->where('id', $playerId)->first();

            return $player;

        } catch (Exception $e) {
            Log::error('Get player from header failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 设置语言环境
     * @param Request $request
     * @return string
     */
    private function setLanguage(Request $request): string
    {
        $lang = $request->post('lang', $request->header('Accept-Language', 'zh_TW'));
        // 统一转换为下划线格式 (zh-CN -> zh_CN)
        $lang = Str::replace('-', '_', $lang);
        locale($lang);
        return $lang;
    }

    /**
     * 获取用于游戏服务的语言格式 (zh_TW -> zh-TW)
     * @param string $lang
     * @return string
     */
    private function getGameLang(string $lang): string
    {
        return Str::replace('_', '-', $lang);
    }

    /**
     * 成功响应
     */
    private function success($data = [], string $message = null): Response
    {
        $message = $message ?? trans('success', [], 'admin_game_platform');
        return json([
            'code' => 200,
            'msg' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 失败响应
     */
    private function fail(string $message = null, int $code = 100): Response
    {
        $message = $message ?? trans('system_error', [], 'admin_game_platform');
        return json([
            'code' => $code,
            'msg' => $message,
            'data' => [],
        ]);
    }

    /**
     * 发送 Telegram 告警通知
     */
    private function sendTelegramAlert(string $action, Exception $e, array $context = []): void
    {
        try {
            Log::error('管理后台游戏操作异常: ' . $action . ' - ' . $e->getMessage(), array_merge($context, [
                'action' => $action,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]));
        } catch (\Throwable $te) {
            Log::warning('Send telegram alert failed: ' . $te->getMessage());
        }
    }

    /**
     * 进入游戏大厅
     * @param Request $request
     * @return Response
     */
    public function lobbyLogin(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail(trans('player_info_failed', [], 'admin_game_platform'));
            }

            $data = $request->all();

            if (empty($data['game_platform_id'])) {
                return $this->fail(trans('game_platform_id_required', [], 'admin_game_platform'));
            }

            /** @var GamePlatform $gamePlatform */
            $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);

            if (empty($gamePlatform)) {
                return $this->fail(trans('game_platform_not_found', [], 'admin_game_platform'));
            }

            if ($gamePlatform->status == 0) {
                return $this->fail(trans('game_platform_disabled', [], 'admin_game_platform'));
            }

            // 调用游戏服务获取大厅URL
            $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);
            $lobbyUrl = $gameService->lobbyLogin(['lang' => $this->getGameLang($lang)]);

            Log::info('Admin enter lobby', [
                'player_id' => $player->id,
                'platform_id' => $gamePlatform->id,
                'platform' => $gamePlatform->code,
            ]);

            return $this->success([
                'url' => $lobbyUrl,
                'lobby_url' => $lobbyUrl,  // 兼容两种字段名
            ]);

        } catch (Exception $e) {
            Log::error('Admin lobby login failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('管理后台进入游戏大厅', $e, [
                'game_platform_id' => $data['game_platform_id'] ?? null,
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? trans('system_error', [], 'admin_game_platform'));
        }
    }

    /**
     * 获取游戏列表
     * @param Request $request
     * @return Response
     */
    public function getGameList(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail(trans('player_info_failed', [], 'admin_game_platform'));
            }

            $data = $request->all();

            if (empty($data['game_platform_id'])) {
                return $this->fail(trans('game_platform_id_required', [], 'admin_game_platform'));
            }

            /** @var GamePlatform $gamePlatform */
            $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);

            if (empty($gamePlatform)) {
                return $this->fail(trans('game_platform_not_found', [], 'admin_game_platform'));
            }

            if ($gamePlatform->status == 0) {
                return $this->fail(trans('game_platform_disabled', [], 'admin_game_platform'));
            }

            // 调用游戏服务获取游戏列表并保存到数据库
            $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);
            $gameService->getGameList($this->getGameLang($lang));

            Log::info('Admin get game list', [
                'player_id' => $player->id,
                'platform_id' => $gamePlatform->id,
                'platform' => $gamePlatform->code,
            ]);

            return $this->success([
                'message' => trans('game_list_updated', [], 'admin_game_platform'),
                'platform_id' => $gamePlatform->id,
                'platform_name' => $gamePlatform->name,
            ]);

        } catch (Exception $e) {
            Log::error('Admin get game list failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('管理后台获取游戏列表', $e, [
                'game_platform_id' => $data['game_platform_id'] ?? null,
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? trans('system_error', [], 'admin_game_platform'));
        }
    }

    /**
     * 进入游戏
     * @param Request $request
     * @return Response
     */
    public function enterGame(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail(trans('player_info_failed', [], 'admin_game_platform'));
            }

            $data = $request->all();

            if (empty($data['game_id'])) {
                return $this->fail(trans('game_id_required', [], 'admin_game_platform'));
            }

            /** @var Game $game */
            $game = Game::query()->where('id', $data['game_id'])->first();

            if (empty($game)) {
                return $this->fail(trans('game_not_found', [], 'admin_game_platform'));
            }

            if ($game->status == 0) {
                return $this->fail(trans('game_disabled', [], 'admin_game_platform'));
            }

            if (empty($game->gamePlatform)) {
                return $this->fail(trans('game_platform_not_found', [], 'admin_game_platform'));
            }

            if ($game->gamePlatform->status == 0) {
                return $this->fail(trans('game_platform_disabled', [], 'admin_game_platform'));
            }

            // 调用游戏服务获取游戏URL
            $gameService = GameServiceFactory::createService(strtoupper($game->gamePlatform->code), $player);
            $gameUrl = $gameService->gameLogin($game, $this->getGameLang($lang));

            Log::info('Admin enter game', [
                'player_id' => $player->id,
                'game_id' => $game->id,
                'platform' => $game->gamePlatform->code,
            ]);

            return $this->success([
                'url' => $gameUrl,
                'display_mode' => $game->display_mode,
            ]);

        } catch (Exception $e) {
            Log::error('Admin enter game failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('管理后台进入游戏', $e, [
                'game_id' => $data['game_id'] ?? null,
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? trans('system_error', [], 'admin_game_platform'));
        }
    }

    /**
     * 游戏回放
     * @param Request $request
     * @return Response
     */
    public function replay(Request $request): Response
    {
        try {
            $lang = $this->setLanguage($request);
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail(trans('player_info_failed', [], 'admin_game_platform'));
            }

            $data = $request->all();

            if (empty($data['game_record_id'])) {
                return $this->fail(trans('game_record_id_required', [], 'admin_game_platform'));
            }

            // 查询游戏记录
            $gameRecord = \app\model\PlayGameRecord::query()
                ->with(['gamePlatform'])
                ->find($data['game_record_id']);

            if (empty($gameRecord)) {
                return $this->fail(trans('game_record_not_found', [], 'admin_game_platform'));
            }

            if (empty($gameRecord->gamePlatform)) {
                return $this->fail(trans('game_platform_not_found', [], 'admin_game_platform'));
            }

            // 调用游戏服务获取回放URL
            $gameService = GameServiceFactory::createService(strtoupper($gameRecord->gamePlatform->code), $player);
            $replayUrl = $gameService->replay($gameRecord->toArray());

            if (empty($replayUrl)) {
                return $this->fail(trans('replay_not_supported', [], 'admin_game_platform'));
            }

            Log::info('Admin replay game', [
                'player_id' => $player->id,
                'game_record_id' => $gameRecord->id,
                'platform' => $gameRecord->gamePlatform->code,
            ]);

            return $this->success([
                'url' => $replayUrl,
            ]);

        } catch (Exception $e) {
            Log::error('Admin replay game failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('管理后台游戏回放', $e, [
                'game_record_id' => $data['game_record_id'] ?? null,
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? trans('system_error', [], 'admin_game_platform'));
        }
    }
}