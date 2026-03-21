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
            $result = Api::trigger($channel, $event, $pushData);

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
            $result = Api::trigger('public-channel', 'broadcast', $pushData);

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
     * 检查推送配置
     * @param Request $request
     * @return Response
     */
    public function checkConfig(Request $request): Response
    {
        try {
            $config = config('plugin.webman.push.app');

            // 隐藏敏感信息
            $safeConfig = [
                'enable' => $config['enable'] ?? false,
                'websocket' => $config['websocket'] ?? 'Not configured',
                'api' => $config['api'] ?? 'Not configured',
                'app_key' => $config['app_key'] ?? 'Not configured',
                'app_secret' => substr($config['app_secret'] ?? '', 0, 8) . '...(已隐藏)',
            ];

            // 检测是否使用占位符
            $warnings = [];
            if (strpos($safeConfig['websocket'], 'gk_api') !== false) {
                $warnings[] = 'WebSocket 地址包含占位符，需要替换为实际地址';
            }
            if (strpos($safeConfig['api'], 'gk_api') !== false) {
                $warnings[] = 'API 地址包含占位符，需要替换为实际地址';
            }
            if ($safeConfig['app_secret'] === 'e8c7f4a1...(已隐藏)') {
                $warnings[] = 'app_secret 可能未更新，请确认与 gk_api 配置一致';
            }

            return json([
                'code' => 200,
                'msg' => '配置信息',
                'data' => [
                    'config' => $safeConfig,
                    'warnings' => $warnings,
                    'env' => [
                        'PUSH_WEBSOCKET' => env('PUSH_WEBSOCKET', '未配置'),
                        'PUSH_API' => env('PUSH_API', '未配置'),
                        'PUSH_APP_KEY' => env('PUSH_APP_KEY', '未配置'),
                        'PUSH_APP_SECRET' => substr(env('PUSH_APP_SECRET', ''), 0, 8) . '...',
                    ],
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

            $result = Api::trigger($channel, 'notification', $pushData);

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
