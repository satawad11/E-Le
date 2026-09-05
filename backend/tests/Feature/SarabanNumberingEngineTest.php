<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Services\Saraban\NumberingException;
use App\Services\Saraban\NumberingService;
use App\Services\TrustedClock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INT-06 — เครื่องออกเลขหนังสือ (SoT §9)
 *
 * ข้อบังคับที่ชุดนี้ตรึงไว้ เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **เลขซ้ำไม่ได้เด็ดขาด** — ทั้งจากตรรกะและจากด่านของฐานข้อมูล
 *   2. **เลขที่ออกไปแล้วห้ามนำกลับมาใช้** (SoT §9 ระบุตรง ๆ)
 *   3. **ปี พ.ศ. + รีเซ็ตทุกปี** — ขึ้นปีใหม่ต้องเริ่มนับหนึ่งใหม่
 *   4. **เปลี่ยนสถานะข้ามขั้นไม่ได้**
 */
class SarabanNumberingEngineTest extends TestCase
{
    use RefreshDatabase;

    private NumberSeries $series;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer = User::factory()->create();

        $this->series = NumberSeries::create([
            'code' => 'outgoing-main',
            'name' => 'หนังสือส่ง สำนักงาน',
            'category' => 'outgoing',
            'prefix' => 'ศธ 04001',
        ]);
    }

    private function numbering(): NumberingService
    {
        return app(NumberingService::class);
    }

    private function document(): Document
    {
        $unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-'.Str::random(6),
            'name' => 'ฝ่ายทดสอบ',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $type = DocumentType::create([
            'code' => 'numbering-test',
            'name' => 'หนังสือทดสอบ',
            'category' => DocumentType::CATEGORY_OUTGOING,
        ]);

        return Document::create([
            'document_type_id' => $type->id,
            'direction' => Document::DIRECTION_OUTGOING,
            'subject' => 'หนังสือทดสอบเลข',
            'owner_org_unit_id' => $unit->id,
            'created_by' => $this->officer->id,
        ]);
    }

    protected function tearDown(): void
    {
        TrustedClock::release();

        parent::tearDown();
    }

    // ── เลขเดินหน้าและไม่ซ้ำ ────────────────────────────────────────────────

    public function test_numbers_run_in_order_without_gaps_or_repeats(): void
    {
        $seen = [];

        for ($i = 0; $i < 25; $i++) {
            $seen[] = $this->numbering()->reserve($this->series, $this->officer)->running_number;
        }

        $this->assertSame(range(1, 25), $seen);
        $this->assertSame(25, count(array_unique($seen)));
    }

    /**
     * ด่านสุดท้ายของฐานข้อมูล
     *
     * ต่อให้ตรรกะพลาดจนพยายามเขียนเลขซ้ำ ฐานข้อมูลต้องไม่ยอม — ข้อนี้คือสิ่งที่
     * ทำให้ "เลขไม่ซ้ำ" เป็นการรับประกัน ไม่ใช่แค่ความตั้งใจของโค้ด
     */
    public function test_the_database_refuses_a_duplicate_number_in_the_same_series_and_year(): void
    {
        $first = $this->numbering()->reserve($this->series, $this->officer);

        $this->expectException(QueryException::class);

        DocumentNumber::create([
            'number_series_id' => $this->series->id,
            'buddhist_year' => $first->buddhist_year,
            'running_number' => $first->running_number,
            'formatted' => 'ซ้ำ',
            'status' => DocumentNumber::STATUS_RESERVED,
        ]);
    }

    /**
     * SoT §9 ห้าม `SELECT MAX()+1` แบบไม่มี lock โดยระบุตรง ๆ
     *
     * ตรวจที่ซอร์สเพราะเป็นข้อบังคับเชิงวิธีการ ไม่ใช่พฤติกรรมที่สังเกตจากผลลัพธ์
     * ได้ในชุดทดสอบกระบวนการเดียว — เลขที่ออกมาถูกต้องไม่ได้แปลว่าวิธีที่ใช้ปลอดภัย
     * ต่อการเรียกพร้อมกัน
     */
    public function test_the_counter_is_taken_under_a_row_lock_and_never_with_a_bare_max_query(): void
    {
        $source = file_get_contents(app_path('Services/Saraban/NumberingService.php'));

        /*
         * ตัดคอมเมนต์ออกก่อนตรวจ — ด่านนี้ตรวจ "โค้ด" ไม่ใช่ "คำอธิบาย"
         * คำอธิบายในไฟล์นั้นอ้างถึง `SELECT MAX()+1` เพื่อบอกว่าห้ามใช้
         * ถ้าไม่ตัดคอมเมนต์ออก ด่านจะจับคำอธิบายว่าเป็นการฝ่าฝืนเสียเอง
         * (เป็นบทเรียนเดียวกับ RISK-09 ที่ `SarabanCoreContractTest` เจอมาก่อน)
         */
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

                continue;
            }

            $code .= $token;
        }

        $this->assertStringContainsString('lockForUpdate()', $code, 'ต้องล็อกแถวตัวนับก่อนเดินเลข');
        $this->assertDoesNotMatchRegularExpression(
            '/->max\(|MAX\(/i',
            $code,
            'ห้ามหาเลขถัดไปด้วย MAX() ตาม SoT §9',
        );
    }

    // ── ปี พ.ศ. และการรีเซ็ตรายปี ──────────────────────────────────────────

    public function test_numbers_are_recorded_in_the_buddhist_year(): void
    {
        TrustedClock::freeze(Carbon::parse('2026-09-04 09:00:00'));

        $number = $this->numbering()->reserve($this->series, $this->officer);

        $this->assertSame(2569, $number->buddhist_year, 'ค.ศ. 2026 ต้องเป็น พ.ศ. 2569');
    }

    public function test_the_counter_restarts_at_one_when_the_year_changes(): void
    {
        TrustedClock::freeze(Carbon::parse('2026-12-31 23:00:00'));
        $this->numbering()->reserve($this->series, $this->officer);
        $lastOfYear = $this->numbering()->reserve($this->series, $this->officer);

        TrustedClock::freeze(Carbon::parse('2027-01-01 08:00:00'));
        $firstOfNextYear = $this->numbering()->reserve($this->series, $this->officer);

        $this->assertSame(2, $lastOfYear->running_number);
        $this->assertSame(2569, $lastOfYear->buddhist_year);

        $this->assertSame(1, $firstOfNextYear->running_number, 'ขึ้นปีใหม่ต้องเริ่มนับหนึ่งใหม่');
        $this->assertSame(2570, $firstOfNextYear->buddhist_year);
    }

    public function test_two_series_keep_independent_counters(): void
    {
        $internal = NumberSeries::create([
            'code' => 'internal-main',
            'name' => 'หนังสือภายใน',
            'category' => 'internal',
        ]);

        $this->numbering()->reserve($this->series, $this->officer);
        $this->numbering()->reserve($this->series, $this->officer);
        $first = $this->numbering()->reserve($internal, $this->officer);

        $this->assertSame(1, $first->running_number, 'ชุดเลขแต่ละชุดต้องนับแยกกัน');
    }

    // ── วงจรสถานะ ───────────────────────────────────────────────────────────

    public function test_a_number_walks_the_full_lifecycle_to_issued(): void
    {
        $number = $this->numbering()->reserve($this->series, $this->officer);
        $this->assertSame(DocumentNumber::STATUS_RESERVED, $number->status);

        $number = $this->numbering()->allocate($number, $this->document(), $this->officer);
        $this->assertSame(DocumentNumber::STATUS_ALLOCATED, $number->status);

        $number = $this->numbering()->issue($number, $this->officer);
        $this->assertSame(DocumentNumber::STATUS_ISSUED, $number->status);
        $this->assertNotNull($number->issued_at);
    }

    public function test_a_number_cannot_be_issued_before_it_is_attached_to_a_document(): void
    {
        $number = $this->numbering()->reserve($this->series, $this->officer);

        $this->expectException(NumberingException::class);

        $this->numbering()->issue($number, $this->officer);
    }

    /** ข้อบังคับตรงตัวของ SoT §9 — ISSUED ห้าม reuse */
    public function test_an_issued_number_is_never_handed_out_again(): void
    {
        $issued = $this->numbering()->issue(
            $this->numbering()->allocate(
                $this->numbering()->reserve($this->series, $this->officer),
                $this->document(),
                $this->officer,
            ),
            $this->officer,
        );

        $next = $this->numbering()->reserve($this->series, $this->officer);

        $this->assertNotSame($issued->running_number, $next->running_number);
        $this->assertSame($issued->running_number + 1, $next->running_number);

        // และจะคืนเลขที่ออกไปแล้วก็ไม่ได้
        $this->expectException(NumberingException::class);
        $this->numbering()->release($issued->fresh(), $this->officer);
    }

    public function test_a_released_number_comes_back_before_the_counter_moves_on(): void
    {
        $first = $this->numbering()->reserve($this->series, $this->officer);
        $second = $this->numbering()->reserve($this->series, $this->officer);

        $this->numbering()->release($first, $this->officer, 'ยกเลิกการจอง');

        $recycled = $this->numbering()->reserve($this->series, $this->officer);

        $this->assertSame($first->running_number, $recycled->running_number, 'เลขที่คืนแล้วต้องถูกหยิบกลับมาใช้ก่อน');
        $this->assertSame(DocumentNumber::STATUS_RESERVED, $recycled->status);
        $this->assertSame(2, $second->running_number);

        // หยิบไปแล้วต้องไม่ถูกหยิบซ้ำ
        $afterwards = $this->numbering()->reserve($this->series, $this->officer);
        $this->assertSame(3, $afterwards->running_number);
    }

    public function test_a_cancelled_number_is_burned_and_never_reused(): void
    {
        $number = $this->numbering()->reserve($this->series, $this->officer);
        $this->numbering()->cancel($number, $this->officer, 'ออกเลขผิดชุด');

        $next = $this->numbering()->reserve($this->series, $this->officer);

        $this->assertSame(2, $next->running_number, 'เลขที่ยกเลิกแล้วต้องไม่ถูกนำกลับมาใช้');
        $this->assertSame(DocumentNumber::STATUS_CANCELLED, $number->fresh()->status);
    }

    public function test_voiding_keeps_the_number_in_the_register_as_evidence(): void
    {
        $issued = $this->numbering()->issue(
            $this->numbering()->allocate(
                $this->numbering()->reserve($this->series, $this->officer),
                $this->document(),
                $this->officer,
            ),
            $this->officer,
        );

        $voided = $this->numbering()->void($issued, $this->officer, 'หนังสือถูกยกเลิก');

        $this->assertSame(DocumentNumber::STATUS_VOID, $voided->status);
        $this->assertSame('หนังสือถูกยกเลิก', $voided->reason);

        // ยังอยู่ในทะเบียน ไม่ได้ถูกลบจนเกิดช่องว่างที่อธิบายไม่ได้
        $this->assertDatabaseHas('saraban_document_numbers', [
            'id' => $voided->id,
            'running_number' => $issued->running_number,
        ]);

        $next = $this->numbering()->reserve($this->series, $this->officer);
        $this->assertSame($issued->running_number + 1, $next->running_number);
    }

    public function test_a_number_that_was_never_issued_cannot_be_voided(): void
    {
        $number = $this->numbering()->reserve($this->series, $this->officer);

        $this->expectException(NumberingException::class);

        $this->numbering()->void($number, $this->officer, 'ยังไม่ได้ออกเลข');
    }

    // ── ประวัติ ─────────────────────────────────────────────────────────────

    public function test_every_state_change_is_written_to_the_central_audit_log(): void
    {
        $number = $this->numbering()->reserve($this->series, $this->officer);
        $this->numbering()->allocate($number, $this->document(), $this->officer);

        foreach (['saraban.number.reserved', 'saraban.number.allocated'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'resource_id' => (string) $number->id,
                'actor_id' => $this->officer->id,
            ]);
        }

        $this->assertSame(
            0,
            DB::table('audit_logs')->where('action', 'like', 'saraban.number.%')->whereNull('entry_hash')->count(),
            'ประวัติของเลขต้องเข้าโซ่แฮชเหมือนประวัติอื่นทั้งหมด',
        );
    }
}
