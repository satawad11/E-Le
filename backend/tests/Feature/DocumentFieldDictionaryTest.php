<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Document\DocumentFieldDefinition;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DocumentEngine\EngineActivation;
use App\Services\DocumentEngine\FieldDictionarySynchronizer;
use App\Services\DocumentEngine\LeaveDocumentGateway;
use App\Services\DocumentEngine\Providers\LeaveRequestDataProvider;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DESIGNER-03 — พจนานุกรมฟิลด์ต้องตรงกับสิ่งที่ระบบมีค่าให้จริง และเปิดใช้ engine ได้จากหน้าจอ
 *
 * เดิมพจนานุกรมถูกเติมครั้งเดียวตอน seed ช่องเฉพาะของประเภทการลา ขั้นอนุมัติรายขั้น และสถิติ
 * รายประเภทจึงวางบนแบบฟอร์มไม่ได้เลย ทั้งที่ระบบสร้างค่าเหล่านั้นให้อยู่แล้ว
 * ส่วนการเปิดใช้ engine รายประเภทต้องแก้ `.env` เท่านั้น และไม่มีบันทึกว่าใครเปิดเมื่อใด
 */
class DocumentFieldDictionaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $admin;
    }

    private function leaveTypeWithExtraFields(): LeaveType
    {
        return LeaveType::create([
            'name' => 'ลาอุปสมบท',
            'code_prefix' => 'O',
            'extra_fields' => [
                ['key' => 'temple_name', 'label' => 'ชื่อวัด', 'type' => 'text', 'required' => true],
                ['key' => 'ordination_date', 'label' => 'วันที่อุปสมบท', 'type' => 'date', 'required' => false],
            ],
        ]);
    }

    public function test_leave_type_extra_fields_become_placeable_variables(): void
    {
        $this->leaveTypeWithExtraFields();

        app(FieldDictionarySynchronizer::class)->sync();

        $temple = DocumentFieldDefinition::where('code', 'extra.temple_name')->first();
        $date = DocumentFieldDefinition::where('code', 'extra.ordination_date')->first();

        $this->assertNotNull($temple, 'ช่องเฉพาะของประเภทการลาต้องอยู่ในพจนานุกรมฟิลด์');
        $this->assertSame('ชื่อวัด', $temple->label_th);
        $this->assertSame('TEXT', $temple->data_type);
        $this->assertStringContainsString('ลาอุปสมบท', (string) $temple->description);
        $this->assertSame('DATE', $date?->data_type);
    }

    public function test_the_field_dictionary_endpoint_syncs_before_answering(): void
    {
        $this->leaveTypeWithExtraFields();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/document-engine/fields')
            ->assertOk();

        $codes = collect($response->json())->pluck('code');

        $this->assertTrue($codes->contains('extra.temple_name'), 'หน้าจอต้องเห็นตัวแปรที่เพิ่งเกิดจากการตั้งค่าประเภทการลา');
        $this->assertTrue($codes->contains('approval.step1.name'));
        $this->assertFalse($codes->contains('stats'), 'ฟิลด์ที่เป็นก้อนข้อมูลต้องไม่อยู่ในรายการของแบบฟอร์ม PDF');
        $this->assertFalse($codes->contains('extra'));
    }

    public function test_approval_steps_follow_the_configured_chain_length(): void
    {
        ApprovalStep::query()->delete();

        foreach ([1, 2, 3, 4, 5] as $order) {
            ApprovalStep::create(['step_order' => $order, 'name' => 'ขั้นที่ '.$order, 'approver_type' => 'permission', 'permission_name' => 'approve_leave']);
        }

        app(FieldDictionarySynchronizer::class)->sync();

        $this->assertDatabaseHas('document_field_definitions', ['code' => 'approval.step5.signature']);
        $this->assertDatabaseMissing('document_field_definitions', ['code' => 'approval.step6.signature']);
    }

    public function test_leave_statistics_are_placeable_per_leave_type(): void
    {
        $type = $this->leaveTypeWithExtraFields();

        app(FieldDictionarySynchronizer::class)->sync();

        $this->assertDatabaseHas('document_field_definitions', [
            'code' => "stats.leave_type_{$type->id}.days_total",
            'data_type' => 'NUMBER',
        ]);
    }

    public function test_the_provider_supplies_values_for_the_flattened_codes(): void
    {
        $department = Department::create(['name' => 'แผนกทดสอบพจนานุกรม']);
        $user = User::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        $type = $this->leaveTypeWithExtraFields();

        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDay()->toDateString(),
            'total_days' => 2, 'reason' => 'อุปสมบท', 'status' => 'Approved', 'approval_level' => 1,
            'meta_data' => ['extra_fields' => ['temple_name' => 'วัดทดสอบ']],
        ]);

        LeaveApproval::create([
            'leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => 1,
            'step_name' => 'ผู้อำนวยการ', 'status' => 'Approved',
            'approver_name' => 'นายทดสอบ ลงนาม', 'approver_position' => 'ผู้อำนวยการ', 'approved_at' => now(),
        ]);

        $values = app(LeaveRequestDataProvider::class)->fields(['leave' => $leave->fresh()]);

        $this->assertSame('วัดทดสอบ', $values['extra.temple_name'] ?? null);
        $this->assertSame('นายทดสอบ ลงนาม', $values['approval.step1.name'] ?? null);
        $this->assertArrayHasKey("stats.leave_type_{$type->id}.days_current", $values);
    }

    // ───────────────────────── การเปิดใช้ engine รายประเภท ─────────────────────────

    public function test_an_admin_can_enable_the_pdf_form_per_leave_type_from_the_screen(): void
    {
        config(['document_engine.enabled' => true, 'document_engine.entities.leave_types' => []]);

        $type = $this->leaveTypeWithExtraFields();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/document-engine/enabled-entities', ['leave_type_ids' => [$type->id]])
            ->assertOk()
            ->assertJsonPath('enabled_leave_types', [(string) $type->id]);

        $this->assertTrue(app(EngineActivation::class)->handles($type->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_engine_entities_changed']);
    }

    public function test_the_kill_switch_still_wins_over_what_is_stored(): void
    {
        config(['document_engine.enabled' => false]);

        $type = $this->leaveTypeWithExtraFields();
        app(EngineActivation::class)->setEnabledLeaveTypes([$type->id]);

        $this->assertFalse(app(EngineActivation::class)->handles($type->id));

        $leave = Leave::create([
            'user_id' => User::factory()->create(['is_active' => true])->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(),
            'total_days' => 1, 'reason' => 'ทดสอบ', 'status' => 'Pending', 'approval_level' => 1,
        ]);

        $this->assertFalse(app(LeaveDocumentGateway::class)->handles($leave));
    }

    public function test_the_leave_type_form_status_tells_admins_what_is_still_missing(): void
    {
        $type = $this->leaveTypeWithExtraFields();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/document-engine/leave-type-forms')
            ->assertOk();

        $row = collect($response->json('rows'))->firstWhere('leave_type_id', $type->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['engine_enabled']);
        $this->assertArrayHasKey('has_source_file', $row);
    }

    // ───────────────────────── การแก้ตัวแปรจากหน้าจอ ─────────────────────────

    public function test_an_admin_can_rename_a_variable_without_changing_what_it_reads(): void
    {
        $field = DocumentFieldDefinition::where('code', 'employee.full_name')->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/document-engine/fields/{$field->id}", [
                'label_th' => 'ชื่อผู้ขออนุญาตลา',
                'group_th' => 'ผู้ยื่นคำขอ',
            ])
            ->assertOk();

        $field->refresh();

        $this->assertSame('ชื่อผู้ขออนุญาตลา', $field->label_th);
        $this->assertSame('employee.full_name', $field->code, 'รหัสฟิลด์ต้องไม่เปลี่ยนตามการแก้ป้ายชื่อ');

        // การซิงก์รอบถัดไปต้องไม่เขียนทับสิ่งที่ผู้ดูแลตั้งเอง
        app(FieldDictionarySynchronizer::class)->sync();
        $this->assertSame('ชื่อผู้ขออนุญาตลา', $field->fresh()->label_th);
    }

    public function test_a_variable_is_deactivated_not_deleted(): void
    {
        $field = DocumentFieldDefinition::where('code', 'employee.position')->firstOrFail();

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/document-engine/fields/{$field->id}")
            ->assertOk();

        $this->assertDatabaseHas('document_field_definitions', ['id' => $field->id, 'is_active' => false]);
    }

    public function test_field_and_activation_endpoints_require_the_document_permission(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $field = DocumentFieldDefinition::first();

        $this->actingAs($outsider)->putJson("/api/admin/document-engine/fields/{$field->id}", ['label_th' => 'x'])->assertForbidden();
        $this->actingAs($outsider)->deleteJson("/api/admin/document-engine/fields/{$field->id}")->assertForbidden();
        $this->actingAs($outsider)->postJson('/api/admin/document-engine/enabled-entities', ['leave_type_ids' => []])->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/admin/document-engine/leave-type-forms')->assertForbidden();
    }
}
