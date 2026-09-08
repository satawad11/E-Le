<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use App\Services\Saraban\DocumentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * RISK-26 — จัดการสายการเดินหนังสือจากหน้าจอ (SoT §11, §12 · NAV §5.2)
 *
 * เรื่องที่ตรึงไว้ เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **เวอร์ชันที่ใช้งานแล้วแก้ไม่ได้** — เป็นหลักฐานว่าเรื่องในอดีตเดินตาม
 *      กติกาใด แก้ย้อนหลังได้เมื่อไรก็ตรวจสอบย้อนหลังไม่ได้อีกเลย
 *   2. **เรื่องที่กำลังเดินไม่เปลี่ยนสายกลางคัน** — instance pin เวอร์ชันไว้
 *   3. **สายงานของสารบรรณแตะสายของโมดูลอื่นไม่ได้** — ตารางเป็นตารางกลาง
 *   4. **ขั้นตอนผูกกับสิทธิ์ในสารบัญของสารบรรณเท่านั้น** — ขั้นที่ไม่มีใครถือสิทธิ์
 *      คือเรื่องที่ค้างโดยไม่มีข้อความบอก
 *   5. **เส้นทางมาจากลำดับขั้นเสมอ** — ผู้ดูแลผูกเส้นทางผิดเองไม่ได้
 */
class SarabanWorkflowDesignTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function admin(): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $user->givePermissionTo(Permission::findOrCreate('saraban.workflow.manage', 'web'));

        return $user->fresh();
    }

    /**
     * ออกโทเคนใหม่ให้ผู้ใช้ พร้อมกำหนดเวลาที่ยืนยันตัวตนครั้งล่าสุด
     *
     * ต้องล้าง guard ก่อนเสมอ — ในชุดทดสอบ application instance เดียวถูกใช้ซ้ำ
     * guard จึงจำผู้ใช้คนแรกไว้และเพิกเฉยต่อโทเคนใบใหม่
     */
    private function actAs(User $user, string $authenticatedAt = 'now'): void
    {
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('test-token');
        $token->accessToken->forceFill(['last_authenticated_at' => $authenticatedAt])->save();

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken]);
    }

    private function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::query()
            ->where('code', DocumentWorkflowService::DEFINITION_CODE)
            ->firstOrFail();
    }

    // ── สายงานตั้งต้นหลัง migration ────────────────────────────────────────

    /**
     * v2 ต้องเป็นสายที่ใช้งานอยู่ และมีขั้นรองผู้อำนวยการคั่นกลาง
     *
     * นี่คือผังที่เจ้าของระบบยืนยัน: สร้าง → ตรวจ → รอง ผอ. → ผอ.
     */
    public function test_the_published_workflow_routes_through_the_deputy_before_the_signer(): void
    {
        $active = $this->definition()->activeVersion();

        $this->assertNotNull($active);
        $this->assertSame(2, $active->version);

        $steps = $active->steps()->get();

        $this->assertSame(
            ['saraban_review', 'deputy_review', 'signature'],
            $steps->pluck('code')->all(),
        );

        $this->assertSame('saraban.workflow.approve', $steps[1]->assignee_permission);
        $this->assertTrue((bool) $steps[2]->is_final);
        $this->assertFalse((bool) $steps[1]->is_final);
    }

    /** เวอร์ชันเดิมถูกปลด แต่ยังอยู่ในทะเบียนเพื่อการตรวจสอบย้อนหลัง */
    public function test_the_previous_version_is_retired_not_deleted(): void
    {
        $v1 = $this->definition()->versions()->where('version', 1)->first();

        $this->assertNotNull($v1, 'เวอร์ชันเดิมต้องไม่ถูกลบ — เป็นหลักฐานของเรื่องที่เดินไปแล้ว');
        $this->assertSame(WorkflowVersion::STATUS_RETIRED, $v1->status);
    }

    // ── การแก้ไขผ่านหน้าจอ ────────────────────────────────────────────────

    public function test_a_draft_is_created_by_copying_the_active_version(): void
    {
        $this->actAs($this->admin());

        $draft = $this->postJson('/api/saraban/workflow/design/drafts')->assertCreated()->json();

        $this->assertSame(WorkflowVersion::STATUS_DRAFT, $draft['status']);
        $this->assertSame(3, $draft['version']);
        $this->assertSame(
            ['saraban_review', 'deputy_review', 'signature'],
            array_column($draft['steps'], 'code'),
            'ฉบับร่างต้องคัดลอกของเดิมมาให้ ไม่เริ่มจากหน้าว่าง',
        );
    }

    public function test_only_one_draft_may_exist_at_a_time(): void
    {
        $this->actAs($this->admin());

        $this->postJson('/api/saraban/workflow/design/drafts')->assertCreated();
        $this->postJson('/api/saraban/workflow/design/drafts')->assertStatus(422);
    }

    /**
     * ผู้ดูแลจัดลำดับขั้น ระบบผูกเส้นทางให้เอง
     *
     * ตรวจเส้นทางที่ได้จริง เพราะนี่คือส่วนที่ผู้ดูแลไม่ได้กรอกเอง — ถ้าผูกผิด
     * จะได้สายงานที่เดินต่อไม่ได้และค้างถาวรโดยไม่มีข้อความใดบอก
     */
    public function test_the_system_wires_the_routes_from_the_step_order(): void
    {
        $this->actAs($this->admin());

        $draft = $this->postJson('/api/saraban/workflow/design/drafts')->assertCreated()->json();

        $saved = $this->putJson("/api/saraban/workflow/design/{$draft['id']}", [
            'steps' => [
                ['code' => 'saraban_review', 'name' => 'งานสารบรรณตรวจสอบ', 'assignee_permission' => 'saraban.workflow.review'],
                ['code' => 'signature', 'name' => 'ผู้มีอำนาจลงนาม', 'assignee_permission' => 'saraban.signature.sign'],
            ],
        ])->assertOk()->json();

        $this->assertSame(['saraban_review', 'signature'], array_column($saved['steps'], 'code'));

        $approvals = array_values(array_filter($saved['transitions'], fn ($t) => $t['action'] === 'approve'));

        $this->assertSame('ผู้มีอำนาจลงนาม', $approvals[0]['to'], 'ขั้นแรกต้องเดินต่อไปขั้นที่สอง');
        $this->assertNull($approvals[1]['to']);
        $this->assertSame('completed', $approvals[1]['result'], 'ขั้นสุดท้ายต้องจบเรื่อง');

        $returns = array_values(array_filter($saved['transitions'], fn ($t) => $t['action'] === 'return'));

        $this->assertSame('returned', $returns[0]['result'], 'ส่งกลับจากขั้นแรกคือคืนเรื่องให้ผู้ยื่น');
        $this->assertSame('งานสารบรรณตรวจสอบ', $returns[1]['to'], 'ส่งกลับจากขั้นหลังต้องย้อนไปขั้นก่อนหน้า');

        foreach ($returns as $return) {
            $this->assertTrue($return['requires_comment'], 'การตีกลับต้องมีเหตุผลเสมอ');
        }
    }

    /** ขั้นสุดท้ายถูกกำหนดจากลำดับ ไม่ใช่จากสิ่งที่หน้าจอส่งมา */
    public function test_the_last_step_is_always_the_final_one(): void
    {
        $this->actAs($this->admin());
        $draft = $this->postJson('/api/saraban/workflow/design/drafts')->assertCreated()->json();

        $saved = $this->putJson("/api/saraban/workflow/design/{$draft['id']}", [
            'steps' => [
                ['name' => 'ตรวจ', 'assignee_permission' => 'saraban.workflow.review'],
                ['name' => 'พิจารณา', 'assignee_permission' => 'saraban.workflow.approve'],
                ['name' => 'ลงนาม', 'assignee_permission' => 'saraban.signature.sign'],
            ],
        ])->assertOk()->json();

        $this->assertSame([false, false, true], array_map(
            fn ($step) => (bool) $step['is_final'],
            $saved['steps'],
        ));
    }

    // ── ข้อบังคับที่ต้องไม่ถูกข้าม ──────────────────────────────────────────

    public function test_an_active_version_cannot_be_edited(): void
    {
        $active = $this->definition()->activeVersion();

        $this->actAs($this->admin());

        $this->putJson("/api/saraban/workflow/design/{$active->id}", [
            'steps' => [['name' => 'ขั้นเดียว', 'assignee_permission' => 'saraban.signature.sign']],
        ])->assertStatus(422);

        $this->assertSame(3, $active->fresh()->steps()->count(), 'ขั้นตอนของเวอร์ชันที่ใช้งานต้องไม่เปลี่ยน');
    }

    public function test_a_published_version_cannot_be_discarded(): void
    {
        $active = $this->definition()->activeVersion();

        $this->actAs($this->admin());

        $this->deleteJson("/api/saraban/workflow/design/{$active->id}")->assertStatus(422);

        $this->assertNotNull($active->fresh());
    }

    /**
     * **ขั้นตอนอ้างสิทธิ์นอกสารบัญของสารบรรณไม่ได้**
     *
     * ถ้าปล่อยผ่าน จะได้ขั้นที่ไม่มีใครถือสิทธิ์นั้น เรื่องจะค้างเงียบ ๆ
     * และยังเป็นช่องให้สายงานของสารบรรณไปอ้างสิทธิ์ของโมดูลการลา
     */
    public function test_a_step_cannot_reference_a_permission_outside_the_saraban_namespace(): void
    {
        $this->actAs($this->admin());
        $draft = $this->postJson('/api/saraban/workflow/design/drafts')->assertCreated()->json();

        $this->putJson("/api/saraban/workflow/design/{$draft['id']}", [
            'steps' => [['name' => 'อนุมัติใบลา', 'assignee_permission' => 'leave.approve']],
        ])->assertStatus(422);
    }

    /**
     * **สายงานของโมดูลอื่นต้องแตะไม่ได้เลย**
     *
     * `workflow_versions` เป็นตารางกลางที่โมดูลการลาใช้ร่วมกัน — ถ้าไม่ตรวจ
     * ผู้ที่ถือ `saraban.workflow.manage` จะแก้สายอนุมัติใบลาได้ด้วยการเดา id
     */
    public function test_the_saraban_endpoints_refuse_versions_of_other_modules(): void
    {
        $other = WorkflowDefinition::create([
            'code' => 'leave.approval.test',
            'module' => 'leave',
            'name' => 'สายอนุมัติใบลา',
            'subject_type' => 'App\\Models\\LeaveRequest',
            'is_active' => true,
        ]);

        $version = WorkflowVersion::create([
            'workflow_definition_id' => $other->id,
            'version' => 1,
            'status' => WorkflowVersion::STATUS_DRAFT,
            'effective_from' => now()->toDateString(),
        ]);

        $this->actAs($this->admin());

        $this->getJson("/api/saraban/workflow/design/{$version->id}")->assertNotFound();
        $this->putJson("/api/saraban/workflow/design/{$version->id}", [
            'steps' => [['name' => 'ขั้น', 'assignee_permission' => 'saraban.signature.sign']],
        ])->assertNotFound();
        $this->deleteJson("/api/saraban/workflow/design/{$version->id}")->assertNotFound();
    }

    public function test_managing_the_workflow_requires_the_manage_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('saraban.workflow.simulate', 'web'));

        $this->actingAs($user->fresh())
            ->getJson('/api/saraban/workflow/design')
            ->assertForbidden();
    }

    // ── การเผยแพร่ ─────────────────────────────────────────────────────────

    /**
     * เผยแพร่แล้วเวอร์ชันเดิมถูกปลด แต่ **เรื่องที่กำลังเดินไม่เปลี่ยนสาย**
     *
     * ข้อนี้คือเหตุผลทั้งหมดที่ระบบใช้เวอร์ชันแทนการแก้ในที่เดิม
     */
    public function test_publishing_does_not_move_running_cases_to_the_new_version(): void
    {
        $active = $this->definition()->activeVersion();

        $unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-WF',
            'name' => 'ฝ่ายอำนวยการ',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );

        $document = Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'เรื่องที่เดินอยู่ก่อนเปลี่ยนสาย',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'status' => Document::STATUS_SUBMITTED,
            'owner_org_unit_id' => $unit->id,
            'created_by' => $this->admin()->id,
        ]);

        $running = WorkflowInstance::create([
            'uuid' => (string) Str::uuid(),
            'workflow_version_id' => $active->id,
            'subject_type' => $document->getMorphClass(),
            'subject_id' => $document->id,
            'status' => WorkflowInstance::STATUS_RUNNING,
            'current_step_id' => $active->steps()->first()->id,
            'is_shadow' => false,
        ]);

        $this->actAs($this->admin());

        $draft = $this->postJson('/api/saraban/workflow/design/drafts')->assertCreated()->json();

        $this->putJson("/api/saraban/workflow/design/{$draft['id']}", [
            'steps' => [
                ['name' => 'ตรวจ', 'assignee_permission' => 'saraban.workflow.review'],
                ['name' => 'ลงนาม', 'assignee_permission' => 'saraban.signature.sign'],
            ],
        ])->assertOk();

        $published = $this->postJson("/api/saraban/workflow/design/{$draft['id']}/publish")->assertOk()->json();

        $this->assertSame(WorkflowVersion::STATUS_ACTIVE, $published['status']);
        $this->assertSame($published['id'], $this->definition()->activeVersion()->id);
        $this->assertSame(WorkflowVersion::STATUS_RETIRED, $active->fresh()->status);

        $this->assertSame(
            $active->id,
            $running->fresh()->workflow_version_id,
            'เรื่องที่กำลังเดินต้องอยู่บนเวอร์ชันเดิมจนจบ',
        );
    }

    public function test_an_empty_draft_cannot_be_published(): void
    {
        $definition = $this->definition();

        $empty = WorkflowVersion::create([
            'workflow_definition_id' => $definition->id,
            'version' => 99,
            'status' => WorkflowVersion::STATUS_DRAFT,
            'effective_from' => now()->toDateString(),
        ]);

        $this->actAs($this->admin());

        $this->postJson("/api/saraban/workflow/design/{$empty->id}/publish")->assertStatus(422);
        $this->assertSame(WorkflowVersion::STATUS_DRAFT, $empty->fresh()->status);
    }

    /** การเผยแพร่ต้องถูกบันทึกลง audit กลาง — เป็นการเปลี่ยนกติกาของทั้งระบบ */
    public function test_publishing_is_written_to_the_central_audit_log(): void
    {
        $admin = $this->admin();
        $this->actAs($admin);

        $draft = $this->postJson('/api/saraban/workflow/design/drafts')->assertCreated()->json();
        $this->postJson("/api/saraban/workflow/design/{$draft['id']}/publish")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'saraban.workflow.published',
            'actor_id' => $admin->id,
        ]);
    }

    /** เผยแพร่เป็นการกระทำที่มีผลกับทุกเรื่องหลังจากนี้ จึงต้องยืนยันตัวตนซ้ำ */
    public function test_publishing_requires_a_recent_re_authentication(): void
    {
        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $admin = $this->admin();
        $this->actAs($admin);

        $draft = $this->postJson('/api/saraban/workflow/design/drafts')->assertCreated()->json();

        // จำลองเครื่องที่เปิดค้างไว้นานเกินช่วงที่ยอมรับ
        $this->actAs($admin, now()->subHours(2)->toDateTimeString());

        $this->postJson("/api/saraban/workflow/design/{$draft['id']}/publish")->assertStatus(423);
        $this->assertSame(
            WorkflowVersion::STATUS_DRAFT,
            WorkflowVersion::query()->find($draft['id'])->status,
        );
    }

    /** ขั้นตอนที่ลบออกจากฉบับร่างต้องหายไปจริง ไม่ค้างเป็นขั้นกำพร้า */
    public function test_removing_a_step_from_a_draft_removes_its_routes_too(): void
    {
        $this->actAs($this->admin());

        $draft = $this->postJson('/api/saraban/workflow/design/drafts')->assertCreated()->json();

        $this->putJson("/api/saraban/workflow/design/{$draft['id']}", [
            'steps' => [['name' => 'ลงนามอย่างเดียว', 'assignee_permission' => 'saraban.signature.sign']],
        ])->assertOk();

        $this->assertSame(1, WorkflowStep::query()->where('workflow_version_id', $draft['id'])->count());
        $this->assertSame(3, WorkflowTransition::query()->where('workflow_version_id', $draft['id'])->count());
    }
}
