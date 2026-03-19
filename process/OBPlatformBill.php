<?php

namespace process;

use app\model\PlayGameRecord;
use app\service\game\GameServiceFactory;
use support\Log;
use Workerman\Crontab\Crontab;

class OBPlatformBill
{
    public function onWorkerStart()
    {
        // 每分钟跑一次
        new Crontab('0 */1 * * * *', function () {
            ini_set('memory_limit', '512M');
            try {
                $data = GameServiceFactory::createService('OB')->handleGameHistories();
                if (!empty($data)) {
                    PlayGameRecord::query()->upsert($data, ['platform_id', 'order_no']);
                }
            } catch (\Exception $e) {
                Log::error('KYGamePlatformBill: ' . $e->getMessage(), ['line' => $e->getLine(), 'file' => $e->getFile(), 'trace' => $e->getTraceAsString()]);
            }
        });
    }
}
