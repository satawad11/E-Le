<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-05 — วงจรชีวิตของหนังสือ และสิทธิ์รายฉบับ (SoT §6, §8)
 *
 * เน้นสามเรื่องที่พังแล้วเสียหายจริง:
 *   1. **default DENY** — ไม่มีสิทธิ์ = เข้าไม่ได้ ไม่ว่าจะเดา id ถูกหรือไม่
 *   2. **ต้นฉบับห้ามถูกเขียนทับ** — แนบไฟล์ใหม่ = เวอร์ชันใหม่เสมอ
 *   3. **เสนอแล้วแก้ไม่ได้** — เนื้อหาที่ผู้อื่นใช้ตัดสินใจต้องไม่เปลี่ยนใต้มือ
 */
class SarabanDocumentCoreTest extends TestCase
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
            'code' => 'UNIT-SARABAN',
            'name' => 'ฝ่ายบริหารงานทั่วไป',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->type = DocumentType::create([
            'code' => 'internal-test',
            'name' => 'หนังสือภายใน',
            'abbreviation' => 'ภย.',
            'category' => DocumentType::CATEGORY_INTERNAL,
        ]);
    }

    /** @param array<int,string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['org_unit_id' => $this->unit->id]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'ขอเชิญประชุมคณะกรรมการ',
            'summary' => 'ประชุมประจำเดือน',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
        ], $overrides);
    }

    private function pdf(string $contents = '%PDF-1.4 ต้นฉบับ'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('หนังสือ.pdf', $contents);
    }

    // ── สิทธิ์ ──────────────────────────────────────────────────────────────

    public function test_a_user_without_saraban_permissions_is_denied_everywhere(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson('/api/saraban/documents')->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/saraban/documents/options')->assertForbidden();
        $this->actingAs($outsider)->postJson('/api/saraban/documents', $this->payload())->assertForbidden();
    }

    public function test_creating_requires_the_create_permission_not_just_the_view_permission(): void
    {
        $viewer = $this->userWith(['saraban.document.view']);

        $this->actingAs($viewer)->getJson('/api/saraban/documents')->assertOk();
        $this->actingAs($viewer)->postJson('/api/saraban/documents', $this->payload())->assertForbidden();
    }

    /**
     * IDOR — เดา id ถูกก็ยังเปิดไม่ได้ถ้าอยู่นอกขอบเขต
     *
     * SoT §26 บังคับ object authorization ไม่ใช่แค่ตรวจว่าล็อกอินแล้ว
     */
    public function test_a_document_from_another_unit_cannot_be_opened_by_guessing_its_id(): void
    {
        $author = $this->userWith(['saraban.document.create', 'saraban.document.view']);
        $document = $this->actingAs($author)
            ->postJson('/api/saraban/documents', $this->payload())
            ->assertCreated()
            ->json();

        $otherUnit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-OTHER',
            'name' => 'ฝ่ายอื่น',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $stranger = User::factory()->create(['org_unit_id' => $otherUnit->id]);
        $stranger->givePermissionTo(Permission::findOrCreate('saraban.document.view', 'web'));

        $this->actingAs($stranger->fresh())
            ->getJson("/api/saraban/documents/{$document['id']}")
            ->assertForbidden();
    }

    public function test_the_list_is_limited_to_the_units_a_user_may_see(): void
    {
        $author = $this->userWith(['saraban.document.create', 'saraban.document.view']);
        $this->actingAs($author)->postJson('/api/saraban/documents', $this->payload())->assertCreated();

        $otherUnit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-FAR',
            'name' => 'ฝ่ายไกล',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $stranger = User::factory()->create(['org_unit_id' => $otherUnit->id]);
        $stranger->givePermissionTo(Permission::findOrCreate('saraban.document.view', 'web'));

        $visible = $this->actingAs($stranger->fresh())->getJson('/api/saraban/documents')->assertOk()->json('data');

        $this->assertSame([], $visible, 'ต้องไม่เห็นหนังสือของหน่วยงานอื่น');
    }

    // ── วงจรชีวิตและเวอร์ชัน ────────────────────────────────────────────────

    public function test_creating_with_a_file_stores_the_original_as_version_one(): void
    {
        $author = $this->userWith(['saraban.document.create', 'saraban.document.view']);

        $response = $this->actingAs($author)
            ->post('/api/saraban/documents', $this->payload(['file' => $this->pdf()]))
            ->assertCreated();

        $document = Document::findOrFail($response->json('id'));

        $this->assertSame(Document::STATUS_DRAFT, $document->status);
        $this->assertCount(1, $document->versions);
        $this->assertSame(1, $document->versions->first()->version_no);
        $this->assertSame('original', $document->versions->first()->stage);
        $this->assertSame($document->current_version_id, $document->versions->first()->id);

        Storage::disk('private_uploads')->assertExists($document->versions->first()->pdf_file_path);
    }

    /**
     * ข้อที่สำคัญที่สุดของเช็คพอยต์นี้ (SoT §8)
     *
     * การแนบไฟล์ใหม่ต้องไม่แตะไฟล์หรือแฮชของต้นฉบับเลย
     */
    public function test_attaching_a_new_file_creates_a_new_version_and_leaves_the_original_untouched(): void
    {
        $author = $this->userWith(['saraban.document.create', 'saraban.document.update', 'saraban.document.view']);

        $created = $this->actingAs($author)
            ->post('/api/saraban/documents', $this->payload(['file' => $this->pdf('%PDF-1.4 ต้นฉบับ')]))
            ->assertCreated()
            ->json();

        $original = Document::findOrFail($created['id'])->versions->first();

        $this->actingAs($author)
            ->post("/api/saraban/documents/{$created['id']}", $this->payload(['file' => $this->pdf('%PDF-1.4 ฉบับแก้ไข')]))
            ->assertOk();

        $document = Document::findOrFail($created['id'])->fresh(['versions']);

        $this->assertCount(2, $document->versions);
        $this->assertSame([1, 2], $document->versions->pluck('version_no')->all());
        $this->assertSame('revised', $document->versions->last()->stage);
        $this->assertSame($document->versions->last()->id, $document->current_version_id);

        $originalNow = $document->versions->first();
        $this->assertSame($original->file_hash, $originalNow->file_hash, 'แฮชของต้นฉบับต้องไม่เปลี่ยน');
        $this->assertSame($original->pdf_file_path, $originalNow->pdf_file_path, 'ไฟล์ต้นฉบับต้องไม่ถูกเขียนทับ');
        Storage::disk('private_uploads')->assertExists($original->pdf_file_path);
    }

    public function test_a_document_cannot_be_submitted_without_a_file(): void
    {
        $author = $this->userWith(['saraban.document.create', 'saraban.document.submit', 'saraban.document.view']);

        $created = $this->actingAs($author)
            ->postJson('/api/saraban/documents', $this->payload())
            ->assertCreated()
            ->json();

        $this->actingAs($author)
            ->postJson("/api/saraban/documents/{$created['id']}/submit")
            ->assertStatus(422);
    }

    public function test_a_submitted_document_can_no_longer_be_edited(): void
    {
        $author = $this->userWith([
            'saraban.document.create', 'saraban.document.update',
            'saraban.document.submit', 'saraban.document.view',
        ]);

        $created = $this->actingAs($author)
            ->post('/api/saraban/documents', $this->payload(['file' => $this->pdf()]))
            ->assertCreated()
            ->json();

        $this->actingAs($author)
            ->postJson("/api/saraban/documents/{$created['id']}/submit")
            ->assertOk()
            ->assertJsonPath('status', Document::STATUS_SUBMITTED);

        $this->actingAs($author)
            ->post("/api/saraban/documents/{$created['id']}", $this->payload(['subject' => 'แก้หลังเสนอ']))
            ->assertForbidden();

        $this->assertSame('ขอเชิญประชุมคณะกรรมการ', Document::findOrFail($created['id'])->subject);
    }

    public function test_submitting_is_recorded_in_the_central_audit_log(): void
    {
        $author = $this->userWith([
            'saraban.document.create', 'saraban.document.submit', 'saraban.document.view',
        ]);

        $created = $this->actingAs($author)
            ->post('/api/saraban/documents', $this->payload(['file' => $this->pdf()]))
            ->assertCreated()
            ->json();

        $this->actingAs($author)->postJson("/api/saraban/documents/{$created['id']}/submit")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'saraban.document.submitted',
            'resource_id' => (string) $created['id'],
            'actor_id' => $author->id,
        ]);
    }

    // ── ไฟล์ ────────────────────────────────────────────────────────────────

    /**
     * ไฟล์ของหนังสือต้องเปิดได้เฉพาะคนที่เปิดหนังสือฉบับนั้นได้ (SoT §26)
     */
    public function test_document_files_are_only_served_to_people_who_may_read_the_document(): void
    {
        $author = $this->userWith(['saraban.document.create', 'saraban.document.view']);

        $created = $this->actingAs($author)
            ->post('/api/saraban/documents', $this->payload(['file' => $this->pdf()]))
            ->assertCreated()
            ->json();

        $path = Document::findOrFail($created['id'])->currentVersion->pdf_file_path;

        $this->actingAs($author)->get('/api/files/'.$path)->assertOk();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get('/api/files/'.$path)->assertForbidden();
    }

    // ── การปิดโมดูล ─────────────────────────────────────────────────────────

    public function test_turning_the_module_off_closes_every_saraban_route(): void
    {
        $author = $this->userWith(['saraban.document.view']);

        config(['modules.saraban.enabled' => false]);

        $this->actingAs($author)->getJson('/api/saraban/documents')->assertNotFound();
    }
}
