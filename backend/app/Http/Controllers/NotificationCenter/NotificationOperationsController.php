<?php

namespace App\Http\Controllers\NotificationCenter;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverNotification;
use App\Models\NotificationCenter\NotificationDelivery;
use App\Services\AuditLogger;
use App\Services\NotificationCenter\ChannelAdapterRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * dead-letter และสุขภาพช่องทางส่ง (SoT §16 · INT-14)
 *
 * เป็นฐานของศูนย์เฝ้าระวังการปฏิบัติการที่ SoT §20 กำหนดไว้ที่ INT-17 —
 * รอบนี้ทำเฉพาะส่วนที่ §16 บังคับ คือ **มองเห็น dead-letter ได้และสั่งส่งใหม่ได้**
 *
 * ใช้สิทธิ์ `system.setting.manage` ซึ่งเป็นเจ้าของการตั้งค่าช่องทางแจ้งเตือน
 * อยู่แล้ว — ไม่สร้าง namespace สิทธิ์ใหม่ให้ระบบต้องดูแลเพิ่ม
 */
class NotificationOperationsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** ข้อความที่ส่งไม่สำเร็จจนครบจำนวนครั้ง */
    public function deadLetters(Request $request)
    {
        $filters = $request->validate([
            'channel' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = NotificationDelivery::query()
            ->whereNotNull('dead_lettered_at')
            ->with(['recipient.user:id,name', 'recipient.event.type:id,code,name'])
            ->when(isset($filters['channel']), fn ($query) => $query->where('channel', $filters['channel']))
            ->orderByDesc('dead_lettered_at')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => collect($items->items())->map(fn (NotificationDelivery $delivery) => [
                ...$delivery->only(['id', 'uuid', 'channel', 'status', 'attempt_count', 'last_error', 'dead_lettered_at']),
                'recipient' => $delivery->recipient?->user?->name,
                'type' => $delivery->recipient?->event?->type?->code,
            ]),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * สั่งส่งข้อความที่ตายแล้วใหม่
     *
     * รีเซ็ตจำนวนครั้งกลับเป็นศูนย์ เพราะการสั่งซ้ำโดยผู้ดูแลเป็นการตัดสินใจ
     * ของคน หลังแก้ต้นเหตุแล้ว ไม่ใช่การ retry อัตโนมัติของรอบเดิม
     */
    public function requeue(Request $request, NotificationDelivery $delivery)
    {
        abort_if($delivery->dead_lettered_at === null, 422, 'ข้อความนี้ไม่ได้อยู่ใน dead-letter');

        $delivery->forceFill([
            'status' => 'pending',
            'attempt_count' => 0,
            'next_attempt_at' => null,
            'dead_lettered_at' => null,
        ])->save();

        DeliverNotification::dispatch($delivery->id);

        $this->audit->log('notification.dead_letter.requeued', $delivery, $delivery->id, null, [
            'channel' => $delivery->channel,
        ], null, $request->user()->id);

        return response()->json(['message' => 'ส่งข้อความเข้าคิวใหม่แล้ว']);
    }

    /**
     * สรุปสุขภาพของแต่ละช่องทาง
     *
     * นับจากผลการส่งจริงใน 24 ชั่วโมงที่ผ่านมา ไม่ใช่จากการ ping ผู้ให้บริการ
     * — ช่องทางที่ ping ผ่านแต่ปฏิเสธข้อความจริงทุกฉบับคือช่องทางที่เสีย
     */
    public function channelHealth(ChannelAdapterRegistry $adapters)
    {
        $since = now()->subDay();

        $counts = DB::table('notification_deliveries')
            ->where('updated_at', '>=', $since)
            ->select('channel', 'status', DB::raw('count(*) as total'))
            ->groupBy('channel', 'status')
            ->get();

        return response()->json([
            'window_hours' => 24,
            'channels' => collect(array_keys($adapters->all()))->map(function (string $channel) use ($counts) {
                $rows = $counts->where('channel', $channel);

                return [
                    'channel' => $channel,
                    'sent' => (int) $rows->where('status', 'sent')->sum('total'),
                    'failed' => (int) $rows->where('status', 'failed')->sum('total'),
                    'pending' => (int) $rows->whereIn('status', ['pending', 'retrying', 'sending'])->sum('total'),
                    'skipped' => (int) $rows->where('status', 'skipped')->sum('total'),
                ];
            })->values(),
            'dead_letters' => NotificationDelivery::query()->whereNotNull('dead_lettered_at')->count(),
        ]);
    }
}
