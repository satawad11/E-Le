<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ลบบัญชีผู้ใช้แล้วประวัติการลาต้องไม่หายตาม
 *
 * **ที่มา:** `DELETE /admin/users/{id}` เคยลบแถวจริง และ foreign key ที่ชี้มาที่
 * `users` ตั้งเป็น CASCADE หลายตัว — `leaves`, `leave_balances`, `attendances`,
 * `leave_approvals` · ลบบุคลากรหนึ่งคนจึงพาทะเบียนการลาทั้งหมดของเขาหายถาวร
 * และลบแถวผู้อนุมัติบนใบลาของ **คนอื่น** ไปด้วย ทำให้เอกสารของผู้อื่นอธิบายไม่ได้
 * ว่าใครเป็นผู้อนุมัติ
 *
 * ทะเบียนการลาของราชการเป็นเอกสารที่ต้องเก็บตามระเบียบ การกดปุ่มผิดครั้งเดียว
 * ไม่ควรลบหลักฐานได้
 */
class UserDeletionProtectsHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findOrCreate('delete_users', 'web'));

        $this->admin = User::factory()->create(['name' => 'ผู้ดูแล ระบบ']);
        $this->admin->assignRole($role);
    }

    private function staffWithLeave(): User
    {
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $unit = OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบ']);
        $user = User::factory()->create(['name' => 'สมหญิง พนักงาน', 'org_unit_id' => $unit->id]);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->subDays(4)->toDateString(),
            'total_days' => 2, 'reason' => 'ทดสอบ', 'status' => 'Approved',
            'document_number' => 'สธ 0412/0001',
        ]);

        return $user;
    }

    public function test_ลบบัญชีที่มีใบลาไม่ได้_และบอกจำนวนที่อ้างถึง(): void
    {
        $staff = $this->staffWithLeave();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/admin/users/{$staff->id}")
            ->assertStatus(422);

        $this->assertStringContainsString('ใบลา 1 รายการ', $response->json('message'));
        $this->assertStringContainsString('ระงับการใช้งาน', $response->json('message'));

        // สำคัญที่สุด: ทั้งบัญชีและใบลายังอยู่ครบ
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'deleted_at' => null]);
        $this->assertSame(1, Leave::where('user_id', $staff->id)->count());
    }

    public function test_ลบบัญชีที่เคยเป็นผู้อนุมัติบนใบลาของคนอื่นไม่ได้(): void
    {
        /*
         * กรณีที่อันตรายที่สุด — เจ้าของใบลาไม่ได้เกี่ยวอะไรด้วยเลย แต่เอกสารของเขา
         * จะสูญเสียแถวผู้อนุมัติไปถ้าบัญชีผู้อนุมัติถูกลบแถวจริง
         */
        $owner = $this->staffWithLeave();
        $approver = User::factory()->create(['name' => 'สมปอง หัวหน้า']);
        $leave = Leave::where('user_id', $owner->id)->firstOrFail();

        DB::table('leave_approvals')->insert([
            'leave_id' => $leave->id,
            'approver_id' => $approver->id,
            'step_order' => 1,
            'status' => 'Approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/admin/users/{$approver->id}")
            ->assertStatus(422);

        $this->assertStringContainsString('การอนุมัติที่ลงนามไว้บนใบลาของผู้อื่น', $response->json('message'));
        $this->assertSame(1, DB::table('leave_approvals')->where('approver_id', $approver->id)->count());
    }

    public function test_บัญชีที่ยังไม่มีเอกสารใดอ้างถึงลบได้แบบ_soft(): void
    {
        $unused = User::factory()->create(['name' => 'บัญชีที่สร้างผิด']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/users/{$unused->id}")
            ->assertOk();

        // แถวยังอยู่ CASCADE จึงไม่ทำงาน — แต่หายจากรายการปกติแล้ว
        $this->assertSoftDeleted('users', ['id' => $unused->id]);
        $this->assertNull(User::find($unused->id));
        $this->assertNotNull(User::withTrashed()->find($unused->id));
    }

    public function test_บัญชีที่ถูกลบเข้าสู่ระบบไม่ได้(): void
    {
        $unused = User::factory()->create([
            'email' => 'gone@example.test',
            'password' => bcrypt('password-for-test-1'),
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/admin/users/{$unused->id}")->assertOk();

        // ปฏิเสธด้วยข้อความเดียวกับรหัสผ่านผิด — ไม่บอกคนนอกว่าบัญชีนี้เคยมีอยู่
        $this->postJson('/api/login', ['email' => 'gone@example.test', 'password' => 'password-for-test-1'])
            ->assertStatus(422)
            ->assertJsonMissingPath('access_token');
    }

    public function test_กู้บัญชีคืนได้และกลับมาในสถานะระงับการใช้งาน(): void
    {
        $unused = User::factory()->create(['name' => 'บัญชีที่กู้คืน', 'is_active' => true]);
        $this->actingAs($this->admin)->deleteJson("/api/admin/users/{$unused->id}")->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$unused->id}/restore")
            ->assertOk();

        $restored = User::find($unused->id);
        $this->assertNotNull($restored);
        // กลับมาแบบระงับไว้ก่อน ให้ผู้ดูแลตัดสินใจอีกครั้งก่อนให้เข้าระบบได้จริง
        $this->assertFalse($restored->is_active);
    }

    public function test_บัญชีที่ถูกลบไม่ปรากฏในรายการผู้ใช้(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::findOrCreate('view_users', 'web'));

        $unused = User::factory()->create(['name' => 'บัญชีที่ถูกลบ']);
        $this->actingAs($this->admin)->deleteJson("/api/admin/users/{$unused->id}")->assertOk();

        $names = collect($this->actingAs($viewer)->getJson('/api/admin/users')->json())->pluck('name');

        $this->assertNotContains('บัญชีที่ถูกลบ', $names);
    }
}
