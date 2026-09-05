<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\LegalHold;
use App\Models\Saraban\RetentionSchedule;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * การเก็บรักษา การอายัด และการทำลายเอกสาร (SoT §25)
 *
 * **ข้อบังคับที่คลาสนี้บังคับ เรียงตามความสำคัญ:**
 *
 *   1. **หนังสือที่ออกไปแล้วห้ามลบตามปกติ** — ทางเดียวที่ทำลายได้คือผ่านกระบวนการ
 *      ในคลาสนี้ ซึ่งต้องครบกำหนด ผ่านการอนุมัติ และไม่ถูกอายัด
 *   2. **การอายัดชนะทุกอย่าง** — เอกสารที่อยู่ระหว่างคดีความหรือการสอบสวน
 *      ทำลายไม่ได้ แม้จะครบกำหนดและได้รับอนุมัติมาก่อนหน้าแล้วก็ตาม
 *   3. **ทำลายไฟล์ ไม่ทำลายทะเบียน** — แถวของหนังสือยังอยู่ พร้อม hash ของไฟล์
 *      ที่ถูกทำลาย · ทะเบียนที่ลบตัวเองพร้อมเอกสารคือทะเบียนที่ตรวจสอบไม่ได้
 */
class RetentionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * ล็อกแถวหนังสือไว้จนจบ transaction
     *
     * เป็น **จุดนัดพบจุดเดียว** ของงานที่แย่งกันไม่ได้ระหว่างการอายัดกับการทำลาย ·
     * ทั้งสองงานล็อกแถวเดียวกัน งานที่มาทีหลังจึงรอ แล้วอ่านสถานะที่งานแรก
     * เขียนไว้แล้วจริง ๆ
     *
     * **หมายเหตุที่ต้องระบุ:** SQLite (ที่ชุดทดสอบใช้) ไม่มี row-level lock จริง
     * เทสต์ปกติจึงพิสูจน์ได้แค่ว่า *ลำดับการตรวจ* ถูกต้อง · การพิสูจน์การแย่งกัน
     * จริงต้องรันบน MySQL ด้วย `saraban:concurrency-check --disposal`
     */
    private function lockDocument(Document $document): void
    {
        Document::query()->whereKey($document->id)->lockForUpdate()->first();

        // ความสัมพันธ์ที่โหลดไว้ก่อนได้ล็อกคือข้อมูลเก่า — บังคับให้อ่านใหม่
        $document->unsetRelation('versions');
    }

    /** เอกสารฉบับนี้ถูกอายัดอยู่หรือไม่ */
    public function isOnHold(Document $document): bool
    {
        return LegalHold::query()
            ->where('document_id', $document->id)
            ->whereNull('released_at')
            ->exists();
    }

    /**
     * อายัดเอกสาร (SoT §25)
     *
     * @throws RetentionException
     */
    public function placeHold(Document $document, string $reference, string $reason, User $actor): LegalHold
    {
        $hold = DB::transaction(function () use ($document, $reference, $reason, $actor) {
            /*
             * ล็อกแถวหนังสือก่อน — **แถวเดียวกับที่ `dispose()` ล็อก**
             *
             * ทั้งสองงานจึงเข้าคิวกันได้จริง ไม่ใช่ต่างคนต่างอ่านสถานะเก่าแล้ว
             * ตัดสินพร้อมกัน · ดูเหตุผลเต็มที่ `dispose()`
             */
            $this->lockDocument($document);

            if ($this->isOnHold($document)) {
                throw new RetentionException('หนังสือฉบับนี้ถูกอายัดอยู่แล้ว');
            }

            /*
             * **อายัดเอกสารที่ไฟล์ถูกทำลายไปแล้วไม่ได้**
             *
             * ไม่ใช่ข้อจำกัดทางเทคนิค แต่เป็นเรื่องความหมายของทะเบียน: แถวอายัด
             * ที่ค้างอยู่บนเอกสารที่ไม่มีไฟล์แล้ว ทำให้ผู้อ่านทะเบียนเข้าใจว่า
             * เอกสารยังถูกคุ้มครองอยู่ ทั้งที่ไม่มีอะไรเหลือให้คุ้มครอง
             *
             * ผลข้างเคียงที่ตั้งใจ: สถานะของเอกสารหนึ่งฉบับจึงไม่กำกวมอีก —
             * "มีอายัด" กับ "ไฟล์ถูกทำลาย" อยู่ร่วมกันไม่ได้ · ซึ่งทำให้
             * `saraban:concurrency-check --disposal` ตรวจผลการแย่งกันได้ชัดเจน
             */
            $disposed = RetentionSchedule::query()
                ->where('document_id', $document->id)
                ->where('status', RetentionSchedule::STATUS_DISPOSED)
                ->exists();

            if ($disposed) {
                throw new RetentionException('หนังสือฉบับนี้ถูกทำลายไปแล้ว อายัดไม่ได้');
            }

            return LegalHold::create([
                'document_id' => $document->id,
                'reference' => $reference,
                'reason' => $reason,
                'placed_at' => $this->clock->now(),
                'placed_by' => $actor->id,
            ]);
        });

        $this->audit->log('saraban.document.hold_placed', $document, $document->id, null, [
            'reference' => $reference,
            'reason' => $reason,
        ], $reason, $actor->id);

        return $hold;
    }

    /**
     * ปลดการอายัด — **แถวเดิมยังอยู่** เพราะต้องตอบได้ว่าเคยถูกอายัดช่วงใด
     *
     * @throws RetentionException
     */
    public function releaseHold(LegalHold $hold, User $actor, ?string $note = null): LegalHold
    {
        if ($hold->released_at !== null) {
            throw new RetentionException('การอายัดนี้ถูกปลดไปแล้ว');
        }

        $hold->forceFill([
            'released_at' => $this->clock->now(),
            'released_by' => $actor->id,
            'release_note' => $note,
        ])->save();

        $this->audit->log('saraban.document.hold_released', $hold->document, $hold->document_id, null, [
            'reference' => $hold->reference,
            'note' => $note,
        ], $note, $actor->id);

        return $hold;
    }

    /**
     * กำหนดอายุการเก็บรักษาของหนังสือฉบับหนึ่ง
     *
     * นับจาก **วันที่ออกหนังสือ** ไม่ใช่วันที่สร้างระเบียน — ระเบียบราชการนับจาก
     * วันที่เอกสารมีผล ไม่ใช่วันที่มีคนพิมพ์เข้าระบบ
     */
    public function schedule(Document $document, int $retainYears, User $actor): RetentionSchedule
    {
        $start = $document->finalized_at ?? $document->issued_at ?? $document->created_at ?? $this->clock->now();

        $schedule = RetentionSchedule::updateOrCreate(
            ['document_id' => $document->id],
            [
                'retain_years' => $retainYears,
                'retain_until' => $start->copy()->addYears($retainYears)->toDateString(),
                'status' => RetentionSchedule::STATUS_PROPOSED,
                'approved_by' => null,
                'approved_at' => null,
            ],
        );

        $this->audit->log('saraban.retention.scheduled', $document, $document->id, null, [
            'retain_years' => $retainYears,
            'retain_until' => $schedule->retain_until->toDateString(),
        ], null, $actor->id);

        return $schedule;
    }

    /**
     * อนุมัติให้ทำลายได้เมื่อครบกำหนด
     *
     * **การอนุมัติกับการทำลายเป็นคนละการกระทำ** — แยกกันเพื่อให้เกิด
     * separation of duties (SoT §26) · ผู้อนุมัติกับผู้ลงมือทำลายไม่จำเป็น
     * ต้องเป็นคนเดียวกัน และการอนุมัติล่วงหน้าไม่ได้ทำลายอะไรทันที
     *
     * @throws RetentionException
     */
    public function approveDisposal(RetentionSchedule $schedule, User $actor): RetentionSchedule
    {
        if ($schedule->status === RetentionSchedule::STATUS_DISPOSED) {
            throw new RetentionException('หนังสือฉบับนี้ถูกทำลายไปแล้ว');
        }

        $schedule->forceFill([
            'status' => RetentionSchedule::STATUS_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => $this->clock->now(),
        ])->save();

        $this->audit->log('saraban.retention.disposal_approved', $schedule->document, $schedule->document_id, null, [
            'retain_until' => $schedule->retain_until->toDateString(),
        ], null, $actor->id);

        return $schedule;
    }

    /**
     * ทำลายไฟล์ของเอกสาร — **ทะเบียนยังอยู่ครบ**
     *
     * เงื่อนไขที่ต้องผ่านครบทุกข้อ:
     *   - ได้รับอนุมัติแล้ว
     *   - ครบกำหนดเก็บแล้ว
     *   - **ไม่ถูกอายัด** (ข้อนี้ชนะทุกข้ออื่น)
     *
     * @throws RetentionException
     */
    public function dispose(RetentionSchedule $schedule, User $actor, ?string $note = null): RetentionSchedule
    {
        $document = $schedule->document;

        return DB::transaction(function () use ($schedule, $document, $actor, $note) {
            /*
             * **ตรวจทุกเงื่อนไข "ข้างใน" transaction หลังล็อกแถวแล้วเท่านั้น**
             *
             * ที่มา (การตรวจทาน 2026-09-05): เดิมตรวจการอายัดก่อนเปิด transaction
             * แล้วค่อยลบไฟล์ข้างใน · ถ้ามีคนสั่งอายัดคั่นระหว่างสองจุดนั้น
             * **ไฟล์ของหนังสือที่ถูกอายัดจะถูกทำลาย และย้อนกลับไม่ได้**
             *
             * การอ่านสถานะแล้วตัดสินทีหลัง ใช้ไม่ได้กับการกระทำที่ย้อนกลับไม่ได้ —
             * ต้องล็อกแถวหนังสือ (แถวเดียวกับที่ `placeHold()` ล็อก) แล้วอ่าน
             * สถานะสดใหม่ทั้งหมดหลังได้ล็อก
             */
            $this->lockDocument($document);
            $schedule->refresh();

            if ($schedule->status === RetentionSchedule::STATUS_DISPOSED) {
                throw new RetentionException('หนังสือฉบับนี้ถูกทำลายไปแล้ว');
            }

            if ($schedule->status !== RetentionSchedule::STATUS_APPROVED) {
                throw new RetentionException('ต้องได้รับอนุมัติให้ทำลายก่อน');
            }

            if ($schedule->retain_until->isFuture()) {
                throw new RetentionException(
                    'ยังไม่ครบกำหนดเก็บรักษา (ครบวันที่ '.$schedule->retain_until->toDateString().')',
                );
            }

            /*
             * การอายัดชนะกำหนดการทำลายเสมอ — ตรวจข้อนี้ **สุดท้าย** เพื่อให้ข้อความ
             * ที่ผู้ใช้เห็นตรงกับเหตุผลที่แท้จริงที่สุด
             */
            if ($this->isOnHold($document)) {
                throw new RetentionException('หนังสือฉบับนี้ถูกอายัดอยู่ ทำลายไม่ได้จนกว่าจะปลดการอายัด');
            }

            /*
             * **บันทึกทะเบียนให้สำเร็จก่อน แล้วจึงลบไฟล์หลัง commit เท่านั้น**
             *
             * ที่มา: หลังปิด TOCTOU ข้างต้น การพิสูจน์ด้วย
             * `saraban:concurrency-check --disposal` บน MySQL จริง เผยข้อบกพร่อง
             * ชั้นที่สอง — เดิมลบไฟล์ *ระหว่าง* transaction · **การลบไฟล์
             * ย้อนกลับไม่ได้ แต่ transaction ย้อนกลับได้** ดังนั้นเมื่อ InnoDB
             * ยกเลิกรายการเพราะ deadlock/lock timeout (ซึ่งเกิดขึ้นจริงเมื่อสอง
             * งานแย่งล็อกกัน) ผลคือ **ไฟล์หายแต่ทะเบียนบอกว่ายังไม่ถูกทำลาย**
             *
             * ทะเบียนจึงต้องเป็นแหล่งความจริง: ถ้าเขียนทะเบียนไม่สำเร็จ ไม่มีไฟล์ใด
             * ถูกแตะเลย · ในทางกลับกัน ไฟล์ที่ยังค้างอยู่หลังทะเบียนบอกว่าทำลายแล้ว
             * เป็นสภาพที่ตรวจพบและลบซ้ำได้ ต่างจากไฟล์ที่หายไปโดยไม่มีทะเบียนรองรับ
             */
            $disk = Storage::disk('private_uploads');
            $hashes = [];
            $paths = [];

            foreach ($document->versions as $version) {
                /** @var DocumentVersion $version */
                if ($version->pdf_file_path !== null && $disk->exists($version->pdf_file_path)) {
                    $hashes[] = $version->file_hash;
                    $paths[] = $version->pdf_file_path;
                }
            }

            DB::afterCommit(function () use ($disk, $paths) {
                foreach ($paths as $path) {
                    $disk->delete($path);
                }
            });

            $schedule->forceFill([
                'status' => RetentionSchedule::STATUS_DISPOSED,
                'disposed_at' => $this->clock->now(),
                'disposed_by' => $actor->id,
                'disposal_note' => $note,
                // hash ของไฟล์ที่ถูกทำลาย — หลักฐานเดียวที่เหลือว่าทำลายไฟล์ไหน
                'disposed_file_hash' => $hashes === [] ? null : hash('sha512', implode('|', $hashes)),
            ])->save();

            $this->audit->log('saraban.retention.disposed', $document, $document->id, null, [
                'file_count' => count($hashes),
                'disposed_file_hash' => $schedule->disposed_file_hash,
                'note' => $note,
            ], $note, $actor->id);

            return $schedule;
        });
    }

    /**
     * เอกสารที่ครบกำหนดแล้วและยังไม่ถูกทำลาย
     *
     * **ไม่รวมเอกสารที่ถูกอายัด** — แสดงในคิวทำลายแล้วกดไม่ได้คือการเสียเวลา
     * ของผู้ใช้ และทำให้คิวดูเหมือนมีงานค้างทั้งที่ไม่มี
     *
     * **ไม่รวมเอกสารที่ `$viewer` เปิดดูไม่ได้** เมื่อระบุผู้เรียกมา — คิวที่ไม่กรอง
     * ด้วยขอบเขตเดียวกับหน้าทะเบียน คือช่องทางค้นพบว่ามีหนังสือฉบับใดอยู่บ้าง
     * ในหน่วยงานอื่น · ตัดสินด้วย `DocumentPolicy` ตัวเดียวกับที่ใช้ทุกที่
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueForDisposal(?User $viewer = null): array
    {
        $onHold = LegalHold::query()->whereNull('released_at')->pluck('document_id');

        return RetentionSchedule::query()
            /*
             * ต้องดึง `owner_org_unit_id` และ `created_by` มาด้วย เพราะ
             * `DocumentPolicy::view()` ตัดสินจากสองคอลัมน์นี้ — เลือกคอลัมน์
             * ไม่ครบแล้วเอาไปตัดสินสิทธิ์ คือการตัดสินจากข้อมูลที่ขาด
             */
            ->with('document:id,uuid,subject,direction,status,owner_org_unit_id,created_by')
            ->where('status', '!=', RetentionSchedule::STATUS_DISPOSED)
            ->whereDate('retain_until', '<=', $this->clock->today())
            ->whereNotIn('document_id', $onHold)
            ->get()
            ->filter(fn (RetentionSchedule $schedule) => $viewer === null
                || ($schedule->document !== null && $viewer->can('view', $schedule->document)))
            ->values()
            ->map(fn (RetentionSchedule $schedule) => [
                'id' => $schedule->id,
                'status' => $schedule->status,
                'retain_until' => $schedule->retain_until->toDateString(),
                'document' => $schedule->document?->only(['id', 'uuid', 'subject', 'direction', 'status']),
            ])
            ->all();
    }
}
