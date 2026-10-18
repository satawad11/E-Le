<?php

namespace Tests\Legacy\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ทะเบียนคุมการลาต้องคงรายการที่ถูกลบไว้ (ห้ามหายจากทะเบียน)
 * แต่ต้องแยกให้เห็นชัดว่าถูกลบแล้ว ไม่ปะปนกับใบที่รออนุมัติจริง
 */
class LeaveRegisterReportTest extends TestCase
{
    use RefreshDatabase;

    private function hrUser(): User
    {
        $hr = User::factory()->create();
        $hr->givePermissionTo(Permission::findOrCreate('leave.report.view', 'web'));

        return $hr;
    }

    private function leaveFor(string $status, bool $deleted): Leave
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $dept = Department::firstOrCreate(['name' => 'แผนกทดสอบ']);
        $type = LeaveType::firstOrCreate(['name' => 'ลาป่วย'], ['code_prefix' => 'S']);
        $user = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);

        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'document_number' => 'ลป.-2569-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'start_date' => '2026-08-10', 'end_date' => '2026-08-10',
            'total_days' => 1, 'reason' => 'ทดสอบ', 'status' => $status, 'approval_level' => 1,
        ]);

        if ($deleted) {
            $leave->delete();
        }

        return $leave;
    }

    private function register(User $hr)
    {
        return $this->actingAs($hr)->getJson('/api/reports/government-leave-register?from=2026-08-01&to=2026-08-31');
    }

    public function test_deleted_leave_stays_in_the_register_but_is_marked_as_deleted(): void
    {
        $hr = $this->hrUser();
        $deleted = $this->leaveFor('Pending', true);
        $live = $this->leaveFor('Pending', false);

        $rows = collect($this->register($hr)->assertOk()->json('data'));

        $this->assertCount(2, $rows, 'ทะเบียนคุมต้องยังแสดงใบที่ถูกลบ');
        $this->assertTrue($rows->firstWhere('id', $deleted->id)['is_deleted']);
        $this->assertFalse($rows->firstWhere('id', $live->id)['is_deleted']);
    }

    public function test_deleted_pending_leave_is_not_counted_as_awaiting_approval(): void
    {
        $hr = $this->hrUser();
        $this->leaveFor('Pending', true);

        // รายการรออนุมัติของผู้อนุมัติต้องไม่เห็นใบที่ถูกลบแล้ว
        $pending = $this->actingAs($hr)->getJson('/api/approvals/pending')->assertOk()->json();

        $this->assertSame([], $pending);
    }

    public function test_csv_export_shows_deleted_status_and_deletion_time(): void
    {
        $hr = $this->hrUser();
        $this->leaveFor('Pending', true);

        $content = $this->actingAs($hr)
            ->get('/api/reports/government-leave-register.csv?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('วันที่ลบเอกสาร', $content);
        $this->assertStringContainsString('ลบแล้ว (เดิม: รออนุมัติ)', $content);
    }
}
