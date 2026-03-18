<?php

namespace process;

use Workerman\Crontab\Crontab;

class MediaClear
{
    public function onWorkerStart()
    {
        // 每分钟跑一次
        new Crontab('0 */5 * * * *', function () {
            mediaClear();
        });
    }
}
