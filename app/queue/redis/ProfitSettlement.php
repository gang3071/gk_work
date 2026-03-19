<?php

namespace app\queue\redis;

use addons\webman\model\Channel;
use addons\webman\model\PlayerPromoter;
use ExAdmin\ui\traits\queueProgress;
use support\Log;
use think\Exception;
use Webman\RedisQueue\Consumer;

class ProfitSettlement implements Consumer
{
    use queueProgress;

    public $queue = 'profit_settlement';

    public $connection = 'default';

    /**
     * @param $data
     * @return void|null
     * @throws \Exception
     */
    public function consume($data)
    {
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
            // 直接设置进度
            $this->progress(1);
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
        } catch (Exception $e) {
            // 执行失败
            return $this->error($e->getMessage());
        }
        foreach ($list as $key => $value) {
            try {
                doSettlement($value['player_id'], $data['user_id'], $data['user_name']);
            } catch (Exception $e) {
                Log::error('推广员分润结算错误: ' . $e->getMessage());
                return $this->error($e->getMessage());
            }
            $this->percentage($total, $key + 1);
        }

        return $this->success();
    }
}