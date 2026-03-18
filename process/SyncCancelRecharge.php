<?php

namespace process;

use Workerman\Crontab\Crontab;

class SyncCancelRecharge
{
    public function onWorkerStart()
    {
        // 每30秒执行一次
        new Crontab('*/30 * * * * *', function () {
            cancelRecharge();
        });
    }
}
