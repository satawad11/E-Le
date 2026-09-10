<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SEC-030 — เลขประจำตัวประชาชนบน endpoint แบบรายการ
 *
 * ก่อนแก้ `GET /api/admin/users` คืนเลขบัตรประชาชน "ฉบับเต็ม" ของบุคลากรทุกคน
 * ในคำขอเดียว แล้วหน้าจอเอาไปแสดงเป็นคอลัมน์ในตาราง ใครที่เปิดหน้านี้ได้จึงได้
 * ฐานเลขบัตรประชาชนทั้งสถานศึกษาไปในคราวเดียว ทั้งที่งานบนหน้าจอต้องการเพียง
 * "ยืนยันว่าใช่คนนี้"
 */
class PersonalIdentifierMaskingTest extends TestCase
{
    use RefreshDatabase;

    private const ID_CARD = '1234567890123';

    private function subject(?int $orgUnitId = null): User
    {
        $user = User::factory()->create(['org_unit_id' => $orgUnitId]);
        $user->profile()->create(['id_card_number' => self::ID_CARD, 'first_name' => 'ทดสอบ', 'last_name' => 'ระบบ']);

        return $user;
    }

    private function administrator(?int $orgUnitId = null): User
    {
        $admin = User::factory()->create(['org_unit_id' => $orgUnitId]);
        Permission::findOrCreate('system.user.view', 'web');
        Permission::findOrCreate('system.user.manage', 'web');
        $admin->givePermissionTo('system.user.view');

        return $admin->fresh();
    }

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

    public function test_the_list_endpoint_never_returns_a_full_id_card_number(): void
    {
        $subject = $this->subject();
        $admin = $this->administrator();

        $response = $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        $this->assertStringNotContainsString(self::ID_CARD, $response->getContent());

        $row = collect($response->json())->firstWhere('id', $subject->id);

        $this->assertSame('x-xxxx-xxxx0-12-3', $row['profile']['id_card_number']);
        $this->assertSame('0123', $row['profile']['id_card_last_four']);
    }

    /** ค่าที่ใช้ค้นย้อนกลับได้ต้องไม่หลุดออกไปกับรายการด้วย */
    public function test_the_list_endpoint_does_not_leak_the_hash_or_passport_number(): void
    {
        $subject = $this->subject();
        $admin = $this->administrator();

        $row = collect($this->actingAs($admin)->getJson('/api/admin/users')->json())
            ->firstWhere('id', $subject->id);

        $this->assertArrayNotHasKey('id_card_number_hash', $row['profile']);
        $this->assertArrayNotHasKey('passport_number', $row['profile']);
    }

    /** workflow ที่ต้องใช้เลขเต็ม: ค้นหาด้วยเลข 13 หลักต้องยังเจอคนที่ถูกต้อง */
    public function test_searching_by_the_full_number_still_finds_the_person(): void
    {
        $subject = $this->subject();
        $this->subject(); // อีกคนหนึ่งที่ต้องไม่ติดมาด้วย
        $admin = $this->administrator();

        $found = $this->actingAs($admin)
            ->getJson('/api/admin/users?id_card='.self::ID_CARD)
            ->assertOk()
            ->json();

        $this->assertCount(2, $found, 'ทั้งสองคนใช้เลขทดสอบเดียวกัน จึงต้องเจอทั้งคู่');
        $this->assertContains($subject->id, array_column($found, 'id'));

        $none = $this->actingAs($admin)->getJson('/api/admin/users?id_card=9999999999999')->json();
        $this->assertCount(0, $none);
    }

    /** หน้ารายละเอียดยังเปิดเลขเต็มได้ และต้องบันทึกการเข้าถึงข้อมูลส่วนบุคคล */
    public function test_the_detail_endpoint_returns_the_full_number_and_records_the_access(): void
    {
        $subject = $this->subject();
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->getJson("/api/admin/users/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('profile.id_card_number', self::ID_CARD);

        $this->assertDatabaseHas('personal_data_access_logs', [
            'actor_id' => $admin->id,
            'subject_id' => $subject->id,
            'resource' => 'user_profile',
        ]);
    }

    /** ผู้ดูแลที่ถูกจำกัดขอบเขต เปิดทะเบียนประวัติของหน่วยงานอื่นไม่ได้ */
    public function test_an_administrator_outside_the_scope_cannot_open_the_detail_record(): void
    {
        $theirUnit = $this->orgUnit('UNIT-A', 'ฝ่ายของผู้ดูแล');
        $otherUnit = $this->orgUnit('UNIT-B', 'ฝ่ายอื่น');

        $subject = $this->subject($otherUnit->id);
        $admin = $this->administrator($theirUnit->id);

        DB::table('permission_scopes')->insert([
            'model_type' => $admin->getMorphClass(),
            'model_id' => $admin->id,
            'permission_name' => 'system.user.view',
            'scope' => 'UNIT',
            'org_unit_id' => $theirUnit->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin->fresh())
            ->getJson("/api/admin/users/{$subject->id}")
            ->assertForbidden();
    }

    /** ผู้ที่ไม่มีสิทธิ์ดูทะเบียนผู้ใช้เลย ต้องเข้าไม่ได้ทั้งรายการและรายละเอียด */
    public function test_a_user_without_the_directory_permission_is_refused(): void
    {
        $subject = $this->subject();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($stranger)->getJson("/api/admin/users/{$subject->id}")->assertForbidden();
    }

    /** ค่าที่ปิดบังต้องเขียนทับข้อมูลจริงไม่ได้ */
    public function test_a_masked_number_cannot_be_written_back_over_the_real_one(): void
    {
        $subject = $this->subject();
        $admin = $this->administrator();
        Permission::findOrCreate('system.user.manage', 'web');
        $admin->givePermissionTo('system.user.manage');

        $this->actingAs($admin->fresh())
            ->putJson("/api/admin/users/{$subject->id}", [
                'name' => $subject->name,
                'email' => $subject->email,
                'role_id' => Role::create(['name' => 'บทบาททดสอบ', 'guard_name' => 'web'])->id,
                'org_unit_id' => OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบ'])->id,
                'profile' => ['id_card_number' => 'x-xxxx-xxxx0-12-3'],
            ])
            ->assertStatus(422);

        $this->assertSame(self::ID_CARD, $subject->fresh()->profile->id_card_number);
    }
}
