<?php

namespace process;

use Workerman\Crontab\Crontab;

class MachineKeepOutPlayer
{
    public function onWorkerStart()
    {
        // 每分钟执行一次
        new Crontab('*/10 * * * * *', function () {
            machineKeepOutPlayer();
        });
    }
}
