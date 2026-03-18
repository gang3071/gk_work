<?php

namespace process;

use Workerman\Crontab\Crontab;

/**
 * 全民代理分润结算
 */
class NationalPromoterRebate
{
    public function onWorkerStart()
    {
        // 每10分钟执行一次
        new Crontab('0 */10 * * * *', function () {
            nationalPromoterRebate();
        });
    }
}
