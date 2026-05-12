<?php

namespace process;

use Workerman\Crontab\Crontab;

class RechargeRemind
{
    public function onWorkerStart()
    {
        // 每25秒检查一次待审核充值，推送通知到管理员
        new Crontab('*/25 * * * * *', function () {
            reviewedRechargeMessage();
        });
    }
}
