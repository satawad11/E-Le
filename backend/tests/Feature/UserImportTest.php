<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CLEANUP-01 — นำเข้าผู้ใช้จาก CSV และรายชื่อหน่วยงานสำหรับตัวกรอง
 *
 * ทั้งสอง endpoint นี้ถูกประกาศไว้ใน routes มาตลอด แต่ชี้ไปยังเมธอดที่ไม่มีอยู่จริง
 * ปุ่ม "นำเข้า CSV" ในหน้าจัดการผู้ใช้และช่องตัวกรองหน่วยงานในหน้ารายงานการลงเวลา
 * จึงพังทุกครั้งที่กด
 */
class UserImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $department;

    private Role $employeeRole;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view_users', 'edit_users'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->department = Department::create(['name' => 'ฝ่ายทดสอบ', 'is_active' => true]);
        $this->employeeRole = Role::findOrCreate('Employee', 'web');

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['view_users', 'edit_users']);
    }

    public function test_department_filter_list_returns_active_departments_to_any_signed_in_user(): void
    {
        Department::create(['name' => 'ฝ่ายที่ปิดใช้งาน', 'is_active' => false]);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/departments')->assertOk();

        $this->assertSame(['ฝ่ายทดสอบ'], array_column($response->json(), 'name'));
    }

    public function test_csv_import_creates_users_with_role_profile_and_leave_balances(): void
    {
        $csv = "id_card_number,name,email,role_id,department_id,title,first_name,last_name,gender,phone,position\n"
            ."1234567890123,นายสมชาย ใจดี,somchai@example.com,{$this->employeeRole->id},{$this->department->id},นาย,สมชาย,ใจดี,ชาย,0812345678,ครู\n";

        $response = $this->actingAs($this->admin)
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertOk();

        $this->assertSame(1, $response->json('imported'));
        $this->assertSame([], $response->json('errors'));

        $created = User::where('email', 'somchai@example.com')->firstOrFail();
        $this->assertTrue($created->hasRole('Employee'));
        $this->assertSame($this->department->id, $created->department_id);
        $this->assertSame('ครู', $created->profile->position);
        $this->assertDatabaseHas('leave_balances', ['user_id' => $created->id]);
    }

    public function test_csv_import_never_accepts_a_password_from_the_file(): void
    {
        $csv = "name,email,role_id,department_id,password\n"
            ."ผู้ใช้ทดสอบ,plain@example.com,{$this->employeeRole->id},{$this->department->id},password-from-file\n";

        $this->actingAs($this->admin)
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertOk();

        $created = User::where('email', 'plain@example.com')->firstOrFail();
        $this->assertFalse(password_verify('password-from-file', $created->password));
    }

    public function test_csv_import_skips_bad_rows_and_reports_the_line_number(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $csv = "name,email,role_id,department_id\n"
            .",no-name@example.com,{$this->employeeRole->id},{$this->department->id}\n"
            ."ซ้ำอีเมล,taken@example.com,{$this->employeeRole->id},{$this->department->id}\n"
            ."ถูกต้อง,good@example.com,{$this->employeeRole->id},{$this->department->id}\n";

        $response = $this->actingAs($this->admin)
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertOk();

        $this->assertSame(1, $response->json('imported'));
        $this->assertCount(2, $response->json('errors'));
        $this->assertStringContainsString('บรรทัดที่ 2', $response->json('errors.0'));
        $this->assertStringContainsString('บรรทัดที่ 3', $response->json('errors.1'));
        $this->assertDatabaseHas('users', ['email' => 'good@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'no-name@example.com']);
    }

    public function test_csv_import_rejects_a_file_without_the_required_columns(): void
    {
        $csv = "name,email\nผู้ใช้,someone@example.com\n";

        $this->actingAs($this->admin)
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_csv_import_requires_the_user_management_permission(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', "name,email,role_id,department_id\n")])
            ->assertForbidden();
    }
}
