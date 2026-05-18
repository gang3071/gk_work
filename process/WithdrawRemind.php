<?php

namespace process;

use Workerman\Crontab\Crontab;

class WithdrawRemind
{
    public function onWorkerStart()
    {
        // 每20秒检查一次待审核提款，推送通知到管理员
        new Crontab('*/20 * * * * *', function () {
            reviewedWithdrawMessage();
        });
    }
}
