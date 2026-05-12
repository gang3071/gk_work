<?php

namespace process;

use Workerman\Crontab\Crontab;

class MediaClear
{
    public function onWorkerStart()
    {
        // 每5分钟清理一次过期媒体文件
        new Crontab('0 */5 * * * *', function () {
            mediaClear();
        });
    }
}
