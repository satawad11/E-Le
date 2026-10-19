<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use App\Services\LeavePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * REDESIGN-2569 เฟส 1.1 — ลาอุปสมบท / ลาไปประกอบพิธีฮัจญ์ ต้องยื่นล่วงหน้าไม่น้อยกว่า 60 วัน
 *
 * เดิมค่า 60 วันอยู่แค่ใน leave_types แต่ระบบตรวจจาก leave_policies ซึ่งเป็น 0 จึงยื่นกระชั้นชิดได้
 */
class OrdinationNoticePeriodTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_19_110000_enforce_ordination_hajj_notice_period.php';

    private PersonnelType $personnelType;

    private LeaveType $ordination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personnelType = PersonnelType::create(['code' => 'notice_test', 'name' => 'ประเภททดสอบ', 'is_active' => true]);
        $this->ordination = LeaveType::where('name', 'ลาอุปสมบท')->first()
            ?? LeaveType::create(['name' => 'ลาอุปสมบท', 'max_days' => 120]);
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function seedPolicy(array $overrides = []): LeavePolicy
    {
        return LeavePolicy::create(array_merge([
            'personnel_type_id' => $this->personnelType->id, 'leave_type_id' => $this->ordination->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 120, 'notice_period_days' => 0,
            'allow_past_dates' => false, 'exclude_holidays' => false, 'allow_half_day' => false,
            'eligibility_rules' => ['once_in_career' => true], 'period_basis' => 'fiscal_year', 'is_active' => true,
        ], $overrides));
    }

    private function staff(): User
    {
        $user = User::factory()->create(['personnel_type_id' => $this->personnelType->id])->fresh();
        $user->personnel?->update(['effective_from' => '2000-01-01']);

        return $user->fresh();
    }

    private function assertRejectedForNotice(User $user, LeavePolicy $policy, Carbon $start): void
    {
        try {
            app(LeavePolicyService::class)->assertEligible($user, $policy, $start, $start->copy()->addDays(14), 15, 'full_day', true);
            $this->fail('ต้องปฏิเสธใบลาที่ยื่นล่วงหน้าไม่ถึง 60 วัน');
        } catch (ValidationException $e) {
            $this->assertSame(['ต้องยื่นลาล่วงหน้าอย่างน้อย 60 วัน'], $e->errors()['start_date']);
        }
    }

    public function test_existing_policy_is_closed_and_a_new_version_requires_60_days_notice(): void
    {
        $old = $this->seedPolicy();

        $this->migration()->up();

        $this->assertSame(today()->subDay()->toDateString(), $old->fresh()->effective_to->toDateString(), 'รุ่นเดิมต้องปิดเมื่อวาน ไม่ถูกเขียนทับ');
        $this->assertSame(0, (int) $old->fresh()->notice_period_days);

        $current = app(LeavePolicyService::class)->resolve($this->staff(), $this->ordination->id, today());
        $this->assertNotSame($old->id, $current->id);
        $this->assertSame(60, (int) $current->notice_period_days);
        $this->assertSame(today()->toDateString(), $current->effective_from->toDateString());
        $this->assertSame(120.0, (float) $current->annual_quota, 'ค่าอื่นของนโยบายต้องคัดลอกมาครบ');
        $this->assertSame(['once_in_career' => true], $current->eligibility_rules);
    }

    public function test_application_59_days_ahead_is_rejected_and_60_days_ahead_is_accepted(): void
    {
        $this->seedPolicy();
        $this->migration()->up();
        $user = $this->staff();
        $policy = app(LeavePolicyService::class)->resolve($user, $this->ordination->id, today());

        $this->assertRejectedForNotice($user, $policy, today()->addDays(59));

        app(LeavePolicyService::class)->assertEligible($user, $policy, today()->addDays(60), today()->addDays(74), 15, 'full_day', true);
        $this->addToAssertionCount(1);
    }

    public function test_hr_recording_on_behalf_still_skips_the_notice_rule(): void
    {
        $this->seedPolicy();
        $this->migration()->up();
        $user = $this->staff();
        $policy = app(LeavePolicyService::class)->resolve($user, $this->ordination->id, today());

        app(LeavePolicyService::class)->assertEligible($user, $policy, today()->addDays(5), today()->addDays(19), 15, 'full_day', false, true);
        $this->addToAssertionCount(1);
    }

    public function test_policies_set_by_an_administrator_are_not_touched(): void
    {
        $custom = $this->seedPolicy(['notice_period_days' => 90]);
        $ended = $this->seedPolicy(['effective_from' => '2024-10-01', 'effective_to' => '2025-09-30']);

        $this->migration()->up();

        $this->assertSame(90, (int) $custom->fresh()->notice_period_days);
        $this->assertNull($custom->fresh()->effective_to);
        $this->assertSame('2025-09-30', $ended->fresh()->effective_to->toDateString());
        $this->assertSame(2, LeavePolicy::where('personnel_type_id', $this->personnelType->id)->count());
    }

    public function test_running_twice_creates_only_one_new_version(): void
    {
        $this->seedPolicy();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(2, LeavePolicy::where('personnel_type_id', $this->personnelType->id)->count());
    }

    public function test_down_restores_the_old_version_when_the_new_one_is_unused(): void
    {
        $old = $this->seedPolicy();
        $this->migration()->up();

        $this->migration()->down();

        $this->assertNull($old->fresh()->effective_to);
        $this->assertSame(1, LeavePolicy::where('personnel_type_id', $this->personnelType->id)->count());
    }

    public function test_down_keeps_a_new_version_that_a_leave_already_uses(): void
    {
        $old = $this->seedPolicy();
        $this->migration()->up();
        $user = $this->staff();
        $current = app(LeavePolicyService::class)->resolve($user, $this->ordination->id, today());
        Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $this->ordination->id, 'leave_policy_id' => $current->id,
            'start_date' => today()->addDays(70), 'end_date' => today()->addDays(84),
            'total_days' => 15, 'reason' => 'อุปสมบท', 'status' => 'Pending',
        ]);

        $this->migration()->down();

        $this->assertNotNull($current->fresh(), 'รุ่นที่มีใบลาอ้างถึงต้องไม่ถูกลบ');
        $this->assertNotNull($old->fresh()->effective_to);
    }

    public function test_sync_quotas_ignores_closed_versions(): void
    {
        // รุ่นปัจจุบันสร้างก่อน รุ่นที่ปิดแล้วมี id สูงกว่า — เดิมคำสั่งวนตาม id จึงให้รุ่นเก่าเขียนทับทีหลัง
        $current = $this->seedPolicy(['effective_from' => today()->toDateString(), 'annual_quota' => 120, 'notice_period_days' => 60]);
        $closed = $this->seedPolicy(['effective_from' => '2024-10-01', 'effective_to' => today()->subDay()->toDateString(), 'annual_quota' => 90]);
        $user = $this->staff();
        $balance = app(LeavePolicyService::class)->balance($user, $current, today());

        $this->artisan('leave:sync-quotas')->assertSuccessful();

        $this->assertSame($current->id, $balance->fresh()->leave_policy_id, 'รุ่นที่ปิดแล้วต้องไม่เขียนทับยอดของปีปัจจุบัน');
        $this->assertSame(120.0, (float) $balance->fresh()->quota);
        $this->assertNotSame($closed->id, $balance->fresh()->leave_policy_id);
    }
}
