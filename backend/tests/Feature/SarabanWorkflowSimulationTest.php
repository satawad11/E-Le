<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowVersion;
use App\Services\Saraban\DocumentWorkflowService;
use App\Services\Saraban\WorkflowSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-09 — การกำหนดเวอร์ชันสายงาน และการจำลองเส้นทาง (SoT §12)
 *
 * ข้อบังคับของ SoT §12 ที่ชุดนี้ตรึงไว้:
 *
 *   1. **การจำลองต้องเป็น READ ONLY** — ห้ามสร้าง instance, ห้ามออกเลข,
 *      ห้ามส่งแจ้งเตือน, ห้ามแก้สถานะใด ๆ
 *   2. **ต้องใช้ resolver ตัวเดียวกับของจริง** — ผลของการจำลองกับผลจริงต้องตรงกัน
 *   3. **บอกเหตุเมื่อหาผู้มีอำนาจไม่ได้**
 *   4. **เรื่องที่เดินอยู่ต้องคง version เดิม** แม้เผยแพร่เวอร์ชันใหม่
 */
class SarabanWorkflowSimulationTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $unit;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');

        $this->unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-SIM',
            'name' => 'ฝ่ายจำลอง',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );
    }

    /** @param array<int,string> $permissions */
    private function userIn(OrgUnit $unit, array $permissions): User
    {
        $user = User::factory()->create(['org_unit_id' => $unit->id]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    private function simulator(): WorkflowSimulationService
    {
        return app(WorkflowSimulationService::class);
    }

    // ── READ ONLY (ข้อบังคับหลักของ SoT §12) ────────────────────────────────

    /**
     * ข้อที่สำคัญที่สุดของเช็คพอยต์นี้
     *
     * ถ้าการจำลองทิ้งร่องรอยไว้ในระบบ มันจะกลายเป็นการ "ลองของจริง" ซึ่งอันตราย
     * กว่าไม่มีการจำลองเลย — เลขที่ถูกจองทิ้งไว้จากการทดลองจะทำให้ทะเบียนเพี้ยน
     */
    public function test_simulating_leaves_no_trace_in_the_system(): void
    {
        $this->userIn($this->unit, ['saraban.workflow.review']);
        $this->userIn($this->unit, ['saraban.signature.sign']);

        $before = [
            'instances' => WorkflowInstance::query()->count(),
            'tasks' => DB::table('workflow_tasks')->count(),
            'actions' => DB::table('workflow_actions')->count(),
            'numbers' => DB::table('saraban_document_numbers')->count(),
            'sequences' => DB::table('saraban_number_sequences')->count(),
            'documents' => Document::query()->count(),
            'notifications' => DB::table('notification_events')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        // จำลองหลายครั้งเพื่อให้แน่ใจว่าไม่สะสมอะไรไว้
        for ($i = 0; $i < 3; $i++) {
            $this->simulator()->simulate(['org_unit_id' => $this->unit->id]);
        }

        foreach ($before as $table => $count) {
            $actual = match ($table) {
                'instances' => WorkflowInstance::query()->count(),
                'documents' => Document::query()->count(),
                default => DB::table(match ($table) {
                    'tasks' => 'workflow_tasks',
                    'actions' => 'workflow_actions',
                    'numbers' => 'saraban_document_numbers',
                    'sequences' => 'saraban_number_sequences',
                    'notifications' => 'notification_events',
                    'audit' => 'audit_logs',
                })->count(),
            };

            $this->assertSame($count, $actual, "การจำลองต้องไม่สร้างข้อมูลใน {$table}");
        }
    }

    // ── ใช้ resolver ตัวเดียวกับของจริง ────────────────────────────────────

    /**
     * ผลของการจำลองต้องตรงกับผู้มีอำนาจจริง
     *
     * ถ้าจำลองด้วยตรรกะคนละชุด ผลที่ได้จะเป็นคำตอบของระบบที่ไม่มีอยู่จริง
     */
    public function test_the_simulation_reports_the_same_people_who_may_really_act(): void
    {
        $reviewer = $this->userIn($this->unit, ['saraban.workflow.review']);
        $signer = $this->userIn($this->unit, ['saraban.signature.sign']);

        $result = $this->simulator()->simulate(['org_unit_id' => $this->unit->id]);

        $this->assertSame(WorkflowSimulationService::STATUS_VALID, $result['status']);
        $this->assertCount(2, $result['route']);

        $reviewStep = $result['route'][0];
        $signStep = $result['route'][1];

        $this->assertSame('saraban_review', $reviewStep['step_code']);
        $this->assertContains($reviewer->id, array_column($reviewStep['candidates'], 'id'));
        $this->assertNotContains($signer->id, array_column($reviewStep['candidates'], 'id'));

        $this->assertSame('signature', $signStep['step_code']);
        $this->assertTrue($signStep['is_final']);
        $this->assertContains($signer->id, array_column($signStep['candidates'], 'id'));
    }

    public function test_a_person_from_another_unit_is_not_offered_as_a_candidate(): void
    {
        $otherUnit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-OTHER-SIM',
            'name' => 'ฝ่ายอื่น',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $outsider = $this->userIn($otherUnit, ['saraban.workflow.review']);
        $this->userIn($this->unit, ['saraban.signature.sign']);

        $result = $this->simulator()->simulate(['org_unit_id' => $this->unit->id]);

        $this->assertNotContains($outsider->id, array_column($result['route'][0]['candidates'], 'id'));
    }

    // ── บอกเหตุเมื่อหาผู้มีอำนาจไม่ได้ ─────────────────────────────────────

    public function test_it_says_which_step_has_nobody_with_authority(): void
    {
        // มีแต่ผู้ตรวจ ไม่มีผู้ลงนาม
        $this->userIn($this->unit, ['saraban.workflow.review']);

        $result = $this->simulator()->simulate(['org_unit_id' => $this->unit->id]);

        $this->assertSame(WorkflowSimulationService::STATUS_UNRESOLVED_AUTHORITY, $result['status']);
        $this->assertNotEmpty($result['warnings']);

        $signStep = collect($result['route'])->firstWhere('step_code', 'signature');

        $this->assertSame([], $signStep['candidates']);
        $this->assertStringContainsString('saraban.signature.sign', $signStep['unresolved_reason']);
    }

    public function test_an_unknown_org_unit_is_reported_as_invalid_not_as_an_error(): void
    {
        $result = $this->simulator()->simulate(['org_unit_id' => 999999]);

        $this->assertSame(WorkflowSimulationService::STATUS_INVALID, $result['status']);
        $this->assertSame([], $result['route']);
    }

    // ── การกำหนดเวอร์ชัน (SoT §12) ─────────────────────────────────────────

    /**
     * เรื่องที่เดินอยู่ต้องคง version เดิม แม้เผยแพร่เวอร์ชันใหม่
     *
     * ระบบเดิมรองรับข้อนี้อยู่แล้ว (`workflow_instances.workflow_version_id`)
     * เทสต์นี้ยืนยันว่าสายงานของสารบรรณได้ประโยชน์นั้นจริง ไม่ใช่แค่ในทฤษฎี
     */
    public function test_a_document_already_in_progress_keeps_its_pinned_version(): void
    {
        $author = $this->userIn($this->unit, [
            'saraban.document.view', 'saraban.document.create', 'saraban.document.submit',
        ]);
        $this->userIn($this->unit, ['saraban.workflow.review']);

        $created = $this->actingAs($author)
            ->post('/api/saraban/documents', [
                'document_type_id' => $this->type->id,
                'direction' => Document::DIRECTION_INTERNAL,
                'subject' => 'เรื่องที่เดินอยู่ก่อนเปลี่ยนสายงาน',
                'confidentiality' => 'normal',
                'urgency' => 'normal',
                'owner_org_unit_id' => $this->unit->id,
                'file' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 x'),
            ])
            ->assertCreated()
            ->json();

        $this->actingAs($author)->postJson("/api/saraban/documents/{$created['id']}/submit")->assertOk();

        $instance = WorkflowInstance::query()->where('subject_id', $created['id'])->firstOrFail();
        $pinnedVersionId = $instance->workflow_version_id;

        // เผยแพร่เวอร์ชัน 2 ที่มีขั้นเดียว
        $definition = WorkflowDefinition::query()->where('code', DocumentWorkflowService::DEFINITION_CODE)->firstOrFail();
        $definition->versions()->where('status', WorkflowVersion::STATUS_ACTIVE)
            ->update(['status' => WorkflowVersion::STATUS_RETIRED]);

        $v2 = WorkflowVersion::create([
            'workflow_definition_id' => $definition->id,
            'version' => 2,
            'status' => WorkflowVersion::STATUS_ACTIVE,
            'effective_from' => now()->toDateString(),
            'published_at' => now(),
        ]);

        WorkflowStep::create([
            'workflow_version_id' => $v2->id,
            'code' => 'sign_only',
            'name' => 'ลงนามอย่างเดียว',
            'step_order' => 1,
            'type' => WorkflowStep::TYPE_APPROVAL,
            'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
            'assignee_permission' => 'saraban.signature.sign',
            'is_final' => true,
        ]);

        $instance->refresh();

        $this->assertSame($pinnedVersionId, $instance->workflow_version_id, 'เรื่องที่เดินอยู่ต้องไม่ถูกย้ายไปเวอร์ชันใหม่');
        $this->assertNotSame($v2->id, $instance->workflow_version_id);

        // เรื่องเดิมยังเดินตามผังของเวอร์ชันที่ pin ไว้
        $state = $this->actingAs($author)
            ->getJson("/api/saraban/documents/{$created['id']}/workflow")
            ->assertOk()
            ->json();

        $this->assertSame('saraban_review', $state['current_step']['code']);
    }

    public function test_a_draft_version_can_be_simulated_before_it_is_published(): void
    {
        $this->userIn($this->unit, ['saraban.signature.sign']);

        $definition = WorkflowDefinition::query()->where('code', DocumentWorkflowService::DEFINITION_CODE)->firstOrFail();

        $draft = WorkflowVersion::create([
            'workflow_definition_id' => $definition->id,
            'version' => 9,
            'status' => WorkflowVersion::STATUS_DRAFT,
            'effective_from' => now()->addYear()->toDateString(),
        ]);

        WorkflowStep::create([
            'workflow_version_id' => $draft->id,
            'code' => 'draft_sign',
            'name' => 'ลงนาม (ร่าง)',
            'step_order' => 1,
            'type' => WorkflowStep::TYPE_APPROVAL,
            'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
            'assignee_permission' => 'saraban.signature.sign',
            'is_final' => true,
        ]);

        $result = $this->simulator()->simulate([
            'org_unit_id' => $this->unit->id,
            'workflow_version_id' => $draft->id,
        ]);

        $this->assertSame('draft', $result['workflow']['version_status']);
        $this->assertCount(1, $result['route']);
        $this->assertSame('draft_sign', $result['route'][0]['step_code']);

        // ร่างยังไม่ถูกเผยแพร่ — เรื่องใหม่ต้องยังเดินบนเวอร์ชันที่มีผลบังคับใช้
        $this->assertSame(1, $definition->activeVersion()->version);
    }

    // ── สิทธิ์ ──────────────────────────────────────────────────────────────

    public function test_simulation_requires_its_own_permission(): void
    {
        $withoutPermission = $this->userIn($this->unit, ['saraban.document.view']);

        $this->actingAs($withoutPermission)
            ->postJson('/api/saraban/workflow/simulate', ['org_unit_id' => $this->unit->id])
            ->assertForbidden();

        $planner = $this->userIn($this->unit, ['saraban.document.view', 'saraban.workflow.simulate']);

        $this->actingAs($planner)
            ->postJson('/api/saraban/workflow/simulate', ['org_unit_id' => $this->unit->id])
            ->assertOk()
            ->assertJsonStructure(['status', 'as_of', 'workflow', 'route', 'warnings']);
    }

    public function test_the_version_list_is_available_to_planners(): void
    {
        $planner = $this->userIn($this->unit, ['saraban.workflow.simulate']);

        $versions = $this->actingAs($planner)
            ->getJson('/api/saraban/workflow/versions')
            ->assertOk()
            ->json('versions');

        $this->assertNotEmpty($versions);
        $this->assertSame('active', $versions[0]['status']);
    }
}
