<?php

namespace Tests\Feature\Saraban;

use App\Models\Department;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use Database\Seeders\SarabanPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures for the saraban feature tests: the real permission set from
 * SarabanPermissionSeeder plus one user per authorization tier.
 */
abstract class SarabanTestCase extends TestCase
{
    use RefreshDatabase;

    protected Department $department;

    protected DocumentType $type;

    protected User $registrar;

    protected User $approver;

    protected User $staff;

    protected User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SarabanPermissionSeeder::class);

        $this->department = Department::create([
            'name' => 'Test Department',
            'status' => 'active',
        ]);

        $this->type = DocumentType::create([
            'name' => 'หนังสือภายใน',
            'code' => 'int',
        ]);

        $this->registrar = $this->makeUser('admin_saraban');
        $this->approver = $this->makeUser('manager');
        $this->staff = $this->makeUser('staff');
        $this->outsider = $this->makeUser('staff');
    }

    protected function makeUser(string $role): User
    {
        $user = User::factory()->create(['department_id' => $this->department->id]);
        $user->assignRole($role);

        return $user;
    }

    protected function makeDocument(array $attributes = []): Document
    {
        $document = Document::create(array_merge([
            'title' => 'Test Document',
            'document_number' => 'TEST-'.Str::uuid(),
            'type_id' => $this->type->id,
            'department_id' => $this->department->id,
            'priority' => 'normal',
            'secrecy' => 'normal',
            'status' => 'draft',
            'created_by' => $this->staff->id,
        ], $attributes));

        // Workflow tests model an assigned approver as a receiver. This keeps
        // policy checks scoped to participants instead of granting approvers
        // organization-wide access to every document.
        $document->receivers()->syncWithoutDetaching([$this->approver->id]);

        return $document;
    }
}
