<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveEligibilityRulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * submit() ยิงใบลาที่ +1 ถึง +6 สัปดาห์จาก "วันนี้" ถ้าปล่อยให้ใช้เวลาจริง
     * ช่วงประมาณ 6 สัปดาห์ก่อน 1 ต.ค. จะทำให้สองใบตกคนละปีงบประมาณ
     * ตัวนับ max_times_per_year/โควตาจึงรีเซ็ตและเทสต์ล้มเป็นบางฤดู
     * ตรึงนาฬิกาไว้กลางปีงบประมาณเพื่อให้ทุกใบลาอยู่ในปีงบเดียวกันเสมอ
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fiscalYear(): int
    {
        return now()->month >= 10 ? now()->year + 1 : now()->year;
    }

    /** @return array{0: User, 1: LeaveType} */
    private function scenario(array $eligibilityRules, array $profile = []): array
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $type = LeaveType::create(['name' => 'ลาทดสอบเงื่อนไข', 'code_prefix' => 'E']);
        $policy = LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 200,
            'period_basis' => 'fiscal_year', 'exclude_holidays' => true,
            'allow_half_day' => true, 'is_active' => true,
            'eligibility_rules' => $eligibilityRules,
        ]);

        $dept = Department::create(['name' => 'ทดสอบ']);
        $user = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $user->profile()->create($profile + ['position' => 'ครู']);

        LeaveBalance::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $this->fiscalYear(),
            'quota' => 200, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0,
            'leave_policy_id' => $policy->id,
        ]);

        return [$user, $type];
    }

    private function submit(User $user, LeaveType $type, int $weeksAhead = 1)
    {
        $start = now()->addWeeks($weeksAhead)->next(Carbon::MONDAY);

        return $this->actingAs($user)->postJson('/api/leaves', [
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'reason' => 'ทดสอบเงื่อนไข',
        ]);
    }

    public function test_once_in_career_leave_can_only_be_used_one_time(): void
    {
        [$user, $type] = $this->scenario(['once_in_career' => true]);

        $this->submit($user, $type, 1)->assertCreated();

        $this->submit($user, $type, 6)
            ->assertStatus(422)
            ->assertJsonPath('errors.leave_type_id.0', 'การลาประเภทนี้ใช้สิทธิ์ได้เพียงครั้งเดียวตลอดอายุราชการ และพบว่าท่านเคยใช้สิทธิ์แล้ว');
    }

    public function test_gender_restricted_leave_rejects_users_outside_the_condition(): void
    {
        [$user, $type] = $this->scenario(['gender' => 'female'], ['gender' => 'ชาย']);

        $this->submit($user, $type)
            ->assertStatus(422)
            ->assertJsonPath('errors.leave_type_id.0', 'ท่านไม่อยู่ในเงื่อนไขผู้มีสิทธิ์ลาประเภทนี้ตามระเบียบ');
    }

    public function test_gender_restricted_leave_accepts_thai_and_english_values(): void
    {
        [$female, $typeTh] = $this->scenario(['gender' => 'female'], ['gender' => 'หญิง']);
        $this->submit($female, $typeTh)->assertCreated();

        [$english, $typeEn] = $this->scenario(['gender' => 'female'], ['gender' => 'Female']);
        $this->submit($english, $typeEn)->assertCreated();
    }

    public function test_missing_gender_in_profile_is_reported_clearly(): void
    {
        [$user, $type] = $this->scenario(['gender' => 'female'], ['gender' => null]);

        $this->submit($user, $type)
            ->assertStatus(422)
            ->assertJsonPath('errors.leave_type_id.0', 'การลาประเภทนี้จำกัดเพศผู้มีสิทธิ์ กรุณาปรับปรุงข้อมูลเพศในทะเบียนประวัติก่อน');
    }

    public function test_max_times_per_year_is_enforced(): void
    {
        [$user, $type] = $this->scenario(['max_times_per_year' => 1]);

        $this->submit($user, $type, 1)->assertCreated();
        $this->submit($user, $type, 6)
            ->assertStatus(422)
            ->assertJsonPath('errors.leave_type_id.0', 'การลาประเภทนี้ใช้สิทธิ์ได้ไม่เกิน 1 ครั้งต่อปี และท่านใช้ครบแล้ว');
    }

    public function test_min_days_between_blocks_leave_taken_too_soon(): void
    {
        [$user, $type] = $this->scenario(['min_days_between' => 365]);

        $this->submit($user, $type, 1)->assertCreated();
        $this->submit($user, $type, 4)->assertStatus(422)->assertJsonValidationErrors('start_date');
    }

    public function test_policy_without_rules_is_unaffected(): void
    {
        [$user, $type] = $this->scenario([]);

        $this->submit($user, $type, 1)->assertCreated();
        $this->submit($user, $type, 6)->assertCreated();
    }

    public function test_rejected_leave_does_not_consume_a_once_in_career_right(): void
    {
        [$user, $type] = $this->scenario(['once_in_career' => true]);

        $leaveId = $this->submit($user, $type, 1)->assertCreated()->json('leave.id');
        Leave::whereKey($leaveId)->update(['status' => 'Rejected']);

        $this->submit($user, $type, 6)->assertCreated();
    }
}
