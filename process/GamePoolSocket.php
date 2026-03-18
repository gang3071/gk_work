<?php

namespace process;

use app\service\GameLotteryServices;
use Workerman\Crontab\Crontab;

class GamePoolSocket
{
    /**
     * 发送彩金变化消息
     * @return void
     */
    public function onWorkerStart()
    {
        new Crontab('*/30 * * * * *', function () {
            try {
                $result = GameLotteryServices::forceSyncRedisToDatabase();

                if ($result['synced_count'] > 0) {
                    \support\Log::info('定时同步电子游戏彩金成功', [
                        'synced_count' => $result['synced_count'],
                        'details' => $result['details'],
                    ]);
                }

                if (!empty($result['errors'])) {
                    \support\Log::error('定时同步电子游戏彩金部分失败', [
                        'errors' => $result['errors'],
                    ]);
                }
            } catch (\Exception $e) {
                \support\Log::error('定时同步电子游戏彩金异常', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
