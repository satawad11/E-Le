<?php

namespace App\Http\Controllers;

use App\Models\NotificationCenter\NotificationPreference;
use App\Models\NotificationCenter\NotificationRecipient;
use App\Models\NotificationCenter\NotificationType;
use App\Services\AuditLogger;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\NotificationCenter\TemplateRenderer;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private TemplateRenderer $renderer, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $centerNotifications = NotificationRecipient::query()
            ->where('user_id', $request->user()->id)
            ->with(['event.type', 'event.recipients', 'deliveries.template'])
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(function (NotificationRecipient $recipient): array {
                $delivery = $recipient->deliveries->firstWhere('channel', 'in_app') ?? $recipient->deliveries->first();
                $message = $delivery?->template
                    ? $this->renderer->render($delivery->template, $recipient->event)
                    : null;

                return [
                    'id' => $recipient->uuid,
                    'type' => $recipient->event->type->code,
                    'data' => [
                        'title' => $message?->subject ?? $recipient->event->type->name,
                        'message' => $message?->body ?? $recipient->event->type->name,
                        'related_type' => $recipient->event->related_type,
                        'related_id' => $recipient->event->related_id,
                    ],
                    'read_at' => $recipient->read_at?->toISOString(),
                    'created_at' => $recipient->created_at?->toISOString(),
                ];
            });
        $legacyNotifications = $request->user()->notifications()->latest()->limit(20)->get()->map(
            fn ($notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at?->toISOString(),
            ]
        );
        $notifications = $centerNotifications->concat($legacyNotifications)
            ->sortByDesc('created_at')->take(20)->values();

        return response()->json($notifications);
    }

    public function markAsRead(Request $request, string $id)
    {
        $notification = NotificationRecipient::query()
            ->where('uuid', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if ($notification) {
            $notification->update(['read_at' => $notification->read_at ?? now()]);
        } else {
            $legacy = $request->user()->notifications()->where('id', $id)->firstOrFail();
            $legacy->markAsRead();
        }

        return response()->json(['message' => 'ทำเครื่องหมายว่าอ่านแล้ว']);
    }

    public function markAllAsRead(Request $request)
    {
        NotificationRecipient::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'ทำเครื่องหมายว่าอ่านแล้วทั้งหมด']);
    }

    public function preferences(Request $request)
    {
        $preferences = NotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->with('type:id,code,name')
            ->get();

        return response()->json([
            'channels' => NotificationCenter::CHANNELS,
            'types' => NotificationType::query()->where('is_active', true)->get(['id', 'code', 'name', 'is_mandatory']),
            'preferences' => $preferences,
        ]);
    }

    public function updatePreference(Request $request)
    {
        $validated = $request->validate([
            'type_code' => ['nullable', 'string', 'exists:notification_types,code'],
            'channel' => ['required', 'string', 'in:'.implode(',', NotificationCenter::CHANNELS)],
            'enabled' => ['required', 'boolean'],
        ]);
        $type = filled($validated['type_code'] ?? null)
            ? NotificationType::query()->where('code', $validated['type_code'])->firstOrFail()
            : null;

        if ($type?->is_mandatory && $validated['channel'] === 'in_app' && ! $validated['enabled']) {
            return response()->json(['message' => 'การแจ้งเตือนในระบบประเภทนี้เป็นการแจ้งเตือนบังคับ จึงปิดไม่ได้'], 422);
        }

        $preference = NotificationPreference::query()->updateOrCreate([
            'user_id' => $request->user()->id,
            'notification_type_id' => $type?->id,
            'channel' => $validated['channel'],
        ], ['enabled' => $validated['enabled']]);

        $this->audit->log('notification.preference.updated', $preference, null, null, [
            'type_code' => $type?->code,
            'channel' => $preference->channel,
            'enabled' => $preference->enabled,
        ]);

        return response()->json($preference);
    }
}
