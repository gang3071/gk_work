<?php

namespace app\service;

use support\Log;
use support\Cache;

/**
 * 超时监控和熔断器
 * 用于ATG等平台的性能监控和自动降级
 */
class TimeoutMonitor
{
    /**
     * 记录请求耗时
     *
     * @param string $platform 平台代码
     * @param string $method 方法名（bet/betResult/refund/balance）
     * @param float $duration 耗时（毫秒）
     * @param bool $success 是否成功
     */
    public static function record(string $platform, string $method, float $duration, bool $success = true): void
    {
        try {
            $key = "timeout_monitor:{$platform}:{$method}";
            $now = time();

            // 使用Redis Sorted Set存储最近100条记录
            \support\Redis::zadd($key, $now, json_encode([
                'duration' => $duration,
                'success' => $success,
                'timestamp' => $now,
            ]));

            // 只保留最近1小时的数据
            \support\Redis::zremrangebyscore($key, '-inf', $now - 3600);

            // 超时告警（超过200ms）
            if ($duration > 200) {
                Log::channel('timeout')->warning("[$platform] $method 接口超时", [
                    'duration_ms' => $duration,
                    'success' => $success,
                ]);
            }

        } catch (\Exception $e) {
            // 监控失败不影响业务
            Log::error('TimeoutMonitor::record failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 获取平台方法的平均耗时
     *
     * @param string $platform 平台代码
     * @param string $method 方法名
     * @param int $minutes 统计最近N分钟，默认5分钟
     * @return array ['avg' => 平均耗时, 'max' => 最大耗时, 'count' => 请求数]
     */
    public static function getStats(string $platform, string $method, int $minutes = 5): array
    {
        try {
            $key = "timeout_monitor:{$platform}:{$method}";
            $since = time() - ($minutes * 60);

            $records = \support\Redis::zrangebyscore($key, $since, '+inf');

            if (empty($records)) {
                return ['avg' => 0, 'max' => 0, 'count' => 0];
            }

            $durations = [];
            foreach ($records as $record) {
                $data = json_decode($record, true);
                if ($data && isset($data['duration'])) {
                    $durations[] = $data['duration'];
                }
            }

            return [
                'avg' => round(array_sum($durations) / count($durations), 2),
                'max' => max($durations),
                'count' => count($durations),
            ];

        } catch (\Exception $e) {
            return ['avg' => 0, 'max' => 0, 'count' => 0];
        }
    }

    /**
     * 检查是否应该熔断（根据最近统计数据）
     *
     * @param string $platform 平台代码
     * @param string $method 方法名
     * @return bool true=应该熔断
     */
    public static function shouldBreak(string $platform, string $method): bool
    {
        // 检查手动熔断开关
        $manualBreakKey = "circuit_breaker:{$platform}:{$method}";
        if (Cache::get($manualBreakKey) === 'on') {
            Log::warning("[$platform] $method 手动熔断中");
            return true;
        }

        // 检查自动熔断条件（最近5分钟平均耗时 > 300ms）
        $stats = self::getStats($platform, $method, 5);
        if ($stats['avg'] > 300 && $stats['count'] > 10) {
            Log::warning("[$platform] $method 自动熔断触发", [
                'avg_duration' => $stats['avg'],
                'count' => $stats['count'],
            ]);
            return true;
        }

        return false;
    }

    /**
     * 手动开启熔断
     *
     * @param string $platform 平台代码
     * @param string $method 方法名（为空则熔断整个平台）
     * @param int $ttl 熔断时长（秒），默认300秒
     */
    public static function enableBreaker(string $platform, string $method = '', int $ttl = 300): void
    {
        $key = $method ? "circuit_breaker:{$platform}:{$method}" : "circuit_breaker:{$platform}:*";
        Cache::set($key, 'on', $ttl);
        Log::warning("[$platform] 手动开启熔断", ['method' => $method ?: 'ALL', 'ttl' => $ttl]);
    }

    /**
     * 手动关闭熔断
     *
     * @param string $platform 平台代码
     * @param string $method 方法名
     */
    public static function disableBreaker(string $platform, string $method = ''): void
    {
        $key = $method ? "circuit_breaker:{$platform}:{$method}" : "circuit_breaker:{$platform}:*";
        Cache::delete($key);
        Log::info("[$platform] 手动关闭熔断", ['method' => $method ?: 'ALL']);
    }
}
