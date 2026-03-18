<?php

namespace process;

use app\service\ProfitSettlementServices;
use Workerman\Crontab\Crontab;

/**
 * 代理分润结算
 */
class ProfitSettlement
{
    public function onWorkerStart()
    {
        // 每天3点执行
        new Crontab('00 3 * * *', function () {
            ProfitSettlementServices::doProfitSettlement();
        });
    }
}
