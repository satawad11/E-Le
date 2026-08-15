<?php

namespace App\Services;

/** @deprecated Use NotificationCenter; retained as a compatibility proxy. */
class TelegramNotificationService
{
    private TelegramService $telegram;

    public function __construct(?TelegramService $telegram = null)
    {
        $this->telegram = $telegram ?? app(TelegramService::class);
    }

    public function sendMessage($chatId, $message)
    {
        return filled($chatId) && $this->telegram->sendMessage((string) $chatId, (string) $message);
    }
}
