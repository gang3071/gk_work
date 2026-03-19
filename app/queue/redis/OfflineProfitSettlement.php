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
        $log = Log::channel('offline_profit_settlement');
        $log->info('开始处理离线分润结算', ['data' => $data]);

        try {
            OfflineProfitSettlementServices::doProfitSettlement($data);
            $log->info('离线分润结算处理完成', ['data' => $data]);
        } catch (Exception $e) {
            $log->error('离线分润结算处理失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}