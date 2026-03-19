<?php

namespace app\queue\redis;

use app\model\PlayerRechargeRecord;
use Exception;
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
        /** @var PlayerRechargeRecord $playerRechargeRecord */
        $playerRechargeRecord = PlayerRechargeRecord::query()
            ->where('type', PlayerRechargeRecord::TYPE_SELF)
            ->where('status', PlayerRechargeRecord::STATUS_WAIT)
            ->where('id', $data['id'])
            ->first();
        if (!empty($playerRechargeRecord)) {
            $playerRechargeRecord->status = PlayerRechargeRecord::STATUS_RECHARGED_SYSTEM_CANCEL;
            $playerRechargeRecord->cancel_time = date('Y-m-d H:i:s');
            $playerRechargeRecord->save();
        }
    }
}