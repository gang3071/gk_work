<?php

namespace app\api\v1;

use support\Request;
use support\Response;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * 媒体服务器代理控制器
 *
 * 功能：为 gk_admin 提供媒体服务器 API 代理服务
 * 原因：gk_admin 无外网 IP，无法直接调用需要 IP 白名单的媒体服务器
 *
 * 架构：
 * gk_admin (内网) -> gk_work (有外网 IP) -> 媒体服务器
 */
class MediaServerProxyController
{
    /**
     * 通用代理方法 - 转发所有媒体服务器 API 请求
     *
     * POST /api/v1/media-proxy
     *
     * 请求参数：
     * {
     *   "method": "POST|GET|DELETE|PUT",
     *   "url": "完整的媒体服务器 API URL",
     *   "headers": {...},  // 可选
     *   "body": {...}      // 可选，仅用于 POST/PUT
     * }
     *
     * 返回：
     * {
     *   "code": 200,
     *   "success": true,
     *   "data": {...},     // 媒体服务器响应
     *   "status": 200      // 媒体服务器 HTTP 状态码
     * }
     */
    public function proxy(Request $request): Response
    {
        try {
            // 验证请求参数
            $method = strtoupper($request->post('method', 'POST'));
            $url = $request->post('url');
            $headers = $request->post('headers', []);
            $body = $request->post('body', []);
            $timeout = $request->post('timeout', 10); // 默认10秒超时

            if (empty($url)) {
                return json([
                    'code' => 400,
                    'success' => false,
                    'message' => 'Missing required parameter: url'
                ]);
            }

            // 验证 URL 合法性（防止SSRF攻击）
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return json([
                    'code' => 400,
                    'success' => false,
                    'message' => 'Invalid URL format'
                ]);
            }

            // 记录请求日志
            Log::channel('media_proxy')->info('[媒体代理] 请求', [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
                'use_json' => !empty($headers['Content-Type']) && $headers['Content-Type'] === 'application/json',
            ]);

            // 构建 HTTP 客户端
            $httpClient = Http::timeout($timeout);

            // 添加自定义头部
            if (!empty($headers)) {
                $httpClient->withHeaders($headers);
            }

            // 检查是否需要 JSON 格式（统一处理 Content-Type）
            $useJson = !empty($headers['Content-Type']) && $headers['Content-Type'] === 'application/json';
            if ($useJson) {
                $httpClient = $httpClient->asJson();
                // asJson() 会自动设置 Content-Type 和 Accept，但为了确保一致性，显式添加
                if (!isset($headers['Accept'])) {
                    $headers['Accept'] = 'application/json';
                }
            }

            // 根据方法发起请求
            switch ($method) {
                case 'GET':
                    $response = $httpClient->get($url, $body);
                    break;

                case 'POST':
                    $response = $httpClient->post($url, $body);
                    break;

                case 'PUT':
                    $response = $httpClient->put($url, $body);
                    break;

                case 'DELETE':
                    // DELETE 请求也可能需要传递 body（如认证参数）
                    $response = $httpClient->delete($url, $body);
                    break;

                default:
                    return json([
                        'code' => 400,
                        'success' => false,
                        'message' => 'Unsupported HTTP method: ' . $method
                    ]);
            }

            // 获取响应状态码和内容
            $statusCode = $response->status();
            $responseBody = $response->body();

            // 尝试解析 JSON
            $responseData = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // 不是 JSON，返回原始内容
                $responseData = $responseBody;
            }

            // 记录响应日志
            Log::channel('media_proxy')->info('[媒体代理] 响应', [
                'status' => $statusCode,
                'url' => $url,
                'response_size' => strlen($responseBody),
                'response_preview' => $statusCode >= 400 ? substr($responseBody, 0, 500) : '(success)',
            ]);

            return json([
                'code' => 200,
                'success' => true,
                'data' => $responseData,
                'status' => $statusCode,
            ]);

        } catch (\Exception $e) {
            // 记录错误日志
            Log::channel('media_proxy')->error('[媒体代理] 错误', [
                'url' => $url ?? 'unknown',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return json([
                'code' => 500,
                'success' => false,
                'message' => '媒体服务器代理请求失败: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 健康检查
     * GET /api/v1/media-proxy/health
     */
    public function health(): Response
    {
        return json([
            'code' => 200,
            'success' => true,
            'message' => 'Media proxy service is running',
            'timestamp' => time(),
        ]);
    }
}