<?php

namespace process;

use app\model\PlayGameRecord;
use app\service\game\GameServiceFactory;
use support\Log;
use Workerman\Crontab\Crontab;

class DGGamePlatformBill
{
    public function onWorkerStart()
    {
        // 每5分钟跑一次
        new Crontab('0 */5 * * * *', function () {
            $env = config('app.env');
            if ($env != 'pro') {
                Log::info('DGGamePlatformBill: DG测试线不拉取游戏记录');
                return;
            }
            ini_set('memory_limit', '512M');
            try {
                $service = GameServiceFactory::createService('DG');
                $data = $service->handleGameHistories();
                if (!empty($data)) {
                    $ids = array_map(function ($item) {
                        return $item['order_no'];
                    }, $data);
                    if ($service->markGameHistories($ids)) {
                        PlayGameRecord::query()->upsert($data, ['platform_id', 'order_no']);
                    }
                }
            } catch (\Exception $e) {
                Log::error('GamePlatformBill: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            }
        });
    }
}
