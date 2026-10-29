<?php

namespace app\support;

use Monolog\Formatter\FormatterInterface;

/**
 * 扁平 JSON 日志格式器（Monolog 2.x，供 machine_ctrl 通道使用）
 *
 * Monolog 默认的 JsonFormatter 将业务字段嵌套在 "context" 键下：
 *   {"message":"open","context":{"machine_id":1,...},"level":200,...}
 *
 * 本 formatter 将 context 字段提升至顶层，输出 ClickHouse/Vector 友好的单层 JSON Lines：
 *   {"datetime":"...","level":"INFO","channel":"...","message":"open","machine_id":1,...}
 *
 * 每条记录以 \n 结尾，符合 JSON Lines 规范。
 * Monolog 2.x：format(array $record)，字段通过数组键访问。
 */
class FlatJsonFormatter implements FormatterInterface
{
    public function format(array $record): string
    {
        $base = [
            'datetime' => $record['datetime']->format('Y-m-d\TH:i:sP'),
            'level' => $record['level_name'],   // Monolog 2.x 已是字符串，如 "INFO"
            'channel' => $record['channel'],
            'message' => $record['message'],
        ];

        // context 字段打平到顶层（machine_id、machine_type 等直接成为 JSON 根键）
        $flat = array_merge($base, $record['context']);

        // JSON_INVALID_UTF8_SUBSTITUTE：用 ? 替换无效字节（如未经 bin2hex 的原始协议数据），
        // 保证 json_encode 永不返回 false，避免向日志文件写入 "false\n" 破坏 Vector 解析。
        return json_encode($flat,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ) . "\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map([$this, 'format'], $records));
    }
}
