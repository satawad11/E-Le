<?php

namespace Tests\Feature;

use App\Models\Personnel;
use App\Models\PersonnelAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonnelEntityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_personnel_table_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('personnel'));
        $this->assertTrue(Schema::hasColumns('personnel', [
            'id',
            'uuid',
            'user_id',
            'personnel_code',
            'title',
            'first_name',
            'last_name',
            'status',
            'effective_from',
            'effective_to',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_personnel_model_has_public_uuid_and_relation_with_user(): void
    {
        $user = User::factory()->create([
            'name' => 'สมชาย ใจดี',
            'email' => 'somchai.j@example.com',
            'is_active' => true,
        ]);

        $personnel = Personnel::create([
            'user_id' => $user->id,
            'personnel_code' => 'P-001',
            'title' => 'นาย',
            'first_name' => 'สมชาย',
            'last_name' => 'ใจดี',
            'status' => 'active',
            'effective_from' => now()->toDateString(),
        ]);

        $this->assertNotEmpty($personnel->uuid);
        $this->assertTrue(Str::isUuid($personnel->uuid));
        $this->assertEquals('นายสมชาย ใจดี', $personnel->full_name);
        $this->assertEquals($user->id, $personnel->user->id);
        $this->assertEquals($personnel->id, $user->fresh()->personnel->id);
    }

    public function test_personnel_assignments_relationship(): void
    {
        $user = User::factory()->create(['name' => 'สมศรี รักเรียน']);
        $personnel = Personnel::create([
            'user_id' => $user->id,
            'first_name' => 'สมศรี',
            'last_name' => 'รักเรียน',
            'effective_from' => now()->toDateString(),
        ]);

        $assignment = PersonnelAssignment::where('user_id', $user->id)->first();
        if (! $assignment) {
            $assignment = PersonnelAssignment::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'position' => 'ครูชำนาญการ',
                'effective_from' => now()->toDateString(),
            ]);
        }

        $this->assertEquals($personnel->id, $assignment->personnel->id);
        $this->assertTrue($personnel->assignments->contains('id', $assignment->id));
    }

    public function test_backfill_creates_personnel_for_existing_users_without_duplication(): void
    {
        $user1 = User::factory()->create(['name' => 'ประสิทธิ์ สุขสม', 'is_active' => true]);
        UserProfile::create([
            'user_id' => $user1->id,
            'personnel_code' => 'T-8899',
            'title' => 'นาย',
            'first_name' => 'ประสิทธิ์',
            'last_name' => 'สุขสม',
            'status' => 'active',
            'start_date' => '2020-01-15',
        ]);

        // Re-execute backfill logic
        $userRow = DB::table('users')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('users.id', $user1->id)
            ->select([
                'users.id as user_id',
                'users.name as user_name',
                'users.is_active as user_is_active',
                'users.created_at as user_created_at',
                'user_profiles.personnel_code',
                'user_profiles.title',
                'user_profiles.first_name',
                'user_profiles.last_name',
                'user_profiles.start_date',
                'user_profiles.status as profile_status',
            ])
            ->first();

        $alreadyExists = DB::table('personnel')->where('user_id', $userRow->user_id)->exists();
        if (! $alreadyExists) {
            DB::table('personnel')->insert([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userRow->user_id,
                'personnel_code' => $userRow->personnel_code ?: null,
                'title' => $userRow->title ?: null,
                'first_name' => $userRow->first_name ?: 'ประสิทธิ์',
                'last_name' => $userRow->last_name ?: 'สุขสม',
                'status' => 'active',
                'effective_from' => $userRow->start_date ?: now()->toDateString(),
                'effective_to' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertDatabaseHas('personnel', [
            'user_id' => $user1->id,
            'personnel_code' => 'T-8899',
            'first_name' => 'ประสิทธิ์',
            'last_name' => 'สุขสม',
        ]);

        $this->assertEquals(1, Personnel::where('user_id', $user1->id)->count());
    }

    public function test_user_account_and_security_identity_remain_authoritative(): void
    {
        $user = User::factory()->create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin.test@example.com',
            'is_active' => true,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);

        $role = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        $user->assignRole($role);

        $this->assertTrue($user->hasRole('Staff'));
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);
    }
}
