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
    public $connection = 'fast';

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

            if ($player) {
                $record->setRelation('player', $player);
            }
            if ($channel) {
                $record->setRelation('channel', $channel);
            }

            // 执行高分广播检测
            $result = HighScoreBroadcastService::checkAndBroadcast($record);

            if ($result) {
                Log::info('高分广播队列：处理成功', [
                    'record_id' => $recordId,
                    'player_id' => $playerId,
                    'win' => $win,
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('高分广播队列：处理失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // 不抛出异常，避免重试，直接记录日志
        }
    }
}
