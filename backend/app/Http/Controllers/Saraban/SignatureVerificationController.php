<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\SignatureEvidence;
use App\Models\Saraban\SignatureRevocation;
use App\Services\Saraban\SignatureException;
use App\Services\Saraban\SignatureRevocationService;
use App\Services\Saraban\SignatureVerificationService;
use App\Services\TrustedClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ตรวจสอบลายเซ็น และวงจรการเพิกถอน (SoT §14, §24)
 *
 * **การตัดสินทุกข้อเกิดที่ฝั่ง server** — endpoint เหล่านี้คืน *ผลที่ตัดสินแล้ว*
 * ไม่ใช่ข้อมูลดิบให้หน้าจอไปคำนวณเอง · หน้าจอที่คำนวณสถานะเองคือหน้าจอที่
 * แก้ผลได้ด้วย devtools
 *
 * ทุก endpoint ผ่าน `DocumentPolicy::view()` ก่อนเสมอ — ผู้ที่เปิดหนังสือไม่ได้
 * ต้องไม่รู้แม้กระทั่งว่าหนังสือฉบับนั้นมีลายเซ็นที่มีปัญหาอยู่
 */
class SignatureVerificationController extends Controller
{
    public function __construct(
        private readonly SignatureVerificationService $verification,
        private readonly SignatureRevocationService $revocations,
        private readonly TrustedClock $clock,
    ) {}

    /** ผลตรวจฉบับเต็มของหนังสือหนึ่งฉบับ */
    public function show(Document $document)
    {
        $this->authorize('view', $document);

        return response()->json($this->verification->verify($document));
    }

    /**
     * ชุดหลักฐานการลงนามฉบับเต็ม พร้อมผลตรวจ (Signature Evidence Package)
     *
     * ใช้แนบประกอบการตรวจสอบหรือการอุทธรณ์ · คืนทุกอย่างที่บันทึกไว้ตอนลงนาม
     * รวม IP และ user agent ซึ่งเป็นข้อมูลภายใน — จึงอยู่หลังด่านสิทธิ์เดียวกับ
     * ตัวหนังสือ ไม่ใช่ในหน้าตรวจสอบสาธารณะ
     */
    public function report(Document $document)
    {
        $this->authorize('view', $document);

        $result = $this->verification->verify($document);

        $evidences = SignatureEvidence::query()
            ->where('document_id', $document->id)
            ->with('version:id,version_no,stage,file_hash,created_by')
            ->orderBy('signed_at')
            ->get();

        return response()->json([
            ...$result,
            'evidence_package' => $evidences->map(fn (SignatureEvidence $evidence) => [
                ...$evidence->only([
                    'id', 'uuid', 'signer_name_snapshot', 'position_snapshot', 'org_unit_snapshot',
                    'authority_snapshot', 'signed_at', 'signature_method', 'signature_type',
                    'signing_sequence', 'document_hash', 'reauthenticated_at', 'ip_address', 'user_agent',
                ]),
                'version' => $evidence->version?->only(['id', 'version_no', 'stage', 'file_hash']),
                /*
                 * ข้อมูลใบรับรองปรากฏ **เฉพาะเมื่อมีจริง** — คีย์นี้เป็น null
                 * เมื่อไม่มีใบรับรอง แทนที่จะเป็นวัตถุที่มีแต่ค่าว่าง ซึ่งอ่านแล้ว
                 * ชวนเข้าใจว่ามีใบรับรองที่ข้อมูลไม่ครบ
                 */
                'certificate' => $evidence->certificate_serial === null ? null : [
                    'serial' => $evidence->certificate_serial,
                    'subject' => $evidence->certificate_subject,
                    'issuer' => $evidence->certificate_issuer,
                    'fingerprint' => $evidence->certificate_fingerprint,
                    'valid_from' => $evidence->certificate_valid_from?->toIso8601String(),
                    'valid_to' => $evidence->certificate_valid_to?->toIso8601String(),
                    'revoked_at' => $evidence->certificate_revoked_at?->toIso8601String(),
                ],
                'trusted_timestamp' => $evidence->timestamped_at === null ? null : [
                    'authority' => $evidence->timestamp_authority,
                    'token_hash' => $evidence->timestamp_token_hash,
                    'timestamped_at' => $evidence->timestamped_at->toIso8601String(),
                ],
            ]),
            'revocations' => SignatureRevocation::query()
                ->where('document_id', $document->id)
                ->with(['requester:id,name', 'decider:id,name'])
                ->orderByDesc('requested_at')
                ->get()
                ->map(fn (SignatureRevocation $revocation) => [
                    ...$revocation->only(['id', 'uuid', 'status', 'reason', 'decision_note']),
                    'requested_by' => $revocation->requester?->name,
                    'requested_at' => $revocation->requested_at?->toIso8601String(),
                    'decided_by' => $revocation->decider?->name,
                    'decided_at' => $revocation->decided_at?->toIso8601String(),
                ]),
            'reported_at' => $this->clock->now()->toIso8601String(),
        ]);
    }

    /** ยื่นคำขอเพิกถอน */
    public function requestRevocation(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        $data = $request->validate([
            // เหตุผลบังคับและต้องยาวพอที่จะอ่านแล้วเข้าใจ — "ผิด" ไม่ใช่เหตุผล
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'signature_evidence_id' => ['nullable', 'integer', 'exists:saraban_signature_evidences,id'],
        ]);

        $evidence = isset($data['signature_evidence_id'])
            ? SignatureEvidence::query()->find($data['signature_evidence_id'])
            : null;

        try {
            $revocation = $this->revocations->request($document, $request->user(), $data['reason'], $evidence);
        } catch (SignatureException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'revocation' => $revocation->only(['id', 'uuid', 'status', 'reason']),
        ], 201);
    }

    /** รายการคำขอเพิกถอนที่รอการตัดสิน — คิวงานของผู้มีอำนาจอนุมัติ */
    public function pending(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'pending' => SignatureRevocation::query()
                ->where('status', SignatureRevocation::STATUS_PENDING)
                ->with(['document.documentNumber:id,formatted', 'requester:id,name'])
                ->orderBy('requested_at')
                ->get()
                /*
                 * กรองด้วย `DocumentPolicy` รายฉบับ — คิวที่ไม่กรองคือช่องทาง
                 * ค้นพบว่ามีหนังสือฉบับใดของหน่วยงานอื่นกำลังมีปัญหา
                 */
                ->filter(fn (SignatureRevocation $revocation) => $revocation->document !== null
                    && $user->can('view', $revocation->document))
                ->values()
                ->map(fn (SignatureRevocation $revocation) => [
                    ...$revocation->only(['id', 'uuid', 'reason']),
                    'document_id' => $revocation->document_id,
                    'document_number' => $revocation->document?->documentNumber?->formatted,
                    'subject' => $revocation->document?->subject,
                    'requested_by' => $revocation->requester?->name,
                    'requested_at' => $revocation->requested_at?->toIso8601String(),
                ]),
        ]);
    }

    /** อนุมัติหรือปฏิเสธคำขอ — คนละการกระทำกับการยื่นคำขอเสมอ */
    public function decide(Request $request, SignatureRevocation $revocation)
    {
        $document = $revocation->document;

        abort_if($document === null, 404);
        $this->authorize('view', $document);

        $data = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if ($data['decision'] === 'approve') {
                $decided = $this->revocations->approve($revocation, $request->user(), $data['note'] ?? null);
            } else {
                // การปฏิเสธต้องมีเหตุผลเสมอ — ผู้ขอต้องรู้ว่าทำไมคำขอจึงไม่ผ่าน
                $note = $request->validate(['note' => ['required', 'string', 'min:10', 'max:1000']])['note'];
                $decided = $this->revocations->reject($revocation, $request->user(), $note);
            }
        } catch (SignatureException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'revocation' => $decided->only(['id', 'uuid', 'status', 'decision_note']),
        ]);
    }
}
