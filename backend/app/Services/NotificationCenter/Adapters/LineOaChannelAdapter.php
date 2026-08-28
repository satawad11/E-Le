<?php

namespace App\Services\NotificationCenter\Adapters;

use App\Contracts\NotificationCenter\NotificationChannelAdapter;
use App\Models\User;
use App\Services\NotificationCenter\DeliveryResult;
use App\Services\NotificationCenter\NotificationMessage;
use App\Services\NotificationCenter\Providers\LineGateway;
use App\Services\SystemSettingService;

class LineOaChannelAdapter implements NotificationChannelAdapter
{
    public function __construct(private LineGateway $line, private SystemSettingService $settings) {}

    public function channel(): string
    {
        return 'line_oa';
    }

    public function isConfiguredFor(User $recipient): bool
    {
        return filled($recipient->line_user_id)
            && filled($this->settings->runtimeValues()->get('line_channel_access_token'));
    }

    public function deliver(NotificationMessage $message, User $recipient): DeliveryResult
    {
        return $this->line->sendMessage($message->body, $recipient->line_user_id)
            ? DeliveryResult::sent()
            : DeliveryResult::failed('LINE OA provider rejected the message.');
    }
}
