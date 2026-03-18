<?php

namespace process;

use app\service\ChannelSettlementServices;
use Workerman\Crontab\Crontab;

/**
 * 代理分润结算
 */
class ChannelSettlement
{
    public function onWorkerStart()
    {
        // 每天3点执行
        new Crontab('00 2 * * *', function () {
            ChannelSettlementServices::doProfitSettlement();
        });
    }
}
