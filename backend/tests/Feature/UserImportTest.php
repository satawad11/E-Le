<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\PersonnelType;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * นำเข้าผู้ใช้จาก CSV — POST /api/admin/users/import
 *
 * route และปุ่มในหน้าจัดการผู้ใช้มีอยู่ตลอด แต่ UserController::importCsv() หายไป
 * ใน baseline snapshot (LEGACY-00) endpoint จึงตอบ 500 ทุกครั้งที่กด
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

    private function import(string $csv, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)]);
    }

    public function test_csv_import_creates_users_with_role_profile_personnel_type_and_leave_balances(): void
    {
        // หัวคอลัมน์เดียวกับไฟล์แม่แบบที่หน้าเว็บให้ดาวน์โหลด รวม BOM ของ Excel
        $csv = "\xEF\xBB\xBFid_card_number,name,email,role_id,department_id,title,first_name,last_name,gender,phone,position\n"
            ."1234567890123,นายสมชาย ใจดี,somchai@example.com,{$this->employeeRole->id},{$this->department->id},นาย,สมชาย,ใจดี,ชาย,0812345678,ครู\n";

        $response = $this->import($csv)->assertOk();

        $this->assertSame(1, $response->json('imported'));
        $this->assertSame([], $response->json('errors'));

        $created = User::where('email', 'somchai@example.com')->firstOrFail();
        $this->assertTrue($created->hasRole('Employee'));
        $this->assertSame($this->department->id, $created->department_id);
        $this->assertSame('ครู', $created->profile->position);
        $this->assertSame('0812345678', $created->phone);
        $this->assertSame(
            PersonnelType::where('code', 'civil_servant')->value('id'),
            $created->personnel_type_id,
            'ผู้ใช้ทุกคนต้องมีประเภทบุคลากร ไม่งั้นนโยบายสิทธิการลาจะหาไม่เจอ'
        );
        $this->assertDatabaseHas('leave_balances', ['user_id' => $created->id]);
    }

    public function test_csv_import_uses_the_personnel_type_given_in_the_file(): void
    {
        $other = PersonnelType::where('code', '!=', 'civil_servant')->firstOrFail();

        $csv = "name,email,role_id,department_id,personnel_type_id\n"
            ."ผู้ใช้,typed@example.com,{$this->employeeRole->id},{$this->department->id},{$other->id}\n";

        $this->import($csv)->assertOk();

        $this->assertSame($other->id, User::where('email', 'typed@example.com')->value('personnel_type_id'));
    }

    public function test_csv_import_stores_the_id_card_number_encrypted_with_its_hash(): void
    {
        $csv = "name,email,role_id,department_id,id_card_number\n"
            ."ผู้ใช้,idcard@example.com,{$this->employeeRole->id},{$this->department->id},1103700012345\n";

        $this->import($csv)->assertOk();

        $userId = User::where('email', 'idcard@example.com')->value('id');
        $raw = DB::table('user_profiles')->where('user_id', $userId)->first();

        $this->assertNotSame('1103700012345', $raw->id_card_number, 'เลขบัตรต้องไม่ถูกเก็บเป็นข้อความธรรมดา');
        $this->assertSame(hash('sha256', '1103700012345'.config('app.key')), $raw->id_card_number_hash);
        $this->assertSame('1103700012345', UserProfile::where('user_id', $userId)->first()->id_card_number);
    }

    public function test_csv_import_rejects_an_id_card_number_already_in_the_system_or_earlier_in_the_file(): void
    {
        User::factory()->create()->profile()->create(['id_card_number' => '1111111111111']);

        $csv = "name,email,role_id,department_id,id_card_number\n"
            ."ซ้ำของเดิม,dup-existing@example.com,{$this->employeeRole->id},{$this->department->id},1111111111111\n"
            ."คนแรก,first@example.com,{$this->employeeRole->id},{$this->department->id},2222222222222\n"
            ."ซ้ำในไฟล์,dup-file@example.com,{$this->employeeRole->id},{$this->department->id},2222222222222\n"
            ."เลขผิดรูปแบบ,bad-id@example.com,{$this->employeeRole->id},{$this->department->id},12345\n";

        $response = $this->import($csv)->assertOk();

        $this->assertSame(1, $response->json('imported'));
        $this->assertCount(3, $response->json('errors'));
        $this->assertStringContainsString('บรรทัดที่ 2', $response->json('errors.0'));
        $this->assertStringContainsString('บรรทัดที่ 4', $response->json('errors.1'));
        $this->assertStringContainsString('บรรทัดที่ 5', $response->json('errors.2'));
        $this->assertDatabaseHas('users', ['email' => 'first@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'dup-existing@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'dup-file@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'bad-id@example.com']);
    }

    public function test_csv_import_never_accepts_a_password_from_the_file(): void
    {
        $csv = "name,email,role_id,department_id,password\n"
            ."ผู้ใช้ทดสอบ,plain@example.com,{$this->employeeRole->id},{$this->department->id},password-from-file\n";

        $this->import($csv)->assertOk();

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

        $response = $this->import($csv)->assertOk();

        $this->assertSame(1, $response->json('imported'));
        $this->assertCount(2, $response->json('errors'));
        $this->assertStringContainsString('บรรทัดที่ 2', $response->json('errors.0'));
        $this->assertStringContainsString('บรรทัดที่ 3', $response->json('errors.1'));
        $this->assertDatabaseHas('users', ['email' => 'good@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'no-name@example.com']);
    }

    public function test_csv_import_writes_an_audit_log_entry(): void
    {
        $csv = "name,email,role_id,department_id\n"
            ."ผู้ใช้,audited@example.com,{$this->employeeRole->id},{$this->department->id}\n"
            .",,{$this->employeeRole->id},{$this->department->id}\n";

        $this->import($csv)->assertOk();

        $log = AuditLog::where('action', 'user.imported')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(1, $log->after['imported']);
        $this->assertSame(1, $log->after['rejected']);
        $this->assertSame([User::where('email', 'audited@example.com')->value('id')], $log->after['user_ids']);
    }

    public function test_csv_import_rejects_a_file_without_the_required_columns(): void
    {
        $this->import("name,email\nผู้ใช้,someone@example.com\n")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_csv_import_requires_the_user_management_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view_users');

        $this->import("name,email,role_id,department_id\nผู้ใช้,x@example.com,{$this->employeeRole->id},{$this->department->id}\n", $viewer)
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_csv_import_requires_authentication(): void
    {
        $this->postJson('/api/admin/users/import')->assertUnauthorized();
    }
}
