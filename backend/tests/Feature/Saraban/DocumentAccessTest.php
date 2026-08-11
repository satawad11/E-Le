<?php

namespace Tests\Feature\Saraban;

use App\Models\Department;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $outsider;
    protected Department $department;
    protected DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'saraban.document.view',
            'saraban.document.create',
            'saraban.document.edit',
            'saraban.document.delete',
            'saraban.document.view_classified',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $staff = Role::firstOrCreate(['name' => 'staff']);
        $staff->givePermissionTo([
            'saraban.document.view',
            'saraban.document.create',
            'saraban.document.edit',
            'saraban.document.delete',
        ]);

        $this->owner = User::factory()->create();
        $this->owner->assignRole('staff');

        $this->outsider = User::factory()->create();
        $this->outsider->assignRole('staff');

        $this->department = Department::create(['name' => 'Test Department', 'status' => 'active']);
        $this->type = DocumentType::create(['name' => 'หนังสือภายใน', 'code' => 'int']);
    }

    protected function makeDocument(array $overrides = []): Document
    {
        return Document::create(array_merge([
            'document_number' => 'int 0001/2569',
            'title' => 'เอกสารทดสอบ',
            'type_id' => $this->type->id,
            'priority' => 'normal',
            'secrecy' => 'normal',
            'status' => 'dispatched',
            'created_by' => $this->owner->id,
            'department_id' => $this->department->id,
        ], $overrides));
    }

    public function test_outsider_cannot_view_document_they_are_not_involved_in(): void
    {
        $document = $this->makeDocument();

        $this->actingAs($this->outsider)
            ->getJson("/api/saraban/documents/{$document->id}")
            ->assertStatus(403);
    }

    public function test_outsider_cannot_delete_document(): void
    {
        $document = $this->makeDocument();

        $this->actingAs($this->outsider)
            ->deleteJson("/api/saraban/documents/{$document->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('saraban_documents', [
            'id' => $document->id,
            'deleted_at' => null,
        ]);
    }

    public function test_receiver_without_permission_cannot_view_classified_document(): void
    {
        $document = $this->makeDocument(['secrecy' => 'secret']);
        $document->receivers()->sync([$this->outsider->id]);

        $this->actingAs($this->outsider)
            ->getJson("/api/saraban/documents/{$document->id}")
            ->assertStatus(403);
    }

    public function test_receiver_with_permission_can_view_classified_document(): void
    {
        $document = $this->makeDocument(['secrecy' => 'secret']);
        $document->receivers()->sync([$this->outsider->id]);
        $this->outsider->givePermissionTo('saraban.document.view_classified');

        $this->actingAs($this->outsider)
            ->getJson("/api/saraban/documents/{$document->id}")
            ->assertStatus(200);
    }

    public function test_classified_document_is_not_listed_for_unauthorized_user(): void
    {
        $this->makeDocument(['secrecy' => 'top_secret']);

        $response = $this->actingAs($this->outsider)->getJson('/api/saraban/documents');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('total'));
    }

    public function test_viewing_a_document_writes_an_audit_log(): void
    {
        $document = $this->makeDocument();

        $this->actingAs($this->owner)
            ->getJson("/api/saraban/documents/{$document->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $document->id,
            'user_id' => $this->owner->id,
            'action' => 'document.viewed',
        ]);
    }

    public function test_deleting_a_document_writes_an_audit_log(): void
    {
        $document = $this->makeDocument();

        $this->actingAs($this->owner)
            ->deleteJson("/api/saraban/documents/{$document->id}")
            ->assertStatus(204);

        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $document->id,
            'action' => 'document.deleted',
        ]);
    }
}
