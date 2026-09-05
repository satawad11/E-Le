<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INT-04 — ประวัติที่แก้ย้อนหลังแล้วรู้ (SEC-GAP-03) และเวลาอ้างอิงที่เปลี่ยนแหล่งได้ (SoT §10)
 *
 * เดิม `audit_logs` เป็น append-only ด้วย **ข้อตกลงในโค้ด** เท่านั้น — กันได้เฉพาะ
 * โค้ดของแอป ใครที่เข้าถึงฐานข้อมูลได้ยังแก้หรือลบแถวได้โดยไม่มีอะไรบอกได้เลย
 * ซึ่งไม่พอสำหรับหลักฐานทางราชการตาม SoT §25
 */
class AuditChainAndTrustedTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustedClock::release();

        parent::tearDown();
    }

    private function writeEntries(int $count): void
    {
        $actor = User::factory()->create();
        $logger = app(AuditLogger::class);

        for ($i = 1; $i <= $count; $i++) {
            $logger->log("saraban.document.tested.{$i}", 'saraban_document', $i, null, ['step' => $i], null, $actor->id);
        }
    }

    // ── โซ่แฮชของประวัติ ────────────────────────────────────────────────────

    public function test_every_entry_is_linked_to_the_one_before_it(): void
    {
        $this->writeEntries(3);

        /*
         * ไม่ยึดจำนวนแถวตายตัว — การสร้างผู้ใช้เขียนประวัติของตัวเองด้วย
         * (`UserObserver`) สิ่งที่ต้องจริงคือ "ทุกแถวต่อกันเป็นโซ่เดียว"
         * ไม่ใช่ "มีกี่แถว" ซึ่งขึ้นกับว่าอะไรบันทึกประวัติไว้บ้าง
         */
        $entries = AuditLog::query()->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(3, $entries->count());
        $this->assertNull($entries[0]->previous_hash, 'แถวแรกของโซ่ไม่มีแถวก่อนหน้า');

        foreach ($entries as $index => $entry) {
            $this->assertNotNull($entry->entry_hash, 'ทุกแถวต้องมีแฮชของตัวเอง');

            if ($index > 0) {
                $this->assertSame($entries[$index - 1]->entry_hash, $entry->previous_hash);
            }
        }
    }

    public function test_the_chain_verifies_clean_when_nothing_was_tampered_with(): void
    {
        $this->writeEntries(5);

        $this->artisan('audit:verify')
            ->expectsOutputToContain('ต่อเนื่องครบถ้วน')
            ->assertExitCode(0);
    }

    /**
     * แก้เนื้อหาย้อนหลังโดยเลี่ยงโมเดล — เหมือนคนที่เข้าถึงฐานข้อมูลได้โดยตรง
     *
     * นี่คือกรณีที่ด่านเดิม (`AuditLog::booted()`) จับไม่ได้เลย
     */
    public function test_editing_history_straight_in_the_database_is_detected(): void
    {
        $this->writeEntries(3);

        $target = AuditLog::query()->orderBy('id')->skip(1)->first();

        DB::table('audit_logs')->where('id', $target->id)->update(['action' => 'ถูกแก้ย้อนหลัง']);

        $this->artisan('audit:verify')
            ->expectsOutputToContain('ถูกแก้ย้อนหลัง')
            ->assertExitCode(1);
    }

    public function test_deleting_a_row_from_the_middle_breaks_the_chain_and_is_detected(): void
    {
        $this->writeEntries(4);

        $target = AuditLog::query()->orderBy('id')->skip(1)->first();

        DB::table('audit_logs')->where('id', $target->id)->delete();

        $this->artisan('audit:verify')
            ->expectsOutputToContain('โซ่ขาด')
            ->assertExitCode(1);
    }

    /**
     * แถวที่มีอยู่ก่อนเริ่มลงแฮชต้องไม่ถูกนับว่าผิด
     *
     * migration เป็นการเพิ่มอย่างเดียว ระบบที่ใช้งานอยู่แล้วมีประวัติเดิมที่ไม่มีแฮช
     * ถ้าตีว่าเป็นความผิดปกติ คำสั่งตรวจจะแดงตลอดไปตั้งแต่วันแรกจนไม่มีใครเชื่อถือ
     */
    public function test_history_written_before_the_chain_started_is_not_reported_as_tampering(): void
    {
        DB::table('audit_logs')->insert([
            'action' => 'legacy.entry',
            'resource_type' => 'leave',
            'resource_id' => '1',
            'created_at' => now(),
            'entry_hash' => null,
            'previous_hash' => null,
        ]);

        $this->writeEntries(2);

        $this->artisan('audit:verify')->assertExitCode(0);
    }

    public function test_the_model_still_refuses_updates_and_deletes_through_the_application(): void
    {
        $this->writeEntries(1);

        $entry = AuditLog::query()->first();

        $this->expectException(\LogicException::class);

        $entry->update(['action' => 'เปลี่ยนไม่ได้']);
    }

    // ── เวลาอ้างอิง ─────────────────────────────────────────────────────────

    public function test_the_trusted_clock_can_be_frozen_without_touching_the_rest_of_the_system(): void
    {
        $at = Carbon::parse('2026-09-03 08:30:00');

        TrustedClock::freeze($at);

        $clock = app(TrustedClock::class);

        $this->assertSame('2026-09-03 08:30:00', $clock->now()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-03', $clock->today());

        TrustedClock::release();

        $this->assertNotSame('2026-09-03 08:30:00', app(TrustedClock::class)->now()->format('Y-m-d H:i:s'));
    }
}
