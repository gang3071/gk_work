<?php

namespace process;

use app\model\mongo\LotteryPoolAddLog;
use app\model\mongo\MachineOperationLog;
use DateTime;
use Workerman\Crontab\Crontab;

class LogClear
{
    /**
     * 清理日志操作
     * @return void
     */
    public function onWorkerStart()
    {
        // 每天2点执行清理日志任务
        new Crontab('00 2 * * *', function () {
            do {
                $result = MachineOperationLog::query()->where('created_at', '<=',
                    (new DateTime())->modify('-10 day'))->limit(10000)->delete();
            } while ($result > 0);
            do {
                $result = LotteryPoolAddLog::query()->where('created_at', '<=',
                    (new DateTime())->modify('-5 day'))->limit(10000)->delete();
            } while ($result > 0);
        });
    }
}

