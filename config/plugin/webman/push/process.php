<?php

// gk_work 不启动推送服务进程，使用 gk_api 的推送服务
// 如果需要在 gk_work 启动独立的推送服务，取消下面的注释并修改端口
return [
    // 'server' => [
    //     'handler'     => Server::class,
    //     'listen'      => config('plugin.webman.push.app.websocket'),
    //     'count'       => 1, // 必须是1
    //     'reloadable'  => false, // 执行reload不重启
    //     'constructor' => [
    //         'api_listen' => config('plugin.webman.push.app.api'),
    //         'app_info'   => [
    //             config('plugin.webman.push.app.app_key') => [
    //                 'channel_hook' => config('plugin.webman.push.app.channel_hook'),
    //                 'app_secret'   => config('plugin.webman.push.app.app_secret'),
    //             ],
    //         ]
    //     ]
    // ]
];
