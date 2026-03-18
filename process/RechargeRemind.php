<?php

namespace process;

use Workerman\Crontab\Crontab;

class RechargeRemind
{
    public function onWorkerStart()
    {
        new Crontab('*/25 * * * * *', function () {
            reviewedRechargeMessage();
        });
    }
}
