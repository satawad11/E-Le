<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\PersonnelAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_and_personnel_foundation_schema_exists(): void
    {
        $this->assertTrue(Schema::hasColumns('org_units', [
            'uuid', 'parent_id', 'code', 'name', 'type', 'effective_from', 'effective_to', 'is_active',
        ]));
        $this->assertTrue(Schema::hasTable('department_org_unit_map'));
        $this->assertTrue(Schema::hasColumns('personnel_assignments', [
            'uuid', 'user_id', 'org_unit_id', 'personnel_type_id', 'position', 'effective_from', 'effective_to',
        ]));
        $this->assertTrue(Schema::hasColumn('users', 'org_unit_id'));
    }

    public function test_new_public_resources_receive_uuids(): void
    {
        $department = Department::create(['name' => 'หน่วยทดสอบ UUID']);
        $user = User::factory()->create(['department_id' => $department->id]);
        $leaveType = LeaveType::create(['name' => 'ลาทดสอบ UUID', 'max_days' => 1]);
        $leave = Leave::create([
            'user_id' => $user->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'total_days' => 1,
            'reason' => 'ทดสอบ',
        ]);
        $attendance = Attendance::create(['user_id' => $user->id, 'date' => now()->toDateString()]);

        $this->assertNotEmpty($user->uuid);
        $this->assertNotEmpty($leave->uuid);
        $this->assertNotEmpty($attendance->uuid);
    }

    public function test_department_and_user_writes_dual_write_foundation_records(): void
    {
        $department = Department::create(['name' => 'หน่วยทดสอบ']);
        $user = User::factory()->create(['department_id' => $department->id]);

        $this->assertNotNull($department->fresh()->orgUnit);
        $this->assertNotNull($user->fresh()->org_unit_id);
        $this->assertTrue(PersonnelAssignment::where('user_id', $user->id)->whereNull('effective_to')->exists());
    }
}
