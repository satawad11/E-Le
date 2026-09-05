<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\Redaction;
use App\Services\Saraban\RedactionException;
use App\Services\Saraban\RedactionService;
use Illuminate\Http\Request;

/**
 * สำเนาที่ปกปิดข้อมูล (SoT §22)
 *
 * **การปกปิดเป็นการกระทำที่อ่อนไหว** — ผลลัพธ์คือไฟล์ที่ถูกสร้างขึ้นเพื่อส่งออก
 * นอกระบบ · ถ้าปกปิดพลาด ข้อมูลลับหลุดทันทีและเรียกคืนไม่ได้
 *
 * ใช้สิทธิ์เดียวกับการลงนาม (`saraban.signature.sign`) เพราะเป็นการรับรองว่า
 * สำเนานี้ปล่อยออกไปได้ ซึ่งเป็นอำนาจระดับเดียวกัน
 */
class RedactionController extends Controller
{
    public function __construct(private readonly RedactionService $redactions) {}

    public function index(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        return response()->json([
            'available' => $this->redactions->available(),
            'redactions' => Redaction::query()
                ->where('document_id', $document->id)
                ->with('creator:id,name')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Redaction $redaction) => [
                    ...$redaction->only(['id', 'uuid', 'reason', 'pdf_file_path', 'file_hash']),
                    'source_version_id' => $redaction->source_version_id,
                    'area_count' => count($redaction->areas ?? []),
                    'created_by' => $redaction->creator?->name,
                    'created_at' => $redaction->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request, Document $document)
    {
        $this->authorize('sign', $document);

        $data = $request->validate([
            'source_version_id' => ['required', 'integer', 'exists:saraban_document_versions,id'],
            // เหตุผลบังคับ — การปกปิดโดยไม่ระบุเหตุผลตรวจสอบย้อนหลังไม่ได้
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'areas' => ['required', 'array', 'min:1', 'max:200'],
            'areas.*.page' => ['required', 'integer', 'min:1'],
            'areas.*.x' => ['required', 'numeric', 'min:0', 'max:5000'],
            'areas.*.y' => ['required', 'numeric', 'min:0', 'max:5000'],
            'areas.*.width' => ['required', 'numeric', 'min:1', 'max:5000'],
            'areas.*.height' => ['required', 'numeric', 'min:1', 'max:5000'],
        ]);

        $version = DocumentVersion::query()->findOrFail($data['source_version_id']);

        // เวอร์ชันต้องเป็นของหนังสือฉบับนี้ — ไม่งั้นเป็นช่องอ่านไฟล์ข้ามฉบับ
        abort_unless((int) $version->document_id === (int) $document->id, 422, 'เวอร์ชันนี้ไม่ใช่ของหนังสือฉบับนี้');

        try {
            $redaction = $this->redactions->redact($document, $version, $data['areas'], $data['reason'], $request->user());
        } catch (RedactionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'redaction' => [
                ...$redaction->only(['id', 'uuid', 'reason', 'pdf_file_path', 'file_hash']),
                'area_count' => count($redaction->areas),
            ],
        ], 201);
    }
}
