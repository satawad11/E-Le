<?php

namespace App\Services\NotificationCenter;

use App\Contracts\NotificationCenter\NotificationChannelAdapter;
use App\Jobs\DeliverNotification;
use App\Models\NotificationCenter\NotificationDelivery;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotificationDeliveryService
{
    /** @param array<string, NotificationChannelAdapter> $adapters */
    public function __construct(
        private array $adapters,
        private TemplateRenderer $renderer,
        private ModuleRegistry $modules,
    ) {}

    public function deliver(int $deliveryId, bool $scheduleRetry = true): void
    {
        if (! $this->modules->isEnabled('notification')) {
            return;
        }

        $delivery = NotificationDelivery::query()
            ->with(['template', 'recipient.user', 'recipient.event'])
            ->find($deliveryId);

        if (! $delivery || ! in_array($delivery->status, ['pending', 'retrying'], true)) {
            return;
        }

        $adapter = $this->adapters[$delivery->channel] ?? null;
        if (! $adapter || ! $delivery->template) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Channel adapter or active template is unavailable.']);
            $this->refreshEventStatus($delivery);

            return;
        }

        $user = $delivery->recipient->user;
        if (! $user || ! $adapter->isConfiguredFor($user)) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Channel is not configured for recipient.']);
            $this->refreshEventStatus($delivery);

            return;
        }

        $attempt = $delivery->attempt_count + 1;
        $delivery->update(['attempt_count' => $attempt, 'last_attempt_at' => now(), 'status' => 'sending']);

        try {
            $result = $adapter->deliver($this->renderer->render($delivery->template, $delivery->recipient->event), $user);
            if ($result->successful) {
                $delivery->update([
                    'status' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null,
                    'provider_message_id' => $result->providerMessageId, 'last_error' => null,
                ]);
            } else {
                $this->recordFailure($delivery, $result->error ?? 'Provider rejected the message.', $scheduleRetry);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($delivery, $exception->getMessage(), $scheduleRetry);
        }

        $this->refreshEventStatus($delivery);
    }

    private function recordFailure(NotificationDelivery $delivery, string $error, bool $scheduleRetry): void
    {
        $terminal = $delivery->attempt_count >= $delivery->max_attempts;
        $delayMinutes = 2 ** max(0, $delivery->attempt_count - 1);
        $nextAttempt = $terminal ? null : now()->addMinutes($delayMinutes);
        $delivery->update([
            'status' => $terminal ? 'failed' : 'retrying',
            'next_attempt_at' => $nextAttempt,
            'last_error' => mb_substr(strip_tags($error), 0, 1000),
        ]);

        if (! $terminal && $scheduleRetry) {
            DeliverNotification::dispatch($delivery->id)->delay($nextAttempt);
        }
    }

    private function refreshEventStatus(NotificationDelivery $delivery): void
    {
        $event = $delivery->recipient->event;
        $statuses = DB::table('notification_deliveries')
            ->join('notification_recipients', 'notification_recipients.id', '=', 'notification_deliveries.notification_recipient_id')
            ->where('notification_recipients.notification_event_id', $event->id)
            ->pluck('notification_deliveries.status');

        $status = $statuses->contains(fn ($status) => in_array($status, ['pending', 'sending', 'retrying'], true))
            ? 'processing'
            : ($statuses->contains('failed') ? 'completed_with_errors' : 'completed');
        $event->update(['status' => $status]);
    }
}
