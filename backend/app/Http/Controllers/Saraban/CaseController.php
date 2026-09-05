<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentCase;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * สารบบเรื่อง — จัดกลุ่มหนังสือเชิงตรรกะ (SoT §22)
 *
 * **ไม่เปลี่ยนตัวตนของหนังสือ** — ไม่มี endpoint ใดในไฟล์นี้แก้ `saraban_documents`
 * แม้แต่คอลัมน์เดียว · การจัดกลุ่มเป็นชั้นบนที่ถอดออกได้เสมอ
 *
 * **การเพิ่มหนังสือเข้าสารบบต้องเห็นหนังสือนั้นได้ก่อน** — ไม่งั้นการจัดกลุ่ม
 * จะกลายเป็นช่องทางค้นพบว่ามีหนังสือฉบับใดอยู่ในระบบบ้าง
 */
class CaseController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:open,closed'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'cases' => DocumentCase::query()
                ->withCount('documents')
                ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
                ->when(
                    isset($filters['q']),
                    fn ($query) => $query->where(fn ($search) => $search
                        ->where('title', 'like', '%'.$filters['q'].'%')
                        ->orWhere('code', 'like', '%'.$filters['q'].'%')),
                )
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(['id', 'uuid', 'code', 'title', 'status', 'created_at']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'unique:saraban_cases,code'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'owner_org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
        ]);

        $case = DocumentCase::create([...$data, 'created_by' => $request->user()->id]);

        $this->audit->log('saraban.case.created', $case, $case->id, null, [
            'code' => $case->code,
            'title' => $case->title,
        ], null, $request->user()->id);

        return response()->json(['case' => $case->only(['id', 'uuid', 'code', 'title', 'status'])], 201);
    }

    public function show(Request $request, DocumentCase $case)
    {
        $this->authorize('view', $case);

        $case->load(['documents' => fn ($query) => $query->with('documentNumber:id,formatted')]);

        /*
         * กรองด้วย policy รายฉบับ — สารบบเรื่องหนึ่งอาจมีหนังสือที่ผู้เปิดดู
         * ไม่มีสิทธิ์เห็นบางฉบับ · แสดงเฉพาะที่เห็นได้ พร้อมบอกจำนวนที่ถูกซ่อน
         * เพื่อไม่ให้เข้าใจผิดว่าสารบบมีแค่นี้
         */
        $visible = $case->documents->filter(
            fn (Document $document) => $request->user()->can('view', $document),
        );

        return response()->json([
            'case' => $case->only(['id', 'uuid', 'code', 'title', 'summary', 'status', 'closed_at']),
            'documents' => $visible->map(fn (Document $document) => [
                ...$document->only(['id', 'uuid', 'subject', 'direction', 'status']),
                'document_number' => $document->documentNumber?->formatted,
                'note' => $document->pivot->note,
            ])->values(),
            'hidden_count' => $case->documents->count() - $visible->count(),
        ]);
    }

    public function attach(Request $request, DocumentCase $case)
    {
        $this->authorize('update', $case);

        abort_unless($case->isOpen(), 422, 'สารบบเรื่องนี้ปิดแล้ว เพิ่มหนังสือใหม่ไม่ได้');

        $data = $request->validate([
            'document_id' => ['required', 'integer', 'exists:saraban_documents,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $document = Document::query()->findOrFail($data['document_id']);
        $this->authorize('view', $document);

        DB::transaction(function () use ($case, $document, $data, $request) {
            $case->documents()->syncWithoutDetaching([
                $document->id => ['note' => $data['note'] ?? null, 'added_by' => $request->user()->id],
            ]);

            $this->audit->log('saraban.case.document_attached', $case, $case->id, null, [
                'document_id' => $document->id,
                'case_code' => $case->code,
            ], null, $request->user()->id);
        });

        return response()->json(['message' => 'เพิ่มหนังสือเข้าสารบบแล้ว'], 201);
    }

    public function detach(Request $request, DocumentCase $case, Document $document)
    {
        $this->authorize('update', $case);

        $case->documents()->detach($document->id);

        $this->audit->log('saraban.case.document_detached', $case, $case->id, null, [
            'document_id' => $document->id,
        ], null, $request->user()->id);

        return response()->json(['message' => 'ถอดหนังสือออกจากสารบบแล้ว']);
    }

    /** ปิดสารบบ — ยังอ่านได้ แต่เพิ่มหนังสือใหม่ไม่ได้ */
    public function close(Request $request, DocumentCase $case)
    {
        $this->authorize('update', $case);

        $case->forceFill(['status' => DocumentCase::STATUS_CLOSED, 'closed_at' => $this->clock->now()])->save();

        $this->audit->log('saraban.case.closed', $case, $case->id, null, ['code' => $case->code], null, $request->user()->id);

        return response()->json(['case' => $case->only(['id', 'uuid', 'code', 'status'])]);
    }
}
