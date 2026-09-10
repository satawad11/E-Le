<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveEvidence;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonalDataProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $name = 'สมชาย ใจดี'): User
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $dept = OrgUnit::factory()->create(['name' => 'แผนกทดสอบ']);
        $user = User::factory()->create(['name' => $name, 'org_unit_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        /*
         * first/last name ต้องมาจาก $name ที่ผู้เรียกส่งมา
         * เดิม hardcode เป็น "สมชาย ใจดี" แล้ว PersonnelSyncService จะ sync
         * ชื่อจาก profile กลับไปทับ users.name ทำให้ทุกคนชื่อเดียวกันหมด
         */
        [$firstName, $lastName] = array_pad(explode(' ', trim($name), 2), 2, '');
        $user->profile()->create([
            'position' => 'ครู',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'nationality' => 'ไทย',
        ]);

        // PersonnelSyncService อ่าน $user->profile ระหว่าง observer ตอน create
        // relation จึงถูก cache เป็น null ไปแล้วก่อนบรรทัดข้างบนจะสร้าง profile
        return $user->fresh();
    }

    public function test_sensitive_fields_cannot_be_mass_assigned_anymore(): void
    {
        $user = $this->staff();

        $user->profile->fill(['religion' => 'พุทธ', 'ethnicity' => 'ไทย'])->save();

        $stored = DB::table('user_profiles')->where('user_id', $user->id)->first();
        $this->assertNull($stored->religion);
        $this->assertNull($stored->ethnicity);
    }

    public function test_profile_update_endpoint_ignores_religion(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/api/profile', [
            'profile' => ['province' => 'กรุงเทพมหานคร', 'religion' => 'พุทธ'],
        ])->assertOk();

        $stored = DB::table('user_profiles')->where('user_id', $user->id)->first();
        $this->assertSame('กรุงเทพมหานคร', $stored->province);
        $this->assertNull($stored->religion);
    }

    public function test_purge_command_reports_before_deleting_and_clears_with_force(): void
    {
        $user = $this->staff();
        DB::table('user_profiles')->where('user_id', $user->id)->update(['religion' => 'พุทธ', 'ethnicity' => 'ไทย']);

        // ไม่ใส่ --force ต้องไม่ลบอะไร
        $this->artisan('pdpa:purge-sensitive-fields')->assertSuccessful();
        $this->assertNotNull(DB::table('user_profiles')->where('user_id', $user->id)->value('religion'));

        $this->artisan('pdpa:purge-sensitive-fields --force')->assertSuccessful();
        $stored = DB::table('user_profiles')->where('user_id', $user->id)->first();
        $this->assertNull($stored->religion);
        $this->assertNull($stored->ethnicity);
    }

    public function test_user_can_export_own_personal_data(): void
    {
        $user = $this->staff();

        $payload = $this->actingAs($user)->getJson('/api/profile/personal-data')->assertOk()->json();

        $this->assertSame($user->email, $payload['account']['email']);
        $this->assertSame('สมชาย', $payload['profile']['first_name']);
        $this->assertArrayHasKey('leaves', $payload);
        $this->assertArrayHasKey('access_history', $payload);
    }

    public function test_personal_data_export_never_returns_another_users_data(): void
    {
        $owner = $this->staff('เจ้าของข้อมูล');
        $other = $this->staff('คนอื่น');

        // ไม่มีพารามิเตอร์ให้ระบุคนอื่นได้ ต่อให้ส่ง user_id ไปก็ยังได้ข้อมูลตนเอง
        $payload = $this->actingAs($other)
            ->getJson('/api/profile/personal-data?user_id='.$owner->id)
            ->assertOk()->json();

        $this->assertSame($other->email, $payload['account']['email']);
    }

    public function test_viewing_another_persons_leave_card_is_logged(): void
    {
        $subject = $this->staff('ผู้ถูกดูข้อมูล');
        $hr = $this->staff('เจ้าหน้าที่บุคลากร');
        $hr->givePermissionTo(Permission::findOrCreate('leave.report.view', 'web'));

        $this->actingAs($hr)->getJson('/api/reports/leave-card?user_id='.$subject->id)->assertOk();

        $log = DB::table('personal_data_access_logs')->where('subject_id', $subject->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($hr->id, $log->actor_id);
        $this->assertSame('leave_card', $log->resource);
    }

    public function test_viewing_own_leave_card_is_not_logged(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->getJson('/api/reports/leave-card')->assertOk();

        $this->assertSame(0, DB::table('personal_data_access_logs')->count());
    }

    public function test_access_history_appears_in_the_subjects_own_export(): void
    {
        $subject = $this->staff('ผู้ถูกดูข้อมูล');
        $hr = $this->staff('เจ้าหน้าที่บุคลากร');
        $hr->givePermissionTo(Permission::findOrCreate('leave.report.view', 'web'));

        $this->actingAs($hr)->getJson('/api/reports/leave-card?user_id='.$subject->id)->assertOk();

        $history = $this->actingAs($subject)->getJson('/api/profile/personal-data')->assertOk()->json('access_history');

        $this->assertCount(1, $history);
        $this->assertSame('เจ้าหน้าที่บุคลากร', $history[0]['accessed_by']);
    }

    public function test_access_history_endpoint_is_paginated_and_scoped_to_self(): void
    {
        $subject = $this->staff('ผู้ถูกดูข้อมูล');
        $other = $this->staff('บุคลากรอื่น');
        $hr = $this->staff('เจ้าหน้าที่บุคลากร');
        $hr->givePermissionTo(Permission::findOrCreate('leave.report.view', 'web'));

        $this->actingAs($hr)->getJson('/api/reports/leave-card?user_id='.$subject->id)->assertOk();
        $this->actingAs($hr)->getJson('/api/reports/leave-card?user_id='.$other->id)->assertOk();

        $page = $this->actingAs($subject)->getJson('/api/profile/access-history')->assertOk()->json();

        // เห็นเฉพาะรายการที่มีตนเองเป็นเจ้าของข้อมูล ไม่เห็นของคนอื่น
        $this->assertSame(1, $page['total']);
        $this->assertSame('เจ้าหน้าที่บุคลากร', $page['data'][0]['accessed_by']);
        $this->assertSame('leave_card', $page['data'][0]['resource']);
    }

    public function test_opening_the_user_directory_is_logged_as_bulk_access(): void
    {
        $this->staff('บุคลากรหนึ่ง');
        $admin = $this->staff('ผู้ดูแลระบบ');
        $admin->givePermissionTo(Permission::findOrCreate('view_users', 'web'), Permission::findOrCreate('edit_users', 'web'));

        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        $log = DB::table('personal_data_access_logs')->where('resource', 'user_directory')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertNull($log->subject_id);
        $this->assertGreaterThan(0, $log->subject_count);
    }

    public function test_editing_another_users_record_is_logged_against_that_person(): void
    {
        $subject = $this->staff('ผู้ถูกแก้ไขข้อมูล');
        $admin = $this->staff('ผู้ดูแลระบบ');
        $admin->givePermissionTo(Permission::findOrCreate('view_users', 'web'), Permission::findOrCreate('edit_users', 'web'));
        $role = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);

        $this->actingAs($admin)->putJson('/api/admin/users/'.$subject->id, [
            'name' => 'ชื่อใหม่',
            'email' => $subject->email,
            'role_id' => $role->id,
            'org_unit_id' => $subject->org_unit_id,
        ])->assertOk();

        $log = DB::table('personal_data_access_logs')
            ->where('subject_id', $subject->id)->where('resource', 'user_profile_edit')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_bulk_report_access_is_logged_without_a_single_subject(): void
    {
        $this->staff('บุคลากรหนึ่ง');
        $hr = $this->staff('เจ้าหน้าที่บุคลากร');
        $hr->givePermissionTo(Permission::findOrCreate('leave.report.view', 'web'));

        $this->actingAs($hr)->getJson('/api/reports/promotion-screening')->assertOk();

        $log = DB::table('personal_data_access_logs')->where('resource', 'promotion_screening')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->subject_id);
        $this->assertGreaterThan(0, $log->subject_count);
    }

    public function test_expired_leave_evidence_files_are_purged_but_metadata_is_kept(): void
    {
        $user = $this->staff();
        $type = LeaveType::create(['name' => 'ลาป่วยทดสอบ', 'code_prefix' => 'S']);
        $policy = LeavePolicy::create([
            'personnel_type_id' => $user->personnel_type_id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 60,
            'period_basis' => 'fiscal_year', 'is_active' => true,
        ]);
        $year = now()->month >= 10 ? now()->year + 1 : now()->year;
        LeaveBalance::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year,
            'quota' => 60, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0, 'leave_policy_id' => $policy->id,
        ]);

        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => '2010-01-04', 'end_date' => '2010-01-05',
            'total_days' => 2, 'reason' => 'ทดสอบ', 'status' => 'Approved', 'approval_level' => 1,
        ]);
        $evidence = LeaveEvidence::create([
            'leave_id' => $leave->id, 'uploaded_by' => $user->id,
            'evidence_type' => 'medical_certificate', 'original_name' => 'cert.pdf',
            'storage_path' => 'private/leave-evidences/old.pdf', 'sha256' => str_repeat('a', 64),
            'mime_type' => 'application/pdf', 'file_size' => 1024,
            'created_at' => now()->subYears(11), 'updated_at' => now()->subYears(11),
        ]);

        $this->artisan('pdpa:purge-leave-evidence --years=10 --force')->assertSuccessful();

        $evidence->refresh();
        $this->assertNull($evidence->storage_path);
        $this->assertNotNull($evidence->purged_at);
        $this->assertSame('cert.pdf', $evidence->original_name); // metadata ยังอยู่
    }
}
