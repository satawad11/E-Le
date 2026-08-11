<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: LeaveType} */
    private function scenario(): array
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $type = LeaveType::create(['name' => 'ลาป่วยทดสอบ', 'code_prefix' => 'V']);
        $policy = LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 60,
            'period_basis' => 'fiscal_year', 'exclude_holidays' => true,
            'allow_half_day' => true, 'is_active' => true,
        ]);
        $dept = Department::create(['name' => 'ทดสอบ']);
        $user = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $user->profile()->create(['title' => 'นาย', 'first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'position' => 'ครู']);

        $year = now()->month >= 10 ? now()->year + 1 : now()->year;
        LeaveBalance::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year,
            'quota' => 60, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0,
            'leave_policy_id' => $policy->id,
        ]);

        return [$user, $type];
    }

    private function submitLeave(User $user, LeaveType $type): Leave
    {
        $start = now()->addWeek()->next(\Carbon\Carbon::MONDAY);
        $id = $this->actingAs($user)->postJson('/api/leaves', [
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'reason' => 'ทดสอบ QR',
        ])->assertCreated()->json('leave.id');

        return Leave::findOrFail($id);
    }

    public function test_every_submitted_leave_gets_a_verification_hash(): void
    {
        [$user, $type] = $this->scenario();
        $leave = $this->submitLeave($user, $type);

        $this->assertNotNull($leave->verification_hash);
        $this->assertSame(40, strlen($leave->verification_hash));
    }

    public function test_verification_endpoint_is_public_and_reports_pending_status(): void
    {
        [$user, $type] = $this->scenario();
        $leave = $this->submitLeave($user, $type);

        // ไม่ต้องล็อกอิน — ผู้ที่ถือเอกสารสแกน QR ได้ทันที
        $body = $this->getJson("/api/leaves/verify/{$leave->verification_hash}")->assertOk()->json();

        $this->assertSame('นายสมชาย ใจดี', $body['holder']['name']);
        $this->assertSame('ลาป่วยทดสอบ', $body['leave_type']);
        $this->assertSame('อยู่ระหว่างพิจารณา', $body['status_label']);
        $this->assertFalse($body['is_valid']); // ยังไม่อนุมัติจึงยังใช้อ้างอิงไม่ได้
    }

    public function test_verification_does_not_expose_the_leave_reason(): void
    {
        [$user, $type] = $this->scenario();
        $leave = $this->submitLeave($user, $type);

        $this->getJson("/api/leaves/verify/{$leave->verification_hash}")
            ->assertOk()
            ->assertJsonMissing(['reason' => 'ทดสอบ QR'])
            ->assertJsonMissingPath('reason');
    }

    public function test_approved_leave_is_valid_and_detects_later_tampering(): void
    {
        [$user, $type] = $this->scenario();
        $leave = $this->submitLeave($user, $type);

        app(\App\Services\LeaveService::class)->deductBalance($leave);
        $leave->update(['status' => 'Approved']);

        $this->getJson("/api/leaves/verify/{$leave->verification_hash}")
            ->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('content_altered', false);

        // แก้จำนวนวันหลังอนุมัติ ลายนิ้วมือเนื้อหาจะไม่ตรงอีกต่อไป
        $leave->update(['total_days' => 99]);

        $this->getJson("/api/leaves/verify/{$leave->verification_hash}")
            ->assertOk()
            ->assertJsonPath('is_valid', false)
            ->assertJsonPath('content_altered', true);
    }

    public function test_unknown_hash_returns_not_found(): void
    {
        $this->getJson('/api/leaves/verify/' . str_repeat('x', 40))->assertNotFound();
    }

    public function test_qrcode_endpoint_returns_svg(): void
    {
        [$user, $type] = $this->scenario();
        $leave = $this->submitLeave($user, $type);

        $response = $this->get("/api/leaves/verify/{$leave->verification_hash}/qrcode");

        $response->assertOk();
        $this->assertStringContainsString('image/svg+xml', $response->headers->get('content-type'));
        $this->assertStringContainsString('<svg', $response->getContent());
    }
}
