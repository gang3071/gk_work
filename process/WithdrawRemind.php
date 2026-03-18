<?php

namespace process;

use Workerman\Crontab\Crontab;

class WithdrawRemind
{
    public function onWorkerStart()
    {
        new Crontab('*/20 * * * * *', function () {
            reviewedWithdrawMessage();
        });
    }
}
