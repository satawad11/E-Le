<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\PersonnelAssignment;
use App\Models\PersonnelType;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelApiAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_user_endpoints_return_personnel_domain_payload(): void
    {
        $orgUnit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-API',
            'name' => 'งานวิชาการ',
            'type' => 'academic',
            'effective_from' => '2025-01-01',
            'is_active' => true,
        ]);

        $personnelType = PersonnelType::firstOrCreate(['code' => 'teacher'], ['name' => 'ครูผู้สอน', 'is_active' => true]);

        $user = User::factory()->create([
            'email' => 'teacher@example.com',
            'password' => bcrypt('password123456'),
        ]);

        $personnel = Personnel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'personnel_code' => 'TCH-001',
            'title' => 'ครู',
            'first_name' => 'วันดี',
            'last_name' => 'มีสุข',
            'status' => 'active',
            'effective_from' => '2025-01-01',
        ]);

        PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'org_unit_id' => $orgUnit->id,
            'personnel_type_id' => $personnelType->id,
            'position' => 'ครูชำนาญการ',
            'effective_from' => '2025-01-01',
        ]);

        // 1. Test POST /api/login
        $loginRes = $this->postJson('/api/login', [
            'email' => 'teacher@example.com',
            'password' => 'password123456',
        ]);

        $loginRes->assertStatus(200);
        $loginRes->assertJsonStructure([
            'access_token',
            'token_type',
            'user' => [
                'id',
                'name',
                'email',
                'personnel_uuid',
                'personnel_code',
                'personnel_status',
                'position',
                'personnel',
                'current_assignment',
            ],
        ]);

        $userData = $loginRes->json('user');
        $this->assertEquals($personnel->uuid, $userData['personnel_uuid']);
        $this->assertEquals('TCH-001', $userData['personnel_code']);
        $this->assertEquals('active', $userData['personnel_status']);
        $this->assertEquals('ครูชำนาญการ', $userData['position']);
        $this->assertEquals('ครูวันดี มีสุข', $userData['personnel']['full_name']);
        $this->assertEquals($orgUnit->id, $userData['current_assignment']['org_unit_id']);

        $token = $loginRes->json('access_token');

        // 2. Test GET /api/user
        $userRes = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user');
        $userRes->assertStatus(200);
        $userRes->assertJsonPath('personnel_code', 'TCH-001');
        $userRes->assertJsonPath('personnel.full_name', 'ครูวันดี มีสุข');
        $userRes->assertJsonPath('current_assignment.position', 'ครูชำนาญการ');
    }

    public function test_admin_users_and_list_endpoints_include_domain_data(): void
    {
        $admin = User::factory()->create();
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin->assignRole('Super Admin');

        $dept = Department::create(['name' => 'แผนกสามัญ']);
        $targetUser = User::factory()->create(['department_id' => $dept->id]);

        Personnel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $targetUser->id,
            'personnel_code' => 'ADM-999',
            'title' => 'นาย',
            'first_name' => 'ประสิทธิ์',
            'last_name' => 'ทองคำ',
            'status' => 'active',
            'effective_from' => '2024-01-01',
        ]);

        // GET /api/admin/users
        $indexRes = $this->actingAs($admin)->getJson('/api/admin/users');
        $indexRes->assertStatus(200);
        $found = collect($indexRes->json())->firstWhere('id', $targetUser->id);
        $this->assertNotNull($found);
        $this->assertEquals('ADM-999', $found['personnel_code']);
        $this->assertEquals('นายประสิทธิ์ ทองคำ', $found['personnel']['full_name']);

        // GET /api/users/list
        $listRes = $this->actingAs($admin)->getJson('/api/users/list');
        $listRes->assertStatus(200);
        $foundList = collect($listRes->json())->firstWhere('id', $targetUser->id);
        $this->assertNotNull($foundList);
        $this->assertArrayHasKey('uuid', $foundList);
        $this->assertArrayHasKey('department_id', $foundList);
        $this->assertEquals('ADM-999', $foundList['personnel']['personnel_code']);
    }
}
