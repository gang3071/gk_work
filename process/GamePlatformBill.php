<?php

namespace process;

use app\model\PlayGameRecord;
use app\service\game\GameServiceFactory;
use support\Log;
use Workerman\Crontab\Crontab;

class GamePlatformBill
{
    public function onWorkerStart()
    {
        // 每5分钟跑一次
        new Crontab('0 */1 * * * *', function () {
            ini_set('memory_limit', '512M');
            $WMData = [];
            $BTGData = [];
            try {
                $WMData = GameServiceFactory::createService('WM')->handleGameHistories();
            } catch (\Exception $e) {
                Log::error('GamePlatformBill: ' . $e->getMessage(), ['line' => $e->getLine(), 'file' => $e->getFile(), 'trace' => $e->getTraceAsString()]);
            }
            try {
                $BTGData = GameServiceFactory::createService('BTG')->handleGameHistories();
            } catch (\Exception $e) {
                Log::error('GamePlatformBill: ' . $e->getMessage(), ['line' => $e->getLine(), 'file' => $e->getFile(), 'trace' => $e->getTraceAsString()]);
            }
            $data = array_merge($WMData, $BTGData);
            if (!empty($data)) {
                PlayGameRecord::query()->upsert($data, ['platform_id', 'order_no']);
            }
        });
    }
}
