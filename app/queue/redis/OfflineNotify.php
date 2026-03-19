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
        $log->info('开始处理下线通知', ['data' => $data]);

        try {
            /** @var Player $player */
            $player = Player::query()->find($data['player_id']);
            if (empty($player)) {
                $log->warning('玩家不存在', ['player_id' => $data['player_id']]);
                return;
            }

            /** @var ExternalApp $externalApp */
            $externalApp = ExternalApp::query()->where('department_id', $player->channel->department_id)
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->first();

            if (empty($externalApp)) {
                $log->info('外部应用未配置', [
                    'player_id' => $data['player_id'],
                    'department_id' => $player->channel->department_id
                ]);
                return;
            }

            if (empty($externalApp->notify_url)) {
                $log->info('未配置通知URL', [
                    'player_id' => $data['player_id'],
                    'app_id' => $externalApp->app_id
                ]);
                return;
            }

            $params = [
                'sign' => md5($externalApp->app_id . $externalApp->app_secret),
                'id' => $player->id,
            ];
            $response = Http::timeout(10)->asForm()->post($externalApp->notify_url, $params);

            $log->info('下线通知发送成功', [
                'player_id' => $data['player_id'],
                'notify_url' => $externalApp->notify_url,
                'response' => $response
            ]);
        } catch (Exception $e) {
            $log->error('下线通知处理失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
