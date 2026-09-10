<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Document\DocumentFieldDefinition;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use App\Services\DocumentEngine\FieldDictionarySynchronizer;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * วงจรชีวิตของ "ประเภทการลา" ต้องไม่พาข้อมูลสิทธิของบุคลากรหายไปด้วย
 *
 * `leave_policies` และ `leave_balances` ผูกกับ `leave_types` แบบ cascade การลบประเภท
 * การลาหนึ่งครั้งจึงลบสิทธิและยอดยกมาของทุกคนที่ผูกกับประเภทนั้นไปพร้อมกันโดยไม่มีคำเตือน
 * ด่านเดิมตรวจแค่ "มีใบลาหรือยัง" ซึ่งไม่ครอบกรณีที่ตั้งค่าสิทธิไว้แล้วแต่ยังไม่มีใครลา
 *
 * และเมื่อประเภทการลาหายไป ตัวแปรของแบบฟอร์มที่ระบบสร้างให้ตามประเภทนั้นต้องถูกปิด
 * มิฉะนั้นผู้ดูแลยังเลือกวางบนแบบฟอร์มได้ทั้งที่ไม่มีค่ามาเติมอีกแล้ว
 */
class LeaveTypeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage_leave_types', 'web'));

        return $admin;
    }

    public function test_a_leave_type_with_entitlement_policies_cannot_be_deleted(): void
    {
        $personnel = PersonnelType::create(['code' => 'lifecycle', 'name' => 'บุคลากรทดสอบวงจร']);
        $type = LeaveType::create(['name' => 'ลาทดสอบวงจร', 'code_prefix' => 'LC']);

        $policy = LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 10,
            'period_basis' => 'fiscal_year', 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/leave-types/'.$type->id)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'นโยบายสิทธิ'));

        $this->assertDatabaseHas('leave_types', ['id' => $type->id]);
        $this->assertDatabaseHas('leave_policies', ['id' => $policy->id]);
    }

    public function test_a_leave_type_with_balances_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $type = LeaveType::create(['name' => 'ลาที่มียอดคงเหลือ', 'code_prefix' => 'BAL']);

        $balance = LeaveBalance::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => 2026,
            'quota' => 10, 'used' => 2, 'reserved' => 0, 'carried_forward' => 3,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/leave-types/'.$type->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('leave_balances', ['id' => $balance->id, 'carried_forward' => 3]);
    }

    public function test_a_leave_type_nobody_uses_can_still_be_deleted(): void
    {
        $type = LeaveType::create(['name' => 'ลาที่ยังไม่มีใครใช้', 'code_prefix' => 'UNU']);

        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/leave-types/'.$type->id)
            ->assertOk();

        $this->assertDatabaseMissing('leave_types', ['id' => $type->id]);
    }

    // ───────────────────── ตัวแปรของแบบฟอร์มที่ต้นทางหายไป ─────────────────────

    public function test_statistics_variables_of_a_removed_leave_type_are_switched_off(): void
    {
        $this->seed(DocumentEngineSeeder::class);

        $type = LeaveType::create(['name' => 'ลาที่จะถูกลบ', 'code_prefix' => 'GONE']);
        $synchronizer = app(FieldDictionarySynchronizer::class);
        $synchronizer->sync();

        $codes = DocumentFieldDefinition::where('code', 'like', "stats.leave_type_{$type->id}.%")->pluck('code');
        $this->assertNotEmpty($codes, 'ต้องมีตัวแปรสถิติของประเภทการลาที่เพิ่งสร้าง');

        $type->delete();
        $synchronizer->sync();

        $this->assertSame(
            0,
            DocumentFieldDefinition::whereIn('code', $codes)->where('is_active', true)->count(),
            'ตัวแปรของประเภทการลาที่ถูกลบต้องไม่ให้เลือกวางบนแบบฟอร์มอีก',
        );

        // ปิดเท่านั้น ไม่ลบทิ้ง — แบบฟอร์มเวอร์ชันเก่าที่อ้างอยู่ยังต้องอธิบายได้ว่ารหัสนี้เคยหมายถึงอะไร
        $this->assertSame($codes->count(), DocumentFieldDefinition::whereIn('code', $codes)->count());
    }

    public function test_extra_field_variables_are_switched_off_when_the_field_is_removed(): void
    {
        $this->seed(DocumentEngineSeeder::class);

        $type = LeaveType::create([
            'name' => 'ลาอุปสมบททดสอบ', 'code_prefix' => 'ORD',
            'extra_fields' => [['key' => 'temple_name', 'label' => 'ชื่อวัด', 'type' => 'text', 'required' => true]],
        ]);

        $synchronizer = app(FieldDictionarySynchronizer::class);
        $synchronizer->sync();

        $this->assertTrue(DocumentFieldDefinition::where('code', 'extra.temple_name')->where('is_active', true)->exists());

        $type->update(['extra_fields' => []]);
        $synchronizer->sync();

        $this->assertFalse(
            DocumentFieldDefinition::where('code', 'extra.temple_name')->where('is_active', true)->exists(),
            'ช่องเฉพาะที่ถูกถอดออกต้องไม่ค้างให้เลือกวางบนแบบฟอร์ม',
        );
    }

    public function test_approval_step_variables_beyond_the_configured_chain_are_switched_off(): void
    {
        $this->seed(DocumentEngineSeeder::class);

        ApprovalStep::query()->delete();

        foreach ([1, 2, 3, 4, 5] as $order) {
            ApprovalStep::create(['step_order' => $order, 'name' => 'ขั้นที่ '.$order, 'approver_type' => 'permission', 'permission_name' => 'approve_leave']);
        }

        $synchronizer = app(FieldDictionarySynchronizer::class);
        $synchronizer->sync();

        $this->assertTrue(DocumentFieldDefinition::where('code', 'approval.step5.name')->where('is_active', true)->exists());

        ApprovalStep::where('step_order', '>', 3)->delete();
        $synchronizer->sync();

        $this->assertFalse(
            DocumentFieldDefinition::where('code', 'approval.step5.name')->where('is_active', true)->exists(),
            'ขั้นอนุมัติที่ถูกลดออกต้องไม่ค้างเป็นตัวแปรให้วาง',
        );
        $this->assertTrue(DocumentFieldDefinition::where('code', 'approval.step3.name')->where('is_active', true)->exists());
    }
}
