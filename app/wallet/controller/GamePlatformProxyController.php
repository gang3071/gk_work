<?php

namespace app\wallet\controller;

use app\exception\GameException;
use app\model\Game;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerEnterGameRecord;
use app\model\PlayerGamePlatform;
use app\service\game\GameServiceFactory;
use Exception;
use Illuminate\Support\Str;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Db;
use support\Log;
use support\Request;
use support\Response;
use Tinywan\Jwt\JwtToken;

/**
 * 游戏平台代理控制器
 * 接收来自 gk_api 的游戏相关请求（通过零信任隧道）
 */
class GamePlatformProxyController
{
    /**
     * 从请求中获取玩家信息
     * @param Request $request
     * @return Player|null
     */
    private function getPlayer(Request $request): ?Player
    {
        try {
            // 从 Authorization header 获取 token
            $authorization = $request->header('Authorization', '');
            if (empty($authorization)) {
                return null;
            }

            // 提取 token
            $token = str_replace('Bearer ', '', $authorization);
            if (empty($token)) {
                return null;
            }

            // 解析 token 获取用户 ID
            $id = JwtToken::getCurrentId();
            if (empty($id)) {
                return null;
            }

            // 查询玩家
            $player = Player::query()->where('id', $id)->first();

            return $player;

        } catch (Exception $e) {
            Log::error('Get player from token failed', [
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
            // 直接使用 Log::error() 会自动触发 TelegramService（已在 config/log.php 配置）
            Log::error('游戏平台代理异常: ' . $action . ' - ' . $e->getMessage(), array_merge($context, [
                'action' => $action,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]));
        } catch (\Throwable $te) {
            // 发送 Telegram 失败不影响主流程
            Log::warning('Send telegram alert failed: ' . $te->getMessage());
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
                return $this->fail('玩家未登录或登录已过期');
            }

            $data = $request->all();

            $validator = v::key('game_id',
                v::stringType()->notEmpty()->setName('游戏ID'));

            try {
                $validator->assert($data);
            } catch (AllOfException $e) {
                return $this->fail($e->getMessage());
            }

            /** @var Game $game */
            $game = Game::query()
                ->where('id', $data['game_id'])
                ->first();

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

            // 记录进入游戏
            try {
                $playerEnterGameRecord = new PlayerEnterGameRecord();
                $playerEnterGameRecord->player_id = $player->id;
                $playerEnterGameRecord->department_id = $player->department_id;
                $playerEnterGameRecord->game_id = $game->id;
                $playerEnterGameRecord->save();
            } catch (Exception $e) {
                Log::warning('Save enter game record failed', [
                    'error' => $e->getMessage(),
                ]);
            }


            // 调用游戏服务获取游戏URL
            $gameService = GameServiceFactory::createService(strtoupper($game->gamePlatform->code), $player);
            $gameUrl = $gameService->gameLogin($game, $lang);

            Log::info('Player enter game', [
                'player_id' => $player->id,
                'game_id' => $game->id,
                'platform' => $game->gamePlatform->code,
            ]);

            return $this->success([
                'url' => $gameUrl,
                'display_mode' => $game->display_mode
            ]);

        } catch (Exception $e) {
            Log::error('Enter game failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('进入游戏', $e, [
                'game_id' => $data['game_id'] ?? null,
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? '系统错误');
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
                return $this->fail('玩家未登录或登录已过期');
            }

            $data = $request->all();

            $validator = v::key('game_platform_id',
                v::stringType()->notEmpty()->setName('游戏平台ID'));

            try {
                $validator->assert($data);
            } catch (AllOfException $e) {
                return $this->fail($e->getMessage());
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

            Log::info('Player enter lobby', [
                'player_id' => $player->id,
                'platform_id' => $gamePlatform->id,
                'platform' => $gamePlatform->code,
            ]);

            return $this->success(['lobby_url' => $lobbyUrl]);

        } catch (Exception $e) {
            Log::error('Lobby login failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('进入游戏大厅', $e, [
                'game_platform_id' => $data['game_platform_id'] ?? null,
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? '系统错误');
        }
    }

    /**
     * 平台转出到电子游戏
     * @param Request $request
     * @return Response
     */
    public function walletTransferOut(Request $request): Response
    {
        try {
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家未登录或登录已过期');
            }

            $data = $request->all();

            $validator = v::key('game_platform_id',
                v::stringType()->notEmpty()->setName('游戏平台ID'))
                ->key('amount', v::floatVal()->notEmpty()->setName('金额'));

            try {
                $validator->assert($data);
            } catch (AllOfException $e) {
                return $this->fail($e->getMessage());
            }

            /** @var GamePlatform $gamePlatform */
            $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);

            if (empty($gamePlatform)) {
                return $this->fail('游戏平台不存在');
            }

            if ($gamePlatform->status != 1) {
                return $this->fail('游戏平台未启用');
            }

            $amount = (float)$data['amount'];

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            DB::beginTransaction();
            try {
                // 调用游戏服务进行转账
                $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);
                $result = $gameService->deposit($amount, ['lang' => $lang]);

                DB::commit();

                Log::info('Wallet transfer out', [
                    'player_id' => $player->id,
                    'platform_id' => $gamePlatform->id,
                    'amount' => $amount,
                ]);

                return $this->success($result ?? []);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception|GameException $e) {
            Log::error('Wallet transfer out failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('钱包转出', $e, [
                'game_platform_id' => $data['game_platform_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? '系统错误');
        }
    }

    /**
     * 电子游戏转入到平台
     * @param Request $request
     * @return Response
     */
    public function walletTransferIn(Request $request): Response
    {
        try {
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家未登录或登录已过期');
            }

            $data = $request->all();

            $validator = v::key('game_platform_id',
                v::stringType()->notEmpty()->setName('游戏平台ID'))
                ->key('take_all', v::in(['false', 'true'])->notEmpty()->setName('是否全部转出'))
                ->key('amount', v::floatVal()->setName('金额'));

            try {
                $validator->assert($data);
            } catch (AllOfException $e) {
                return $this->fail('参数错误');
            }

            /** @var GamePlatform $gamePlatform */
            $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);

            if (empty($gamePlatform)) {
                return $this->fail('游戏平台不存在');
            }

            if ($gamePlatform->status != 1) {
                return $this->fail('游戏平台未启用');
            }

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            DB::beginTransaction();
            try {
                // 调用游戏服务进行转账
                $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);

                $takeAll = $data['take_all'] === 'true';
                $amount = $takeAll ? null : (float)$data['amount'];

                $result = $gameService->withdraw($amount, ['lang' => $lang]);

                DB::commit();

                Log::info('Wallet transfer in', [
                    'player_id' => $player->id,
                    'platform_id' => $gamePlatform->id,
                    'amount' => $amount,
                    'take_all' => $takeAll,
                ]);

                return $this->success($result ?? []);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception|GameException $e) {
            Log::error('Wallet transfer in failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('钱包转入', $e, [
                'game_platform_id' => $data['game_platform_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'take_all' => $data['take_all'] ?? null,
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? '系统错误');
        }
    }

    /**
     * 查询电子游戏平台余额
     * @param Request $request
     * @return Response
     */
    public function getBalance(Request $request): Response
    {
        try {
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家未登录或登录已过期');
            }

            $data = $request->all();

            $validator = v::key('game_platform_id',
                v::stringType()->notEmpty()->setName('游戏平台ID'));

            try {
                $validator->assert($data);
            } catch (AllOfException $e) {
                return $this->fail('参数错误');
            }

            /** @var GamePlatform $gamePlatform */
            $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);

            if (empty($gamePlatform)) {
                return $this->fail('游戏平台不存在');
            }

            if ($gamePlatform->status != 1) {
                return $this->fail('游戏平台未启用');
            }

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            // 调用游戏服务查询余额
            $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);
            $balance = $gameService->getBalance(['lang' => $lang]);

            return $this->success(['balance' => $balance]);

        } catch (Exception $e) {
            Log::error('Get balance failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('查询余额', $e, [
                'game_platform_id' => $data['game_platform_id'] ?? null,
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? '系统错误');
        }
    }

    /**
     * 查询所有电子游戏平台余额
     * @param Request $request
     * @return Response
     */
    public function getWallet(Request $request): Response
    {
        try {
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家未登录或登录已过期');
            }

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            $allBalance = 0;

            if (empty($player->channel->game_platform)) {
                return $this->success(['list' => [], 'all_balance' => $allBalance]);
            }

            $gamePlatform = json_decode($player->channel->game_platform, true);
            $data = PlayerGamePlatform::query()
                ->whereIn('platform_id', $gamePlatform)
                ->where('player_id', $player->id)
                ->where('status', 1)
                ->with('gamePlatform')
                ->get();

            $list = [];
            foreach ($data as $item) {
                try {
                    $gameService = GameServiceFactory::createService(strtoupper($item->gamePlatform->code), $player);
                    $balance = $gameService->getBalance(['lang' => $lang]);

                    $list[] = [
                        'game_platform_id' => $item->platform_id,
                        'platform_name' => $item->gamePlatform->name,
                        'balance' => $balance,
                    ];

                    $allBalance += $balance;
                } catch (Exception $e) {
                    Log::warning('Get platform balance failed', [
                        'platform_id' => $item->platform_id,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            return $this->success(['list' => $list, 'all_balance' => $allBalance]);

        } catch (Exception $e) {
            Log::error('Get wallet failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('查询所有钱包', $e, [
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? '系统错误');
        }
    }

    /**
     * 全部转出
     * @param Request $request
     * @return Response
     */
    public function withdrawAmountAll(Request $request): Response
    {
        try {
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家未登录或登录已过期');
            }

            if (empty($player->channel->game_platform)) {
                return $this->fail('游戏平台不存在');
            }

            $gamePlatform = json_decode($player->channel->game_platform, true);
            $playerGamePlatformList = PlayerGamePlatform::query()
                ->whereIn('platform_id', $gamePlatform)
                ->where('player_id', $player->id)
                ->get();

            if (empty($playerGamePlatformList)) {
                return $this->fail('游戏平台不存在');
            }

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            foreach ($playerGamePlatformList as $playerGamePlatform) {
                try {
                    $gameService = GameServiceFactory::createService(strtoupper($playerGamePlatform->gamePlatform->code), $player);
                    $gameService->withdraw(null, ['lang' => $lang]); // null 表示全部转出
                } catch (Exception $e) {
                    Log::warning('Withdraw all from platform failed', [
                        'platform_id' => $playerGamePlatform->platform_id,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            Log::info('Withdraw all completed', [
                'player_id' => $player->id,
            ]);

            return $this->success();

        } catch (Exception $e) {
            Log::error('Withdraw all failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('全部转出', $e, [
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? '系统错误');
        }
    }

    /**
     * 快速转出电子游戏钱包余额
     * @param Request $request
     * @return Response
     */
    public function fastTransfer(Request $request): Response
    {
        try {
            $player = $this->getPlayer($request);
            if (empty($player)) {
                return $this->fail('玩家未登录或登录已过期');
            }

            $playerGamePlatform = PlayerGamePlatform::query()->where('player_id', $player->id)->get();

            if (empty($playerGamePlatform)) {
                return $this->success();
            }

            $lang = $request->header('Accept-Language', 'zh-CN');
            $lang = Str::replace('_', '-', $lang);

            foreach ($playerGamePlatform as $item) {
                try {
                    $gameService = GameServiceFactory::createService(strtoupper($item->gamePlatform->code), $player);
                    $gameService->withdraw(null, ['lang' => $lang]); // null 表示全部转出
                } catch (Exception $e) {
                    Log::warning('Fast transfer from platform failed', [
                        'platform_id' => $item->platform_id,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            Log::info('Fast transfer completed', [
                'player_id' => $player->id,
            ]);

            return $this->success();

        } catch (Exception $e) {
            Log::error('Fast transfer failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendTelegramAlert('快速转出', $e, [
                'player_id' => $player->id ?? null,
            ]);
            return $this->fail($e->getMessage() ?? '系统错误');
        }
    }
}