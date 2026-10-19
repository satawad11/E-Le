<?php

namespace Tests\Legacy\Feature\Saraban;

use App\Models\Department;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup base permissions
        Permission::firstOrCreate(['name' => 'saraban.document.create']);
        $role = Role::firstOrCreate(['name' => 'staff']);
        $role->givePermissionTo('saraban.document.create');

        $this->user = User::factory()->create();
        $this->user->assignRole('staff');

        $this->department = Department::create([
            'name' => 'Test Department',
            'status' => 'active',
        ]);

        $this->type = DocumentType::create([
            'name' => 'หนังสือภายใน',
            'code' => 'int',
        ]);
    }

    public function test_user_can_create_document()
    {
        $response = $this->actingAs($this->user)->postJson('/api/saraban/documents', [
            'title' => 'Test Document',
            'type_id' => $this->type->id,
            'department_id' => $this->department->id,
            'priority' => 'normal',
            'secrecy' => 'normal',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('saraban_documents', [
            'title' => 'Test Document',
            'type_id' => $this->type->id,
        ]);
    }
}
