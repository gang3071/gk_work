<?php

namespace app\queue\redis;

use app\service\OfflineProfitSettlementServices;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

class OfflineProfitSettlement implements Consumer
{
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
            Log::channel('offline_profit_settlement')->error('offline_profit_settlement : ' . $e->getMessage());
        }
    }
}