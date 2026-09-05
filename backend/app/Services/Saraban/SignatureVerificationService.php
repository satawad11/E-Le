<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\Policy;
use App\Models\Saraban\SignatureEvidence;
use App\Models\Saraban\SignatureRevocation;
use App\Services\TrustedClock;
use App\Support\StoredFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * ตรวจสอบลายเซ็นและความสมบูรณ์ของหนังสือ (SoT §14, §26)
 *
 * **คำนวณสดทุกครั้ง ไม่เก็บผลลงฐานข้อมูล**
 *
 * ผลการตรวจคือ "สถานะ ณ วินาทีที่ถาม" — ไฟล์ถูกแก้พรุ่งนี้ อำนาจหมดอายุเดือนหน้า
 * ใบรับรองถูกเพิกถอนปีหน้า · ผลที่เก็บไว้เมื่อวานจึงตอบคำถามของวันนี้ไม่ได้
 * และผลที่เก็บได้ก็คือผลที่แก้ได้
 *
 * **ข้อบังคับที่คลาสนี้ไม่ยอมละเมิดเด็ดขาด**
 *
 *   1. **Electronic Signature ≠ Digital Signature** — ระบบนี้ทำอย่างแรก
 *      จะไม่รายงานผลใบรับรองหรือ trusted timestamp เมื่อไม่มีของจริง
 *   2. **ห้าม VALID เพราะมีภาพลายเซ็น** — ต้องผ่านการผูกเวอร์ชัน แฮชไฟล์
 *      ตัวตนผู้ลงนาม และอำนาจ ณ วันที่ลงนาม ครบทุกข้อ
 *   3. **ไม่มีลายเซ็น = `UNVERIFIED` ไม่ใช่ `INVALID`** — หนังสือที่ยังไม่ลงนาม
 *      ไม่ได้ "ตรวจแล้วไม่ผ่าน" แต่ "ยังไม่มีอะไรให้ตรวจ"
 */
class SignatureVerificationService
{
    // ── สถานะรวมและสถานะรายข้อ ────────────────────────────────────────────
    public const VALID = 'VALID';

    public const VALID_WITH_WARNINGS = 'VALID_WITH_WARNINGS';

    public const INVALID = 'INVALID';

    public const DOCUMENT_MODIFIED_AFTER_SIGNING = 'DOCUMENT_MODIFIED_AFTER_SIGNING';

    public const CERTIFICATE_EXPIRED = 'CERTIFICATE_EXPIRED';

    public const CERTIFICATE_REVOKED = 'CERTIFICATE_REVOKED';

    public const CERTIFICATE_UNTRUSTED = 'CERTIFICATE_UNTRUSTED';

    public const TIMESTAMP_INVALID = 'TIMESTAMP_INVALID';

    public const SIGNER_MISMATCH = 'SIGNER_MISMATCH';

    public const AUTHORITY_INVALID = 'AUTHORITY_INVALID';

    public const SIGNATURE_ORDER_INVALID = 'SIGNATURE_ORDER_INVALID';

    public const REVOCATION_PENDING = 'REVOCATION_PENDING';

    public const REVOKED = 'REVOKED';

    public const UNVERIFIED = 'UNVERIFIED';

    /**
     * ลำดับความรุนแรง — ยิ่งมากยิ่งร้ายแรง · ใช้เลือกสถานะรวมจากผลรายข้อ
     *
     * `REVOKED` อยู่สูงสุดเพราะเป็นการตัดสินของมนุษย์ที่ชนะผลตรวจทางเทคนิคทุกข้อ
     * ส่วน `UNVERIFIED` อยู่ต่ำกว่า `INVALID` เพราะ "ยังไม่มีอะไรให้ตรวจ" ไม่ใช่ความล้มเหลว
     */
    private const SEVERITY = [
        self::VALID => 0,
        self::UNVERIFIED => 1,
        self::VALID_WITH_WARNINGS => 2,
        self::REVOCATION_PENDING => 3,
        self::TIMESTAMP_INVALID => 4,
        self::CERTIFICATE_UNTRUSTED => 5,
        self::CERTIFICATE_EXPIRED => 6,
        self::CERTIFICATE_REVOKED => 7,
        self::SIGNATURE_ORDER_INVALID => 8,
        self::AUTHORITY_INVALID => 9,
        self::SIGNER_MISMATCH => 10,
        self::INVALID => 11,
        self::DOCUMENT_MODIFIED_AFTER_SIGNING => 12,
        self::REVOKED => 13,
    ];

    public function __construct(
        private readonly AuthorityResolver $authority,
        private readonly DocumentVerificationService $documents,
        private readonly PolicyCenter $policies,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * ผลตรวจฉบับเต็ม — สำหรับผู้ใช้ภายในที่มีสิทธิ์เห็นหนังสือฉบับนั้น
     *
     * @return array<string,mixed>
     */
    public function verify(Document $document): array
    {
        $evidences = SignatureEvidence::query()
            ->where('document_id', $document->id)
            ->with(['version', 'signer:id,name'])
            ->orderBy('signed_at')
            ->orderBy('id')
            ->get();

        $revocation = $this->revocationStatus($document);
        $signatures = [];
        $sequence = 0;

        foreach ($evidences as $evidence) {
            $sequence++;
            $signatures[] = $this->verifySignature($document, $evidence, $sequence, $evidences);
        }

        $checks = $this->documentChecks($document);

        $statuses = array_merge(
            array_column($signatures, 'status'),
            array_column($checks, 'status'),
            $revocation === null ? [] : [$revocation['status']],
        );

        if ($evidences->isEmpty()) {
            $statuses[] = self::UNVERIFIED;
        }

        return [
            'status' => $this->worst($statuses),
            'document' => [
                'id' => $document->id,
                'uuid' => $document->uuid,
                'subject' => $document->subject,
                'document_number' => $document->documentNumber?->formatted,
                'status' => $document->status,
                'finalized_at' => $document->finalized_at?->toIso8601String(),
            ],
            'signature_count' => $evidences->count(),
            'signatures' => $signatures,
            'document_checks' => $checks,
            'revocation' => $revocation,
            'verified_at' => $this->clock->now()->toIso8601String(),
        ];
    }

    /**
     * สถานะอย่างย่อสำหรับหน้าตรวจสอบสาธารณะ
     *
     * **ไม่เปิดเผยว่าอะไรผิดพลาด** — คนทั่วไปที่ถือกระดาษต้องรู้แค่ว่าเอกสาร
     * ในมือใช้อ้างอิงได้หรือไม่ · รายละเอียดว่าอำนาจของใครหมดอายุหรือใบรับรองใด
     * ถูกเพิกถอน เป็นข้อมูลภายในที่บอกโครงสร้างองค์กรและช่องโหว่ให้ผู้ไม่หวังดี
     *
     * @return array{status:string,usable:bool}
     */
    public function publicSummary(Document $document): array
    {
        $status = $this->verify($document)['status'];

        return [
            'status' => $status,
            'usable' => in_array($status, [self::VALID, self::VALID_WITH_WARNINGS], true),
        ];
    }

    // ── การตรวจรายลายเซ็น ──────────────────────────────────────────────────

    /**
     * @param  Collection<int,SignatureEvidence>  $all
     * @return array<string,mixed>
     */
    private function verifySignature(
        Document $document,
        SignatureEvidence $evidence,
        int $sequence,
        $all,
    ): array {
        $binding = $this->bindingCheck($document, $evidence);

        $checks = [$binding];

        /*
         * ตรวจความสมบูรณ์ของไฟล์ **เฉพาะเมื่อการผูกเวอร์ชันยังดีอยู่**
         *
         * จะบอกว่า "ไฟล์ถูกแก้หลังลงนาม" ได้ ต้องรู้ก่อนว่าไฟล์ไหนคือไฟล์ที่ลงนาม ·
         * เมื่อการผูกเสียหาย เรายังไม่รู้ด้วยซ้ำว่ากำลังเทียบกับอะไร การรายงานว่า
         * "ถูกแก้" จึงเป็นการสรุปเกินกว่าที่หลักฐานบอกได้ และชี้ผู้ดูแลไปผิดทาง
         */
        if ($binding['status'] === self::VALID) {
            $checks[] = $this->integrityCheck($evidence);
        }

        $checks[] = $this->signerCheck($evidence);
        $checks[] = $this->authorityCheck($document, $evidence);
        $checks[] = $this->orderCheck($evidence, $sequence, $all);

        /*
         * ตรวจใบรับรองและ timestamp **เฉพาะเมื่อมีของจริง**
         *
         * ข้อบังคับตรงตัว: ห้ามแสดง certificate validity ถ้าไม่มี certificate จริง ·
         * การเติมหัวข้อ "ใบรับรอง: ผ่าน" ให้ลายเซ็นที่ไม่มีใบรับรอง คือการอ้างเท็จ
         */
        if ($evidence->certificate_serial !== null || $evidence->signature_type === SignatureEvidence::TYPE_DIGITAL) {
            $checks[] = $this->certificateCheck($evidence);
        }

        if ($evidence->timestamped_at !== null || $evidence->timestamp_authority !== null) {
            $checks[] = $this->timestampCheck($evidence);
        }

        return [
            'id' => $evidence->id,
            'uuid' => $evidence->uuid,
            'sequence' => $sequence,
            'signer' => $evidence->signer_name_snapshot,
            'position' => $evidence->position_snapshot,
            'org_unit' => $evidence->org_unit_snapshot,
            'signed_at' => $evidence->signed_at?->toIso8601String(),
            /*
             * บอกชนิดตรงไปตรงมาเสมอ — ผู้อ่านต้องรู้ว่ากำลังดูลายเซ็นอิเล็กทรอนิกส์
             * ที่ผูกกับตัวตนที่ยืนยันแล้ว ไม่ใช่ลายเซ็นดิจิทัลที่มีหลักฐานเชิงรหัสวิทยา
             */
            'signature_type' => $evidence->signature_type,
            'has_certificate' => $evidence->certificate_serial !== null,
            'has_trusted_timestamp' => $evidence->timestamped_at !== null,
            'version_no' => $evidence->version?->version_no,
            'document_hash' => $evidence->document_hash,
            'status' => $this->worst(array_column($checks, 'status')),
            'checks' => $checks,
        ];
    }

    /** ไฟล์ที่ลงนามยังเป็นไฟล์เดิมหรือไม่ */
    private function integrityCheck(SignatureEvidence $evidence): array
    {
        $path = $evidence->version?->pdf_file_path;
        $disk = Storage::disk(StoredFile::DISK);

        if ($path === null || ! $disk->exists($path)) {
            /*
             * ไฟล์หายไม่เท่ากับไฟล์ถูกแก้ แต่ **ผลต่อผู้ใช้เหมือนกัน**: ยืนยัน
             * ไม่ได้ว่าสิ่งที่ลงนามคืออะไร · แยกข้อความให้ผู้ดูแลตามเรื่องได้ถูกทาง
             */
            return $this->check('integrity', self::INVALID, 'ไม่พบไฟล์ของเวอร์ชันที่ลงนาม');
        }

        $actual = hash('sha256', (string) $disk->get($path));

        return hash_equals($evidence->document_hash, $actual)
            ? $this->check('integrity', self::VALID, 'ไฟล์ตรงกับแฮชที่บันทึกไว้ตอนลงนาม')
            : $this->check('integrity', self::DOCUMENT_MODIFIED_AFTER_SIGNING, 'ไฟล์ถูกแก้หลังการลงนาม');
    }

    /**
     * หลักฐาน ↔ เวอร์ชัน ↔ หนังสือ ต้องผูกกันครบสามทาง
     *
     * เป็นข้อที่ปิดช่อง "ลงนามฉบับหนึ่ง แล้วอ้างว่าลงนามอีกฉบับ" — แฮชในหลักฐาน
     * ต้องเท่ากับแฮชของเวอร์ชันที่ผูกไว้ และเวอร์ชันนั้นต้องเป็นของหนังสือฉบับนี้
     */
    private function bindingCheck(Document $document, SignatureEvidence $evidence): array
    {
        $version = $evidence->version;

        if ($version === null) {
            return $this->check('version_binding', self::INVALID, 'หลักฐานไม่ได้ผูกกับเวอร์ชันใด');
        }

        if ((int) $version->document_id !== (int) $document->id) {
            return $this->check('version_binding', self::INVALID, 'หลักฐานผูกกับเวอร์ชันของหนังสือฉบับอื่น');
        }

        if (! hash_equals((string) $version->file_hash, (string) $evidence->document_hash)) {
            return $this->check(
                'version_binding',
                self::INVALID,
                'แฮชในหลักฐานไม่ตรงกับแฮชของเวอร์ชันที่ผูกไว้',
            );
        }

        return $this->check('version_binding', self::VALID, "ผูกกับเวอร์ชันที่ {$version->version_no} และแฮชตรงกัน");
    }

    /**
     * ผู้ลงนามกับผู้สร้างเวอร์ชันที่ลงนาม ต้องเป็นคนเดียวกัน
     *
     * ปิดช่องที่ธุรการเรนเดอร์เวอร์ชันแล้วผูกหลักฐานเป็นชื่อผู้บริหาร ซึ่งเป็น
     * การลงนามแทนที่ SoT §16 ห้ามไว้
     */
    private function signerCheck(SignatureEvidence $evidence): array
    {
        if ($evidence->signer_id === null) {
            return $this->check('signer_identity', self::SIGNER_MISMATCH, 'หลักฐานไม่ระบุผู้ลงนาม');
        }

        $version = $evidence->version;

        if ($version !== null && (int) $version->created_by !== (int) $evidence->signer_id) {
            return $this->check(
                'signer_identity',
                self::SIGNER_MISMATCH,
                'ผู้สร้างเวอร์ชันที่ลงนามไม่ใช่ผู้ลงนามที่ระบุในหลักฐาน',
            );
        }

        if ($evidence->reauthenticated_at === null) {
            /*
             * ไม่มีร่องรอยการยืนยันตัวตนซ้ำ = พิสูจน์ไม่ได้ว่าเจ้าตัวเป็นผู้กด
             * ไม่ถึงขั้นผิดพลาด แต่เป็นลายเซ็นที่อ่อนกว่าที่ควรเป็น (SoT §24)
             */
            return $this->check(
                'signer_identity',
                self::VALID_WITH_WARNINGS,
                'ไม่มีบันทึกการยืนยันตัวตนซ้ำประกอบการลงนามครั้งนี้',
            );
        }

        return $this->check('signer_identity', self::VALID, 'ผู้ลงนามตรงกับผู้สร้างเวอร์ชันและมีการยืนยันตัวตนซ้ำ');
    }

    /**
     * อำนาจลงนาม
     *
     * **`authority_snapshot` คือหลักฐานของอำนาจ ณ วันที่ลงนาม — ไม่ใช่การคำนวณซ้ำ**
     *
     * เหตุผลที่ต้องเป็นอย่างนี้: สิทธิ์ในระบบ (spatie) **ไม่มีประวัติย้อนหลัง** ·
     * ถามวันนี้ว่า "เมื่อสองปีก่อนคนนี้มีสิทธิ์ไหม" จะได้คำตอบของวันนี้เสมอ ·
     * ถ้าใช้ผลนั้นตัดสิน หนังสือที่ลงนามถูกต้องจะกลายเป็นไม่ถูกต้องทันทีที่
     * ผู้ลงนามเกษียณ ซึ่งเป็นคำตอบที่ผิด
     *
     * snapshot ถูกเขียนโดยระบบเองด้วย `AuthorityResolver` ตัวเดียวกัน ณ วินาที
     * ที่ลงนาม และหลักฐานแก้ไม่ได้ที่ระดับโมเดล จึงเป็นบันทึกที่เชื่อถือได้
     *
     * การตรวจซ้ำกับสถานะปัจจุบันยังทำอยู่ แต่ใช้เพื่อ **เตือน** ว่าอำนาจนั้น
     * สิ้นสุดแล้ว ไม่ใช่เพื่อล้มลายเซ็นเดิม
     *
     * **ข้อจำกัดที่ต้องระบุ:** ระบบนี้พิสูจน์ย้อนหลังไม่ได้ว่าตอนนั้นมีอำนาจจริง
     * เกินกว่าที่ snapshot บันทึกไว้ · การพิสูจน์ที่แน่นกว่านี้ต้องมีประวัติสิทธิ์
     * แบบมีช่วงเวลา ซึ่งเป็นการเปลี่ยนแกนกลางของระบบ ไม่ใช่ขอบเขตของงานนี้
     */
    private function authorityCheck(Document $document, SignatureEvidence $evidence): array
    {
        if ($evidence->authority_snapshot === null) {
            return $this->check('authority', self::AUTHORITY_INVALID, 'ไม่มีบันทึกอำนาจลงนาม ณ เวลาที่ลงนาม');
        }

        $signer = $evidence->signer;

        if ($signer === null) {
            return $this->check('authority', self::AUTHORITY_INVALID, 'ไม่พบผู้ลงนามในระบบแล้ว');
        }

        $stillHasAuthority = $this->authority->hasAuthorityOver(
            $signer,
            $evidence->authority_snapshot,
            $document->owner_org_unit_id,
        );

        return $stillHasAuthority
            ? $this->check('authority', self::VALID, 'มีอำนาจลงนามทั้งวันที่ลงนามและปัจจุบัน')
            : $this->check('authority', self::VALID_WITH_WARNINGS, 'มีอำนาจ ณ วันที่ลงนามตามหลักฐาน แต่อำนาจนั้นสิ้นสุดแล้วในปัจจุบัน');
    }

    /**
     * ลำดับการลงนาม — เวลาจริงต้องเรียงตามลำดับที่บันทึกไว้
     *
     * ตรวจเมื่อมีการบันทึก `signing_sequence` เท่านั้น · หนังสือที่ไม่ได้กำหนด
     * ลำดับไว้ ไม่ควรถูกตัดสินว่าผิดลำดับ
     *
     * @param  Collection<int,SignatureEvidence>  $all
     */
    private function orderCheck(SignatureEvidence $evidence, int $sequence, $all): array
    {
        $expected = $evidence->signing_sequence;

        if ($expected === null) {
            return $this->check('signing_order', self::VALID, 'หนังสือฉบับนี้ไม่ได้กำหนดลำดับการลงนามไว้');
        }

        if ((int) $expected !== $sequence) {
            return $this->check(
                'signing_order',
                self::SIGNATURE_ORDER_INVALID,
                "ลงนามเป็นลำดับที่ {$sequence} แต่ควรเป็นลำดับที่ {$expected}",
            );
        }

        return $this->check('signing_order', self::VALID, "ลงนามตามลำดับที่กำหนด (ลำดับที่ {$sequence})");
    }

    /**
     * ใบรับรอง — เรียกเฉพาะเมื่อมีใบรับรองจริง หรือประกาศตัวว่าเป็นลายเซ็นดิจิทัล
     *
     * **ประกาศว่า digital แต่ไม่มีใบรับรอง = ไม่ผ่าน** เพราะเป็นการอ้างสิ่งที่
     * พิสูจน์ไม่ได้ ซึ่งอันตรายกว่าการไม่อ้างอะไรเลย
     */
    private function certificateCheck(SignatureEvidence $evidence): array
    {
        if ($evidence->certificate_serial === null) {
            return $this->check(
                'certificate',
                self::INVALID,
                'ระบุว่าเป็นลายเซ็นดิจิทัล แต่ไม่มีข้อมูลใบรับรองประกอบ',
            );
        }

        $signedAt = $evidence->signed_at;

        if ($evidence->certificate_revoked_at !== null && $evidence->certificate_revoked_at->lte($signedAt)) {
            return $this->check('certificate', self::CERTIFICATE_REVOKED, 'ใบรับรองถูกเพิกถอนก่อนการลงนาม');
        }

        if ($evidence->certificate_valid_to !== null && $evidence->certificate_valid_to->lt($signedAt)) {
            return $this->check('certificate', self::CERTIFICATE_EXPIRED, 'ใบรับรองหมดอายุก่อนการลงนาม');
        }

        if ($evidence->certificate_valid_from !== null && $evidence->certificate_valid_from->gt($signedAt)) {
            return $this->check('certificate', self::CERTIFICATE_EXPIRED, 'ลงนามก่อนวันที่ใบรับรองเริ่มมีผล');
        }

        $trusted = (array) ($this->signaturePolicy()['trusted_certificate_issuers'] ?? []);

        if ($trusted !== [] && ! in_array((string) $evidence->certificate_issuer, $trusted, true)) {
            return $this->check('certificate', self::CERTIFICATE_UNTRUSTED, 'ผู้ออกใบรับรองไม่อยู่ในรายชื่อที่หน่วยงานเชื่อถือ');
        }

        /*
         * ใบรับรองที่ถูกเพิกถอน **หลัง** วันลงนาม ไม่ทำให้ลายเซ็นเดิมเสีย แต่ต้อง
         * บอกผู้ตรวจให้รู้ — เป็นเหตุผลหนึ่งที่ trusted timestamp มีความสำคัญ
         */
        if ($evidence->certificate_revoked_at !== null) {
            return $this->check('certificate', self::VALID_WITH_WARNINGS, 'ใบรับรองถูกเพิกถอนภายหลังการลงนาม');
        }

        return $this->check('certificate', self::VALID, 'ใบรับรองมีผลอยู่ ณ เวลาที่ลงนาม');
    }

    /** trusted timestamp — เรียกเฉพาะเมื่อมีของจริง */
    private function timestampCheck(SignatureEvidence $evidence): array
    {
        if ($evidence->timestamped_at === null || $evidence->timestamp_authority === null) {
            return $this->check('timestamp', self::TIMESTAMP_INVALID, 'ข้อมูล trusted timestamp ไม่ครบ');
        }

        $signedAt = $evidence->signed_at;

        /*
         * เวลาที่ประทับต้องไม่มาก่อนการลงนาม และไม่อยู่ในอนาคต — ทั้งสองกรณี
         * แปลว่าเวลาที่อ้างเชื่อไม่ได้ ซึ่งทำลายจุดประสงค์ทั้งหมดของ timestamp
         */
        if ($signedAt !== null && $evidence->timestamped_at->lt($signedAt)) {
            return $this->check('timestamp', self::TIMESTAMP_INVALID, 'เวลาที่ประทับมาก่อนเวลาที่ลงนาม');
        }

        if ($evidence->timestamped_at->gt($this->clock->now())) {
            return $this->check('timestamp', self::TIMESTAMP_INVALID, 'เวลาที่ประทับอยู่ในอนาคต');
        }

        $trusted = (array) ($this->signaturePolicy()['trusted_timestamp_authorities'] ?? []);

        if ($trusted !== [] && ! in_array((string) $evidence->timestamp_authority, $trusted, true)) {
            return $this->check('timestamp', self::TIMESTAMP_INVALID, 'ผู้ให้บริการเวลาไม่อยู่ในรายชื่อที่หน่วยงานเชื่อถือ');
        }

        return $this->check('timestamp', self::VALID, 'เวลาที่ประทับสอดคล้องกับการลงนาม');
    }

    // ── การตรวจระดับหนังสือ ────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function documentChecks(Document $document): array
    {
        $checks = [];

        /*
         * เมทาดาทาที่ผนึกไว้ตอนออกฉบับสมบูรณ์ — คนละเรื่องกับแฮชไฟล์
         *
         * ข้อนี้จับ "เลขหนังสือหรือเรื่องถูกแก้ในฐานข้อมูลหลังออกหนังสือ"
         * ส่วนแฮชไฟล์จับ "ไฟล์ PDF ถูกสับเปลี่ยน" — ต้องมีทั้งคู่
         */
        $checks[] = $this->documents->wasAltered($document)
            ? $this->check('metadata_seal', self::DOCUMENT_MODIFIED_AFTER_SIGNING, 'ข้อมูลทะเบียนถูกแก้หลังออกหนังสือ')
            : $this->check('metadata_seal', self::VALID, 'ข้อมูลทะเบียนตรงกับที่ผนึกไว้ตอนออกหนังสือ');

        if ($document->status === Document::STATUS_CANCELLED) {
            $checks[] = $this->check('document_status', self::REVOKED, 'หนังสือฉบับนี้ถูกยกเลิกแล้ว');
        }

        return $checks;
    }

    /**
     * สถานะการเพิกถอนของหนังสือฉบับนี้
     *
     * @return array<string,mixed>|null
     */
    private function revocationStatus(Document $document): ?array
    {
        $revocation = SignatureRevocation::query()
            ->where('document_id', $document->id)
            ->whereIn('status', [SignatureRevocation::STATUS_PENDING, SignatureRevocation::STATUS_APPROVED])
            ->orderByRaw("CASE WHEN status = '".SignatureRevocation::STATUS_APPROVED."' THEN 0 ELSE 1 END")
            ->orderByDesc('requested_at')
            ->first();

        if ($revocation === null) {
            return null;
        }

        return [
            'id' => $revocation->id,
            'uuid' => $revocation->uuid,
            'status' => $revocation->status === SignatureRevocation::STATUS_APPROVED
                ? self::REVOKED
                : self::REVOCATION_PENDING,
            'reason' => $revocation->reason,
            'requested_at' => $revocation->requested_at?->toIso8601String(),
            'decided_at' => $revocation->decided_at?->toIso8601String(),
        ];
    }

    // ── เครื่องมือภายใน ────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function signaturePolicy(): array
    {
        return $this->policies->current(Policy::KEY_SIGNATURE);
    }

    /** @return array<string,string> */
    private function check(string $name, string $status, string $detail): array
    {
        return ['check' => $name, 'status' => $status, 'detail' => $detail];
    }

    /**
     * สถานะรวม = ข้อที่ร้ายแรงที่สุด
     *
     * **ไม่ใช่การนับคะแนน** — ลายเซ็นที่ผ่านเก้าข้อแต่ไฟล์ถูกแก้ ไม่ใช่ "ผ่าน 90%"
     * แต่คือเอกสารที่ใช้อ้างอิงไม่ได้
     *
     * @param  array<int,string>  $statuses
     */
    private function worst(array $statuses): string
    {
        $worst = self::VALID;

        foreach ($statuses as $status) {
            if ((self::SEVERITY[$status] ?? 0) > (self::SEVERITY[$worst] ?? 0)) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
