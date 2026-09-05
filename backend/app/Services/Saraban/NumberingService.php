<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\NumberSequence;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use Illuminate\Support\Facades\DB;

/**
 * เครื่องออกเลขหนังสือ (SoT §9 · INT-06)
 *
 * ข้อบังคับสามข้อที่คลาสนี้มีไว้เพื่อรักษา:
 *
 *   1. **ห้าม `SELECT MAX()+1` แบบไม่มี lock** — ใช้แถวตัวนับแถวเดียวต่อ
 *      (ชุดเลข, ปี) แล้ว `lockForUpdate()` ล็อกแถวนั้น สองคำขอที่จองเลขพร้อมกัน
 *      จึงเข้าคิวกัน ไม่ได้เลขเดียวกัน · ด่านสุดท้ายคือ unique constraint
 *      `(ชุดเลข, ปี, เลขที่)` ที่ฐานข้อมูลบังคับไว้อีกชั้น
 *
 *   2. **เลขที่ ISSUED แล้วห้ามนำกลับมาใช้** — และ CANCELLED/VOID ก็เช่นกัน
 *      มีเพียง RELEASED (จองแล้วคืนโดยยังไม่ได้ใช้) เท่านั้นที่กลับมาใช้ได้
 *
 *   3. **ทุกการเปลี่ยนสถานะต้องเปลี่ยนได้ตามผังเท่านั้น** — การข้ามขั้น
 *      เช่นออกเลขที่ยังไม่ได้ผูกเอกสาร ถูกปฏิเสธด้วย exception ไม่ใช่ปล่อยผ่าน
 *
 * ปี พ.ศ. มาจาก `TrustedClock` ตามสัญญา INT-01 §1.15 — ไม่อ่านนาฬิกาเครื่องตรง ๆ
 */
class NumberingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    /** ปี พ.ศ. ปัจจุบันตามเวลาอ้างอิงของระบบ */
    public function currentBuddhistYear(): int
    {
        return $this->clock->now()->year + 543;
    }

    /**
     * จองเลขถัดไปของชุดเลขนี้
     *
     * คืนเลขที่เคย RELEASED ไว้ก่อน (เลขน้อยสุด) เพื่อไม่ให้ทะเบียนมีเลขว่าง
     * โดยไม่จำเป็น — ถ้าไม่มีจึงเดินตัวนับไปข้างหน้า
     */
    public function reserve(NumberSeries $series, User $actor, ?int $buddhistYear = null): DocumentNumber
    {
        $year = $buddhistYear ?? $this->currentBuddhistYear();

        $number = DB::transaction(function () use ($series, $actor, $year) {
            $recycled = $this->claimReleasedNumber($series, $year, $actor);

            if ($recycled !== null) {
                return $recycled;
            }

            $running = $this->nextRunningNumber($series, $year);

            return DocumentNumber::create([
                'number_series_id' => $series->id,
                'buddhist_year' => $year,
                'running_number' => $running,
                'formatted' => $this->format($series, $year, $running),
                'status' => DocumentNumber::STATUS_RESERVED,
                'reserved_by' => $actor->id,
                'reserved_at' => $this->clock->now(),
            ]);
        }, 5);

        $this->record('saraban.number.reserved', $number, $actor);

        return $number;
    }

    /** ผูกเลขที่จองไว้เข้ากับหนังสือ */
    public function allocate(DocumentNumber $number, Document $document, User $actor): DocumentNumber
    {
        $this->assertStatus($number, [DocumentNumber::STATUS_RESERVED], 'ผูกเลขกับหนังสือ');

        $number->update([
            'status' => DocumentNumber::STATUS_ALLOCATED,
            'document_id' => $document->id,
        ]);

        $this->record('saraban.number.allocated', $number, $actor);

        return $number->fresh();
    }

    /**
     * ออกเลข — จุดที่เลขกลายเป็นของถาวร
     *
     * หลังจากนี้เลขนี้ห้ามนำกลับมาใช้กับหนังสือฉบับอื่นตลอดไป (SoT §9)
     */
    public function issue(DocumentNumber $number, User $actor): DocumentNumber
    {
        $this->assertStatus($number, [DocumentNumber::STATUS_ALLOCATED], 'ออกเลขหนังสือ');

        if ($number->document_id === null) {
            throw new NumberingException('ออกเลขที่ยังไม่ได้ผูกกับหนังสือไม่ได้');
        }

        $number->update([
            'status' => DocumentNumber::STATUS_ISSUED,
            'issued_at' => $this->clock->now(),
        ]);

        $this->record('saraban.number.issued', $number, $actor);

        return $number->fresh();
    }

    /**
     * คืนเลขที่จองไว้แต่ยังไม่ได้ใช้ — เลขกลับมาใช้ได้อีก
     *
     * ทำได้เฉพาะตอนที่ยังไม่ออกเลข ถ้าออกไปแล้วต้องใช้ `void()` ซึ่งเผาเลขทิ้ง
     */
    public function release(DocumentNumber $number, User $actor, ?string $reason = null): DocumentNumber
    {
        $this->assertStatus($number, [DocumentNumber::STATUS_RESERVED, DocumentNumber::STATUS_ALLOCATED], 'คืนเลข');

        $number->update([
            'status' => DocumentNumber::STATUS_RELEASED,
            'document_id' => null,
            'released_at' => $this->clock->now(),
            'reason' => $reason,
        ]);

        $this->record('saraban.number.released', $number, $actor);

        return $number->fresh();
    }

    /** ยกเลิกเลขอย่างเป็นทางการ — เลขนี้จะไม่ถูกใช้อีกเลย */
    public function cancel(DocumentNumber $number, User $actor, string $reason): DocumentNumber
    {
        $this->assertStatus($number, [DocumentNumber::STATUS_RESERVED, DocumentNumber::STATUS_ALLOCATED], 'ยกเลิกเลข');

        $number->update([
            'status' => DocumentNumber::STATUS_CANCELLED,
            'closed_at' => $this->clock->now(),
            'reason' => $reason,
        ]);

        $this->record('saraban.number.cancelled', $number, $actor);

        return $number->fresh();
    }

    /**
     * เพิกถอนเลขที่ออกไปแล้ว
     *
     * ใช้เมื่อหนังสือที่ออกเลขไปแล้วถูกยกเลิก — เลขยังคงอยู่ในทะเบียนเป็นหลักฐาน
     * ว่าเคยถูกออกและถูกเพิกถอน ไม่ใช่ลบทิ้งจนทะเบียนมีช่องว่างที่อธิบายไม่ได้
     */
    public function void(DocumentNumber $number, User $actor, string $reason): DocumentNumber
    {
        $this->assertStatus($number, [DocumentNumber::STATUS_ISSUED], 'เพิกถอนเลข');

        $number->update([
            'status' => DocumentNumber::STATUS_VOID,
            'closed_at' => $this->clock->now(),
            'reason' => $reason,
        ]);

        $this->record('saraban.number.voided', $number, $actor);

        return $number->fresh();
    }

    /**
     * เลขถัดไปของตัวนับ — จุดที่ต้องปลอดภัยต่อการเรียกพร้อมกัน
     *
     * `firstOrCreate` สร้างแถวตัวนับของปีนั้นถ้ายังไม่มี จากนั้น `lockForUpdate()`
     * ล็อกแถวเดียวนั้นจนจบ transaction — คำขออื่นที่เข้ามาพร้อมกันจะรอ
     * แล้วอ่านค่าที่เพิ่มแล้ว จึงไม่มีทางได้เลขเดียวกัน
     */
    private function nextRunningNumber(NumberSeries $series, int $year): int
    {
        NumberSequence::firstOrCreate(
            ['number_series_id' => $series->id, 'buddhist_year' => $year],
            ['last_number' => 0],
        );

        $sequence = NumberSequence::query()
            ->where('number_series_id', $series->id)
            ->where('buddhist_year', $year)
            ->lockForUpdate()
            ->firstOrFail();

        $next = $sequence->last_number + 1;
        $sequence->update(['last_number' => $next]);

        return $next;
    }

    /**
     * หยิบเลขที่เคยคืนไว้กลับมาใช้ (ถ้ามี)
     *
     * ล็อกแถวเลขนั้นด้วยเพื่อไม่ให้สองคำขอหยิบเลขคืนใบเดียวกันไปพร้อมกัน
     */
    private function claimReleasedNumber(NumberSeries $series, int $year, User $actor): ?DocumentNumber
    {
        $released = DocumentNumber::query()
            ->where('number_series_id', $series->id)
            ->where('buddhist_year', $year)
            ->where('status', DocumentNumber::STATUS_RELEASED)
            ->orderBy('running_number')
            ->lockForUpdate()
            ->first();

        if ($released === null) {
            return null;
        }

        $released->update([
            'status' => DocumentNumber::STATUS_RESERVED,
            'reserved_by' => $actor->id,
            'reserved_at' => $this->clock->now(),
            'released_at' => null,
            'reason' => null,
        ]);

        return $released->fresh();
    }

    /** รูปแบบเลขที่แสดงจริง เช่น `ศธ 04001/0007` */
    private function format(NumberSeries $series, int $year, int $running): string
    {
        $sequence = str_pad((string) $running, 4, '0', STR_PAD_LEFT);

        return $series->prefix
            ? "{$series->prefix}/{$sequence}"
            : "{$sequence}/{$year}";
    }

    /**
     * @param  array<int,string>  $allowed
     *
     * @throws NumberingException
     */
    private function assertStatus(DocumentNumber $number, array $allowed, string $action): void
    {
        if (in_array($number->status, $allowed, true)) {
            return;
        }

        throw new NumberingException(sprintf(
            'เลข %s อยู่ในสถานะ "%s" จึง%sไม่ได้',
            $number->formatted,
            $number->status,
            $action,
        ));
    }

    private function record(string $action, DocumentNumber $number, User $actor): void
    {
        $this->audit->log($action, $number, $number->id, null, [
            'formatted' => $number->formatted,
            'status' => $number->status,
            'document_id' => $number->document_id,
        ], $number->reason, $actor->id);
    }
}
