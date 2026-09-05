<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use App\Services\Webhooks\WebhookCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * จัดการปลายทาง webhook และติดตามผลการส่ง (SoT §17)
 *
 * อยู่ใต้ `system.setting.manage` เช่นเดียวกับการตั้งค่าช่องทางแจ้งเตือน —
 * การเปิดทางให้ข้อมูลไหลออกนอกระบบเป็นการตัดสินใจระดับผู้ดูแลระบบ
 * ไม่ใช่ระดับผู้ใช้งานโมดูล
 *
 * **ความลับสำหรับเซ็นแสดงครั้งเดียวตอนสร้าง** — หลังจากนั้นไม่มีเส้นทางใดอ่านได้
 * ผู้ดูแลที่ทำหายต้องสร้างใหม่ ซึ่งปลอดภัยกว่าการเก็บไว้ให้อ่านซ้ำได้ตลอด
 */
class WebhookEndpointController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index()
    {
        return response()->json([
            'endpoints' => WebhookEndpoint::query()
                ->withCount([
                    'deliveries as dead_letter_count' => fn ($query) => $query->whereNotNull('dead_lettered_at'),
                ])
                ->orderBy('name')
                ->get(['id', 'uuid', 'name', 'url', 'events', 'is_active', 'created_at']),
            'available_events' => WebhookCatalogue::EVENTS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],

            /*
             * บังคับ HTTPS — payload มีสาระสำคัญของหนังสือราชการ การส่งผ่าน
             * http ธรรมดาเท่ากับเปิดเผยเนื้อหาให้ทุกจุดที่ข้อมูลวิ่งผ่าน
             */
            'url' => ['required', 'url:https', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(WebhookCatalogue::EVENTS)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $secret = Str::random(48);

        $endpoint = WebhookEndpoint::create([
            ...$data,
            'secret' => $secret,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->log('webhook.endpoint.created', $endpoint, $endpoint->id, null, [
            'name' => $endpoint->name,
            'events' => $endpoint->events,
        ], null, $request->user()->id);

        return response()->json([
            'endpoint' => $endpoint->only(['id', 'uuid', 'name', 'url', 'events', 'is_active']),
            // แสดงครั้งเดียว — ไม่มีเส้นทางใดอ่านซ้ำได้อีก
            'secret' => $secret,
        ], 201);
    }

    public function update(Request $request, WebhookEndpoint $endpoint)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'url' => ['sometimes', 'url:https', 'max:500'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(WebhookCatalogue::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = $endpoint->only(['name', 'url', 'events', 'is_active']);
        $endpoint->update($data);

        $this->audit->log(
            'webhook.endpoint.updated',
            $endpoint,
            $endpoint->id,
            $before,
            $endpoint->only(['name', 'url', 'events', 'is_active']),
            null,
            $request->user()->id,
        );

        return response()->json(['endpoint' => $endpoint->only(['id', 'uuid', 'name', 'url', 'events', 'is_active'])]);
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint)
    {
        $this->audit->log('webhook.endpoint.deleted', $endpoint, $endpoint->id, $endpoint->only(['name', 'url']), null, null, $request->user()->id);

        $endpoint->delete();

        return response()->json(['message' => 'ลบปลายทางแล้ว']);
    }

    /** ประวัติการส่งของปลายทางหนึ่ง — ใช้ตอบว่า "ปลายทางพลาดอะไรไปบ้าง" */
    public function deliveries(Request $request, WebhookEndpoint $endpoint)
    {
        $filters = $request->validate([
            'dead_lettered' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->id)
            ->when(
                filter_var($filters['dead_lettered'] ?? false, FILTER_VALIDATE_BOOL),
                fn ($query) => $query->whereNotNull('dead_lettered_at'),
            )
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => collect($items->items())->map(fn (WebhookDelivery $delivery) => $delivery->only([
                'id', 'uuid', 'event', 'status', 'attempt_count', 'response_status',
                'last_error', 'sent_at', 'dead_lettered_at', 'correlation_id', 'created_at',
            ])),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /** สั่งส่งใหม่ — กติกาเดียวกับ dead-letter ของศูนย์แจ้งเตือน (INT-14) */
    public function requeue(Request $request, WebhookDelivery $delivery)
    {
        abort_if($delivery->dead_lettered_at === null, 422, 'รายการนี้ไม่ได้อยู่ใน dead-letter');

        $delivery->forceFill([
            'status' => 'pending',
            'attempt_count' => 0,
            'next_attempt_at' => null,
            'dead_lettered_at' => null,
        ])->save();

        DeliverWebhook::dispatch($delivery->id);

        $this->audit->log('webhook.delivery.requeued', $delivery, $delivery->id, null, [
            'event' => $delivery->event,
        ], null, $request->user()->id);

        return response()->json(['message' => 'ส่งเข้าคิวใหม่แล้ว']);
    }
}
