<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\Policy;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use App\Services\Webhooks\WebhookCatalogue;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * ออกหนังสือฉบับสมบูรณ์ พร้อม QR สำหรับตรวจสอบ (SoT §8, §12 · INT-12)
 *
 * ลำดับ:
 *   ตรวจว่าลงนามแล้ว → ออกรหัสตรวจสอบ → วาง QR บนหน้าเอกสาร
 *   → เรนเดอร์ฉบับสมบูรณ์ที่ฝั่ง server → ผนึกลายนิ้วมือเนื้อหา → audit
 *
 * **ฉบับสมบูรณ์เป็นเวอร์ชันใหม่เสมอ** — ฉบับที่ลงนามและต้นฉบับยังอยู่ครบ
 * ตาม SoT §8 · หลักฐานการลงนามยังชี้ไปที่ไฟล์ฉบับที่ลงนามจริง ไม่ใช่ฉบับที่
 * เติม QR ทีหลัง มิฉะนั้นแฮชในหลักฐานจะไม่ตรงกับไฟล์ที่มันอ้างถึง
 */
class FinalizationService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentVerificationService $verification,
        private readonly SarabanPdfRenderer $renderer,
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
        private readonly WebhookDispatcher $webhooks,
        private readonly PolicyCenter $policies,
    ) {}

    public function canFinalize(Document $document): bool
    {
        return $document->status === Document::STATUS_SIGNED
            && $document->currentVersion !== null
            && $document->finalized_at === null;
    }

    /**
     * @param  array<string,mixed>  $placement  ตำแหน่งของ QR บนหน้าเอกสาร
     */
    public function finalize(Document $document, array $placement, User $actor): DocumentVersion
    {
        if (! $this->canFinalize($document)) {
            throw new SignatureException(
                $document->finalized_at !== null
                    ? 'หนังสือฉบับนี้ออกฉบับสมบูรณ์ไปแล้ว'
                    : 'ต้องลงนามให้เรียบร้อยก่อนจึงจะออกฉบับสมบูรณ์ได้',
            );
        }

        $layout = $this->qrLayoutFor($document);

        $version = DB::transaction(function () use ($document, $placement, $layout, $actor) {
            $hash = $this->verification->ensureHash($document);
            $signedVersion = $document->currentVersion;

            DocumentAnnotation::create([
                'document_version_id' => $signedVersion->id,
                /*
                 * ตำแหน่ง QR มาจากนโยบายกลาง ไม่ใช่ค่าที่ฝังในโค้ด (RISK-32 · SoT §18)
                 *
                 * ค่าที่ผู้เรียกส่งมาชนะได้ เพราะบางฉบับต้องขยับหลบเนื้อหาบนหน้ากระดาษ
                 * แต่ **ค่าตั้งต้นเป็นของนโยบาย** จึงเปลี่ยนมาตรฐานทั้งระบบได้จากที่เดียว
                 * และมีประวัติว่าเปลี่ยนเมื่อใดโดยใคร
                 */
                'page' => $placement['page'] ?? $layout['page'],
                'type' => DocumentAnnotation::TYPE_QR,
                'x' => $placement['x'] ?? $layout['x'],
                'y' => $placement['y'] ?? $layout['y'],
                'width' => $placement['size'] ?? $layout['size'],
                'height' => $placement['size'] ?? $layout['size'],
                // รหัสตรวจสอบมาจากระบบ ไม่ใช่ค่าที่ผู้ใช้พิมพ์เข้ามา
                'payload' => ['hash' => $hash],
                'created_by' => $actor->id,
            ]);

            $final = $this->documents->addVersionFromContents(
                $document,
                $this->renderer->render($signedVersion->fresh()),
                $actor,
                DocumentVersion::STAGE_FINAL,
            );

            $document->update(['finalized_at' => $this->clock->now()]);

            // ผนึกหลังตั้ง finalized_at เพื่อให้ลายนิ้วมือครอบคลุมสถานะสุดท้ายจริง
            $this->verification->seal($document->fresh());

            $this->audit->log('saraban.document.finalized', $document, $document->id, null, [
                'version_no' => $final->version_no,
                'file_hash' => $final->file_hash,
                'verification_hash' => $hash,
            ], null, $actor->id);

            return $final;
        }, 5);

        /*
         * แจ้งระบบภายนอกหลังธุรกรรมสำเร็จแล้วเท่านั้น (SoT §17)
         *
         * ส่งเฉพาะ **ข้อเท็จจริงและตัวชี้** ไม่ส่งสาระสำคัญหรือไฟล์ออกนอกระบบ —
         * ปลายทางที่ต้องการรายละเอียดต้องเรียก API กลับเข้ามาด้วยสิทธิ์ของตนเอง
         * ซึ่งผ่านการตรวจสิทธิ์ตามปกติ
         */
        $document->refresh();

        $this->webhooks->dispatch(WebhookCatalogue::DOCUMENT_FINALIZED, [
            'document_uuid' => $document->uuid,
            'document_number' => $document->documentNumber?->formatted,
            'subject' => $document->subject,
            'finalized_at' => $document->finalized_at?->toIso8601String(),
            'verification_hash' => $document->verification_hash,
        ]);

        return $version;
    }

    /**
     * ตำแหน่ง QR ตามนโยบาย โดยให้ค่าเฉพาะประเภทหนังสือชนะค่ากลาง (SoT §18)
     *
     * หนังสือคนละประเภทมีผังหน้ากระดาษต่างกัน — หนังสือภายนอกมีหัวจดหมาย
     * ส่วนคำสั่งไม่มี · การใช้ตำแหน่งเดียวกันทุกประเภททำให้ QR ทับเนื้อหาในบางแบบ
     *
     * @return array{page:int,x:float,y:float,size:float}
     */
    private function qrLayoutFor(Document $document): array
    {
        $layout = $this->policies->current(Policy::KEY_DOCUMENT_LAYOUT);
        $base = $layout['qr'] ?? [];
        $byType = $layout['qr_by_document_type'][(string) $document->document_type_id] ?? [];

        return [
            'page' => (int) ($byType['page'] ?? $base['page'] ?? 1),
            'x' => (float) ($byType['x'] ?? $base['x'] ?? 470),
            'y' => (float) ($byType['y'] ?? $base['y'] ?? 740),
            'size' => (float) ($byType['size'] ?? $base['size'] ?? 72),
        ];
    }
}
