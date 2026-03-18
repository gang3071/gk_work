<?php

namespace process;

use app\service\ActivityServices;
use Workerman\Crontab\Crontab;

class SyncMachineActivity
{
    public function onWorkerStart()
    {
        // 每30秒执行一次
        new Crontab('*/30 * * * * *', function () {
            // 待审核活动持续提醒
            (new ActivityServices(null, null))->reviewedMessage();
        });
    }
}
