<?php

namespace app\queue\redis;

use app\model\ExternalApp;
use app\model\Player;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;
use WebmanTech\LaravelHttpClient\Facades\Http;

class OfflineNotify implements Consumer
{
    // 要消费的队列名
    public $queue = 'offline-notify';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    // 消费
    public function consume($data)
    {
        $log = Log::channel('offline_notify_server');
        try {
            /** @var Player $player */
            $player = Player::query()->find($data['player_id']);
            if (!empty($player)) {
                /** @var ExternalApp $externalApp */
                $externalApp = ExternalApp::query()->where('department_id', $player->channel->department_id)
                    ->whereNull('deleted_at')
                    ->where('status', 1)
                    ->first();
                if (!empty($externalApp->notify_url)) {
                    $params = [
                        'sign' => md5($externalApp->app_id . $externalApp->app_secret),
                        'id' => $player->id,
                    ];
                    $response = Http::timeout(10)->asForm()->post($externalApp->notify_url, $params);
                    $log->info('玩家下线回调', ['params' => $params, 'res' => $response]);
                }
            }
        } catch (Exception $e) {
            $log->error('玩家下线回调', [$e->getMessage()]);
        }
    }
}
