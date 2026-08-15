<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\PersonnelAssignment;
use App\Models\PersonnelType;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PersonnelSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonnelSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creation_creates_and_links_personnel_and_assignment(): void
    {
        $orgUnit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-001',
            'name' => 'กลุ่มงานวิชาการ',
            'type' => 'academic',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $personnelType = PersonnelType::firstOrCreate(
            ['code' => 'teacher'],
            ['name' => 'ข้าราชการครู', 'is_active' => true]
        );

        $user = User::create([
            'name' => 'วิทยา เรียนดี',
            'email' => 'wittaya.r@example.com',
            'password' => bcrypt('password123456'),
            'org_unit_id' => $orgUnit->id,
            'personnel_type_id' => $personnelType->id,
            'is_active' => true,
        ]);

        $this->assertNotNull($user->fresh()->personnel);
        $this->assertEquals('วิทยา', $user->fresh()->personnel->first_name);
        $this->assertEquals('เรียนดี', $user->fresh()->personnel->last_name);

        $assignment = $user->fresh()->currentAssignment;
        $this->assertNotNull($assignment);
        $this->assertEquals($orgUnit->id, $assignment->org_unit_id);
        $this->assertEquals($personnelType->id, $assignment->personnel_type_id);
    }

    public function test_profile_update_syncs_personnel_and_user_name(): void
    {
        $user = User::factory()->create([
            'name' => 'กิตติพงษ์ สว่างวงศ์',
            'email' => 'kittipong.s@example.com',
        ]);

        $profile = UserProfile::create([
            'user_id' => $user->id,
            'title' => 'นาย',
            'first_name' => 'กิตติพงษ์',
            'last_name' => 'แสงสว่าง',
            'personnel_code' => 'P-9900',
            'status' => 'active',
        ]);

        $personnel = $user->fresh()->personnel;
        $this->assertNotNull($personnel);
        $this->assertEquals('P-9900', $personnel->personnel_code);
        $this->assertEquals('กิตติพงษ์', $personnel->first_name);
        $this->assertEquals('แสงสว่าง', $personnel->last_name);
        $this->assertEquals('กิตติพงษ์ แสงสว่าง', $user->fresh()->name);
    }

    public function test_personnel_update_syncs_profile_and_user_name(): void
    {
        $user = User::factory()->create(['name' => 'สุภาภรณ์ ดวงแก้ว']);
        $profile = UserProfile::create([
            'user_id' => $user->id,
            'title' => 'นางสาว',
            'first_name' => 'สุภาภรณ์',
            'last_name' => 'ดวงแก้ว',
        ]);

        $personnel = Personnel::where('user_id', $user->id)->first();
        $personnel->update([
            'first_name' => 'สุภาณี',
            'last_name' => 'ดวงมณี',
        ]);

        $this->assertEquals('สุภาณี', $profile->fresh()->first_name);
        $this->assertEquals('ดวงมณี', $profile->fresh()->last_name);
        $this->assertEquals('สุภาณี ดวงมณี', $user->fresh()->name);
    }

    public function test_assignment_change_syncs_user_org_unit_and_legacy_department(): void
    {
        $dept = Department::create(['name' => 'ฝ่ายบริหารทั่วไป']);
        $orgUnit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-ADM',
            'name' => 'ฝ่ายบริหารทั่วไป',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        DB::table('department_org_unit_map')->insert([
            'department_id' => $dept->id,
            'org_unit_id' => $orgUnit->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create(['name' => 'วิชัย มั่นคง']);

        $assignment = PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'org_unit_id' => $orgUnit->id,
            'effective_from' => now()->toDateString(),
        ]);

        $this->assertEquals($orgUnit->id, $user->fresh()->org_unit_id);
        $this->assertEquals($dept->id, $user->fresh()->department_id);
    }

    public function test_assign_position_preserves_historical_effective_dates(): void
    {
        $user = User::factory()->create(['name' => 'อนุชา สุขสันต์']);

        $syncService = app(PersonnelSyncService::class);

        $assignment1 = $syncService->assignPosition($user->id, [
            'position' => 'ครูผู้ช่วย',
            'effective_from' => '2024-01-01',
        ]);

        $this->assertNull($assignment1->fresh()->effective_to);
        $this->assertEquals('ครูผู้ช่วย', $assignment1->position);

        $assignment2 = $syncService->assignPosition($user->id, [
            'position' => 'ครู ค.ศ. 1',
            'effective_from' => '2026-01-01',
        ]);

        $this->assertEquals('2025-12-31', $assignment1->fresh()->effective_to->toDateString());
        $this->assertNull($assignment2->fresh()->effective_to);
        $this->assertEquals('ครู ค.ศ. 1', $assignment2->position);

        // Historical record preserved
        $this->assertEquals(2, PersonnelAssignment::where('user_id', $user->id)->count());
    }

    public function test_sync_prevents_recursive_infinite_loops(): void
    {
        $syncService = app(PersonnelSyncService::class);
        $executed = false;

        $syncService->withoutSyncRecursion('test-key', function () use ($syncService, &$executed) {
            $innerExecuted = false;
            $result = $syncService->withoutSyncRecursion('test-key', function () use (&$innerExecuted) {
                $innerExecuted = true;
            });

            $this->assertNull($result);
            $this->assertFalse($innerExecuted);
            $executed = true;
        });

        $this->assertTrue($executed);
    }

    public function test_sync_rollback_on_transaction_failure(): void
    {
        $user = User::factory()->create(['name' => 'ธงชัย ใจสงบ']);
        $initialCount = PersonnelAssignment::count();

        try {
            DB::transaction(function () use ($user) {
                PersonnelAssignment::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'position' => 'หัวหน้ากลุ่มสาระ',
                    'effective_from' => now()->toDateString(),
                ]);

                throw new \RuntimeException('บังคับ Error เพื่อทดสอบ Rollback');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertEquals($initialCount, PersonnelAssignment::count());
    }
}
