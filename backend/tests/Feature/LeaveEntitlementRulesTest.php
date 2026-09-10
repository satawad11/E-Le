<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelType;
use App\Models\User;
use App\Services\LeavePolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveEntitlementRulesTest extends TestCase
{
    use RefreshDatabase;

    private function currentFiscalYear(): int
    {
        return now()->month >= 10 ? now()->year + 1 : now()->year;
    }

    /** สร้างนโยบาย + ผู้ใช้ 1 คน พร้อม balance ปีปัจจุบัน */
    private function makeScenario(array $policyAttributes, array $balanceAttributes = []): array
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $type = LeaveType::create(['name' => 'ลาทดสอบ', 'code_prefix' => 'T']);
        $policy = LeavePolicy::create(array_merge([
            'personnel_type_id' => $personnel->id,
            'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01',
            'period_basis' => 'fiscal_year',
            'exclude_holidays' => true,
            'allow_half_day' => true,
            'is_active' => true,
        ], $policyAttributes));

        $dept = OrgUnit::factory()->create(['name' => 'ทดสอบ']);
        $user = User::factory()->create(['org_unit_id' => $dept->id, 'personnel_type_id' => $personnel->id]);

        $balance = LeaveBalance::create(array_merge([
            'user_id' => $user->id,
            'leave_type_id' => $type->id,
            'year' => $this->currentFiscalYear(),
            'quota' => $policy->annual_quota ?? 0,
            'used' => 0,
            'reserved' => 0,
            'carried_forward' => 0,
            'leave_policy_id' => $policy->id,
        ], $balanceAttributes));

        return [$user, $type, $policy, $balance];
    }

    public function test_days_beyond_paid_cap_are_recorded_as_unpaid(): void
    {
        // สิทธิ์รวม 120 วัน แต่ได้รับเงินเดือนแค่ 4 วันแรก และใช้ไปแล้ว 3 วัน
        [$user, $type] = $this->makeScenario(
            ['annual_quota' => 120, 'max_days_with_pay' => 4],
            ['used' => 3]
        );

        $start = now()->addWeek()->next(Carbon::MONDAY);
        $leave = $this->actingAs($user)->postJson('/api/leaves', [
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(), // จ.-พ. = 3 วันทำการ
            'reason' => 'ทดสอบวันไม่รับเงินเดือน',
        ])->assertCreated()->json('leave');

        $this->assertEquals(3.0, (float) $leave['total_days']);
        $this->assertEquals(1.0, (float) $leave['paid_days']);   // เหลือสิทธิ์รับเงินเดือน 1 วัน
        $this->assertEquals(2.0, (float) $leave['unpaid_days']); // อีก 2 วันไม่ได้รับเงินเดือน
    }

    public function test_policy_without_paid_cap_marks_every_day_as_paid(): void
    {
        [$user, $type] = $this->makeScenario(['annual_quota' => 30, 'max_days_with_pay' => null]);

        $start = now()->addWeek()->next(Carbon::MONDAY);
        $leave = $this->actingAs($user)->postJson('/api/leaves', [
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'reason' => 'ทดสอบ',
        ])->assertCreated()->json('leave');

        $this->assertEquals(2.0, (float) $leave['paid_days']);
        $this->assertEquals(0.0, (float) $leave['unpaid_days']);
    }

    public function test_carry_forward_respects_accumulation_ceiling(): void
    {
        // สิทธิ์ปีละ 10 เพดานสะสมรวม 30 → ทบได้ไม่เกิน 20 แม้เหลือ 25
        $year = $this->currentFiscalYear();
        [$user, $type, $policy] = $this->makeScenario(
            ['annual_quota' => 10, 'is_carry_forward' => true, 'max_carry_forward' => 20, 'max_accumulated' => 30],
            ['year' => $year, 'quota' => 10, 'carried_forward' => 15]
        );

        $this->artisan("app:carry-forward-leaves --year={$year}")->assertSuccessful();

        $next = LeaveBalance::where('user_id', $user->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year + 1)
            ->first();

        $this->assertNotNull($next);
        $this->assertEquals(20.0, (float) $next->carried_forward);
        $this->assertEquals(10.0, (float) $next->quota); // สิทธิ์ประจำปีใหม่มาจากนโยบาย
    }

    public function test_carry_forward_excludes_days_still_awaiting_approval(): void
    {
        $year = $this->currentFiscalYear();
        [$user, $type] = $this->makeScenario(
            ['annual_quota' => 10, 'is_carry_forward' => true, 'max_carry_forward' => 20, 'max_accumulated' => 30],
            ['year' => $year, 'quota' => 10, 'used' => 2, 'reserved' => 3]
        );

        $this->artisan("app:carry-forward-leaves --year={$year}")->assertSuccessful();

        $next = LeaveBalance::where('user_id', $user->id)->where('year', $year + 1)->first();
        $this->assertEquals(5.0, (float) $next->carried_forward); // 10 - 2 ใช้ไป - 3 รออนุมัติ
    }

    public function test_carry_forward_can_run_twice_without_stacking(): void
    {
        $year = $this->currentFiscalYear();
        [$user] = $this->makeScenario(
            ['annual_quota' => 10, 'is_carry_forward' => true, 'max_carry_forward' => 20, 'max_accumulated' => 30],
            ['year' => $year, 'quota' => 10]
        );

        $this->artisan("app:carry-forward-leaves --year={$year}")->assertSuccessful();
        $this->artisan("app:carry-forward-leaves --year={$year}")->assertSuccessful();

        $next = LeaveBalance::where('user_id', $user->id)->where('year', $year + 1)->first();
        $this->assertEquals(10.0, (float) $next->carried_forward);
    }

    public function test_leave_card_reports_paid_and_unpaid_totals(): void
    {
        [$user, $type] = $this->makeScenario(
            ['annual_quota' => 120, 'max_days_with_pay' => 1],
            ['used' => 0]
        );

        $start = now()->addWeek()->next(Carbon::MONDAY);
        $this->actingAs($user)->postJson('/api/leaves', [
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
            'reason' => 'ทดสอบบัตรลา',
        ])->assertCreated();

        $card = $this->actingAs($user)
            ->getJson('/api/reports/leave-card?year='.$this->currentFiscalYear())
            ->assertOk()
            ->json();

        $this->assertSame($this->currentFiscalYear() + 543, $card['fiscal_year_th']);
        $this->assertCount(1, $card['entries']);
        $this->assertEquals(1.0, (float) $card['entries'][0]['paid_days']);
        $this->assertEquals(2.0, (float) $card['entries'][0]['unpaid_days']);
        // ยอดสรุปนับเฉพาะใบที่อนุมัติแล้ว ใบนี้ยังรออนุมัติจึงเป็น 0
        $this->assertEquals(0.0, (float) $card['totals']['used_days']);
        $this->assertEquals(3.0, (float) $card['summary'][0]['pending_days']);
    }

    public function test_leave_card_of_another_user_requires_report_permission(): void
    {
        [$user] = $this->makeScenario(['annual_quota' => 10]);
        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/reports/leave-card?user_id='.$user->id)
            ->assertForbidden();
    }

    public function test_fiscal_year_number_converts_buddhist_year_from_fiscal_years_table(): void
    {
        $service = app(LeavePolicyService::class);
        $fy = FiscalYear::create([
            'name' => 'ปีงบประมาณ 2569', 'year' => 2569,
            'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'is_current' => true,
        ]);

        $this->assertSame(2026, $service->fiscalYearNumber($fy, now()));
    }
}
