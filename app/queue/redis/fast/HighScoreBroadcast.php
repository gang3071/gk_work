<?php

namespace app\queue\redis\fast;

use app\model\Channel;
use app\model\Player;
use app\model\PlayGameRecord;
use app\service\HighScoreBroadcastService;
use support\Log;
use Webman\RedisQueue\Consumer;

/**
 * 高分广播队列消费者
 *
 * 优化点：
 * 1. 异步处理，不阻塞游戏记录同步
 * 2. 批量预加载关联数据，减少N+1查询
 */
class HighScoreBroadcast implements Consumer
{
    /**
     * 队列名称
     */
    public $queue = 'high-score-broadcast';

    /**
     * 连接名称
     */
    public $connection = 'default';

    /**
     * 消费消息
     *
     * @param array $data 消息数据
     * @return void
     */
    public function consume($data)
    {
        try {
            $recordId = $data['record_id'] ?? 0;
            $playerId = $data['player_id'] ?? 0;
            $departmentId = $data['department_id'] ?? 0;
            $win = $data['win'] ?? 0;

            Log::info('📨 高分广播队列：收到消息', [
                'record_id' => $recordId,
                'player_id' => $playerId,
                'department_id' => $departmentId,
                'win' => $win,
            ]);

            if (!$recordId || !$playerId || !$departmentId) {
                Log::warning('高分广播队列：数据不完整', $data);
                return;
            }

            // 查询游戏记录
            $record = PlayGameRecord::find($recordId);
            if (!$record) {
                Log::warning('高分广播队列：游戏记录不存在', ['record_id' => $recordId]);
                return;
            }

            // 预加载关联数据（避免N+1查询）
            $player = Player::find($playerId);
            $channel = Channel::where('department_id', $departmentId)->first();

            Log::info('📨 高分广播队列：关联数据查询', [
                'record_id' => $recordId,
                'has_player' => !is_null($player),
                'has_channel' => !is_null($channel),
                'record_settlement_status' => $record->settlement_status,
                'record_win' => $record->win,
            ]);

            if ($player) {
                $record->setRelation('player', $player);
            }
            if ($channel) {
                $record->setRelation('channel', $channel);
            }

            // 执行高分广播检测
            $result = HighScoreBroadcastService::checkAndBroadcast($record);

            if ($result) {
                Log::info('✅ 高分广播队列：广播成功', [
                    'record_id' => $recordId,
                    'player_id' => $playerId,
                    'win' => $win,
                ]);
            } else {
                Log::info('⏭️ 高分广播队列：未触发广播（未达阈值/防抖/数据不完整）', [
                    'record_id' => $recordId,
                    'player_id' => $playerId,
                    'department_id' => $departmentId,
                    'win' => $win,
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('❌ 高分广播队列：处理异常', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // 不抛出异常，避免重试，直接记录日志
        }
    }
}
