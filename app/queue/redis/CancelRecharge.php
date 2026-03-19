<?php

namespace app\queue\redis;

use app\model\PlayerRechargeRecord;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

class CancelRecharge implements Consumer
{

    public $queue = 'cancel_recharge';

    public $connection = 'default';

    /**
     * @param $data
     * @throws Exception
     */
    public function consume($data)
    {
        $log = Log::channel('cancel_recharge');
        $log->info('开始处理取消充值', ['data' => $data]);

        /** @var PlayerRechargeRecord $playerRechargeRecord */
        $playerRechargeRecord = PlayerRechargeRecord::query()
            ->where('type', PlayerRechargeRecord::TYPE_SELF)
            ->where('status', PlayerRechargeRecord::STATUS_WAIT)
            ->where('id', $data['id'])
            ->first();

        if (empty($playerRechargeRecord)) {
            $log->warning('充值记录不存在或状态不正确', ['recharge_id' => $data['id']]);
            return;
        }

        try {
            $playerRechargeRecord->status = PlayerRechargeRecord::STATUS_RECHARGED_SYSTEM_CANCEL;
            $playerRechargeRecord->cancel_time = date('Y-m-d H:i:s');
            $playerRechargeRecord->save();

            $log->info('取消充值处理完成', [
                'recharge_id' => $data['id'],
                'player_id' => $playerRechargeRecord->player_id,
                'cancel_time' => $playerRechargeRecord->cancel_time
            ]);
        } catch (Exception $e) {
            $log->error('取消充值处理失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}