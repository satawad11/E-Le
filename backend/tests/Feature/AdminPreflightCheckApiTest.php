<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\FiscalYear;
use App\Models\Holiday;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\PersonnelAssignment;
use App\Models\PersonnelType;
use App\Models\Time\WorkSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPreflightCheckApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_preflight_check(): void
    {
        $response = $this->getJson('/api/admin/preflight-check');

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/admin/preflight-check');

        $response->assertForbidden();
    }

    public function test_authorized_user_receives_structured_preflight_check_results(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('system.setting.manage', 'web'));

        $response = $this->actingAs($user)->getJson('/api/admin/preflight-check');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'pass_count',
                        'warn_count',
                        'fail_count',
                        'is_ready',
                        'status',
                        'checked_at',
                    ],
                    'checks' => [
                        '*' => [
                            'id',
                            'category',
                            'status',
                            'message',
                            'advice',
                            'action_url',
                            'action_text',
                        ],
                    ],
                ],
            ]);

        $checks = $response->json('data.checks');
        $this->assertCount(9, $checks);
        $ids = collect($checks)->pluck('id')->all();
        $this->assertEquals([
            'org_units',
            'org_unit_managers',
            'personnel_and_users',
            'fiscal_year_and_policies',
            'work_schedule_and_holidays',
            'document_templates',
            'notification_channels',
            'saraban_number_series',
            'saraban_signers',
        ], $ids);
    }

    public function test_preflight_check_reflects_ready_status_when_data_is_complete(): void
    {
        // 1. Root & Child OrgUnits
        $root = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'ROOT',
            'name' => 'วิทยาลัยเทคนิคตัวอย่าง',
            'type' => 'institution',
            'parent_id' => null,
            'effective_from' => '2020-01-01',
            'is_active' => true,
        ]);
        $dep = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'DEP1',
            'name' => 'ฝ่ายบริหารทรัพยากร',
            'type' => 'department',
            'parent_id' => $root->id,
            'effective_from' => '2020-01-01',
            'is_active' => true,
        ]);

        // 2. Admin User & Personnel
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['name' => 'ผู้ดูแลระบบ']);
        $admin->assignRole('Super Admin');
        $admin->givePermissionTo(Permission::findOrCreate('system.setting.manage', 'web'));

        Personnel::updateOrCreate(
            ['user_id' => $admin->id],
            [
                'personnel_code' => 'ADM001',
                'title' => 'นาย',
                'first_name' => 'ผู้ดูแล',
                'last_name' => 'ระบบ',
                'status' => 'active',
                'effective_from' => now()->subYear()->toDateString(),
            ]
        );
        $pt = PersonnelType::create(['code' => 'gov_officer', 'name' => 'ข้าราชการครู', 'is_active' => true]);

        PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'org_unit_id' => $dep->id,
            'personnel_type_id' => $pt->id,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);

        // 3. OrgUnit Managers
        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(),
            'org_unit_id' => $root->id,
            'user_id' => $admin->id,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(),
            'org_unit_id' => $dep->id,
            'user_id' => $admin->id,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Fiscal Year & Leave Policies
        FiscalYear::create([
            'name' => 'ปีงบประมาณ 2569',
            'year' => 2569,
            'start_date' => now()->subMonths(3)->toDateString(),
            'end_date' => now()->addMonths(9)->toDateString(),
            'is_current' => true,
        ]);

        $lt = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 60]);

        LeavePolicy::create([
            'personnel_type_id' => $pt->id,
            'leave_type_id' => $lt->id,
            'annual_quota' => 60,
            'effective_from' => now()->subYear()->toDateString(),
            'is_active' => true,
        ]);

        // 5. WorkSchedule & Holidays
        WorkSchedule::create([
            'code' => 'STANDARD',
            'name' => 'เวลาราชการปกติ',
            'timezone' => 'Asia/Bangkok',
            'is_default' => true,
            'is_active' => true,
        ]);
        Holiday::create([
            'name' => 'วันขึ้นปีใหม่',
            'date' => now()->startOfYear()->toDateString(),
            'is_active' => true,
        ]);

        // 6. Document Templates
        DocumentTemplate::create([
            'code' => 'LEAVE_SICK',
            'name' => 'แบบใบลาป่วย',
            'module' => 'leave',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'margin_top' => 8.0,
            'margin_bottom' => 8.0,
            'margin_left' => 12.0,
            'margin_right' => 12.0,
            'content_html' => '<p>แบบใบลา</p>',
            'is_active' => true,
        ]);

        // 7. Notification Channels
        config(['mail.mailers.smtp.host' => 'smtp.school.ac.th']);

        $response = $this->actingAs($admin)->getJson('/api/admin/preflight-check');

        $response->assertOk();
        $this->assertTrue($response->json('data.summary.is_ready'));
        $this->assertEquals(0, $response->json('data.summary.fail_count'));
    }
}
