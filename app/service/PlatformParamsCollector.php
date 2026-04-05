<?php

namespace app\service;

use support\Log;

/**
 * 游戏平台参数收集器
 * 用于收集各平台的真实请求参数，方便分析和调试
 */
class PlatformParamsCollector
{
    /**
     * 参数保存路径
     */
    private static string $storePath = '';

    /**
     * 初始化存储路径
     */
    private static function initStorePath(): void
    {
        if (empty(self::$storePath)) {
            self::$storePath = runtime_path('platform_params');
            if (!is_dir(self::$storePath)) {
                mkdir(self::$storePath, 0755, true);
            }
        }
    }

    /**
     * 收集平台参数
     *
     * @param string $platform 平台代码 (RSG, MT, BTG等)
     * @param string $action 操作类型 (bet, settle, cancel等)
     * @param array $params 原始请求参数
     * @param array $context 额外上下文信息
     * @return void
     */
    public static function collect(string $platform, string $action, array $params, array $context = []): void
    {
        try {
            self::initStorePath();

            $data = [
                'platform' => $platform,
                'action' => $action,
                'timestamp' => date('Y-m-d H:i:s'),
                'datetime' => time(),
                'params' => $params,
                'context' => $context,
            ];

            // 文件名：平台_操作_日期.json
            $date = date('Ymd');
            $filename = strtolower("{$platform}_{$action}_{$date}.json");
            $filepath = self::$storePath . DIRECTORY_SEPARATOR . $filename;

            // 读取已有数据
            $existingData = [];
            if (file_exists($filepath)) {
                $content = file_get_contents($filepath);
                $existingData = json_decode($content, true) ?: [];
            }

            // 只保留最新的 100 条记录（避免文件过大）
            if (count($existingData) >= 100) {
                $existingData = array_slice($existingData, -99);
            }

            // 添加新记录
            $existingData[] = $data;

            // 保存到文件
            file_put_contents(
                $filepath,
                json_encode($existingData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

        } catch (\Throwable $e) {
            // 收集失败不影响主业务，只记录日志
            Log::warning('PlatformParamsCollector 收集失败', [
                'platform' => $platform,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取已收集的参数列表
     *
     * @param string|null $platform 平台代码（为空则返回所有平台）
     * @param string|null $action 操作类型（为空则返回所有操作）
     * @return array
     */
    public static function getCollectedFiles(?string $platform = null, ?string $action = null): array
    {
        self::initStorePath();

        $files = glob(self::$storePath . DIRECTORY_SEPARATOR . '*.json');
        $result = [];

        foreach ($files as $file) {
            $filename = basename($file);

            // 解析文件名：platform_action_date.json
            if (preg_match('/^([a-z0-9]+)_([a-z]+)_(\d{8})\.json$/i', $filename, $matches)) {
                $filePlatform = strtoupper($matches[1]);
                $fileAction = $matches[2];
                $fileDate = $matches[3];

                // 过滤条件
                if ($platform && strtoupper($platform) !== $filePlatform) {
                    continue;
                }
                if ($action && $action !== $fileAction) {
                    continue;
                }

                $result[] = [
                    'platform' => $filePlatform,
                    'action' => $fileAction,
                    'date' => $fileDate,
                    'filepath' => $file,
                    'filename' => $filename,
                    'size' => filesize($file),
                    'mtime' => filemtime($file),
                ];
            }
        }

        // 按修改时间倒序排列
        usort($result, function ($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });

        return $result;
    }

    /**
     * 读取收集的参数数据
     *
     * @param string $filename 文件名
     * @param int $limit 返回最新的 N 条记录（0 表示返回所有）
     * @return array
     */
    public static function read(string $filename, int $limit = 10): array
    {
        self::initStorePath();

        $filepath = self::$storePath . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($filepath)) {
            return [];
        }

        $content = file_get_contents($filepath);
        $data = json_decode($content, true) ?: [];

        if ($limit > 0 && count($data) > $limit) {
            return array_slice($data, -$limit);
        }

        return $data;
    }

    /**
     * 清除旧的收集数据
     *
     * @param int $days 保留最近 N 天的数据（默认 7 天）
     * @return int 删除的文件数
     */
    public static function cleanup(int $days = 7): int
    {
        self::initStorePath();

        $files = glob(self::$storePath . DIRECTORY_SEPARATOR . '*.json');
        $cutoffTime = strtotime("-{$days} days");
        $deletedCount = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    /**
     * 生成参数对比报告
     *
     * @param string $platform 平台代码
     * @param string $action 操作类型
     * @return array
     */
    public static function generateReport(string $platform, string $action): array
    {
        $files = self::getCollectedFiles($platform, $action);

        if (empty($files)) {
            return [
                'platform' => $platform,
                'action' => $action,
                'total_samples' => 0,
                'fields' => [],
            ];
        }

        // 读取所有样本
        $allSamples = [];
        foreach ($files as $file) {
            $samples = self::read($file['filename'], 0);
            $allSamples = array_merge($allSamples, $samples);
        }

        // 分析字段
        $fieldStats = [];
        foreach ($allSamples as $sample) {
            $params = $sample['params'] ?? [];
            foreach ($params as $key => $value) {
                if (!isset($fieldStats[$key])) {
                    $fieldStats[$key] = [
                        'name' => $key,
                        'count' => 0,
                        'types' => [],
                        'examples' => [],
                    ];
                }

                $fieldStats[$key]['count']++;

                $type = gettype($value);
                if (!isset($fieldStats[$key]['types'][$type])) {
                    $fieldStats[$key]['types'][$type] = 0;
                }
                $fieldStats[$key]['types'][$type]++;

                // 保存示例值（最多 5 个不重复的）
                if (count($fieldStats[$key]['examples']) < 5) {
                    $valueStr = is_scalar($value) ? (string)$value : json_encode($value);
                    if (!in_array($valueStr, $fieldStats[$key]['examples'])) {
                        $fieldStats[$key]['examples'][] = $valueStr;
                    }
                }
            }
        }

        return [
            'platform' => $platform,
            'action' => $action,
            'total_samples' => count($allSamples),
            'fields' => $fieldStats,
            'latest_sample' => end($allSamples),
        ];
    }
}
