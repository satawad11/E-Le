<?php

namespace Tests\Feature;

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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckPreflightReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_check_fails_when_no_data_configured(): void
    {
        $this->artisan('app:preflight-check')
            ->expectsOutputToContain('ระบบบริหารงานสถานศึกษา E-La')
            ->assertExitCode(1);
    }

    public function test_preflight_check_passes_when_all_master_data_present(): void
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
        $fy = FiscalYear::create([
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
            'date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->artisan('app:preflight-check')
            ->expectsOutputToContain('สรุป:')
            ->assertExitCode(0);
    }
}
