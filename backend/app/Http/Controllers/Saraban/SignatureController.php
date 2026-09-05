<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\SignatureEvidence;
use App\Services\Saraban\FinalizationService;
use App\Services\Saraban\SignatureException;
use App\Services\Saraban\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * การลงนามและการเกษียนหนังสือ (SoT §14, §15 · INT-11)
 *
 * **การลงนามผ่านด่าน `step_up` ที่ route** และถูกตรวจซ้ำใน `SignatureService`
 * — สองชั้นโดยตั้งใจ เพราะการลงนามย้อนกลับไม่ได้ การพึ่งด่านเดียวแปลว่า
 * การต่อ route ผิดพลาดครั้งเดียวก็เปิดช่องได้
 *
 * **การเกษียนไม่ต้องยืนยันตัวตนซ้ำ** เพราะ SoT §15 ระบุว่าลายมือ ≠ ลายเซ็น
 * การให้ความเห็นไม่ใช่การรับรองเอกสารในทางกฎหมาย
 */
class SignatureController extends Controller
{
    public function __construct(private readonly SignatureService $signatures) {}

    public function sign(Request $request, Document $document)
    {
        $this->authorize('sign', $document);

        $data = $request->validate([
            'page' => ['required', 'integer', 'min:1'],
            'x' => ['required', 'numeric', 'min:0', 'max:5000'],
            'y' => ['required', 'numeric', 'min:0', 'max:5000'],
            'width' => ['nullable', 'numeric', 'min:1', 'max:5000'],
            'height' => ['nullable', 'numeric', 'min:1', 'max:5000'],
            'line_width' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            // ชุดเส้นของลายเซ็น — พิกัด ไม่ใช่ภาพ (SoT §14)
            'strokes' => ['required', 'array', 'min:1'],
            'strokes.*' => ['required', 'array', 'min:2'],
            'strokes.*.*.x' => ['required', 'numeric'],
            'strokes.*.*.y' => ['required', 'numeric'],
        ]);

        try {
            $evidence = $this->signatures->sign($document, $data, $request->user(), $request);
        } catch (SignatureException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'evidence' => $evidence->only([
                'id', 'uuid', 'signer_name_snapshot', 'position_snapshot',
                'org_unit_snapshot', 'signed_at', 'signature_method', 'document_hash',
            ]),
        ], 201);
    }

    /** เกษียนหนังสือ — ลายมือ ข้อความ หรือเครื่องหมายถูก (SoT §15) */
    public function endorse(Request $request, Document $document)
    {
        $this->authorize('endorse', $document);

        $data = $request->validate([
            'objects' => ['required', 'array', 'min:1'],
            'objects.*.page' => ['required', 'integer', 'min:1'],
            'objects.*.type' => ['required', 'string', Rule::in([
                DocumentAnnotation::TYPE_HANDWRITING,
                DocumentAnnotation::TYPE_NOTE,
                DocumentAnnotation::TYPE_CHECKMARK,
                DocumentAnnotation::TYPE_DATE,
            ])],
            'objects.*.x' => ['required', 'numeric', 'min:0', 'max:5000'],
            'objects.*.y' => ['required', 'numeric', 'min:0', 'max:5000'],
            'objects.*.width' => ['nullable', 'numeric', 'min:1', 'max:5000'],
            'objects.*.height' => ['nullable', 'numeric', 'min:1', 'max:5000'],
            'objects.*.payload' => ['nullable', 'array'],
        ]);

        try {
            $version = $this->signatures->endorse($document, $data['objects'], $request->user());
        } catch (SignatureException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'version' => $version->only(['id', 'version_no', 'stage', 'pdf_file_path', 'file_hash']),
        ], 201);
    }

    /**
     * ออกหนังสือฉบับสมบูรณ์พร้อม QR สำหรับตรวจสอบ (SoT §8, §12)
     *
     * ต้องลงนามเรียบร้อยก่อน และทำได้ครั้งเดียว
     */
    public function finalize(Request $request, Document $document, FinalizationService $finalization)
    {
        $this->authorize('finalize', $document);

        $placement = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'x' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'y' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'size' => ['nullable', 'numeric', 'min:36', 'max:200'],
        ]);

        try {
            $version = $finalization->finalize($document, $placement, $request->user());
        } catch (SignatureException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'version' => $version->only(['id', 'version_no', 'stage', 'pdf_file_path', 'file_hash']),
            'verification_hash' => $document->fresh()->verification_hash,
        ], 201);
    }

    /** หลักฐานการลงนามของหนังสือฉบับนี้ พร้อมผลตรวจความสมบูรณ์ของไฟล์ */
    public function evidence(Document $document)
    {
        $this->authorize('view', $document);

        return response()->json([
            'evidences' => SignatureEvidence::query()
                ->where('document_id', $document->id)
                ->with('version:id,version_no,stage')
                ->orderBy('signed_at')
                ->get()
                ->map(fn (SignatureEvidence $evidence) => [
                    ...$evidence->only([
                        'id', 'uuid', 'signer_name_snapshot', 'position_snapshot',
                        'org_unit_snapshot', 'authority_snapshot', 'signed_at',
                        'signature_method', 'document_hash', 'reauthenticated_at',
                    ]),
                    'version_no' => $evidence->version?->version_no,
                    'file_intact' => $this->signatures->evidenceIsIntact($evidence),
                ]),
        ]);
    }
}
