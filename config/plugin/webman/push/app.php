<?php
// gk_work 连接到 gk_api 的推送服务
// 注意：需要替换为实际的 gk_api 地址
return [
    'enable' => true,
    // 连接到 gk_api 的 WebSocket 服务地址
    'websocket' => env('PUSH_WEBSOCKET', 'websocket://127.0.0.1:3131'),
    // 连接到 gk_api 的 API 服务地址
    'api' => env('PUSH_API', 'http://127.0.0.1:3232'),
    // 从 gk_api 获取的 app_key (必须与 gk_api 配置一致)
    'app_key' => env('PUSH_APP_KEY', '20f94408fc4c52845f162e92a253c7a3'),
    // 从 gk_api 获取的 app_secret (必须与 gk_api 配置一致)
    'app_secret' => env('PUSH_APP_SECRET', 'e8c7f4a1d3b6259f8e0c2a5b7d1f4e9a'),
    // hook 回调地址 (保持 gk_work 自己的地址)
    'channel_hook' => 'http://127.0.0.1:8787/plugin/webman/push/hook',
    // 鉴权地址 (保持 gk_work 自己的地址)
    'auth' => '/plugin/webman/push/auth'
];
