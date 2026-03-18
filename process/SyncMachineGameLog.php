<?php

namespace process;

use Workerman\Crontab\Crontab;

class SyncMachineGameLog
{
    public function onWorkerStart()
    {
        // 每分钟执行一次
        new Crontab('0 */1 * * * *', function () {
            syncMachineGamingLog();
        });
    }
}
