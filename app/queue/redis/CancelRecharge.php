<?php

namespace app\queue\redis;

use addons\webman\model\PlayerRechargeRecord;
use ExAdmin\ui\traits\queueProgress;
use think\Exception;
use Webman\RedisQueue\Consumer;

class CancelRecharge implements Consumer
{
    use queueProgress;

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