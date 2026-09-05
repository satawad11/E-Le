<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentCase;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\RetentionSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Regression evidence for the organization scope review on 2026-09-05. */
class ReviewScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private OrgUnit $foreignUnit;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private_uploads');
        $unit = OrgUnit::create(['uuid' => (string) Str::uuid(), 'code' => 'REVIEW-A', 'name' => 'หน่วยทดสอบ ก', 'type' => 'department', 'effective_from' => '2020-01-01', 'is_active' => true]);
        $this->foreignUnit = OrgUnit::create(['uuid' => (string) Str::uuid(), 'code' => 'REVIEW-B', 'name' => 'หน่วยทดสอบ ข', 'type' => 'department', 'effective_from' => '2020-01-01', 'is_active' => true]);
        $this->actor = User::factory()->create(['org_unit_id' => $unit->id]);
        $this->owner = User::factory()->create(['org_unit_id' => $this->foreignUnit->id]);
        foreach (['saraban.document.view', 'saraban.document.create', 'saraban.config.manage', 'saraban.policy.manage'] as $ability) {
            $this->actor->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        $token = $this->actor->createToken('review');
        $token->accessToken->forceFill(['last_authenticated_at' => now()])->save();
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    public function test_cannot_close_a_case_owned_by_another_unit(): void
    {
        $case = DocumentCase::create(['code' => 'REVIEW-CASE', 'title' => 'เรื่องของหน่วยอื่น', 'owner_org_unit_id' => $this->foreignUnit->id, 'created_by' => $this->owner->id]);
        $response = $this->postJson('/api/saraban/cases/'.$case->id.'/close');
        $this->assertSame('open', $case->fresh()->status, 'Out-of-scope case was closed; HTTP '.$response->status());
        $response->assertForbidden();
    }

    public function test_cannot_dispose_a_document_that_the_actor_cannot_view(): void
    {
        $type = DocumentType::query()->firstOrCreate(['code' => 'internal'], ['name' => 'หนังสือภายใน', 'category' => 'internal']);
        $document = Document::create(['document_type_id' => $type->id, 'direction' => 'internal', 'subject' => 'เอกสารหน่วยอื่น', 'owner_org_unit_id' => $this->foreignUnit->id, 'created_by' => $this->owner->id]);
        $this->assertFalse($this->actor->can('view', $document));
        Storage::disk('private_uploads')->put('review/foreign.pdf', 'review-only fixture');
        DocumentVersion::create(['document_id' => $document->id, 'version_no' => 1, 'stage' => 'original', 'pdf_file_path' => 'review/foreign.pdf', 'file_hash' => hash('sha512', 'review-only fixture'), 'created_by' => $this->owner->id]);
        $schedule = RetentionSchedule::create(['document_id' => $document->id, 'retain_years' => 1, 'retain_until' => '2020-01-01', 'status' => 'approved', 'approved_by' => $this->owner->id, 'approved_at' => now()]);
        $response = $this->postJson('/api/saraban/retention/'.$schedule->id.'/dispose');
        $this->assertTrue(Storage::disk('private_uploads')->exists('review/foreign.pdf'), 'Out-of-scope file was deleted; HTTP '.$response->status());
        $response->assertForbidden();
    }
}
