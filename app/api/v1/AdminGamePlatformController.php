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
     * 成功响应
     */
    private function success($data = [], string $message = 'success'): Response
    {
        return json([
            'code' => 200,
            'msg' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 失败响应
     */
    private function fail(string $message, int $code = 100): Response
    {
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
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家信息获取失败，请检查 X-Player-Id header');
            }

            $data = $request->all();

            if (empty($data['game_platform_id'])) {
                return $this->fail('游戏平台ID不能为空');
            }

            /** @var GamePlatform $gamePlatform */
            $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);

            if (empty($gamePlatform)) {
                return $this->fail('游戏平台不存在');
            }

            if ($gamePlatform->status == 0) {
                return $this->fail('游戏平台已禁用');
            }

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            // 调用游戏服务获取大厅URL
            $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);
            $lobbyUrl = $gameService->lobbyLogin(['lang' => $lang]);

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
            return $this->fail($e->getMessage() ?? '系统错误');
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
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家信息获取失败，请检查 X-Player-Id header');
            }

            $data = $request->all();

            if (empty($data['game_platform_id'])) {
                return $this->fail('游戏平台ID不能为空');
            }

            /** @var GamePlatform $gamePlatform */
            $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);

            if (empty($gamePlatform)) {
                return $this->fail('游戏平台不存在');
            }

            if ($gamePlatform->status == 0) {
                return $this->fail('游戏平台已禁用');
            }

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            // 调用游戏服务获取游戏列表并保存到数据库
            $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);
            $gameService->getGameList($lang);

            Log::info('Admin get game list', [
                'player_id' => $player->id,
                'platform_id' => $gamePlatform->id,
                'platform' => $gamePlatform->code,
            ]);

            return $this->success([
                'message' => '游戏列表已更新',
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
            return $this->fail($e->getMessage() ?? '系统错误');
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
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家信息获取失败，请检查 X-Player-Id header');
            }

            $data = $request->all();

            if (empty($data['game_id'])) {
                return $this->fail('游戏ID不能为空');
            }

            /** @var Game $game */
            $game = Game::query()->where('id', $data['game_id'])->first();

            if (empty($game)) {
                return $this->fail('游戏不存在');
            }

            if ($game->status == 0) {
                return $this->fail('游戏已禁用');
            }

            if (empty($game->gamePlatform)) {
                return $this->fail('游戏平台不存在');
            }

            if ($game->gamePlatform->status == 0) {
                return $this->fail('游戏平台已禁用');
            }

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            // 调用游戏服务获取游戏URL
            $gameService = GameServiceFactory::createService(strtoupper($game->gamePlatform->code), $player);
            $gameUrl = $gameService->gameLogin($game, $lang);

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
            return $this->fail($e->getMessage() ?? '系统错误');
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
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家信息获取失败，请检查 X-Player-Id header');
            }

            $data = $request->all();

            if (empty($data['game_record_id'])) {
                return $this->fail('游戏记录ID不能为空');
            }

            // 查询游戏记录
            $gameRecord = \app\model\PlayGameRecord::query()
                ->with(['gamePlatform'])
                ->find($data['game_record_id']);

            if (empty($gameRecord)) {
                return $this->fail('游戏记录不存在');
            }

            if (empty($gameRecord->gamePlatform)) {
                return $this->fail('游戏平台不存在');
            }

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            // 调用游戏服务获取回放URL
            $gameService = GameServiceFactory::createService(strtoupper($gameRecord->gamePlatform->code), $player);
            $replayUrl = $gameService->replay($gameRecord->toArray());

            if (empty($replayUrl)) {
                return $this->fail('该游戏平台不支持回放功能');
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
            return $this->fail($e->getMessage() ?? '系统错误');
        }
    }
}