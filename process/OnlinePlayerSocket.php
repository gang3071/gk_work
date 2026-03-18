<?php

namespace process;

use app\model\Player;
use app\model\PlayerGameLog;
use app\model\PlayerGameRecord;
use app\model\PlayGameRecord;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 在线玩家Socket推送进程
 * 定期推送正在游戏的玩家列表到后台管理页面
 */
class OnlinePlayerSocket
{
    /**
     * 上一次推送的数据哈希值（实体机台）
     * @var string|null
     */
    private static $lastMachineDataHash = null;

    /**
     * 上一次推送的数据哈希值（电子游戏）
     * @var string|null
     */
    private static $lastGameDataHash = null;

    /**
     * 上一次在线的玩家ID列表（实体机台）
     * @var array
     */
    private static $lastMachinePlayerIds = [];

    /**
     * 上一次在线的玩家ID列表（电子游戏）
     * @var array
     */
    private static $lastGamePlayerIds = [];

    public function onWorkerStart()
    {
        Log::debug('推送电子游戏在线玩家数据');

        // 每2秒推送一次在线玩家数据
        new Crontab('*/2 * * * * *', function () {
            try {
                $this->pushOnlinePlayersData();
            } catch (\Throwable $e) {
                Log::error('OnlinePlayerSocket推送失败: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        });
    }

    /**
     * 推送在线玩家数据
     * @return void
     */
    private function pushOnlinePlayersData()
    {
        // 获取实体机台在线玩家
        $machinePlayers = $this->getMachineOnlinePlayers();
        $machineHash = md5(json_encode($machinePlayers));
        $currentMachinePlayerIds = array_column($machinePlayers, 'id');

        // 检测离线玩家
        $offlineMachinePlayerIds = array_diff(self::$lastMachinePlayerIds, $currentMachinePlayerIds);
        if (!empty($offlineMachinePlayerIds)) {
            sendSocketMessage('group-online-players-machine', [
                'msg_type' => 'players_offline',
                'type' => 'machine',
                'player_ids' => array_values($offlineMachinePlayerIds),
                'timestamp' => time(),
            ]);
            Log::debug('推送实体机台离线玩家', ['player_ids' => $offlineMachinePlayerIds]);
        }

        // 只在数据变化时推送 - 或者强制推送用于调试
        if ($machineHash !== self::$lastMachineDataHash || true) {
//            sendSocketMessage('group-online-players-machine', [
//                'msg_type' => 'online_players_update',
//                'type' => 'machine',
//                'players' => $machinePlayers,
//                'count' => count($machinePlayers),
//                'timestamp' => time(),
//            ]);
            self::$lastMachineDataHash = $machineHash;
            self::$lastMachinePlayerIds = $currentMachinePlayerIds;

            Log::debug('推送实体机台在线玩家数据', ['count' => count($machinePlayers)]);
        }

        // 获取电子游戏在线玩家
        $gamePlayers = $this->getGameOnlinePlayers();
        $gameHash = md5(json_encode($gamePlayers));
        $currentGamePlayerIds = array_column($gamePlayers, 'id');

        // 检测离线玩家
        $offlineGamePlayerIds = array_diff(self::$lastGamePlayerIds, $currentGamePlayerIds);
        if (!empty($offlineGamePlayerIds)) {
            sendSocketMessage('group-online-players-game', [
                'msg_type' => 'players_offline',
                'type' => 'game',
                'player_ids' => array_values($offlineGamePlayerIds),
                'timestamp' => time(),
            ]);
            Log::debug('推送电子游戏离线玩家', ['player_ids' => $offlineGamePlayerIds]);
        }

        // 只在数据变化时推送 - 或者强制推送用于调试
        if ($gameHash !== self::$lastGameDataHash || true) {
//            sendSocketMessage('group-online-players-game', [
//                'msg_type' => 'online_players_update',
//                'type' => 'game',
//                'players' => $gamePlayers,
//                'count' => count($gamePlayers),
//                'timestamp' => time(),
//            ]);
            self::$lastGameDataHash = $gameHash;
            self::$lastGamePlayerIds = $currentGamePlayerIds;

            Log::debug('推送电子游戏在线玩家数据', ['count' => count($gamePlayers)]);
        }
    }

    /**
     * 获取实体机台在线玩家
     * @return array
     */
    private function getMachineOnlinePlayers(): array
    {
        $tenSecondsAgo = date('Y-m-d H:i:s', time() - 10);
        $fiveMinutesAgo = date('Y-m-d H:i:s', time() - 300);

        $players = Player::query()
            ->select([
                'player.id',
                'player.uuid',
                'player.name',
                'player.phone',
                'player.avatar',
                'player.department_id',
                'player.is_test',
                'player.is_coin',
                'player.is_promoter',
            ])
            ->selectRaw('MAX(player_game_log.created_at) as last_bet_time')
            ->selectRaw('MAX(player_game_log.pressure) as last_pressure')
            ->join('player_game_record', 'player.id', '=', 'player_game_record.player_id')
            ->join('player_game_log', 'player_game_record.id', '=', 'player_game_log.game_record_id')
            ->where('player_game_record.status', PlayerGameRecord::STATUS_START)
            ->where('player_game_log.created_at', '>=', $tenSecondsAgo)
            ->where('player_game_log.pressure', '>', 0)
            ->groupBy([
                'player.id',
                'player.uuid',
                'player.name',
                'player.phone',
                'player.avatar',
                'player.department_id',
                'player.is_test',
                'player.is_coin',
                'player.is_promoter'
            ])
            ->orderBy('last_bet_time', 'desc')
            ->limit(100) // 限制返回数量
            ->get();

        $result = [];
        foreach ($players as $player) {
            // 获取当前机台信息
            $record = PlayerGameRecord::query()
                ->with('machine')
                ->where('player_id', $player->id)
                ->where('status', PlayerGameRecord::STATUS_START)
                ->orderBy('id', 'desc')
                ->first();

            // 获取累计押注
            $totalPressure = PlayerGameLog::query()
                ->where('player_id', $player->id)
                ->where('created_at', '>=', $fiveMinutesAgo)
                ->sum('pressure');

            $betSecondsAgo = time() - strtotime($player->last_bet_time);

            // 只返回10秒内有押注的玩家
            if ($betSecondsAgo <= 10) {
                $result[] = [
                    'id' => $player->id,
                    'uuid' => $player->uuid,
                    'name' => $player->name ?: $player->uuid,
                    'phone' => $player->phone,
                    'avatar' => $player->avatar,
                    'is_test' => $player->is_test,
                    'is_coin' => $player->is_coin,
                    'is_promoter' => $player->is_promoter,
                    'machine_id' => $record?->machine_id,
                    'machine_name' => $record?->machine?->name,
                    'machine_code' => $record?->machine?->code,
                    'last_bet_time' => $player->last_bet_time,
                    'bet_seconds_ago' => $betSecondsAgo,
                    'total_pressure' => number_format($totalPressure, 2),
                    'last_pressure' => number_format($player->last_pressure, 2),
                ];
            }
        }

        return $result;
    }

    /**
     * 获取电子游戏在线玩家
     * @return array
     */
    private function getGameOnlinePlayers(): array
    {
        $tenSecondsAgo = date('Y-m-d H:i:s', time() - 10);
        $fiveMinutesAgo = date('Y-m-d H:i:s', time() - 300);

        $players = Player::query()
            ->select([
                'player.id',
                'player.uuid',
                'player.name',
                'player.phone',
                'player.avatar',
                'player.department_id',
                'player.is_test',
                'player.is_coin',
                'player.is_promoter',
            ])
            ->selectRaw('MAX(play_game_record.created_at) as last_bet_time')
            ->selectRaw('MAX(play_game_record.bet) as last_bet')
            ->join('play_game_record', 'player.id', '=', 'play_game_record.player_id')
            ->where('play_game_record.created_at', '>=', $tenSecondsAgo)
            ->where('play_game_record.bet', '>', 0)
            ->groupBy([
                'player.id',
                'player.uuid',
                'player.name',
                'player.phone',
                'player.avatar',
                'player.department_id',
                'player.is_test',
                'player.is_coin',
                'player.is_promoter'
            ])
            ->orderBy('last_bet_time', 'desc')
            ->limit(100) // 限制返回数量
            ->get();

        $result = [];
        foreach ($players as $player) {
            // 获取当前平台信息
            $record = PlayGameRecord::query()
                ->with('gamePlatform')
                ->where('player_id', $player->id)
                ->orderBy('id', 'desc')
                ->first();

            // 获取累计押注
            $totalBet = PlayGameRecord::query()
                ->where('player_id', $player->id)
                ->where('created_at', '>=', $fiveMinutesAgo)
                ->sum('bet');

            $betSecondsAgo = time() - strtotime($player->last_bet_time);

            // 只返回10秒内有押注的玩家
            if ($betSecondsAgo <= 10) {
                $result[] = [
                    'id' => $player->id,
                    'uuid' => $player->uuid,
                    'name' => $player->name ?: $player->uuid,
                    'phone' => $player->phone,
                    'avatar' => $player->avatar,
                    'is_test' => $player->is_test,
                    'is_coin' => $player->is_coin,
                    'is_promoter' => $player->is_promoter,
                    'platform_id' => $record?->platform_id,
                    'platform_name' => $record?->gamePlatform?->name,
                    'game_code' => $record?->game_code,
                    'last_bet_time' => $player->last_bet_time,
                    'bet_seconds_ago' => $betSecondsAgo,
                    'total_bet' => number_format($totalBet, 2),
                    'last_bet' => number_format($player->last_bet, 2),
                ];
            }
        }

        return $result;
    }
}