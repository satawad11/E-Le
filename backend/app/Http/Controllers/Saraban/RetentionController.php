<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\LegalHold;
use App\Models\Saraban\RetentionSchedule;
use App\Services\Saraban\RetentionException;
use App\Services\Saraban\RetentionService;
use Illuminate\Http\Request;

/**
 * การเก็บรักษา การอายัด และการทำลายเอกสาร (SoT §25)
 *
 * **การทำลายเอกสารราชการย้อนกลับไม่ได้** — จึงต้องผ่านด่านครบทุกชั้น:
 * สิทธิ์เฉพาะ + `step_up` + การอนุมัติที่แยกจากการลงมือทำ + ไม่ถูกอายัด
 *
 * **การอนุมัติกับการลงมือทำลายเป็นคนละ endpoint โดยตั้งใจ** — เพื่อให้
 * แยกหน้าที่กันได้จริงตาม SoT §26 (separation of duties)
 */
class RetentionController extends Controller
{
    public function __construct(private readonly RetentionService $retention) {}

    /** สถานะการเก็บรักษาและการอายัดของหนังสือฉบับหนึ่ง */
    public function show(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        return response()->json([
            'on_hold' => $this->retention->isOnHold($document),
            'holds' => LegalHold::query()
                ->where('document_id', $document->id)
                ->with('placer:id,name')
                ->orderByDesc('placed_at')
                ->get()
                ->map(fn (LegalHold $hold) => [
                    ...$hold->only(['id', 'uuid', 'reference', 'reason', 'release_note']),
                    'active' => $hold->isActive(),
                    'placed_at' => $hold->placed_at?->toIso8601String(),
                    'placed_by' => $hold->placer?->name,
                    'released_at' => $hold->released_at?->toIso8601String(),
                ]),
            'schedule' => RetentionSchedule::query()
                ->where('document_id', $document->id)
                ->first()
                ?->only(['id', 'retain_years', 'retain_until', 'status', 'disposed_at', 'disposed_file_hash']),
        ]);
    }

    public function placeHold(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $hold = $this->retention->placeHold($document, $data['reference'], $data['reason'], $request->user());
        } catch (RetentionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['hold' => $hold->only(['id', 'uuid', 'reference', 'reason'])], 201);
    }

    public function releaseHold(Request $request, LegalHold $hold)
    {
        $this->authorize('view', $hold->document);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->retention->releaseHold($hold, $request->user(), $data['note'] ?? null);
        } catch (RetentionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'ปลดการอายัดแล้ว']);
    }

    public function schedule(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        $data = $request->validate([
            // 1-100 ปี — ระเบียบราชการมีทั้งเก็บ 1 ปีและเก็บถาวรระดับหลายสิบปี
            'retain_years' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $schedule = $this->retention->schedule($document, $data['retain_years'], $request->user());

        return response()->json([
            'schedule' => [
                ...$schedule->only(['id', 'retain_years', 'status']),
                'retain_until' => $schedule->retain_until->toDateString(),
            ],
        ], 201);
    }

    /**
     * คิวเอกสารที่ครบกำหนดแล้ว — ไม่รวมที่ถูกอายัด และ**ไม่รวมที่ผู้เรียกไม่มีสิทธิ์เห็น**
     *
     * สิทธิ์ `saraban.config.manage` บอกว่า "ทำงานทะเบียนได้" ไม่ได้แปลว่า
     * "เห็นหนังสือของทุกหน่วยงาน" — คิวที่ไม่กรองคือช่องทางค้นพบหนังสือนอกขอบเขต
     */
    public function dueForDisposal(Request $request)
    {
        return response()->json([
            'due' => $this->retention->dueForDisposal($request->user()),
        ]);
    }

    public function approveDisposal(Request $request, RetentionSchedule $schedule)
    {
        $this->authorizeDocumentOf($schedule);

        try {
            $this->retention->approveDisposal($schedule, $request->user());
        } catch (RetentionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'อนุมัติให้ทำลายเมื่อครบกำหนดแล้ว']);
    }

    public function dispose(Request $request, RetentionSchedule $schedule)
    {
        $this->authorizeDocumentOf($schedule);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $result = $this->retention->dispose($schedule, $request->user(), $data['note'] ?? null);
        } catch (RetentionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'ทำลายไฟล์ของหนังสือฉบับนี้แล้ว ทะเบียนยังคงอยู่เพื่อการตรวจสอบ',
            'disposed_file_hash' => $result->disposed_file_hash,
        ]);
    }

    /**
     * ด่านรายฉบับของกำหนดการเก็บรักษา
     *
     * **ที่มา:** การตรวจทานเมื่อ 2026-09-05 พบว่า `approveDisposal()` และ
     * `dispose()` ตรวจแค่ `ability:saraban.config.manage` กับ `step_up` —
     * **ไม่มีการตรวจว่าผู้เรียกเห็นหนังสือฉบับนั้นได้ไหมเลย** · ผลคือผู้ที่มี
     * สิทธิ์ทะเบียนสามารถสั่งทำลายไฟล์ของหนังสือที่ตนเปิดอ่านไม่ได้ด้วยซ้ำ
     * ซึ่งเป็นการทำลายที่ย้อนกลับไม่ได้
     */
    private function authorizeDocumentOf(RetentionSchedule $schedule): void
    {
        $document = $schedule->document;

        abort_if($document === null, 404);

        $this->authorize('view', $document);
    }
}
