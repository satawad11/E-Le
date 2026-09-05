<?php

namespace App\Services\NotificationCenter;

use App\Jobs\DeliverNotification;
use App\Models\NotificationCenter\NotificationDelivery;
use App\Models\NotificationCenter\NotificationEvent;
use App\Models\NotificationCenter\NotificationPreference;
use App\Models\NotificationCenter\NotificationRecipient;
use App\Models\NotificationCenter\NotificationTemplate;
use App\Models\NotificationCenter\NotificationType;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NotificationCenter
{
    /*
     * INT-14 — เพิ่ม `web_push` (SoT §16)
     *
     * ยังไม่มี `mobile_push` ในรายการนี้โดยตั้งใจ · SoT ระบุว่า "MOBILE_PUSH_READY"
     * คือ *พร้อมรองรับ* ไม่ใช่ *ต้องมี* — สัญญา `NotificationChannelAdapter`
     * รับช่องทางใหม่ได้โดยไม่ต้องแก้ศูนย์แจ้งเตือน การใส่ชื่อช่องทางที่ยังไม่มี
     * adapter จะสร้างแถวสถานะ `skipped` ให้ผู้รับทุกคนทุกครั้งโดยไม่มีประโยชน์
     */
    public const CHANNELS = ['in_app', 'email', 'line_oa', 'telegram', 'web_push'];

    public function __construct(
        private NotificationPayloadSanitizer $sanitizer,
        private ModuleRegistry $modules,
    ) {}

    /**
     * Business modules emit a domain event here and never call a provider directly.
     *
     * @param  iterable<User|int>  $recipients
     */
    public function emit(
        string $typeCode,
        iterable $recipients,
        ?Model $related = null,
        array $context = [],
        ?User $actor = null,
    ): ?NotificationEvent {
        if (! $this->modules->isEnabled('notification')) {
            return null;
        }

        $type = NotificationType::query()->where('code', $typeCode)->where('is_active', true)->first();
        if (! $type) {
            throw new InvalidArgumentException("Unknown or inactive notification type: {$typeCode}");
        }

        $users = $this->resolveUsers($recipients);

        $event = DB::transaction(function () use ($type, $users, $related, $context, $actor): NotificationEvent {
            $event = NotificationEvent::query()->create([
                'notification_type_id' => $type->id,
                'actor_id' => $actor?->id,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
                'payload' => $this->sanitizer->sanitize($context),
                'status' => 'queued',
                'occurred_at' => now(),
            ]);

            foreach ($users as $user) {
                $recipient = NotificationRecipient::query()->create([
                    'notification_event_id' => $event->id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                ]);

                foreach ($this->channelsFor($type, $user) as $channel => $enabled) {
                    $template = $this->activeTemplate($type, $channel);
                    NotificationDelivery::query()->create([
                        'notification_recipient_id' => $recipient->id,
                        'notification_template_id' => $template?->id,
                        'channel' => $channel,
                        'status' => $enabled && $template ? 'pending' : 'skipped',
                        'last_error' => $enabled && ! $template ? 'No active template configured.' : ($enabled ? null : 'Disabled by user preference.'),
                    ]);
                }
            }

            return $event;
        });

        $event->recipients()->with('deliveries')->get()->flatMap->deliveries
            ->where('status', 'pending')
            ->each(fn (NotificationDelivery $delivery) => DeliverNotification::dispatch($delivery->id)->afterCommit());

        return $event->fresh(['type', 'recipients.deliveries']);
    }

    private function resolveUsers(iterable $recipients): Collection
    {
        $items = collect($recipients);
        $ids = $items->map(fn ($recipient) => $recipient instanceof User ? $recipient->id : $recipient)
            ->filter()->unique()->values();

        return User::query()->whereIn('id', $ids)->where('is_active', true)->get();
    }

    /** @return array<string, bool> */
    private function channelsFor(NotificationType $type, User $user): array
    {
        $defaults = collect($type->default_channels)->intersect(self::CHANNELS)->values();
        $preferences = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('notification_type_id')->orWhere('notification_type_id', $type->id))
            ->orderByRaw('notification_type_id IS NULL DESC')
            ->get();

        $channels = $defaults->merge($preferences->where('enabled', true)->pluck('channel'))
            ->intersect(self::CHANNELS)->unique();

        return $channels->mapWithKeys(function (string $channel) use ($defaults, $preferences, $type): array {
            $preference = $preferences->where('channel', $channel)->last();
            $enabled = $preference ? $preference->enabled : $defaults->contains($channel);
            if ($type->is_mandatory && $channel === 'in_app') {
                $enabled = true;
            }

            return [$channel => $enabled];
        })->all();
    }

    private function activeTemplate(NotificationType $type, string $channel): ?NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('notification_type_id', $type->id)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->latest('version')
            ->first();
    }
}
