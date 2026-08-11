<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveCardPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_download_own_leave_card_pdf(): void
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $type = LeaveType::create(['name' => 'ลาป่วยทดสอบ', 'code_prefix' => 'S']);
        $policy = LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 120, 'max_days_with_pay' => 60,
            'period_basis' => 'fiscal_year', 'is_active' => true,
        ]);
        $dept = Department::create(['name' => 'ทดสอบ']);
        $user = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);

        $year = now()->month >= 10 ? now()->year + 1 : now()->year;
        LeaveBalance::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year,
            'quota' => 120, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0,
            'leave_policy_id' => $policy->id,
        ]);

        $response = $this->actingAs($user)->get("/api/reports/leave-card.pdf?year={$year}");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
