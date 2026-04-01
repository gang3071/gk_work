<?php

namespace app\queue\redis\slow;

use app\model\Channel;
use app\model\PlayerPromoter;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

class ProfitSettlement implements Consumer
{
    public $queue = 'profit_settlement';

    public $connection = 'default';

    /**
     * @param $data
     * @return void|null
     * @throws \Exception
     */
    public function consume($data)
    {
        $log = Log::channel('profit_settlement');

        try {
            /** @var Channel $channel */
            $channel = Channel::where('department_id', $data['department_id'])
                ->whereNull('deleted_at')
                ->first();
            if ($channel->status == 0) {
                throw new Exception(trans('channel_closed', [], 'message'));
            }
            if ($channel->promotion_status == 0) {
                throw new Exception(trans('channel_promotion_closed', [], 'message'));
            }

            $list = PlayerPromoter::whereHas('player', function ($query) {
                $query->where('status', 1)->whereNull('deleted_at');
            })
                ->where('department_id', $data['department_id'])
                ->where('status', 1)
                ->get()
                ->toArray();
            $total = count($list);
            if ($total <= 0) {
                throw new Exception(trans('channel_settlement_promoter_null', [], 'message'));
            }

            $log->info('开始分润结算', ['department_id' => $data['department_id'], 'total' => $total]);
        } catch (Exception $e) {
            // 执行失败
            $log->error('分润结算初始化失败: ' . $e->getMessage(), ['department_id' => $data['department_id']]);
            return;
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($list as $key => $value) {
            try {
                doSettlement($value['player_id'], $data['user_id'], $data['user_name']);
                $successCount++;
                $log->info('推广员分润结算成功', [
                    'player_id' => $value['player_id'],
                    'progress' => ($key + 1) . '/' . $total
                ]);
            } catch (Exception $e) {
                $failCount++;
                $log->error('推广员分润结算错误: ' . $e->getMessage(), [
                    'player_id' => $value['player_id'],
                    'progress' => ($key + 1) . '/' . $total,
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $log->info('分润结算完成', [
            'department_id' => $data['department_id'],
            'total' => $total,
            'success' => $successCount,
            'fail' => $failCount
        ]);
    }
}