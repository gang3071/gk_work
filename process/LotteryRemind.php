<?php

namespace process;

use app\service\LotteryServices;
use Workerman\Crontab\Crontab;

class LotteryRemind
{
    public function onWorkerStart()
    {
        new Crontab('*/30 * * * * *', function () {
            // 待审核彩金奖励持续提醒
            LotteryServices::reviewedMessage();
        });
    }
}
