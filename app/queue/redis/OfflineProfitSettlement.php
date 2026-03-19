<?php

namespace app\queue\redis;

use app\service\OfflineProfitSettlementServices;
use ExAdmin\ui\traits\queueProgress;
use support\Log;
use think\Exception;
use Webman\RedisQueue\Consumer;

class OfflineProfitSettlement implements Consumer
{
    use queueProgress;

    public $queue = 'offline_profit_settlement';

    public $connection = 'default';

    /**
     * @param $data
     * @return void|null
     * @throws \Exception
     */
    public function consume($data)
    {
        try {
            OfflineProfitSettlementServices::doProfitSettlement($data);
        } catch (Exception $e) {
            Log::error('offline_profit_settlement : ' . $e->getMessage());
        }
    }
}