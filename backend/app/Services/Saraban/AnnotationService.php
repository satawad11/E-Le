<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * จัดการวัตถุที่วางบนหน้าหนังสือ และสร้างเวอร์ชันที่ประทับตราแล้ว (SoT §14 · INT-10)
 *
 * ข้อบังคับที่คลาสนี้รักษา:
 *
 *   1. **วางวัตถุได้เฉพาะบนเวอร์ชันปัจจุบันของหนังสือที่ยังไม่ลงนาม** — หนังสือ
 *      ที่ลงนามแล้วเป็นหลักฐาน การเติมตราลงไปทีหลังคือการแก้เอกสารที่ออกไปแล้ว
 *   2. **การเรนเดอร์สร้างเวอร์ชันใหม่เสมอ** ไม่เขียนทับไฟล์เดิม (SoT §8)
 *   3. **เรนเดอร์ที่ฝั่ง server เท่านั้น** — ตัวเรนเดอร์รับเฉพาะข้อมูลพิกัด
 *      ไม่รับภาพจากเบราว์เซอร์เลย จึงไม่มีทางที่ภาพหน้าจอจะกลายเป็น Final PDF
 */
class AnnotationService
{
    public function __construct(
        private readonly SarabanPdfRenderer $renderer,
        private readonly DocumentService $documents,
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    /** หนังสือฉบับนี้ยังวางวัตถุเพิ่มได้หรือไม่ */
    public function isEditable(Document $document): bool
    {
        return ! in_array($document->status, [
            Document::STATUS_SIGNED,
            Document::STATUS_CANCELLED,
        ], true);
    }

    /**
     * แทนที่ชุดวัตถุของเวอร์ชันหนึ่งทั้งชุด
     *
     * ใช้วิธี "แทนที่ทั้งชุด" แทนการแก้ทีละชิ้น เพราะหน้าจอทำงานแบบลากวางหลายชิ้น
     * แล้วกดบันทึกครั้งเดียว การส่งเฉพาะส่วนต่างจะทำให้สองฝั่งต้องรู้สถานะตรงกัน
     * ซึ่งเป็นที่มาของวัตถุค้างที่ลบไม่ออก
     *
     * @param  array<int,array<string,mixed>>  $annotations
     * @return Collection<int,DocumentAnnotation>
     */
    public function replaceFor(DocumentVersion $version, array $annotations, User $actor)
    {
        return DB::transaction(function () use ($version, $annotations, $actor) {
            DocumentAnnotation::query()->where('document_version_id', $version->id)->delete();

            foreach ($annotations as $annotation) {
                DocumentAnnotation::create([
                    'document_version_id' => $version->id,
                    'page' => $annotation['page'],
                    'type' => $annotation['type'],
                    'x' => $annotation['x'],
                    'y' => $annotation['y'],
                    'width' => $annotation['width'] ?? null,
                    'height' => $annotation['height'] ?? null,
                    'payload' => $annotation['payload'] ?? null,
                    'created_by' => $actor->id,
                ]);
            }

            $this->audit->log('saraban.document.annotated', $version->document, $version->document_id, null, [
                'version_no' => $version->version_no,
                'count' => count($annotations),
            ], null, $actor->id);

            return DocumentAnnotation::query()
                ->where('document_version_id', $version->id)
                ->orderBy('page')
                ->get();
        });
    }

    /**
     * ตราประทับรับหนังสือที่เติมข้อมูลจากทะเบียนรับให้แล้ว (SoT §10)
     *
     * เนื้อหาของตรามาจากข้อมูลที่ INT-07 บันทึกไว้ ไม่ใช่ให้ผู้ใช้พิมพ์เอง —
     * ตราประทับรับที่เลขไม่ตรงกับทะเบียนคือหลักฐานที่ขัดกันเอง
     *
     * @return array<string,mixed>|null
     */
    public function receiveStampPayloadFor(Document $document): ?array
    {
        $registration = $document->incomingRegistration;

        if ($registration === null) {
            return null;
        }

        return [
            'title' => 'รับหนังสือ',
            'register_number' => $registration->registerNumber?->formatted ?? '',
            'received_date' => $registration->received_at?->format('d/m/').($registration->received_at ? $registration->received_at->year + 543 : ''),
            'received_time' => $registration->received_at?->format('H:i') ?? '',
        ];
    }

    /**
     * เรนเดอร์วัตถุลงบนไฟล์จริง แล้วบันทึกเป็นเวอร์ชันใหม่
     *
     * ผลลัพธ์เป็นเวอร์ชันขั้น `reviewed` — ยังไม่ใช่ฉบับสมบูรณ์ เพราะ QR และ
     * การตรวจสอบสาธารณะเป็นงานของ INT-12
     */
    public function renderStampedVersion(Document $document, DocumentVersion $version, User $actor): DocumentVersion
    {
        return DB::transaction(function () use ($document, $version, $actor) {
            $contents = $this->renderer->render($version);

            $rendered = $this->documents->addVersionFromContents(
                $document,
                $contents,
                $actor,
                DocumentVersion::STAGE_REVIEWED,
            );

            $this->audit->log('saraban.document.stamped', $document, $document->id, null, [
                'from_version' => $version->version_no,
                'new_version' => $rendered->version_no,
                'file_hash' => $rendered->file_hash,
            ], null, $actor->id);

            return $rendered;
        });
    }
}
