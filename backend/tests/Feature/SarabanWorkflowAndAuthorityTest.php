<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;
use App\Services\Saraban\AuthorityResolver;
use App\Services\Workflow\Assignee\AssigneeResolverRegistry;
use App\Services\Workflow\Assignee\SarabanAssigneeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-08 — การเดินหนังสือและอำนาจ (SoT §11, §12, §13)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **ใช้ workflow engine ของระบบเดิม** ไม่มี state machine ของสารบรรณเอง
 *   2. **อำนาจถูกหรี่ตามหน่วยงาน** — ถือสิทธิ์อย่างเดียวไม่พอ ต้องมีอำนาจกับ
 *      หน่วยงานเจ้าของหนังสือฉบับนั้นด้วย
 *   3. **เลขหนังสือออกตอนลงนามเสร็จ ไม่ใช่ตอนสร้าง**
 *   4. **Authority Matrix เป็น projection** — ไม่มีทะเบียนอำนาจซ้ำ
 */
class SarabanWorkflowAndAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $unitA;

    private OrgUnit $unitB;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');

        $this->unitA = $this->orgUnit('UNIT-A', 'ฝ่ายบริหารงานทั่วไป');
        $this->unitB = $this->orgUnit('UNIT-B', 'ฝ่ายวิชาการ');

        $this->type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );
    }

    private function orgUnit(string $code, string $name): OrgUnit
    {
        return OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'name' => $name,
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);
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

    private function submittedDocument(User $author, ?OrgUnit $unit = null): Document
    {
        $created = $this->actingAs($author)
            ->post('/api/saraban/documents', [
                'document_type_id' => $this->type->id,
                'direction' => Document::DIRECTION_INTERNAL,
                'subject' => 'ขออนุมัติจัดกิจกรรม',
                'confidentiality' => 'normal',
                'urgency' => 'normal',
                'owner_org_unit_id' => ($unit ?? $this->unitA)->id,
                'file' => UploadedFile::fake()->createWithContent('เรื่อง.pdf', '%PDF-1.4 x'),
            ])
            ->assertCreated()
            ->json();

        $this->actingAs($author)->postJson("/api/saraban/documents/{$created['id']}/submit")->assertOk();

        return Document::findOrFail($created['id']);
    }

    // ── ใช้ engine ของระบบเดิม ──────────────────────────────────────────────

    public function test_the_saraban_resolver_is_registered_with_the_core_engine(): void
    {
        $reflection = new \ReflectionClass(AssigneeResolverRegistry::class);
        $resolvers = $reflection->getConstant('RESOLVERS');

        $this->assertContains(SarabanAssigneeResolver::class, $resolvers);
    }

    public function test_submitting_starts_an_instance_on_the_core_workflow_engine(): void
    {
        $author = $this->userIn($this->unitA, [
            'saraban.document.view', 'saraban.document.create', 'saraban.document.submit',
        ]);

        $document = $this->submittedDocument($author);

        $instance = WorkflowInstance::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->id)
            ->first();

        $this->assertNotNull($instance, 'ต้องเดินบน workflow engine กลาง ไม่ใช่ state machine ของตัวเอง');
        $this->assertSame(WorkflowInstance::STATUS_RUNNING, $instance->status);
        $this->assertFalse((bool) $instance->is_shadow);
        $this->assertSame('saraban.document', $instance->version->definition->code);
    }

    public function test_the_workflow_endpoint_reports_the_current_step(): void
    {
        $author = $this->userIn($this->unitA, [
            'saraban.document.view', 'saraban.document.create', 'saraban.document.submit',
        ]);
        $document = $this->submittedDocument($author);

        $state = $this->actingAs($author)
            ->getJson("/api/saraban/documents/{$document->id}/workflow")
            ->assertOk()
            ->json();

        $this->assertTrue($state['started']);
        $this->assertSame('saraban_review', $state['current_step']['code']);
    }

    // ── อำนาจถูกหรี่ตามหน่วยงาน (SoT §13) ───────────────────────────────────

    /**
     * ข้อที่สำคัญที่สุดของเช็คพอยต์นี้
     *
     * ตรรกะกลางของ engine คืนทุกคนที่ถือสิทธิ์ ซึ่งจะทำให้เจ้าหน้าที่สารบรรณ
     * ของฝ่ายหนึ่ง ตรวจหนังสือของอีกฝ่ายได้ · ตัวแปลของสารบรรณต้องหรี่ให้เหลือ
     * เฉพาะผู้มีอำนาจกับหน่วยงานเจ้าของหนังสือ
     */
    public function test_an_officer_from_another_unit_cannot_act_on_this_units_document(): void
    {
        $author = $this->userIn($this->unitA, [
            'saraban.document.view', 'saraban.document.create', 'saraban.document.submit',
        ]);
        $document = $this->submittedDocument($author);

        // ถือสิทธิ์ตรวจเหมือนกัน แต่สังกัดคนละหน่วยงาน
        $outsideOfficer = $this->userIn($this->unitB, ['saraban.document.view', 'saraban.workflow.review']);

        $this->actingAs($outsideOfficer)
            ->postJson("/api/saraban/documents/{$document->id}/workflow", ['action' => 'approve'])
            ->assertForbidden();

        $this->assertSame(
            WorkflowInstance::STATUS_RUNNING,
            WorkflowInstance::query()->where('subject_id', $document->id)->value('status'),
        );
    }

    public function test_an_officer_in_the_owning_unit_can_move_the_document_forward(): void
    {
        $author = $this->userIn($this->unitA, [
            'saraban.document.view', 'saraban.document.create', 'saraban.document.submit',
        ]);
        $document = $this->submittedDocument($author);

        $officer = $this->userIn($this->unitA, ['saraban.document.view', 'saraban.workflow.review']);

        $state = $this->actingAs($officer)
            ->postJson("/api/saraban/documents/{$document->id}/workflow", ['action' => 'approve'])
            ->assertOk()
            ->json();

        $this->assertSame('signature', $state['current_step']['code'], 'ต้องเดินไปขั้นลงนาม');
    }

    public function test_authority_is_a_projection_of_core_data_not_a_table_of_its_own(): void
    {
        $officer = $this->userIn($this->unitA, ['saraban.workflow.review']);

        $holders = app(AuthorityResolver::class)->holdersFor('saraban.workflow.review', $this->unitA->id);
        $this->assertContains($officer->id, $holders);

        $outside = app(AuthorityResolver::class)->holdersFor('saraban.workflow.review', $this->unitB->id);
        $this->assertNotContains($officer->id, $outside);

        // ไม่มีตารางอำนาจของสารบรรณเอง — ทุกคำตอบคำนวณจากข้อมูลของระบบกลาง
        $tables = collect(DB::select('SELECT name FROM sqlite_master WHERE type = "table"'))
            ->pluck('name')
            ->filter(fn (string $name) => str_contains($name, 'authorit'));

        $this->assertTrue($tables->isEmpty(), 'ห้ามมีทะเบียนอำนาจของสารบรรณ (SoT §13)');
    }

    /**
     * หัวหน้าหน่วยงานมีอำนาจกับหน่วยงานที่ตนดูแล แม้ไม่ได้สังกัดหน่วยงานนั้น
     *
     * `org_unit_managers` เป็นแหล่งความจริงของ "หัวหน้า/ผู้รักษาการ" ตามสัญญา INT-01 §1.4
     */
    public function test_a_unit_manager_has_authority_over_the_unit_they_manage(): void
    {
        $manager = $this->userIn($this->unitB, ['saraban.workflow.review']);

        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(),
            'org_unit_id' => $this->unitA->id,
            'user_id' => $manager->id,
            'assignment_type' => 'MANAGER',
            'scope' => 'UNIT',
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(
            app(AuthorityResolver::class)->hasAuthorityOver($manager, 'saraban.workflow.review', $this->unitA->id),
        );
    }

    // ── RISK-20: ทำเรื่องแทนหน่วยงานอื่นไม่ได้ ──────────────────────────────

    public function test_a_user_cannot_file_a_document_on_behalf_of_a_unit_they_have_no_authority_over(): void
    {
        $author = $this->userIn($this->unitA, ['saraban.document.view', 'saraban.document.create']);

        $this->actingAs($author)
            ->postJson('/api/saraban/documents', [
                'document_type_id' => $this->type->id,
                'direction' => Document::DIRECTION_INTERNAL,
                'subject' => 'ยื่นแทนหน่วยงานอื่น',
                'confidentiality' => 'normal',
                'urgency' => 'normal',
                'owner_org_unit_id' => $this->unitB->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_org_unit_id');
    }

    public function test_the_form_only_offers_units_the_user_may_act_for(): void
    {
        $author = $this->userIn($this->unitA, ['saraban.document.view', 'saraban.document.create']);

        $units = $this->actingAs($author)
            ->getJson('/api/saraban/documents/options')
            ->assertOk()
            ->json('org_units');

        $ids = array_column($units, 'id');

        $this->assertContains($this->unitA->id, $ids);
        $this->assertNotContains($this->unitB->id, $ids);
    }

    // ── เลขหนังสือออกตอนลงนาม (SoT §9 + §11) ────────────────────────────────

    public function test_the_document_number_is_issued_only_when_signing_completes(): void
    {
        NumberSeries::create([
            'code' => 'internal-main',
            'name' => 'หนังสือภายใน',
            'category' => DocumentType::CATEGORY_INTERNAL,
            'prefix' => 'ศธ 04001',
        ]);

        $author = $this->userIn($this->unitA, [
            'saraban.document.view', 'saraban.document.create', 'saraban.document.submit',
        ]);
        $document = $this->submittedDocument($author);

        // ยังไม่ลงนาม — ต้องยังไม่มีเลข
        $this->assertNull($document->fresh()->document_number_id);

        $officer = $this->userIn($this->unitA, ['saraban.document.view', 'saraban.workflow.review']);
        $this->actingAs($officer)
            ->postJson("/api/saraban/documents/{$document->id}/workflow", ['action' => 'approve'])
            ->assertOk();

        $this->assertNull($document->fresh()->document_number_id, 'ผ่านการตรวจแล้วแต่ยังไม่ลงนาม ต้องยังไม่มีเลข');

        $signer = $this->userIn($this->unitA, ['saraban.document.view', 'saraban.signature.sign']);
        $this->actingAs($signer)
            ->postJson("/api/saraban/documents/{$document->id}/workflow", ['action' => 'approve'])
            ->assertOk();

        $signed = $document->fresh();

        $this->assertSame(Document::STATUS_SIGNED, $signed->status);
        $this->assertNotNull($signed->document_number_id, 'ลงนามแล้วต้องได้เลขหนังสือ');
        $this->assertNotNull($signed->issued_at);

        $number = DocumentNumber::findOrFail($signed->document_number_id);
        $this->assertSame(DocumentNumber::STATUS_ISSUED, $number->status);
        $this->assertSame($signed->id, $number->document_id);
    }

    /**
     * ไม่มีชุดเลขที่ตั้งค่าไว้ = ล้มพร้อมบอกเหตุ ไม่ใช่ลงนามผ่านไปโดยไม่มีเลข
     *
     * หนังสือที่ลงนามแล้วแต่ไม่มีเลขที่ ใช้เป็นหนังสือราชการไม่ได้ และจะกลายเป็น
     * งานค้างที่ไม่มีใครรู้ว่าค้าง
     */
    public function test_signing_fails_loudly_when_no_number_series_is_configured(): void
    {
        $author = $this->userIn($this->unitA, [
            'saraban.document.view', 'saraban.document.create', 'saraban.document.submit',
        ]);
        $document = $this->submittedDocument($author);

        $officer = $this->userIn($this->unitA, ['saraban.document.view', 'saraban.workflow.review']);
        $this->actingAs($officer)->postJson("/api/saraban/documents/{$document->id}/workflow", ['action' => 'approve'])->assertOk();

        $signer = $this->userIn($this->unitA, ['saraban.document.view', 'saraban.signature.sign']);

        $this->actingAs($signer)
            ->postJson("/api/saraban/documents/{$document->id}/workflow", ['action' => 'approve'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ชุดเลขหนังสือ'));

        $this->assertNotSame(Document::STATUS_SIGNED, $document->fresh()->status, 'ต้องไม่ถือว่าลงนามสำเร็จ');
    }

    // ── ส่งกลับแก้ไข ────────────────────────────────────────────────────────

    public function test_returning_a_document_puts_it_back_into_draft_so_it_can_be_fixed(): void
    {
        $author = $this->userIn($this->unitA, [
            'saraban.document.view', 'saraban.document.create', 'saraban.document.submit', 'saraban.document.update',
        ]);
        $document = $this->submittedDocument($author);

        $officer = $this->userIn($this->unitA, ['saraban.document.view', 'saraban.workflow.review']);

        $this->actingAs($officer)
            ->postJson("/api/saraban/documents/{$document->id}/workflow", [
                'action' => 'return',
                'comment' => 'ขอให้แก้ไขเรื่องให้ชัดเจน',
            ])
            ->assertOk();

        $this->assertSame(Document::STATUS_DRAFT, $document->fresh()->status, 'ส่งกลับแล้วต้องแก้ไขได้อีกครั้ง');
    }

    public function test_the_workflow_history_is_visible_on_the_document(): void
    {
        $author = $this->userIn($this->unitA, [
            'saraban.document.view', 'saraban.document.create', 'saraban.document.submit',
        ]);
        $document = $this->submittedDocument($author);

        $officer = $this->userIn($this->unitA, ['saraban.document.view', 'saraban.workflow.review']);
        $this->actingAs($officer)->postJson("/api/saraban/documents/{$document->id}/workflow", ['action' => 'approve'])->assertOk();

        $history = $this->actingAs($author)
            ->getJson("/api/saraban/documents/{$document->id}/workflow")
            ->assertOk()
            ->json('history');

        $this->assertNotEmpty($history);
        $this->assertContains('approve', array_column($history, 'action'));
    }
}
