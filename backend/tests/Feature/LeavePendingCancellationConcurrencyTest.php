<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveAuditLog;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\Attendance\LeaveAttendanceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\FreezesClockMidFiscalYear;
use Tests\TestCase;

/**
 * SEC-027 — ยกเลิกใบลาที่รออนุมัติต้องคืนโควตาครั้งเดียวเท่านั้น
 *
 * ก่อนแก้: คืนโควตา · ล้างวันลาในตารางลงเวลา · เปลี่ยนสถานะ · เขียนประวัติ
 * วิ่งเรียงกันนอก transaction และไม่ล็อกแถวใบลาเลย · กดปุ่มยกเลิกซ้ำเร็ว ๆ
 * ทั้งสองคำขออ่านสถานะ `Pending` ได้พร้อมกันแล้วคืนโควตาคนละครั้ง
 * วันลาคงเหลือจึงเพิ่มขึ้นเกินจริงโดยไม่มีร่องรอย
 */
class LeavePendingCancellationConcurrencyTest extends TestCase
{
    use FreezesClockMidFiscalYear;
    use RefreshDatabase;

    private User $owner;

    private LeaveType $type;

    private Leave $leave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClockMidFiscalYear();

        $department = OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบ']);
        $this->type = LeaveType::create(['name' => 'ลาพักผ่อน', 'max_days' => 10]);
        $this->owner = User::factory()->create(['org_unit_id' => $department->id]);

        LeaveBalance::create([
            'user_id' => $this->owner->id,
            'leave_type_id' => $this->type->id,
            'year' => now()->year,
            'total_days' => 10,
            'used' => 0,
            'reserved' => 2,
        ]);

        $this->leave = Leave::create([
            'user_id' => $this->owner->id,
            'leave_type_id' => $this->type->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'total_days' => 2,
            'reserved_days' => 2,
            'reason' => 'ทดสอบการกดซ้ำ',
            'status' => 'Pending',
        ]);
    }

    private function balance(): LeaveBalance
    {
        return LeaveBalance::where('user_id', $this->owner->id)
            ->where('leave_type_id', $this->type->id)
            ->firstOrFail();
    }

    private function cancel(): TestResponse
    {
        return $this->actingAs($this->owner)->postJson("/api/leaves/{$this->leave->id}/cancel");
    }

    /**
     * กดยกเลิกซ้ำ — คำขอที่สองต้องไม่คืนโควตาอีกรอบ
     *
     * ในสภาพแวดล้อมเทสต์ (SQLite) คำขอวิ่งทีละใบ การกดซ้ำจึงเป็นแบบจำลองที่
     * ตรงกับสิ่งที่ผู้ใช้ทำจริงที่สุด ส่วนความพร้อมกันจริง ๆ อาศัย `lockForUpdate()`
     * ที่ใส่ไว้ในเส้นทางเดียวกันนี้
     */
    public function test_pressing_cancel_twice_restores_the_quota_only_once(): void
    {
        $this->cancel()->assertOk()->assertJsonPath('message', 'ยกเลิกใบลาเรียบร้อยแล้ว');

        $afterFirst = $this->balance()->reserved;

        $this->cancel()->assertOk()->assertJsonPath('message', 'ใบลานี้ถูกยกเลิกไปแล้ว');

        $this->assertSame(0.0, (float) $afterFirst);
        $this->assertSame(0.0, (float) $this->balance()->reserved);
        $this->assertSame('Cancelled', $this->leave->fresh()->status);
    }

    /** ประวัติและ audit ต้องถูกสร้างครั้งเดียว ไม่ใช่ครั้งละครั้งที่กด */
    public function test_history_and_audit_are_written_exactly_once(): void
    {
        $this->cancel()->assertOk();
        $this->cancel()->assertOk();

        $this->assertSame(1, LeaveActionLog::where('leave_id', $this->leave->id)->where('action', 'Cancelled')->count());
        $this->assertSame(1, LeaveAuditLog::where('leave_id', $this->leave->id)->where('action', 'pending_cancelled')->count());
    }

    /** ถ้าขั้นตอนใดขั้นตอนหนึ่งล้ม ต้องไม่เหลือสภาพ "คืนโควตาแล้วแต่ยังไม่ยกเลิก" */
    public function test_a_failure_midway_rolls_the_whole_cancellation_back(): void
    {
        $this->mock(LeaveAttendanceSync::class, function ($mock) {
            $mock->shouldReceive('clear')->andThrow(new RuntimeException('ล้างตารางลงเวลาไม่สำเร็จ'));
        });

        try {
            $this->cancel();
        } catch (RuntimeException) {
            // คาดไว้แล้ว — สนใจเฉพาะสภาพของข้อมูลหลังจากนี้
        }

        $this->assertSame('Pending', $this->leave->fresh()->status);
        $this->assertSame(2.0, (float) $this->balance()->reserved);
        $this->assertSame(2.0, (float) $this->leave->fresh()->reserved_days);
        $this->assertSame(0, LeaveActionLog::where('leave_id', $this->leave->id)->where('action', 'Cancelled')->count());
    }

    /** ใบลาที่ไม่ได้อยู่สถานะรออนุมัติแล้วต้องไม่ถูกยกเลิกเงียบ ๆ และโควตาไม่ถูกแตะ */
    public function test_a_leave_that_moved_on_is_not_cancelled_silently(): void
    {
        $this->leave->status = 'Rejected';
        $this->leave->save();

        $this->cancel()->assertStatus(422);

        $this->assertSame('Rejected', $this->leave->fresh()->status);
        $this->assertSame(2.0, (float) $this->balance()->reserved);
    }
}
