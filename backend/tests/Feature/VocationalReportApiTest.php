<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelType;
use App\Models\User;
use Database\Seeders\VocationalOrgStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VocationalReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_evaluation_summary_for_authorized_users(): void
    {
        $this->seed(VocationalOrgStructureSeeder::class);

        $perm = Permission::firstOrCreate(['name' => 'leave.report.view', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $role->givePermissionTo($perm);

        $hrAdmin = User::factory()->create();
        $hrAdmin->assignRole($role);

        $academicDiv = OrgUnit::where('code', 'ORG-DIV-ACADEMIC')->firstOrFail();
        $teacherType = PersonnelType::firstOrCreate(['name' => 'ข้าราชการครู', 'code' => 'GOV_TEACHER']);

        $teacher = User::factory()->create([
            'name' => 'ครู สมศักดิ์',
            'org_unit_id' => $academicDiv->id,
            'personnel_type_id' => $teacherType->id,
            'is_active' => true,
        ]);

        $sickLeaveType = LeaveType::create([
            'name' => 'ลาป่วย',
            'code_prefix' => 'ลป.',
            'attendance_bucket' => 'sick',
            'max_days' => 60,
        ]);

        // Create approved sick leave (15 days) within Round 1 (Oct 2025 - Mar 2026)
        Leave::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $teacher->id,
            'leave_type_id' => $sickLeaveType->id,
            'start_date' => '2025-11-10',
            'end_date' => '2025-11-25',
            'total_days' => 15,
            'reason' => 'ผ่าตัด',
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($hrAdmin);

        $response = $this->getJson('/api/v1/reports/vocational/evaluation-summary?cycle=round_1&year=2569');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cycle', 'round_1');

        $records = $response->json('data.records');
        $teacherRecord = collect($records)->firstWhere('user_id', $teacher->id);

        $this->assertNotNull($teacherRecord);
        $this->assertEquals(15.0, $teacherRecord['sick_days']);
        $this->assertEquals(15.0, $teacherRecord['total_countable_days']);
        $this->assertFalse($teacherRecord['threshold_exceeded']); // 15 <= 23 days
        $this->assertEquals('ปกติ (ผ่านเกณฑ์)', $teacherRecord['evaluation_status']);
    }

    public function test_it_flags_exceeded_leave_threshold_over_23_days(): void
    {
        $this->seed(VocationalOrgStructureSeeder::class);

        $perm = Permission::firstOrCreate(['name' => 'leave.report.view', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $role->givePermissionTo($perm);

        $hrAdmin = User::factory()->create();
        $hrAdmin->assignRole($role);

        $academicDiv = OrgUnit::where('code', 'ORG-DIV-ACADEMIC')->firstOrFail();
        $teacherType = PersonnelType::firstOrCreate(['name' => 'ข้าราชการครู', 'code' => 'GOV_TEACHER']);
        $teacher = User::factory()->create([
            'name' => 'ครู สมพร',
            'org_unit_id' => $academicDiv->id,
            'personnel_type_id' => $teacherType->id,
            'is_active' => true,
        ]);

        $sickType = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => 'ลป.', 'attendance_bucket' => 'sick', 'max_days' => 60]);
        $personalType = LeaveType::create(['name' => 'ลากิจ', 'code_prefix' => 'ลก.', 'attendance_bucket' => 'personal', 'max_days' => 45]);

        // 15 days sick + 10 days personal = 25 days (> 23 days)
        Leave::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $teacher->id,
            'leave_type_id' => $sickType->id,
            'start_date' => '2025-10-05',
            'end_date' => '2025-10-20',
            'total_days' => 15,
            'reason' => 'ป่วย',
            'status' => 'Approved',
        ]);

        Leave::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $teacher->id,
            'leave_type_id' => $personalType->id,
            'start_date' => '2025-12-01',
            'end_date' => '2025-12-10',
            'total_days' => 10,
            'reason' => 'ธุระจำเป็น',
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($hrAdmin);

        $response = $this->getJson('/api/v1/reports/vocational/evaluation-summary?cycle=round_1&year=2569');

        $response->assertOk();
        $teacherRecord = collect($response->json('data.records'))->firstWhere('user_id', $teacher->id);

        $this->assertNotNull($teacherRecord);
        $this->assertEquals(25.0, $teacherRecord['total_countable_days']);
        $this->assertTrue($teacherRecord['threshold_exceeded']);
        $this->assertEquals('เกินเกณฑ์วันลา', $teacherRecord['evaluation_status']);

        // Verify round_2 also executes without error
        $responseRound2 = $this->getJson('/api/v1/reports/vocational/evaluation-summary?cycle=round_2&year=2569');
        $responseRound2->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cycle', 'round_2');
    }

    public function test_it_imports_biometric_scan_file_via_api(): void
    {
        $perm = Permission::firstOrCreate(['name' => 'attendance.view_all', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'HR Admin', 'guard_name' => 'web']);
        $role->givePermissionTo($perm);

        $admin = User::factory()->create();
        $admin->assignRole($role);

        $user = User::factory()->create(['id' => 999]);

        $fileContent = "999\t2026-09-16 08:00:00\t0\n999\t2026-09-16 16:30:00\t1\n";
        $uploadedFile = UploadedFile::fake()->createWithContent('attendance_log.dat', $fileContent);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/attendance/biometric/import-file', [
            'file' => $uploadedFile,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_events', 2)
            ->assertJsonPath('data.imported', 2);
    }

    public function test_unauthorized_user_is_forbidden_from_viewing_evaluation_reports(): void
    {
        $ordinaryUser = User::factory()->create();

        Sanctum::actingAs($ordinaryUser);

        $response = $this->getJson('/api/v1/reports/vocational/evaluation-summary');
        $response->assertForbidden();
    }

    public function test_teacher_can_access_own_pa_evidence(): void
    {
        $this->seed(VocationalOrgStructureSeeder::class);

        $academicDiv = OrgUnit::where('code', 'ORG-DIV-ACADEMIC')->firstOrFail();
        $teacher = User::factory()->create([
            'name' => 'ครู เอกลักษณ์',
            'org_unit_id' => $academicDiv->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/v1/reports/vocational/teacher-pa-evidence');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $teacher->id);
    }

    public function test_ordinary_teacher_cannot_access_other_teacher_pa_evidence(): void
    {
        $teacher1 = User::factory()->create(['name' => 'Teacher 1']);
        $teacher2 = User::factory()->create(['name' => 'Teacher 2']);

        Sanctum::actingAs($teacher1);

        $response = $this->getJson("/api/v1/reports/vocational/teacher-pa-evidence?target_user_id={$teacher2->id}");
        $response->assertForbidden();
    }
}
