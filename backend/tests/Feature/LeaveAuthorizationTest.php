<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SEC-003 / SEC-004 — การเข้าถึงใบลาของผู้อื่น
 *
 * เดิม GET /api/leaves/{leave}/pdf, /word และ POST /api/leaves/{leave}/hr-verify
 * มีแค่ auth:sanctum ไม่มีการตรวจ ownership หรือ permission เลย
 * ผู้ใช้ที่ล็อกอินคนใดก็ได้จึงดาวน์โหลดใบลาของคนอื่น (ซึ่งมีเหตุผลการลาป่วย
 * ที่อยู่ติดต่อ และลายเซ็น) และรับรองสถิติการลาของใครก็ได้
 *
 * เทสต์ชุดนี้เขียนก่อนแก้โค้ด — ตอนเขียนครั้งแรกต้องล้มทุกข้อที่คาดว่า 403
 */
class LeaveAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Department $deptA;

    private Department $deptB;

    private LeaveType $leaveType;

    private User $owner;

    private Leave $leave;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'leave.management.manage',
            'leave.approval.hr',
            'leave.approval.dept',
            'leave.report.view',
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->deptA = Department::create(['name' => 'ฝ่าย ก']);
        $this->deptB = Department::create(['name' => 'ฝ่าย ข']);

        $this->leaveType = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $this->owner = User::factory()->create(['department_id' => $this->deptA->id]);

        $this->leave = Leave::create([
            'user_id' => $this->owner->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'total_days' => 2,
            'reason' => 'เหตุผลการลาที่เป็นข้อมูลอ่อนไหว',
            'status' => 'Pending',
        ]);
    }

    private function userIn(?Department $department, array $permissions = []): User
    {
        $user = User::factory()->create(['department_id' => $department?->id]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    // ---------------------------------------------------------------- SEC-003

    public function test_owner_can_download_own_leave_pdf(): void
    {
        $this->actingAs($this->owner)
            ->get("/api/leaves/{$this->leave->id}/pdf")
            ->assertOk();
    }

    public function test_unrelated_user_cannot_download_another_persons_leave_pdf(): void
    {
        $stranger = $this->userIn($this->deptB);

        $this->actingAs($stranger)
            ->get("/api/leaves/{$this->leave->id}/pdf")
            ->assertForbidden();
    }

    public function test_unrelated_user_cannot_download_another_persons_leave_word(): void
    {
        $stranger = $this->userIn($this->deptB);

        $this->actingAs($stranger)
            ->get("/api/leaves/{$this->leave->id}/word")
            ->assertForbidden();
    }

    public function test_user_in_same_department_without_permission_is_still_denied(): void
    {
        // อยู่ฝ่ายเดียวกันเฉย ๆ ไม่ใช่เหตุผลให้เห็นใบลาของเพื่อนร่วมฝ่าย
        $colleague = $this->userIn($this->deptA);

        $this->actingAs($colleague)
            ->get("/api/leaves/{$this->leave->id}/pdf")
            ->assertForbidden();
    }

    public function test_department_approver_can_view_leave_in_own_department(): void
    {
        $head = $this->userIn($this->deptA, ['leave.approval.dept']);

        $this->actingAs($head)
            ->get("/api/leaves/{$this->leave->id}/pdf")
            ->assertOk();
    }

    public function test_department_approver_cannot_view_leave_from_another_department(): void
    {
        $otherHead = $this->userIn($this->deptB, ['leave.approval.dept']);

        $this->actingAs($otherHead)
            ->get("/api/leaves/{$this->leave->id}/pdf")
            ->assertForbidden();
    }

    public function test_hr_with_management_permission_can_view_any_leave(): void
    {
        $hr = $this->userIn($this->deptB, ['leave.management.manage']);

        $this->actingAs($hr)
            ->get("/api/leaves/{$this->leave->id}/pdf")
            ->assertOk();
    }

    public function test_assigned_approver_can_view_the_leave_they_must_act_on(): void
    {
        $approver = $this->userIn($this->deptB);

        LeaveApproval::create([
            'leave_id' => $this->leave->id,
            'approver_id' => $approver->id,
            'level' => 1,
            'status' => 'Pending',
        ]);

        $this->actingAs($approver)
            ->get("/api/leaves/{$this->leave->id}/pdf")
            ->assertOk();
    }

    public function test_substitute_can_view_the_leave_assigned_to_them(): void
    {
        $substitute = $this->userIn($this->deptA);
        $this->leave->update(['substitute_id' => $substitute->id]);

        $this->actingAs($substitute)
            ->get("/api/leaves/{$this->leave->id}/pdf")
            ->assertOk();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson("/api/leaves/{$this->leave->id}/pdf")->assertUnauthorized();
    }

    // ---------------------------------------------------------------- SEC-004

    public function test_ordinary_user_cannot_hr_verify_a_leave(): void
    {
        $stranger = $this->userIn($this->deptB);

        $this->actingAs($stranger)
            ->postJson("/api/leaves/{$this->leave->id}/hr-verify")
            ->assertForbidden();

        $this->assertNull($this->leave->fresh()->hr_verified_at);
    }

    public function test_owner_cannot_hr_verify_their_own_leave(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/leaves/{$this->leave->id}/hr-verify")
            ->assertForbidden();

        $this->assertNull($this->leave->fresh()->hr_verified_at);
    }

    public function test_user_with_hr_permission_can_hr_verify(): void
    {
        $hr = $this->userIn($this->deptB, ['leave.approval.hr']);

        $this->actingAs($hr)
            ->postJson("/api/leaves/{$this->leave->id}/hr-verify")
            ->assertOk();

        $verified = $this->leave->fresh();
        $this->assertNotNull($verified->hr_verified_at);
        $this->assertSame($hr->id, $verified->hr_verified_by);
    }

    public function test_user_with_leave_management_permission_can_hr_verify(): void
    {
        $manager = $this->userIn($this->deptB, ['leave.management.manage']);

        $this->actingAs($manager)
            ->postJson("/api/leaves/{$this->leave->id}/hr-verify")
            ->assertOk();
    }

    public function test_hr_verify_requires_authentication(): void
    {
        $this->postJson("/api/leaves/{$this->leave->id}/hr-verify")->assertUnauthorized();
    }
}
