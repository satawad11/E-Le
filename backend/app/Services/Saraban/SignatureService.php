<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\SignatureEvidence;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\StepUpAuthentication;
use App\Services\TrustedClock;
use App\Support\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * การลงนามหนังสือ และหลักฐานประกอบ (SoT §14, §24 · INT-11)
 *
 * ข้อบังคับที่คลาสนี้รักษา:
 *
 *   1. **ผู้ลงนามต้องยืนยันตัวตนเอง** — ตรวจซ้ำที่นี่อีกชั้นแม้ route จะมีด่าน
 *      `step_up` แล้ว เพราะการลงนามเป็นการกระทำที่ย้อนกลับไม่ได้ การพึ่งด่านเดียว
 *      แปลว่าการต่อ route ผิดพลาดครั้งเดียวก็เปิดช่องได้
 *   2. **งานสารบรรณเตรียมที่ว่างให้ได้ แต่ลงนามแทนไม่ได้** (SoT §16) — ลายเซ็น
 *      ต้องผูกกับผู้ลงนามที่กำลังยืนยันตัวตนอยู่เท่านั้น
 *   3. **หลักฐานครบตาม SoT §14** — ผู้ลงนาม ตำแหน่ง อำนาจ session เวลา วิธี
 *      เวอร์ชัน hash IP UA และเข้า audit กลาง
 *   4. **ลายมือ ≠ ลายเซ็น** (SoT §15) — ลายมือเป็นการเกษียน ไม่สร้างหลักฐานการลงนาม
 */
class SignatureService
{
    public function __construct(
        private readonly AnnotationService $annotations,
        private readonly DocumentService $documents,
        private readonly AuthorityResolver $authority,
        private readonly StepUpAuthentication $stepUp,
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * ลงนามหนังสือ — วางลายเซ็นลงบนไฟล์แล้วบันทึกหลักฐาน
     *
     * @param  array<string,mixed>  $signature  พิกัดและเส้นของลายเซ็น
     */
    public function sign(Document $document, array $signature, User $signer, Request $request): SignatureEvidence
    {
        if (! $this->stepUp->isRecent($signer)) {
            throw new SignatureException('ต้องยืนยันตัวตนอีกครั้งก่อนลงนาม');
        }

        $version = $document->currentVersion;

        if ($version === null) {
            throw new SignatureException('หนังสือฉบับนี้ยังไม่มีไฟล์ให้ลงนาม');
        }

        return DB::transaction(function () use ($document, $version, $signature, $signer, $request) {
            /*
             * บันทึกลายเซ็นเป็นวัตถุบนเวอร์ชันปัจจุบัน แล้วเรนเดอร์เป็นเวอร์ชันใหม่
             * ขั้น `signed` — ต้นฉบับและฉบับก่อนหน้ายังอยู่ครบตาม SoT §8
             */
            DocumentAnnotation::create([
                'document_version_id' => $version->id,
                'page' => $signature['page'],
                'type' => DocumentAnnotation::TYPE_SIGNATURE,
                'x' => $signature['x'],
                'y' => $signature['y'],
                'width' => $signature['width'] ?? null,
                'height' => $signature['height'] ?? null,
                'payload' => ['strokes' => $signature['strokes'], 'line_width' => $signature['line_width'] ?? 1.2],
                'created_by' => $signer->id,
            ]);

            /*
             * วันที่และข้อความที่วางพร้อมลายเซ็น (SoT §14 · NAV §4.7)
             *
             * **อยู่ในการกระทำเดียวกับการลงนามโดยเจตนา** — หนังสือราชการที่ลงนาม
             * ประกอบด้วยลายเซ็น ชื่อ-ตำแหน่ง และวันที่ ซึ่งเป็นบล็อกเดียวกัน ·
             * ถ้าให้วางวันที่แยกเป็นการเกษียนอีกครั้ง จะได้เวอร์ชันเพิ่มอีกฉบับ
             * และหลักฐานการลงนามจะชี้ไปยังไฟล์ที่ยังไม่มีวันที่กำกับ
             *
             * ต่างจากการเกษียน (`endorse`) ตรงที่ **การเกษียนไม่สร้างหลักฐานการ
             * ลงนาม** ตาม SoT §15 ส่วนวัตถุเหล่านี้เป็นส่วนประกอบของลายเซ็นเอง
             */
            foreach ($signature['objects'] ?? [] as $object) {
                DocumentAnnotation::create([
                    'document_version_id' => $version->id,
                    'page' => $object['page'],
                    'type' => $object['type'],
                    'x' => $object['x'],
                    'y' => $object['y'],
                    'width' => $object['width'] ?? null,
                    'height' => $object['height'] ?? null,
                    'payload' => $object['payload'] ?? null,
                    'created_by' => $signer->id,
                ]);
            }

            $signed = $this->documents->addVersionFromContents(
                $document,
                app(SarabanPdfRenderer::class)->render($version->fresh()),
                $signer,
                DocumentVersion::STAGE_SIGNED,
            );

            $evidence = SignatureEvidence::create([
                'document_id' => $document->id,
                'document_version_id' => $signed->id,
                'signer_id' => $signer->id,
                ...$this->snapshotOf($signer, $document),
                'signed_at' => $this->clock->now(),
                'signature_method' => SignatureEvidence::METHOD_DRAWN,
                /*
                 * **ประกาศชนิดตรงไปตรงมา** — ระบบนี้ทำลายเซ็นอิเล็กทรอนิกส์ที่ผูก
                 * กับตัวตนที่ยืนยันแล้ว ไม่ใช่ลายเซ็นดิจิทัลที่มีใบรับรองรองรับ
                 * การเขียนค่านี้ไว้ชัดเจน ทำให้ไม่มีใครในอนาคตเผลออ้างสิ่งที่
                 * ระบบพิสูจน์ไม่ได้ (SoT §14)
                 */
                'signature_type' => SignatureEvidence::TYPE_ELECTRONIC,
                /*
                 * ลำดับการลงนามจริงของหนังสือฉบับนี้ — นับจากหลักฐานที่มีอยู่แล้ว
                 * บันทึกไว้เพื่อให้ตรวจย้อนหลังได้ว่าลำดับเวลาตรงกับลำดับที่ควรเป็น
                 */
                'signing_sequence' => SignatureEvidence::query()
                    ->where('document_id', $document->id)
                    ->count() + 1,
                'document_hash' => $signed->file_hash,
                ...$this->assuranceOf($signer),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $this->audit->log('saraban.signature.signed', $document, $document->id, null, [
                'version_no' => $signed->version_no,
                'document_hash' => $signed->file_hash,
                'signer' => $evidence->signer_name_snapshot,
                'position' => $evidence->position_snapshot,
            ], null, $signer->id);

            return $evidence;
        }, 5);
    }

    /**
     * เกษียนหนังสือด้วยลายมือหรือข้อความ (SoT §15)
     *
     * **ไม่สร้างหลักฐานการลงนาม** เพราะ SoT §15 ระบุชัดว่า "ลายมือ ≠ ลายเซ็น"
     * การเกษียนเป็นการให้ความเห็น ไม่ใช่การรับรองเอกสารในทางกฎหมาย
     * จึงไม่ต้องยืนยันตัวตนซ้ำ และไม่ทำให้หนังสือเปลี่ยนสถานะ
     *
     * @param  array<int,array<string,mixed>>  $objects
     */
    public function endorse(Document $document, array $objects, User $actor): DocumentVersion
    {
        $version = $document->currentVersion;

        if ($version === null) {
            throw new SignatureException('หนังสือฉบับนี้ยังไม่มีไฟล์ให้เกษียน');
        }

        return DB::transaction(function () use ($document, $version, $objects, $actor) {
            foreach ($objects as $object) {
                DocumentAnnotation::create([
                    'document_version_id' => $version->id,
                    'page' => $object['page'],
                    'type' => $object['type'],
                    'x' => $object['x'],
                    'y' => $object['y'],
                    'width' => $object['width'] ?? null,
                    'height' => $object['height'] ?? null,
                    'payload' => $object['payload'] ?? null,
                    'created_by' => $actor->id,
                ]);
            }

            $endorsed = $this->documents->addVersionFromContents(
                $document,
                app(SarabanPdfRenderer::class)->render($version->fresh()),
                $actor,
                DocumentVersion::STAGE_ENDORSED,
            );

            $this->audit->log('saraban.endorse.created', $document, $document->id, null, [
                'version_no' => $endorsed->version_no,
                'objects' => count($objects),
            ], null, $actor->id);

            return $endorsed;
        }, 5);
    }

    /**
     * snapshot ของผู้ลงนาม ณ เวลาที่ลงนาม (SoT §14)
     *
     * เก็บเป็นข้อความ ไม่ใช่ join — ชื่อและตำแหน่งต้องไม่เปลี่ยนตามเมื่อผู้ลงนาม
     * ย้ายหน่วยงานในภายหลัง หนังสือที่ลงนามไปแล้วต้องบอกได้เสมอว่าใครลงนาม
     * ในฐานะอะไร ณ วันนั้น
     *
     * @return array<string,string|null>
     */
    private function snapshotOf(User $signer, Document $document): array
    {
        $assignment = $signer->currentAssignment;

        return [
            'signer_name_snapshot' => $signer->name,
            'position_snapshot' => $assignment?->position,
            'org_unit_snapshot' => $assignment?->orgUnit?->name ?? $signer->orgUnit?->name,
            'authority_snapshot' => $this->authority->hasAuthorityOver($signer, 'saraban.signature.sign', $document->owner_org_unit_id)
                ? 'saraban.signature.sign'
                : null,
        ];
    }

    /** @return array<string,mixed> */
    private function assuranceOf(User $signer): array
    {
        $reference = $this->stepUp->assuranceReferenceFor($signer);

        return [
            'auth_token_id' => $reference['token_id'],
            'reauthenticated_at' => $reference['authenticated_at'],
        ];
    }

    /** ตรวจว่าไฟล์ที่ลงนามยังไม่ถูกแก้ — ใช้ตรวจสอบย้อนหลัง (SoT §14) */
    public function evidenceIsIntact(SignatureEvidence $evidence): bool
    {
        $path = $evidence->version?->pdf_file_path;

        if ($path === null || ! Storage::disk(StoredFile::DISK)->exists($path)) {
            return false;
        }

        return hash_equals(
            $evidence->document_hash,
            hash('sha256', Storage::disk(StoredFile::DISK)->get($path)),
        );
    }
}
