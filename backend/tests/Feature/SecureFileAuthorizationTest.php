<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SecureFileAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');
    }

    public function test_user_can_open_own_profile_photo_but_another_user_cannot(): void
    {
        $owner = User::factory()->create();
        $owner->profile()->create(['profile_photo_path' => '/storage/profile_photos/owner.png']);
        $stranger = User::factory()->create();
        Storage::disk('private_uploads')->put('profile_photos/owner.png', 'image');

        $this->actingAs($owner)
            ->get('/api/files/profile_photos/owner.png')
            ->assertOk();

        $this->actingAs($stranger)
            ->get('/api/files/profile_photos/owner.png')
            ->assertForbidden();
    }

    public function test_user_can_open_own_attendance_image_but_another_user_cannot(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $attendance = Attendance::query()->create([
            'user_id' => $owner->id,
            'date' => '2026-08-13',
            'status' => 'Present',
        ]);
        $attendance->check_in_image = '/storage/attendances/owner.png';
        $attendance->save();
        Storage::disk('private_uploads')->put('attendances/owner.png', 'image');

        $this->actingAs($owner)
            ->get('/api/files/attendances/owner.png')
            ->assertOk();

        $this->actingAs($stranger)
            ->get('/api/files/attendances/owner.png')
            ->assertForbidden();
    }

    public function test_unassociated_private_file_is_denied_even_to_an_authenticated_user(): void
    {
        $user = User::factory()->create();
        Storage::disk('private_uploads')->put('signatures/orphan.png', 'image');

        $this->actingAs($user)
            ->get('/api/files/signatures/orphan.png')
            ->assertForbidden();
    }

    /*
     * SEC-029 — ไฟล์ต้องใช้ตัวตัดสินสิทธิ์ตัวเดียวกับทั้งระบบ
     *
     * เดิมบริการนี้ถาม Spatie ตรง ๆ ด้วย `hasAnyPermission()` จึงข้าม explicit deny
     * และไม่รู้จักชื่อ permission ที่เทียบเท่ากัน
     */

    private function orgUnit(string $code, string $name): OrgUnit
    {
        return OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'name' => $name,
            'type' => 'administrative',
            'effective_from' => '2025-01-01',
            'is_active' => true,
        ]);
    }

    private function ownerWithPhoto(): User
    {
        $owner = User::factory()->create();
        $owner->profile()->create(['profile_photo_path' => '/storage/profile_photos/owner.png']);
        Storage::disk('private_uploads')->put('profile_photos/owner.png', 'image');

        return $owner;
    }

    /** ผู้ดูแลทะเบียนบุคลากรเปิดรูปของผู้อื่นได้ */
    public function test_a_user_administrator_can_open_someone_elses_profile_photo(): void
    {
        $this->ownerWithPhoto();
        $admin = User::factory()->create();
        Permission::findOrCreate('system.user.view', 'web');
        $admin->givePermissionTo('system.user.view');

        $this->actingAs($admin->fresh())
            ->get('/api/files/profile_photos/owner.png')
            ->assertOk();
    }

    /** ชื่อสิทธิ์ชุดเดิมต้องใช้ได้ผ่าน alias โดยไม่ต้องเขียนชื่อเก่าไว้ในโค้ด */
    public function test_the_legacy_permission_name_still_grants_access_through_its_alias(): void
    {
        $this->ownerWithPhoto();
        $admin = User::factory()->create();
        Permission::findOrCreate('view_users', 'web');
        $admin->givePermissionTo('view_users');

        $this->actingAs($admin->fresh())
            ->get('/api/files/profile_photos/owner.png')
            ->assertOk();
    }

    /** explicit deny ต้องชนะ แม้ผู้ใช้จะถือสิทธิ์นั้นอยู่ */
    public function test_an_explicit_deny_beats_the_permission_the_user_holds(): void
    {
        $this->ownerWithPhoto();
        $admin = User::factory()->create();
        Permission::findOrCreate('system.user.view', 'web');
        $admin->givePermissionTo('system.user.view');

        DB::table('permission_denies')->insert([
            'model_type' => $admin->getMorphClass(),
            'model_id' => $admin->id,
            'permission_name' => 'system.user.view',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin->fresh())
            ->get('/api/files/profile_photos/owner.png')
            ->assertForbidden();
    }

    /** ผู้ดูแลที่ถูกจำกัดขอบเขตไว้ที่หน่วยงานของตน เปิดไฟล์ของหน่วยงานอื่นไม่ได้ */
    public function test_an_administrator_scoped_to_their_own_unit_cannot_open_another_units_file(): void
    {
        $theirUnit = $this->orgUnit('UNIT-ADMIN', 'ฝ่ายของผู้ดูแล');
        $otherUnit = $this->orgUnit('UNIT-OTHER', 'ฝ่ายอื่น');

        $owner = User::factory()->create(['org_unit_id' => $otherUnit->id]);
        $owner->profile()->create(['profile_photo_path' => '/storage/profile_photos/owner.png']);
        Storage::disk('private_uploads')->put('profile_photos/owner.png', 'image');

        $admin = User::factory()->create(['org_unit_id' => $theirUnit->id]);
        Permission::findOrCreate('system.user.view', 'web');
        $admin->givePermissionTo('system.user.view');

        DB::table('permission_scopes')->insert([
            'model_type' => $admin->getMorphClass(),
            'model_id' => $admin->id,
            'permission_name' => 'system.user.view',
            'scope' => OrgScopeResolver::UNIT,
            'org_unit_id' => $theirUnit->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin->fresh())
            ->get('/api/files/profile_photos/owner.png')
            ->assertForbidden();
    }

    /** และเปิดไฟล์ของบุคลากรในหน่วยงานของตนได้ตามปกติ */
    public function test_an_administrator_scoped_to_their_own_unit_can_open_a_file_inside_it(): void
    {
        $unit = $this->orgUnit('UNIT-SAME', 'ฝ่ายเดียวกัน');

        $owner = User::factory()->create(['org_unit_id' => $unit->id]);
        $owner->profile()->create(['profile_photo_path' => '/storage/profile_photos/owner.png']);
        Storage::disk('private_uploads')->put('profile_photos/owner.png', 'image');

        $admin = User::factory()->create(['org_unit_id' => $unit->id]);
        Permission::findOrCreate('system.user.view', 'web');
        $admin->givePermissionTo('system.user.view');

        DB::table('permission_scopes')->insert([
            'model_type' => $admin->getMorphClass(),
            'model_id' => $admin->id,
            'permission_name' => 'system.user.view',
            'scope' => OrgScopeResolver::UNIT,
            'org_unit_id' => $unit->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin->fresh())
            ->get('/api/files/profile_photos/owner.png')
            ->assertOk();
    }
}
