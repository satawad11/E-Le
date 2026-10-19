<?php

namespace Tests\Legacy\Feature;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PromotionScreeningTest extends TestCase
{
    use RefreshDatabase;

    private int $fiscalYear;

    private Carbon $insideRoundOne;

    protected function setUp(): void
    {
        parent::setUp();
        // ตรึงเวลาไว้กลางรอบที่ 1 (1 ต.ค. 2025 – 31 มี.ค. 2026) ให้ผลทดสอบไม่ผูกกับวันที่รันจริง
        $this->fiscalYear = 2026;
        $this->insideRoundOne = Carbon::create(2025, 11, 10);
    }

    private function hrUser(): User
    {
        $hr = User::factory()->create();
        $hr->givePermissionTo(Permission::findOrCreate('leave.report.view', 'web'));

        return $hr;
    }

    private function staff(string $name): User
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $dept = Department::firstOrCreate(['name' => 'แผนกทดสอบ']);
        $user = User::factory()->create([
            'name' => $name,
            'department_id' => $dept->id,
            'personnel_type_id' => $personnel->id,
            'is_active' => true,
        ]);
        $user->profile()->create(['position' => 'ครู', 'start_date' => '2015-05-01']);

        // PersonnelSyncService อ่าน $user->profile ระหว่าง observer ตอน create
        // relation จึงถูก cache เป็น null ไปแล้วก่อนบรรทัดข้างบนจะสร้าง profile
        // ต้องคืน instance ใหม่ ไม่งั้น $user->profile ยังเป็น null
        return $user->fresh();
    }

    private function approvedLeave(User $user, LeaveType $type, float $days): void
    {
        Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => $this->insideRoundOne->toDateString(),
            'end_date' => $this->insideRoundOne->copy()->addDays((int) $days)->toDateString(),
            'total_days' => $days, 'paid_days' => $days, 'reason' => 'ทดสอบ',
            'status' => 'Approved', 'approval_level' => 1,
        ]);
    }

    private function screen(User $hr, array $extra = [])
    {
        return $this->actingAs($hr)->getJson('/api/reports/promotion-screening?'.http_build_query(array_merge([
            'year' => $this->fiscalYear, 'round' => 1,
        ], $extra)));
    }

    public function test_flags_staff_whose_countable_leave_exceeds_the_threshold(): void
    {
        $hr = $this->hrUser();
        $user = $this->staff('ผู้ลาเยอะ');
        $sick = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => 'S', 'counts_toward_promotion' => true]);
        $this->approvedLeave($user, $sick, 25);

        $row = collect($this->screen($hr)->assertOk()->json('rows'))->firstWhere('user_id', $user->id);

        $this->assertEquals(25.0, $row['leave_days']);
        $this->assertFalse($row['passed']);
        $this->assertStringContainsString('ลาเกินเกณฑ์', $row['flags'][0]);
    }

    public function test_leave_types_excluded_from_promotion_count_do_not_flag_staff(): void
    {
        $hr = $this->hrUser();
        $user = $this->staff('ผู้ลาอุปสมบท');
        $ordination = LeaveType::create(['name' => 'ลาอุปสมบท', 'code_prefix' => 'O', 'counts_toward_promotion' => false]);
        $this->approvedLeave($user, $ordination, 90);

        $row = collect($this->screen($hr)->assertOk()->json('rows'))->firstWhere('user_id', $user->id);

        $this->assertEquals(0.0, $row['leave_days']);
        $this->assertEquals(90.0, $row['excluded_days']);
        $this->assertTrue($row['passed']);
    }

    public function test_flags_staff_who_were_late_too_many_times(): void
    {
        $hr = $this->hrUser();
        $user = $this->staff('ผู้มาสายบ่อย');

        for ($i = 0; $i < 20; $i++) {
            Attendance::create([
                'user_id' => $user->id,
                'date' => $this->insideRoundOne->copy()->addDays($i)->toDateString(),
                'check_in_time' => '09:15:00', 'status' => 'Late', 'late_minutes' => 15,
            ]);
        }

        $row = collect($this->screen($hr)->assertOk()->json('rows'))->firstWhere('user_id', $user->id);

        $this->assertSame(20, $row['late_times']);
        $this->assertSame(300, $row['late_minutes']);
        $this->assertFalse($row['passed']);
        $this->assertStringContainsString('มาสายเกินเกณฑ์', implode(' ', $row['flags']));
    }

    public function test_thresholds_can_be_adjusted_per_request(): void
    {
        $hr = $this->hrUser();
        $user = $this->staff('ผู้ลาพอประมาณ');
        $sick = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => 'S', 'counts_toward_promotion' => true]);
        $this->approvedLeave($user, $sick, 25);

        $lenient = collect($this->screen($hr, ['max_leave_days' => 30])->assertOk()->json('rows'))->firstWhere('user_id', $user->id);
        $strict = collect($this->screen($hr, ['max_leave_days' => 10])->assertOk()->json('rows'))->firstWhere('user_id', $user->id);

        $this->assertTrue($lenient['passed']);
        $this->assertFalse($strict['passed']);
    }

    public function test_staff_appointed_mid_round_is_flagged_for_insufficient_service(): void
    {
        $hr = $this->hrUser();
        $user = $this->staff('ผู้บรรจุใหม่');
        // บรรจุ 1 ก.พ. 2026 → เหลือเวลาในรอบถึง 31 มี.ค. ไม่ถึง 4 เดือน
        $user->profile->update(['start_date' => '2026-02-01']);

        $row = collect($this->screen($hr)->assertOk()->json('rows'))->firstWhere('user_id', $user->id);

        $this->assertFalse($row['passed']);
        $this->assertStringContainsString('เวลาปฏิบัติราชการไม่ครบ', implode(' ', $row['flags']));
    }

    public function test_full_round_service_time_is_exactly_six_months(): void
    {
        $hr = $this->hrUser();
        $user = $this->staff('ผู้อยู่ครบรอบ');

        $row = collect($this->screen($hr)->assertOk()->json('rows'))->firstWhere('user_id', $user->id);

        // 1 ต.ค. 2025 – 31 มี.ค. 2026 ต้องได้ 6 เดือนพอดี ไม่ใช่ 5.99999…
        $this->assertSame(6.0, (float) $row['service_months']);
        $this->assertTrue($row['passed']);
    }

    public function test_service_time_of_mid_round_appointment_is_counted_from_start_date(): void
    {
        $hr = $this->hrUser();
        $user = $this->staff('ผู้บรรจุกลางรอบ');
        // บรรจุ 1 ธ.ค. 2025 → ถึงสิ้นรอบ 31 มี.ค. 2026 = 4 เดือนพอดี ผ่านเกณฑ์ขั้นต่ำ
        $user->profile->update(['start_date' => '2025-12-01']);

        $row = collect($this->screen($hr)->assertOk()->json('rows'))->firstWhere('user_id', $user->id);

        $this->assertSame(4.0, (float) $row['service_months']);
        $this->assertTrue($row['passed']);
    }

    public function test_report_requires_permission(): void
    {
        $this->actingAs($this->staff('พนักงานทั่วไป'))
            ->getJson('/api/reports/promotion-screening')
            ->assertForbidden();
    }

    public function test_csv_export_contains_every_row(): void
    {
        $hr = $this->hrUser();
        $this->staff('คนที่หนึ่ง');
        $this->staff('คนที่สอง');

        $response = $this->actingAs($hr)->get("/api/reports/promotion-screening.csv?year={$this->fiscalYear}&round=1");

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('คนที่หนึ่ง', $content);
        $this->assertStringContainsString('คนที่สอง', $content);
    }
}
