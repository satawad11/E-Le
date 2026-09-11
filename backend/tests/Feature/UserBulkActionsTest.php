<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\User;
use App\Support\SystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ดำเนินการกับบุคลากรหลายคนพร้อมกัน
 *
 * งานจริงตอนต้นปีการศึกษาคือย้ายคนทั้งกลุ่มไปอีกหน่วยงาน หรือระงับบัญชีของผู้ที่
 * พ้นหน้าที่พร้อมกันหลายคน · เดิมทำได้ทีละคน ผู้ดูแลจึงต้องเปิด–แก้–บันทึกซ้ำหลายสิบรอบ
 *
 * ที่สำคัญกว่าความสะดวกคือ **ทางลัดนี้ต้องไม่ข้ามด่านของการทำทีละคน**
 */
class UserBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view_users', 'edit_users', 'system.user.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->admin = User::factory()->create(['name' => 'ผู้ดูแล ระบบ']);
        $this->admin->givePermissionTo(['view_users', 'edit_users', 'system.user.manage']);
    }

    public function test_ย้ายสังกัดหลายคนพร้อมกัน(): void
    {
        $from = OrgUnit::factory()->create(['name' => 'ฝ่ายเดิม']);
        $to = OrgUnit::factory()->create(['name' => 'ฝ่ายใหม่']);
        $staff = User::factory()->count(3)->create(['org_unit_id' => $from->id]);

        $this->actingAs($this->admin->fresh())
            ->postJson('/api/admin/users/bulk', [
                'action' => 'move_org_unit',
                'user_ids' => $staff->pluck('id')->all(),
                'org_unit_id' => $to->id,
            ])
            ->assertOk()
            ->assertJsonPath('changed', 3);

        $this->assertSame(3, User::where('org_unit_id', $to->id)->count());
    }

    public function test_ระงับหลายคนพร้อมกัน(): void
    {
        $staff = User::factory()->count(2)->create(['is_active' => true]);

        $this->actingAs($this->admin->fresh())
            ->postJson('/api/admin/users/bulk', [
                'action' => 'suspend',
                'user_ids' => $staff->pluck('id')->all(),
            ])
            ->assertOk()
            ->assertJsonPath('changed', 2);

        $this->assertSame(0, User::whereIn('id', $staff->pluck('id'))->where('is_active', true)->count());
    }

    public function test_ย้ายสังกัดต้องระบุหน่วยงานปลายทาง(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($this->admin->fresh())
            ->postJson('/api/admin/users/bulk', [
                'action' => 'move_org_unit',
                'user_ids' => [$staff->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('org_unit_id');
    }

    public function test_ไม่กวาดบัญชีของผู้เรียกเองไปด้วย(): void
    {
        // กดเลือกทั้งหน้าแล้วสั่งระงับ ต้องไม่ล็อกตัวเองออกจากระบบโดยไม่ตั้งใจ
        $other = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->admin->fresh())
            ->postJson('/api/admin/users/bulk', [
                'action' => 'suspend',
                'user_ids' => [$this->admin->id, $other->id],
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('changed'));
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertFalse($other->fresh()->is_active);
    }

    public function test_ระงับผู้ดูแลระบบสูงสุดคนสุดท้ายไม่ได้แม้ทำเป็นชุด(): void
    {
        $superRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        SystemRoles::backfillExistingRoles();

        $lastSuperAdmin = User::factory()->create(['is_active' => true]);
        $lastSuperAdmin->assignRole($superRole->name);

        $response = $this->actingAs($this->admin->fresh())
            ->postJson('/api/admin/users/bulk', [
                'action' => 'suspend',
                'user_ids' => [$lastSuperAdmin->id],
            ])
            ->assertOk();

        $this->assertSame(0, $response->json('changed'));
        $this->assertNotEmpty($response->json('skipped'));
        $this->assertTrue($lastSuperAdmin->fresh()->is_active);
    }

    public function test_แตะคนนอกขอบเขตที่รับผิดชอบไม่ได้(): void
    {
        /*
         * ด่านขอบเขตเดียวกับหน้ารายการ — ถ้าทางลัดนี้ข้ามได้ ผู้ดูแลที่ถูกจำกัด
         * ขอบเขตก็จะแก้ข้อมูลของทั้งองค์กรได้ด้วยการเดา id
         */
        $mine = OrgUnit::factory()->create(['name' => 'ฝ่ายที่รับผิดชอบ']);
        $other = OrgUnit::factory()->create(['name' => 'ฝ่ายอื่น']);

        $head = User::factory()->create(['org_unit_id' => $mine->id]);
        $head->givePermissionTo(['view_users', 'edit_users']);

        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(),
            'org_unit_id' => $mine->id,
            'user_id' => $head->id,
            'assignment_type' => 'MANAGER',
            'scope' => 'UNIT',
            'effective_from' => now()->subYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $outsider = User::factory()->create(['org_unit_id' => $other->id, 'is_active' => true]);

        $response = $this->actingAs($head->fresh())
            ->postJson('/api/admin/users/bulk', [
                'action' => 'suspend',
                'user_ids' => [$outsider->id],
            ])
            ->assertOk();

        $this->assertSame(0, $response->json('changed'));
        $this->assertSame(1, $response->json('out_of_scope'));
        $this->assertTrue($outsider->fresh()->is_active);
    }

    public function test_ผู้ที่ไม่มีสิทธิ์จัดการผู้ใช้เรียกไม่ได้(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->postJson('/api/admin/users/bulk', ['action' => 'suspend', 'user_ids' => [1]])
            ->assertForbidden();
    }
}
