<?php

namespace process;

use app\model\PlayGameRecord;
use app\service\game\GameServiceFactory;
use support\Log;
use Workerman\Crontab\Crontab;

class KYSPlatformBill
{
    public function onWorkerStart()
    {
        // 每5分钟15s跑一次
        new Crontab('15 */5 * * * *', function () {
            ini_set('memory_limit', '512M');
            try {
                $data = GameServiceFactory::createService('KYS')->handleGameHistories();
                if (!empty($data)) {
                    PlayGameRecord::query()->upsert($data, ['platform_id', 'order_no']);
                }
            } catch (\Exception $e) {
                Log::error('KYSGamePlatformBill: ' . $e->getMessage(), ['line' => $e->getLine(), 'file' => $e->getFile(), 'trace' => $e->getTraceAsString()]);
            }
        });
    }
}
