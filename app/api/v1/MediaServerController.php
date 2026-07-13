<?php

namespace app\api\v1;

use app\service\MediaServer;
use support\Request;
use support\Response;
use support\Log;

/**
 * 媒体服务器 API 控制器
 * 为 gk_admin 提供媒体服务器操作接口
 */
class MediaServerController
{
    /**
     * 删除机台流
     * POST /api/admin/media-server/delete-machine-stream
     */
    public function deleteMachineStream(Request $request): Response
    {
        try {
            $streamName = $request->post('stream_name');
            $domain = $request->post('domain', '');
            $mediaApp = $request->post('media_app', '');

            if (empty($streamName)) {
                return json([
                    'code' => 400,
                    'success' => false,
                    'message' => 'Missing required parameter: stream_name'
                ]);
            }

            // 调用本地 MediaServer 服务
            $mediaServer = new MediaServer($domain, $mediaApp);
            $result = $mediaServer->deleteMachineStream($streamName, $domain, $mediaApp);

            return json([
                'code' => 200,
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('[媒体服务器API] deleteMachineStream 失败', [
                'error' => $e->getMessage(),
                'stream_name' => $streamName ?? null,
            ]);

            return json([
                'code' => 500,
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 创建机台流
     * POST /api/admin/media-server/create-machine-stream
     */
    public function createMachineStream(Request $request): Response
    {
        try {
            $name = $request->post('name');
            $streamUrl = $request->post('stream_url');
            $type = $request->post('type');
            $pushList = $request->post('push_list', []);
            $domain = $request->post('domain', '');
            $mediaApp = $request->post('media_app', '');

            if (empty($name) || empty($streamUrl) || !isset($type)) {
                return json([
                    'code' => 400,
                    'success' => false,
                    'message' => 'Missing required parameters: name, stream_url, type'
                ]);
            }

            // 调用本地 MediaServer 服务
            $mediaServer = new MediaServer($domain, $mediaApp);
            $result = $mediaServer->createMachineStream($name, $streamUrl, $type, $pushList);

            return json([
                'code' => 200,
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('[媒体服务器API] createMachineStream 失败', [
                'error' => $e->getMessage(),
                'name' => $name ?? null,
            ]);

            return json([
                'code' => 500,
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 添加 RTMP 节点
     * POST /api/admin/media-server/rtmp-endpoint
     */
    public function rtmpEndpoint(Request $request): Response
    {
        try {
            $rtmpUrl = $request->post('rtmp_url');
            $endpointServiceId = $request->post('endpoint_service_id');
            $streamName = $request->post('stream_name');
            $domain = $request->post('domain', '');
            $mediaApp = $request->post('media_app', '');

            if (empty($rtmpUrl) || empty($endpointServiceId) || empty($streamName)) {
                return json([
                    'code' => 400,
                    'success' => false,
                    'message' => 'Missing required parameters'
                ]);
            }

            $mediaServer = new MediaServer($domain, $mediaApp);
            $result = $mediaServer->rtmpEndpoint($rtmpUrl, $endpointServiceId, $streamName);

            return json([
                'code' => 200,
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('[媒体服务器API] rtmpEndpoint 失败', [
                'error' => $e->getMessage(),
            ]);

            return json([
                'code' => 500,
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 删除 RTMP 节点
     * POST /api/admin/media-server/delete-rtmp-endpoint
     */
    public function deleteRtmpEndpoint(Request $request): Response
    {
        try {
            $endpointServiceId = $request->post('endpoint_service_id');
            $streamName = $request->post('stream_name');
            $domain = $request->post('domain', '');
            $mediaApp = $request->post('media_app', '');

            if (empty($endpointServiceId) || empty($streamName)) {
                return json([
                    'code' => 400,
                    'success' => false,
                    'message' => 'Missing required parameters'
                ]);
            }

            $mediaServer = new MediaServer($domain, $mediaApp);
            $result = $mediaServer->deleteRtmpEndpoint($endpointServiceId, $streamName);

            return json([
                'code' => 200,
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('[媒体服务器API] deleteRtmpEndpoint 失败', [
                'error' => $e->getMessage(),
            ]);

            return json([
                'code' => 500,
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取观看人数
     * POST /api/admin/media-server/get-viewers
     */
    public function getViewers(Request $request): Response
    {
        try {
            $streamName = $request->post('stream_name');
            $domain = $request->post('domain', '');
            $mediaApp = $request->post('media_app', '');

            if (empty($streamName)) {
                return json([
                    'code' => 400,
                    'success' => false,
                    'message' => 'Missing required parameter: stream_name'
                ]);
            }

            $mediaServer = new MediaServer($domain, $mediaApp);
            $result = $mediaServer->getViewers($streamName);

            return json([
                'code' => 200,
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('[媒体服务器API] getViewers 失败', [
                'error' => $e->getMessage(),
            ]);

            return json([
                'code' => 500,
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 开始录制
     * POST /api/admin/media-server/start-recording
     */
    public function startRecording(Request $request): Response
    {
        try {
            // 这个方法需要 MachineMedia 对象，暂时不实现
            // 建议在 gk_admin 中直接操作，不通过 API
            return json([
                'code' => 501,
                'success' => false,
                'message' => 'Not implemented - use local service in gk_admin'
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 停止录制
     * POST /api/admin/media-server/stop-recording
     */
    public function stopRecording(Request $request): Response
    {
        try {
            // 同上，需要模型对象
            return json([
                'code' => 501,
                'success' => false,
                'message' => 'Not implemented - use local service in gk_admin'
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取流信息
     * POST /api/admin/media-server/get-broadcasts
     */
    public function getBroadcasts(Request $request): Response
    {
        try {
            $streamName = $request->post('stream_name');
            $domain = $request->post('domain', '');
            $mediaApp = $request->post('media_app', '');

            if (empty($streamName)) {
                return json([
                    'code' => 400,
                    'success' => false,
                    'message' => 'Missing required parameter: stream_name'
                ]);
            }

            $mediaServer = new MediaServer($domain, $mediaApp);
            $result = $mediaServer->getBroadcasts($streamName);

            return json([
                'code' => 200,
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('[媒体服务器API] getBroadcasts 失败', [
                'error' => $e->getMessage(),
            ]);

            return json([
                'code' => 500,
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}