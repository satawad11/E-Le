<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\SignatureEvidence;
use App\Services\Saraban\DocumentVerificationService;
use App\Services\TrustedClock;

/**
 * ตรวจสอบหนังสือที่พิมพ์ออกไปผ่าน QR — **เปิดสาธารณะ** (SoT §12)
 *
 * ใช้รูปแบบเดียวกับการตรวจสอบใบลาที่ระบบมีอยู่แล้ว: เข้าถึงได้เฉพาะผู้ที่ถือเอกสาร
 * (โทเคน 40 ตัวอักษรเดาไม่ได้) และจำกัดอัตราด้วย `throttle:verify` เพื่อกันการสุ่มโทเคน
 *
 * **คืนเฉพาะข้อมูลที่ปรากฏบนหน้าเอกสารอยู่แล้ว** — ไม่คืนสาระสำคัญ ไฟล์แนบ
 * หรือข้อมูลภายในอื่น เพราะผู้เปิดหน้านี้คือคนทั่วไปที่ถือกระดาษอยู่ในมือ
 * ไม่ใช่ผู้ใช้ที่ผ่านการยืนยันตัวตน
 */
class DocumentVerificationController extends Controller
{
    public function __construct(
        private readonly DocumentVerificationService $verification,
        private readonly TrustedClock $clock,
    ) {}

    public function verify(string $hash)
    {
        $document = Document::query()
            ->with(['documentType:id,name', 'ownerOrgUnit:id,name', 'documentNumber:id,formatted,status'])
            ->where('verification_hash', $hash)
            ->first();

        abort_unless($document, 404, 'ไม่พบเอกสารที่ตรงกับรหัสตรวจสอบนี้');

        $altered = $this->verification->wasAltered($document);

        return response()->json([
            'document_number' => $document->documentNumber?->formatted,
            'subject' => $document->subject,
            'document_type' => $document->documentType?->name,
            'owner_org_unit' => $document->ownerOrgUnit?->name,
            'issued_at' => $document->issued_at?->toIso8601String(),
            'finalized_at' => $document->finalized_at?->toIso8601String(),

            /*
             * ผู้ลงนาม — ใช้ snapshot ที่บันทึกไว้ตอนลงนาม ไม่ใช่ข้อมูลปัจจุบัน
             * หนังสือต้องบอกได้เสมอว่าใครลงนามในฐานะอะไร ณ วันนั้น (SoT §14)
             */
            'signatures' => SignatureEvidence::query()
                ->where('document_id', $document->id)
                ->orderBy('signed_at')
                ->get(['signer_name_snapshot', 'position_snapshot', 'org_unit_snapshot', 'signed_at'])
                ->map(fn (SignatureEvidence $evidence) => [
                    'name' => $evidence->signer_name_snapshot,
                    'position' => $evidence->position_snapshot,
                    'org_unit' => $evidence->org_unit_snapshot,
                    'signed_at' => $evidence->signed_at?->toIso8601String(),
                ]),

            /*
             * ใช้อ้างอิงได้เมื่อออกฉบับสมบูรณ์แล้ว ยังไม่ถูกยกเลิก
             * และข้อมูลไม่ถูกแก้หลังออกหนังสือ
             */
            'is_valid' => $document->finalized_at !== null
                && $document->status !== Document::STATUS_CANCELLED
                && ! $altered,
            'content_altered' => $altered,
            'cancelled' => $document->status === Document::STATUS_CANCELLED,
            'verified_at' => $this->clock->now()->toIso8601String(),
        ]);
    }

    public function qrcode(string $hash)
    {
        abort_unless(Document::query()->where('verification_hash', $hash)->exists(), 404);

        return response($this->verification->qrCodeSvg($hash))
            ->header('Content-Type', 'image/svg+xml');
    }
}
