<?php

namespace Tests\Legacy\Feature\Saraban;

use App\Models\Department;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use Database\Seeders\OperationalPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures for the saraban feature tests: one user per authorization tier.
 *
 * LEGACY-04 (ปิดงาน) · Decision 2 — เดิมชั้นสิทธิ์ที่นี่ผูกกับ **ชื่อ role**
 * (`admin_saraban`, `manager`, `staff`) ที่ seeder สร้างขึ้นมาเองและไม่มีอยู่
 * บนระบบจริงเลยสักชื่อ ทำให้เทสต์ผ่านทั้งที่ของจริงใช้ไม่ได้
 *
 * ตอนนี้ให้สิทธิ์กับผู้ใช้ตรง ๆ ตามหน้าที่ เทสต์จึงวัดสิ่งที่ระบบบังคับใช้จริง
 * (permission) ไม่ใช่ชื่อบทบาทที่แก้ได้จากหน้าจอ
 */
abstract class SarabanTestCase extends TestCase
{
    use RefreshDatabase;

    /** เจ้าหน้าที่ทะเบียนรับ-ส่ง — ดูแลทะเบียนทั้งเล่ม */
    protected const REGISTRAR_PERMISSIONS = [
        'saraban.document.view',
        'saraban.document.view_classified',
        'saraban.document.create',
        'saraban.document.edit',
        'saraban.document.delete',
        'saraban.document.register',
        'saraban.document.approve',
        'saraban.document.sign',
        'saraban.document.manage_types',
        'saraban.document.report',
    ];

    /** ผู้บริหาร — อ่าน กลั่นกรอง ลงนาม แต่ไม่ดูแลทะเบียน */
    protected const APPROVER_PERMISSIONS = [
        'saraban.document.view',
        'saraban.document.view_classified',
        'saraban.document.approve',
        'saraban.document.sign',
        'saraban.document.report',
    ];

    /** บุคลากรทั่วไป — อ่านและร่างหนังสือของตนเอง */
    protected const STAFF_PERMISSIONS = [
        'saraban.document.view',
        'saraban.document.create',
        'saraban.document.edit',
    ];

    protected Department $department;

    protected DocumentType $type;

    protected User $registrar;

    protected User $approver;

    protected User $staff;

    protected User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        // สร้างชุด permission จริงของระบบ (ไม่แจกให้ role ใด เพราะยังไม่มี role)
        $this->seed(OperationalPermissionSeeder::class);

        $this->department = Department::create([
            'name' => 'Test Department',
            'status' => 'active',
        ]);

        $this->type = DocumentType::create([
            'name' => 'หนังสือภายใน',
            'code' => 'int',
        ]);

        $this->registrar = $this->makeUser(self::REGISTRAR_PERMISSIONS);
        $this->approver = $this->makeUser(self::APPROVER_PERMISSIONS);
        $this->staff = $this->makeUser(self::STAFF_PERMISSIONS);
        $this->outsider = $this->makeUser(self::STAFF_PERMISSIONS);
    }

    /**
     * @param  array<int,string>  $permissions
     */
    protected function makeUser(array $permissions): User
    {
        $user = User::factory()->create(['department_id' => $this->department->id]);
        $user->givePermissionTo($permissions);

        return $user->fresh();
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
