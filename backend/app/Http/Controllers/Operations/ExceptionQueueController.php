<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\SystemException;
use App\Models\User;
use App\Services\Operations\ExceptionQueue;
use App\Services\Operations\ExceptionQueueException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * คิวข้อผิดพลาดที่ต้องมีคนตามแก้ (SoT §19)
 *
 * การกระทำที่ SoT §19 กำหนด: **assign / retry / resolve / ack** — ครบทั้งสี่
 * และทุกข้อลง audit กลาง
 *
 * **แยกสิทธิ์ดูออกจากสิทธิ์แก้** — `system.operations.view` สำหรับดู
 * และ `system.operations.retry` สำหรับสั่งทำซ้ำ/มอบหมาย/ปิดเรื่อง
 * ผู้บริหารที่ต้องเห็นสถานะไม่จำเป็นต้องมีสิทธิ์ไปกดสั่งงานระบบ
 */
class ExceptionQueueController extends Controller
{
    public function __construct(private readonly ExceptionQueue $queue) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                SystemException::STATUS_OPEN,
                SystemException::STATUS_ACKNOWLEDGED,
                SystemException::STATUS_ASSIGNED,
                SystemException::STATUS_RESOLVED,
            ])],
            'severity' => ['nullable', 'string', Rule::in(SystemException::severities())],
            'type' => ['nullable', 'string', 'max:80'],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = SystemException::query()
            ->with('assignee:id,name')
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            // ไม่ระบุสถานะ = ดูเฉพาะที่ยังค้าง เพราะนั่นคือสิ่งที่ต้องทำอะไรกับมัน
            ->when(! isset($filters['status']), fn ($query) => $query->where('status', '!=', SystemException::STATUS_RESOLVED))
            ->when(isset($filters['severity']), fn ($query) => $query->where('severity', $filters['severity']))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(
                filter_var($filters['mine'] ?? false, FILTER_VALIDATE_BOOL),
                fn ($query) => $query->where('assigned_to', $request->user()->id),
            )
            /*
             * เรียงตามความรุนแรงก่อนเวลา — รายการวิกฤตที่เกิดเมื่อวานสำคัญกว่า
             * คำเตือนที่เพิ่งเกิดเมื่อนาทีที่แล้ว
             */
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('last_seen_at')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => collect($items->items())->map(fn (SystemException $item) => $this->present($item)),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
            'open_total' => SystemException::query()->where('status', '!=', SystemException::STATUS_RESOLVED)->count(),
        ]);
    }

    public function acknowledge(Request $request, SystemException $exception)
    {
        return response()->json([
            'exception' => $this->present($this->queue->acknowledge($exception, $request->user())),
        ]);
    }

    public function assign(Request $request, SystemException $exception)
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        return response()->json([
            'exception' => $this->present($this->queue->assign(
                $exception,
                User::query()->findOrFail($data['user_id']),
                $request->user(),
            )),
        ]);
    }

    public function resolve(Request $request, SystemException $exception)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json([
            'exception' => $this->present($this->queue->resolve($exception, $request->user(), $data['note'] ?? null)),
        ]);
    }

    /**
     * สั่งทำงานที่ล้มเหลวซ้ำ
     *
     * **ไม่ปิดรายการให้เอง** — การสั่งซ้ำไม่ได้แปลว่าสำเร็จ · ผู้ใช้ต้องกลับมา
     * ปิดเองเมื่อยืนยันแล้วว่าเรียบร้อย ซึ่งเป็นการตัดสินใจของคน
     */
    public function retry(Request $request, SystemException $exception)
    {
        try {
            $this->queue->retry($exception, $request->user());
        } catch (ExceptionQueueException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['message' => 'สั่งทำงานซ้ำแล้ว กรุณาตรวจสอบผลอีกครั้งก่อนปิดรายการ']);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(SystemException $exception): array
    {
        return [
            ...$exception->only([
                'id', 'uuid', 'type', 'severity', 'module', 'title', 'detail',
                'status', 'occurrence_count', 'resolution_note', 'correlation_id',
            ]),
            'assigned_to' => $exception->assignee?->name,
            'can_retry' => $exception->retry_type !== null,
            'first_seen_at' => $exception->first_seen_at?->toIso8601String(),
            'last_seen_at' => $exception->last_seen_at?->toIso8601String(),
            'acknowledged_at' => $exception->acknowledged_at?->toIso8601String(),
            'resolved_at' => $exception->resolved_at?->toIso8601String(),
        ];
    }
}
