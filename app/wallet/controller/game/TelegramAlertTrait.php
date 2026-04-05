<?php

namespace app\wallet\controller\game;

use app\service\TelegramService;
use Monolog\Logger;
use support\Log;

/**
 * Telegram 告警通知 Trait
 * 用于单一钱包控制器的异常通知
 */
trait TelegramAlertTrait
{
    /**
     * 发送 Telegram 告警通知
     */
    private function sendTelegramAlert(string $platform, string $action, \Throwable $e, array $context = []): void
    {
        try {
            $token = env('TELEGRAM_BOT_TOKEN');
            $chatId = env('TELEGRAM_CHAT_ID');

            if (empty($token) || empty($chatId)) {
                return;
            }

            $telegram = new TelegramService($token, $chatId, Logger::ERROR);
            $telegram->sendAlert([
                'datetime' => new \DateTime(),
                'level_name' => 'ERROR',
                'message' => "[{$platform}单一钱包] {$action}",
                'context' => array_merge($context, [
                    'platform' => $platform,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]),
            ]);
        } catch (\Throwable $te) {
            Log::warning('Send telegram alert failed', [
                'error' => $te->getMessage(),
            ]);
        }
    }
}