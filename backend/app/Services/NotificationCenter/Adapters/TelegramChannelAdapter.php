<?php

namespace App\Services\NotificationCenter\Adapters;

use App\Contracts\NotificationCenter\NotificationChannelAdapter;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationCenter\DeliveryResult;
use App\Services\NotificationCenter\NotificationMessage;
use App\Services\TelegramService;

class TelegramChannelAdapter implements NotificationChannelAdapter
{
    public function __construct(private TelegramService $telegram) {}

    public function channel(): string
    {
        return 'telegram';
    }

    public function isConfiguredFor(User $recipient): bool
    {
        return filled($recipient->telegram_chat_id)
            && Setting::query()->where('key', 'telegram_bot_token')->whereNotNull('value')->exists();
    }

    public function deliver(NotificationMessage $message, User $recipient): DeliveryResult
    {
        return $this->telegram->sendMessage($recipient->telegram_chat_id, $message->body)
            ? DeliveryResult::sent()
            : DeliveryResult::failed('Telegram provider rejected the message.');
    }
}
