<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use App\Support\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * วงจรชีวิตของหนังสือ (SoT §8 · INT-05)
 *
 * หลักที่คลาสนี้บังคับ:
 *
 *   1. **ต้นฉบับห้ามถูกเขียนทับ** — การแนบไฟล์ใหม่สร้างเวอร์ชันใหม่เสมอ
 *      ไม่มีเส้นทางใดในระบบที่แก้ไฟล์ของเวอร์ชันเดิม
 *   2. **เวลาที่เป็นหลักฐานมาจากตะเข็บเดียว** — ใช้ `TrustedClock` ไม่ใช่ `now()`
 *      เพื่อให้เปลี่ยนไปใช้แหล่งเวลาที่รับรองได้ภายหลัง (SoT §10)
 *   3. **ทุกการเปลี่ยนสถานะถูกบันทึกลง audit กลาง** ผ่าน `AuditLogger`
 *      ไม่ใช่ตาราง audit ของสารบรรณเอง (SoT §25)
 *
 * ไฟล์ทุกไฟล์เก็บบน disk `private_uploads` และเข้าถึงได้เฉพาะผ่าน
 * `SecureFileController` ตามสัญญา INT-01 §1.12
 */
class DocumentService
{
    private const FILE_DIRECTORY = 'saraban/documents';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * สร้างหนังสือฉบับร่าง พร้อมต้นฉบับถ้ามีไฟล์แนบมาด้วย
     *
     * @param  array<string,mixed>  $attributes
     */
    public function createDraft(array $attributes, User $author, ?UploadedFile $file = null): Document
    {
        return DB::transaction(function () use ($attributes, $author, $file) {
            $document = Document::create([
                ...$attributes,
                'status' => Document::STATUS_DRAFT,
                'created_by' => $author->id,
            ]);

            if ($file !== null) {
                $this->addVersion($document, $file, $author, DocumentVersion::STAGE_ORIGINAL);
            }

            $this->audit->log('saraban.document.created', $document, $document->id, null, [
                'subject' => $document->subject,
                'direction' => $document->direction,
            ], null, $author->id);

            return $document->fresh(['documentType', 'currentVersion']);
        });
    }

    /**
     * แก้ไขหนังสือฉบับร่าง
     *
     * ไฟล์ใหม่ = เวอร์ชันใหม่ ไม่ใช่การเขียนทับของเดิม (SoT §8)
     *
     * @param  array<string,mixed>  $attributes
     */
    public function updateDraft(Document $document, array $attributes, User $editor, ?UploadedFile $file = null): Document
    {
        return DB::transaction(function () use ($document, $attributes, $editor, $file) {
            $before = $document->only(['subject', 'summary', 'confidentiality', 'urgency', 'document_type_id', 'direction']);

            $document->update($attributes);

            if ($file !== null) {
                $this->addVersion($document, $file, $editor, DocumentVersion::STAGE_REVISED);
            }

            $this->audit->log('saraban.document.updated', $document, $document->id, $before, $document->only(array_keys($before)), null, $editor->id);

            return $document->fresh(['documentType', 'currentVersion']);
        });
    }

    /**
     * เสนอหนังสือเข้าสู่กระบวนการ
     *
     * หลังจากนี้เนื้อหาแก้ทับไม่ได้อีก (`DocumentPolicy::update()` ปฏิเสธ)
     * การเดินสายงานจริงต่อจากนี้เป็นงานของ workflow engine ในเช็คพอยต์ถัดไป
     */
    public function submit(Document $document, User $actor): Document
    {
        return DB::transaction(function () use ($document, $actor) {
            $document->update([
                'status' => Document::STATUS_SUBMITTED,
                'submitted_at' => $this->clock->now(),
            ]);

            /*
             * INT-08 — เสนอเรื่อง = เริ่มสายการเดินหนังสือบน engine กลาง
             *
             * resolve จาก container ตอนใช้แทนการรับผ่าน constructor เพื่อตัดวงจร
             * การพึ่งพากันไปมา (`DocumentWorkflowService` ต้องใช้ `DocumentService`
             * ผ่าน `IncomingRegistrationService` อยู่แล้ว)
             */
            app(DocumentWorkflowService::class)->start($document, $actor);

            $this->audit->log('saraban.document.submitted', $document, $document->id, ['status' => Document::STATUS_DRAFT], ['status' => Document::STATUS_SUBMITTED], null, $actor->id);

            return $document->fresh(['documentType', 'currentVersion']);
        });
    }

    /**
     * เพิ่มเวอร์ชันใหม่จากไฟล์ที่อัปโหลดเข้ามา
     *
     * เลขเวอร์ชันมาจาก `MAX(version_no) + 1` ภายใต้ `lockForUpdate` และมี
     * unique constraint `(document_id, version_no)` เป็นด่านสุดท้าย — สองคำขอ
     * ที่แนบไฟล์พร้อมกันจึงไม่ได้เลขเวอร์ชันชนกันเงียบ ๆ
     */
    public function addVersion(Document $document, UploadedFile $file, User $author, string $stage): DocumentVersion
    {
        return $this->addVersionFromContents(
            $document,
            (string) file_get_contents($file->getRealPath()),
            $author,
            $stage,
        );
    }

    /**
     * เพิ่มเวอร์ชันใหม่จากเนื้อไฟล์ที่ระบบสร้างขึ้นเอง
     *
     * ใช้ตอนเรนเดอร์ไฟล์ที่ประทับตราแล้ว (INT-10) ซึ่งไม่ได้มาจากการอัปโหลด
     * แต่ต้องผ่านเส้นทางเขียนไฟล์และการเดินเลขเวอร์ชันชุดเดียวกันทุกประการ
     */
    public function addVersionFromContents(Document $document, string $contents, User $author, string $stage): DocumentVersion
    {
        return DB::transaction(function () use ($document, $contents, $author, $stage) {
            $latest = DocumentVersion::query()
                ->where('document_id', $document->id)
                ->lockForUpdate()
                ->max('version_no');

            $path = self::FILE_DIRECTORY.'/'.$document->id.'/'.Str::uuid().'.pdf';

            Storage::disk(StoredFile::DISK)->put($path, $contents);

            $version = DocumentVersion::create([
                'document_id' => $document->id,
                'version_no' => ((int) $latest) + 1,
                'stage' => $stage,
                'pdf_file_path' => $path,
                'file_hash' => hash('sha256', $contents),
                'created_by' => $author->id,
                'created_at' => $this->clock->now(),
            ]);

            $document->update(['current_version_id' => $version->id]);

            return $version;
        });
    }
}
