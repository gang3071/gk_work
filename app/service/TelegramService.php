<?php

namespace app\service;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class TelegramService extends AbstractProcessingHandler
{
    protected $token;
    protected $chatId;

    public function __construct($token, $chatId, $level = Logger::ERROR, bool $bubble = true)
    {
        $this->token = $token;
        $this->chatId = $chatId;
        parent::__construct($level, $bubble);
    }

    /**
     * 公开方法：发送告警消息
     *
     * @param array $record
     * @return void
     */
    public function sendAlert(array $record): void
    {
        $this->write($record);
    }

    protected function write(array $record): void
    {
        // 组装消息内容
        $date = $record['datetime']->format('Y-m-d H:i:s');
        $level = $record['level_name'];
        $message = $record['message'];
        $context = json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $text = "🚨 *Webman 错误告警*\n";
        $text .= "📅 时间: `{$date}`\n";
        $text .= "🔴 级别: `{$level}`\n";
        $text .= "🖥️ 节点: `" . gethostname() . "`\n";
        $text .= "📝 消息: {$message}\n";

        if (!empty($record['context'])) {
            $text .= "🔗 上下文: \n```\n{$context}\n```";
        }

        // 发送到 Telegram API
        $this->sendToTelegram($text);
    }

    protected function sendToTelegram(string $text): void
    {
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";

        // 确保UTF-8编码
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

        // 使用 curl 发送请求（JSON格式）
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // 如果发送失败，记录到文件日志（避免无限循环）
        if ($httpCode !== 200 && function_exists('error_log')) {
            error_log("Telegram send failed: HTTP {$httpCode}, Response: {$response}");
        }

        curl_close($ch);
    }
}
