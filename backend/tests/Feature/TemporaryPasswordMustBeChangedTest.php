<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รหัสผ่านที่ผู้ดูแลตั้งให้ต้องเป็นรหัสชั่วคราวเสมอ
 *
 * **ที่มา:** หน้าจัดการผู้ใช้ให้ผู้ดูแลพิมพ์รหัสผ่านของบุคลากรเองทั้งตอนสร้างบัญชี
 * และตอนรีเซ็ต · ผู้ดูแลจึงรู้รหัสผ่านที่บุคลากรใช้เข้าระบบจริง และไม่มีอะไรบังคับ
 * ให้เปลี่ยน รหัสนั้นจึงกลายเป็นรหัสถาวรของเจ้าตัวไปเลย
 *
 * ผลที่ตามมาคือ audit log ตอบไม่ได้ว่าใครทำรายการจริง — ถ้าสองคนรู้รหัสเดียวกัน
 * การกระทำในระบบก็ชี้ตัวคนทำไม่ได้ ซึ่งเป็นหัวใจของ audit trail
 */
class TemporaryPasswordMustBeChangedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OrgUnit $unit;

    private Role $employeeRole;

    private PersonnelType $personnelType;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view_users', 'edit_users', 'system.user.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->unit = OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบ']);
        $this->employeeRole = Role::findOrCreate('Employee', 'web');
        $this->personnelType = PersonnelType::firstOrCreate(
            ['code' => 'civil_servant'],
            ['name' => 'ข้าราชการ', 'is_active' => true],
        );

        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->givePermissionTo(['view_users', 'edit_users', 'system.user.manage']);
    }

    public function test_บัญชีที่ผู้ดูแลสร้างถูกบังคับให้เปลี่ยนรหัสผ่าน(): void
    {
        $this->actingAs($this->admin->fresh())
            ->postJson('/api/admin/users', [
                'name' => 'บุคลากรใหม่',
                'email' => 'newcomer@example.test',
                'password' => 'temporary-pass-1234',
                'role_id' => $this->employeeRole->id,
                'org_unit_id' => $this->unit->id,
                'personnel_type_id' => $this->personnelType->id,
            ])
            ->assertCreated();

        $this->assertTrue(User::where('email', 'newcomer@example.test')->firstOrFail()->must_change_password);
    }

    public function test_ผู้ดูแลรีเซ็ตรหัสให้_ก็ต้องเปลี่ยนก่อนใช้งานเช่นกัน(): void
    {
        $staff = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($this->admin->fresh())
            ->putJson("/api/admin/users/{$staff->id}", [
                'name' => $staff->name,
                'email' => $staff->email,
                'password' => 'reset-by-admin-1234',
                'role_id' => $this->employeeRole->id,
                'org_unit_id' => $this->unit->id,
                'personnel_type_id' => $this->personnelType->id,
            ])
            ->assertOk();

        $this->assertTrue($staff->fresh()->must_change_password);
    }

    public function test_นำเข้าด้วย_csv_ก็ตั้งธงเดียวกัน(): void
    {
        $csv = "name,email,role_id,org_unit_id,personnel_type_id\n"
            ."นำเข้า ทดสอบ,imported@example.test,{$this->employeeRole->id},{$this->unit->id},{$this->personnelType->id}\n";

        $this->actingAs($this->admin->fresh())
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertOk();

        $this->assertTrue(User::where('email', 'imported@example.test')->firstOrFail()->must_change_password);
    }

    public function test_ระบบปฏิเสธการใช้งานจนกว่าจะเปลี่ยนรหัส(): void
    {
        /*
         * ต้องกันที่ฝั่งเซิร์ฟเวอร์ ไม่ใช่แค่พาหน้าเว็บไปหน้าเปลี่ยนรหัส —
         * ผู้ที่ถือโทเคนยังยิง API ตรงได้ และรหัสชั่วคราวที่ผู้ดูแลรู้ก็จะยังใช้ได้ทุกอย่าง
         */
        $staff = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($staff)
            ->getJson('/api/leaves')
            ->assertStatus(423)
            ->assertJsonPath('must_change_password', true);
    }

    public function test_ยังเปลี่ยนรหัสและออกจากระบบได้ระหว่างถูกบังคับ(): void
    {
        // ถ้าปิดทุกทาง ผู้ใช้จะติดอยู่ในสภาพที่แก้เรื่องที่ระบบขอไม่ได้เลย
        $staff = User::factory()->create([
            'password' => bcrypt('temporary-pass-1234'),
            'must_change_password' => true,
        ]);

        $this->actingAs($staff)
            ->postJson('/api/profile/password', [
                'current_password' => 'temporary-pass-1234',
                'password' => 'chosen-by-owner-5678',
                'password_confirmation' => 'chosen-by-owner-5678',
            ])
            ->assertOk();

        $this->assertFalse($staff->fresh()->must_change_password);
    }

    public function test_เปลี่ยนรหัสเองแล้วใช้งานระบบได้ตามปกติ(): void
    {
        $staff = User::factory()->create([
            'password' => bcrypt('temporary-pass-1234'),
            'must_change_password' => true,
        ]);

        $this->actingAs($staff)->postJson('/api/profile/password', [
            'current_password' => 'temporary-pass-1234',
            'password' => 'chosen-by-owner-5678',
            'password_confirmation' => 'chosen-by-owner-5678',
        ])->assertOk();

        $this->actingAs($staff->fresh())->getJson('/api/leaves')->assertOk();
    }

    public function test_ตั้งรหัสผ่านผ่านลิงก์ลืมรหัสผ่านก็ปลดการบังคับ(): void
    {
        $staff = User::factory()->create([
            'email' => 'forgot@example.test',
            'must_change_password' => true,
        ]);

        $token = app('auth.password.broker')->createToken($staff);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'forgot@example.test',
            'password' => 'set-via-email-link-99',
            'password_confirmation' => 'set-via-email-link-99',
        ])->assertOk();

        $this->assertFalse($staff->fresh()->must_change_password);
    }

    public function test_บัญชีเดิมในระบบไม่ถูกบังคับย้อนหลัง(): void
    {
        // ค่าเริ่มต้นของคอลัมน์เป็น false — ไม่งั้นทุกคนถูกขวางพร้อมกันในเช้าวันที่อัปเดต
        $existing = User::factory()->create();

        $this->assertFalse($existing->must_change_password);
        $this->actingAs($existing)->getJson('/api/leaves')->assertOk();
    }
}
