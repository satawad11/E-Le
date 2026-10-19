<?php

namespace Tests\Feature;

use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use App\Services\LeavePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * REDESIGN-2569 · D-06 — ลาป่วยต้องแนบใบรับรองแพทย์เมื่อลาตั้งแต่ 30 วัน (เดิม 3 วัน)
 */
class SickLeaveCertificateThresholdTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_19_120000_require_medical_certificate_from_30_sick_days.php';

    private LeaveType $sick;

    private PersonnelType $civil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sick = LeaveType::where('name', 'ลาป่วย')->first() ?? LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 120]);
        $this->civil = PersonnelType::firstOrCreate(['code' => 'civil_servant'], ['name' => 'ข้าราชการ', 'is_active' => true]);

        // ล้างนโยบายลาป่วยจาก seed เพื่อให้แต่ละ test ตั้งสถานการณ์เอง
        LeavePolicy::where('leave_type_id', $this->sick->id)->delete();
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function policy(PersonnelType $type, array $overrides = []): LeavePolicy
    {
        return LeavePolicy::create(array_merge([
            'personnel_type_id' => $type->id, 'leave_type_id' => $this->sick->id, 'effective_from' => '2025-10-01',
            'annual_quota' => 120, 'max_days_with_pay' => 60, 'attachment_required_after_days' => 3,
            'required_evidence_type' => 'medical_certificate', 'allow_past_dates' => true, 'exclude_holidays' => true,
            'allow_half_day' => true, 'period_basis' => 'fiscal_year', 'is_active' => true,
        ], $overrides));
    }

    private function civilServant(): User
    {
        $user = User::factory()->create(['personnel_type_id' => $this->civil->id])->fresh();
        $user->personnel?->update(['effective_from' => '2000-01-01']);

        return $user->fresh();
    }

    public function test_a_new_version_requires_a_certificate_from_30_days(): void
    {
        $old = $this->policy($this->civil);

        $this->migration()->up();

        $this->assertSame(today()->subDay()->toDateString(), $old->fresh()->effective_to->toDateString());
        $current = app(LeavePolicyService::class)->resolve($this->civilServant(), $this->sick->id, today());
        $this->assertSame(30.0, (float) $current->attachment_required_after_days);
        $this->assertSame(60.0, (float) $current->max_days_with_pay, 'ค่าอื่นต้องคัดลอกมาครบ');
    }

    public function test_29_days_without_certificate_is_accepted_and_30_days_is_rejected(): void
    {
        $this->policy($this->civil);
        $this->migration()->up();
        $user = $this->civilServant();
        $service = app(LeavePolicyService::class);
        $policy = $service->resolve($user, $this->sick->id, today());

        $service->assertEligible($user, $policy, today(), today()->addDays(40), 29, 'full_day', false);

        try {
            $service->assertEligible($user, $policy, today(), today()->addDays(42), 30, 'full_day', false);
            $this->fail('ลาป่วย 30 วันโดยไม่แนบใบรับรองแพทย์ต้องถูกปฏิเสธ');
        } catch (ValidationException $e) {
            $this->assertSame(['ต้องแนบหลักฐานประกอบการลาตามหลักเกณฑ์'], $e->errors()['attachment']);
        }

        $service->assertEligible($user, $policy, today(), today()->addDays(42), 30, 'full_day', true);
    }

    public function test_other_personnel_types_and_admin_values_are_not_touched(): void
    {
        $gov = PersonnelType::firstOrCreate(['code' => 'government_employee'], ['name' => 'พนักงานราชการ', 'is_active' => true]);
        $govPolicy = $this->policy($gov);
        $custom = $this->policy($this->civil, ['attachment_required_after_days' => 7]);

        $this->migration()->up();

        $this->assertSame(3.0, (float) $govPolicy->fresh()->attachment_required_after_days, 'ประเภทอื่นรอ D-03');
        $this->assertNull($govPolicy->fresh()->effective_to);
        $this->assertSame(7.0, (float) $custom->fresh()->attachment_required_after_days);
        $this->assertSame(2, LeavePolicy::where('leave_type_id', $this->sick->id)->count());
    }

    public function test_down_restores_the_previous_version(): void
    {
        $old = $this->policy($this->civil);
        $this->migration()->up();

        $this->migration()->down();

        $this->assertNull($old->fresh()->effective_to);
        $this->assertSame(1, LeavePolicy::where('leave_type_id', $this->sick->id)->count());
    }
}
