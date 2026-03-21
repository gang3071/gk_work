<?php

namespace app\api\v1;

use support\Log;
use support\Request;
use support\Response;
use Webman\Push\Api;

/**
 * 推送服务测试控制器
 */
class PushTestController
{
    /**
     * 创建 Push API 实例（连接到 gk_api 的推送服务）
     * @return Api
     */
    private function createPushApi(): Api
    {
        return new Api(
            env('PUSH_API_URL', 'http://10.140.0.6:3232'),
            env('PUSH_APP_KEY', '20f94408fc4c52845f162e92a253c7a3'),
            env('PUSH_APP_SECRET', '3151f8648a6ccd9d4515386f34127e28')
        );
    }

    /**
     * 测试推送消息
     * @param Request $request
     * @return Response
     */
    public function test(Request $request): Response
    {
        try {
            $data = $request->all();

            // 默认参数
            $channel = $data['channel'] ?? 'test-channel';
            $event = $data['event'] ?? 'test-event';
            $message = $data['message'] ?? '这是一条测试推送消息';
            $playerId = $data['player_id'] ?? null;

            // 构建推送数据
            $pushData = [
                'type' => 'test',
                'message' => $message,
                'timestamp' => time(),
                'datetime' => date('Y-m-d H:i:s'),
            ];

            // 如果指定了玩家ID，使用玩家专属频道
            if ($playerId) {
                $channel = "player-{$playerId}";
                $pushData['player_id'] = $playerId;
            }

            Log::info('Testing push notification', [
                'channel' => $channel,
                'event' => $event,
                'data' => $pushData,
            ]);

            // 发送推送
            $api = $this->createPushApi();
            $result = $api->trigger($channel, $event, $pushData);

            if ($result) {
                return json([
                    'code' => 200,
                    'msg' => '推送成功',
                    'data' => [
                        'channel' => $channel,
                        'event' => $event,
                        'push_data' => $pushData,
                        'push_result' => $result,
                    ],
                ]);
            } else {
                return json([
                    'code' => 500,
                    'msg' => '推送失败',
                    'data' => [
                        'channel' => $channel,
                        'event' => $event,
                    ],
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('Push test failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return json([
                'code' => 500,
                'msg' => '推送异常: ' . $e->getMessage(),
                'data' => [],
            ]);
        }
    }

    /**
     * 广播推送测试
     * @param Request $request
     * @return Response
     */
    public function broadcast(Request $request): Response
    {
        try {
            $data = $request->all();
            $message = $data['message'] ?? '这是一条广播消息';

            $pushData = [
                'type' => 'broadcast',
                'message' => $message,
                'timestamp' => time(),
                'datetime' => date('Y-m-d H:i:s'),
            ];

            // 发送到广播频道
            $api = $this->createPushApi();
            $result = $api->trigger('public-channel', 'broadcast', $pushData);

            return json([
                'code' => 200,
                'msg' => '广播推送成功',
                'data' => [
                    'channel' => 'public-channel',
                    'event' => 'broadcast',
                    'push_data' => $pushData,
                    'result' => $result,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Broadcast push failed', [
                'error' => $e->getMessage(),
            ]);

            return json([
                'code' => 500,
                'msg' => '广播推送失败: ' . $e->getMessage(),
                'data' => [],
            ]);
        }
    }

    /**
     * 检查推送配置（gk_api 推送服务）
     * @param Request $request
     * @return Response
     */
    public function checkConfig(Request $request): Response
    {
        try {
            $websocket = env('PUSH_WEBSOCKET', '未配置');
            $apiUrl = env('PUSH_API_URL', '未配置');
            $appKey = env('PUSH_APP_KEY', '未配置');
            $appSecret = env('PUSH_APP_SECRET', '');

            // 检测配置问题
            $warnings = [];
            if ($websocket === '未配置' || strpos($websocket, 'gk_api') !== false) {
                $warnings[] = 'PUSH_WEBSOCKET 未配置或包含占位符';
            }
            if ($apiUrl === '未配置' || strpos($apiUrl, 'gk_api') !== false) {
                $warnings[] = 'PUSH_API_URL 未配置或包含占位符';
            }
            if ($appKey === '未配置') {
                $warnings[] = 'PUSH_APP_KEY 未配置';
            }
            if (empty($appSecret) || $appSecret === 'e8c7f4a1d3b6259f8e0c2a5b7d1f4e9a') {
                $warnings[] = 'PUSH_APP_SECRET 可能未更新（应该是: 3151f8648a6ccd9d4515386f34127e28）';
            }

            return json([
                'code' => 200,
                'msg' => 'gk_api 推送服务配置信息',
                'data' => [
                    'config' => [
                        'websocket' => $websocket,
                        'api_url' => $apiUrl,
                        'app_key' => $appKey,
                        'app_secret' => substr($appSecret, 0, 8) . '...(已隐藏)',
                    ],
                    'warnings' => $warnings,
                    'status' => empty($warnings) ? '✅ 配置正常' : '⚠️ 配置有问题',
                ],
            ]);

        } catch (\Throwable $e) {
            return json([
                'code' => 500,
                'msg' => '获取配置失败: ' . $e->getMessage(),
                'data' => [],
            ]);
        }
    }

    /**
     * 测试推送到玩家
     * @param Request $request
     * @return Response
     */
    public function testPlayerPush(Request $request): Response
    {
        try {
            $playerId = $request->input('player_id');

            if (empty($playerId)) {
                return json([
                    'code' => 400,
                    'msg' => '请提供 player_id 参数',
                    'data' => [],
                ]);
            }

            $message = $request->input('message', '您有新消息');

            // 推送到玩家专属频道
            $channel = "player-{$playerId}";
            $pushData = [
                'type' => 'notification',
                'title' => '系统通知',
                'message' => $message,
                'player_id' => $playerId,
                'timestamp' => time(),
            ];

            $api = $this->createPushApi();
            $result = $api->trigger($channel, 'notification', $pushData);

            Log::info('Push to player', [
                'player_id' => $playerId,
                'channel' => $channel,
                'result' => $result,
            ]);

            return json([
                'code' => 200,
                'msg' => '推送成功',
                'data' => [
                    'player_id' => $playerId,
                    'channel' => $channel,
                    'event' => 'notification',
                    'push_data' => $pushData,
                    'result' => $result,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Player push failed', [
                'error' => $e->getMessage(),
                'player_id' => $playerId ?? null,
            ]);

            return json([
                'code' => 500,
                'msg' => '推送失败: ' . $e->getMessage(),
                'data' => [],
            ]);
        }
    }
}
