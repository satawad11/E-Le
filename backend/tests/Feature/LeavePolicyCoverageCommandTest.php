<?php

namespace Tests\Feature;

use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REDESIGN-2569 เฟส 1 — คำสั่งตรวจช่องว่างของนโยบายการลา (ประเภทบุคลากร × ประเภทการลา)
 */
class LeavePolicyCoverageCommandTest extends TestCase
{
    use RefreshDatabase;

    private function onlyThese(PersonnelType $personnelType, LeaveType $leaveType): void
    {
        PersonnelType::whereKeyNot($personnelType->id)->update(['is_active' => false]);
        LeaveType::whereKeyNot($leaveType->id)->delete();
    }

    private function policy(PersonnelType $personnelType, LeaveType $leaveType, array $overrides = []): LeavePolicy
    {
        return LeavePolicy::create(array_merge([
            'personnel_type_id' => $personnelType->id, 'leave_type_id' => $leaveType->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 0, 'period_basis' => 'fiscal_year', 'is_active' => true,
        ], $overrides));
    }

    public function test_reports_missing_pairs_and_fails(): void
    {
        $personnelType = PersonnelType::create(['code' => 'coverage_new', 'name' => 'เจ้าหน้าที่ตามสัญญา', 'is_active' => true]);
        $leaveType = LeaveType::create(['name' => 'ลาทดสอบความครบ', 'max_days' => 10]);
        $this->onlyThese($personnelType, $leaveType);

        $this->artisan('leave:policy-coverage')
            ->expectsOutputToContain('พบประเภทบุคลากรที่ยังไม่มีนโยบายการลา 1 รายการ')
            ->expectsTable(['ประเภทบุคลากร', 'ประเภทการลา'], [['เจ้าหน้าที่ตามสัญญา', 'ลาทดสอบความครบ']])
            ->assertFailed();
    }

    public function test_a_zero_quota_active_policy_counts_as_covered(): void
    {
        $personnelType = PersonnelType::create(['code' => 'coverage_ok', 'name' => 'ลูกจ้างทดสอบ', 'is_active' => true]);
        $leaveType = LeaveType::create(['name' => 'ลาที่ไม่มีสิทธิ์', 'max_days' => 10]);
        $this->onlyThese($personnelType, $leaveType);
        $this->policy($personnelType, $leaveType);

        $this->artisan('leave:policy-coverage')
            ->expectsOutputToContain('นโยบายการลาครบทุกประเภทบุคลากร')
            ->assertSuccessful();
    }

    public function test_closed_inactive_or_future_policies_do_not_count(): void
    {
        $personnelType = PersonnelType::create(['code' => 'coverage_gap', 'name' => 'ประเภทมีช่องว่าง', 'is_active' => true]);
        $leaveType = LeaveType::create(['name' => 'ลาตรวจรุ่น', 'max_days' => 10]);
        $this->onlyThese($personnelType, $leaveType);
        $this->policy($personnelType, $leaveType, ['effective_to' => today()->subDay()->toDateString()]);
        $this->policy($personnelType, $leaveType, ['is_active' => false]);
        $this->policy($personnelType, $leaveType, ['effective_from' => today()->addDay()->toDateString()]);

        $this->artisan('leave:policy-coverage')->assertFailed();
        $this->artisan('leave:policy-coverage', ['--date' => today()->addDay()->toDateString()])->assertSuccessful();
    }

    public function test_inactive_personnel_types_are_skipped(): void
    {
        $personnelType = PersonnelType::create(['code' => 'coverage_off', 'name' => 'ประเภทเลิกใช้', 'is_active' => true]);
        $leaveType = LeaveType::create(['name' => 'ลาตรวจประเภทเลิกใช้', 'max_days' => 10]);
        $this->onlyThese($personnelType, $leaveType);
        $personnelType->update(['is_active' => false]);

        $this->artisan('leave:policy-coverage')->assertSuccessful();
    }
}
