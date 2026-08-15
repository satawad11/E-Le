<?php

namespace App\Services\NotificationCenter\Adapters;

use App\Contracts\NotificationCenter\NotificationChannelAdapter;
use App\Models\User;
use App\Services\NotificationCenter\DeliveryResult;
use App\Services\NotificationCenter\NotificationMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class EmailChannelAdapter implements NotificationChannelAdapter
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfiguredFor(User $recipient): bool
    {
        return filter_var($recipient->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function deliver(NotificationMessage $message, User $recipient): DeliveryResult
    {
        $mailable = (new Mailable)
            ->subject($message->subject)
            ->html(nl2br(e($message->body)));
        Mail::to($recipient->email, $recipient->name)->send($mailable);

        return DeliveryResult::sent();
    }
}
