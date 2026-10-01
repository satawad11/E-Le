<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document\DocumentBindingRule;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\User;
use App\Services\DocumentEngine\TemplateResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DC-03 — ประวัติว่า "วันนั้นใช้แบบฟอร์มใด" ต้องย้อนดูได้เสมอ
 *
 * การแก้หรือลบกติกาที่เคยมีผล และการปลุกเวอร์ชันที่ปลดแล้ว เคยเขียนทับช่วงวันที่เดิม
 */
class DocumentTemplateHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 09:00:00');

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    }

    private function template(string $code): DocumentTemplate
    {
        return DocumentTemplate::create([
            'code' => $code, 'name' => 'แบบ '.$code, 'module' => 'leave', 'document_type' => 'FORM',
            'paper_size' => 'A4', 'orientation' => 'portrait', 'content_html' => '<p>x</p>', 'is_active' => true,
        ]);
    }

    /** กติกาที่สร้างไว้ตั้งแต่ต้นเดือนและมีผลมาแล้ว */
    private function establishedRule(DocumentTemplate $template): DocumentBindingRule
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        $rule = DocumentBindingRule::create([
            'module' => 'LEAVE', 'entity_type' => 'LEAVE_TYPE', 'entity_id' => 7,
            'document_template_id' => $template->id, 'priority' => 100,
            'effective_from' => '2026-03-01', 'is_active' => true,
        ]);
        Carbon::setTestNow('2026-03-10 09:00:00');

        return $rule;
    }

    public function test_editing_an_established_rule_closes_it_and_issues_a_new_one(): void
    {
        $old = $this->template('form_old');
        $new = $this->template('form_new');
        $rule = $this->establishedRule($old);

        $this->actingAs($this->admin)->postJson('/api/admin/document-engine/bindings', [
            'id' => $rule->id, 'module' => 'LEAVE', 'entity_type' => 'LEAVE_TYPE', 'entity_id' => 7,
            'document_template_id' => $new->id, 'effective_from' => '2026-03-01',
        ])->assertOk();

        $this->assertSame('2026-03-09', $rule->fresh()->effective_to->toDateString());
        $this->assertSame($old->id, $rule->fresh()->document_template_id, 'แถวเดิมต้องไม่ถูกแก้ทับ');
        $this->assertSame(2, DocumentBindingRule::count());

        $resolver = app(TemplateResolver::class);
        $this->assertSame($old->id, $resolver->resolveTemplate('LEAVE', 'LEAVE_TYPE', 7, '2026-03-05')?->id);
        $this->assertSame($new->id, $resolver->resolveTemplate('LEAVE', 'LEAVE_TYPE', 7, '2026-03-10')?->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_binding_superseded', 'resource_id' => (string) $rule->id]);
    }

    public function test_a_rule_created_today_is_still_edited_in_place(): void
    {
        $old = $this->template('form_a');
        $new = $this->template('form_b');
        $rule = DocumentBindingRule::create([
            'module' => 'LEAVE', 'entity_type' => 'LEAVE_TYPE', 'entity_id' => 7,
            'document_template_id' => $old->id, 'effective_from' => '2026-03-10', 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->postJson('/api/admin/document-engine/bindings', [
            'id' => $rule->id, 'module' => 'LEAVE', 'entity_type' => 'LEAVE_TYPE', 'entity_id' => 7,
            'document_template_id' => $new->id,
        ])->assertOk();

        $this->assertSame(1, DocumentBindingRule::count());
        $this->assertSame($new->id, $rule->fresh()->document_template_id);
    }

    public function test_deleting_an_established_rule_ends_it_instead(): void
    {
        $template = $this->template('form_end');
        $rule = $this->establishedRule($template);

        $this->actingAs($this->admin)->deleteJson("/api/admin/document-engine/bindings/{$rule->id}")->assertOk();

        $this->assertNotNull($rule->fresh(), 'กติกาที่เคยมีผลต้องไม่ถูกลบจริง');
        $this->assertSame('2026-03-09', $rule->fresh()->effective_to->toDateString());
        $this->assertSame($template->id, app(TemplateResolver::class)->resolveTemplate('LEAVE', 'LEAVE_TYPE', 7, '2026-03-05')?->id);
        $this->assertNull(app(TemplateResolver::class)->resolveTemplate('LEAVE', 'LEAVE_TYPE', 7, '2026-03-10'));
    }

    public function test_deleting_a_rule_that_never_applied_removes_it_with_its_values_audited(): void
    {
        $rule = DocumentBindingRule::create([
            'module' => 'LEAVE', 'entity_type' => 'LEAVE_TYPE', 'entity_id' => 7,
            'document_template_id' => $this->template('form_future')->id, 'effective_from' => '2026-04-01', 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/admin/document-engine/bindings/{$rule->id}")->assertOk();

        $this->assertNull($rule->fresh());
        $audit = AuditLog::where('action', 'document_binding_deleted')->firstOrFail();
        $this->assertNotEmpty($audit->before);
    }

    public function test_a_retired_version_cannot_be_republished_but_can_be_copied_forward(): void
    {
        $template = $this->template('form_versions');
        $first = $template->versions()->firstOrFail();

        $second = $this->actingAs($this->admin)
            ->postJson("/api/admin/document-engine/templates/{$template->id}/versions")
            ->assertCreated()->json('version');
        $this->actingAs($this->admin)->postJson("/api/admin/document-engine/versions/{$second['id']}/publish")->assertOk();
        $this->assertSame(DocumentTemplateVersion::STATUS_RETIRED, $first->fresh()->status);

        $this->actingAs($this->admin)->postJson("/api/admin/document-engine/versions/{$first->id}/publish")
            ->assertUnprocessable();
        $this->assertSame(DocumentTemplateVersion::STATUS_RETIRED, $first->fresh()->status);

        $copy = $this->actingAs($this->admin)->postJson("/api/admin/document-engine/templates/{$template->id}/versions", [
            'from_version_id' => $first->id,
        ])->assertCreated()->json('version');

        $this->assertSame(3, $copy['version']);
        $this->assertSame($first->content_html, DocumentTemplateVersion::findOrFail($copy['id'])->content_html);
        $this->actingAs($this->admin)->postJson("/api/admin/document-engine/versions/{$copy['id']}/publish")->assertOk();
    }
}
