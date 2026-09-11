<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ประเภทบุคลากรต้องถูกเลือก ไม่ใช่ให้ระบบเดา
 *
 * **ที่มา:** ฟอร์มจัดการผู้ใช้ไม่มีช่อง "ประเภทบุคลากร" เลย และฝั่งเซิร์ฟเวอร์เดา
 * เป็น "ข้าราชการ" ให้เงียบ ๆ ทุกครั้งที่ไม่ได้ส่งค่ามา · ประเภทบุคลากรเป็นตัวตัดสิน
 * สิทธิวันลาทั้งปี (`leave_policies` แยกตามประเภท) ครูอัตราจ้างหรือพนักงานราชการ
 * ที่ถูกสร้างจากหน้าจอจึงได้โควตาของข้าราชการไปตั้งแต่วันแรกโดยไม่มีอะไรบอก
 *
 * ซ้ำร้ายคือหน้าแก้ไขผู้ใช้ก็ไม่รับค่านี้ ค่าที่เดาผิดจึงติดอยู่ถาวร
 */
class PersonnelTypeMustBeChosenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OrgUnit $unit;

    private Role $employeeRole;

    private PersonnelType $contractType;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view_users', 'edit_users', 'system.user.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->unit = OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบ']);
        $this->employeeRole = Role::findOrCreate('Employee', 'web');
        $this->contractType = PersonnelType::firstOrCreate(
            ['code' => 'temporary_employee'],
            ['name' => 'ลูกจ้างชั่วคราว', 'is_active' => true],
        );

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['view_users', 'edit_users', 'system.user.manage']);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'บุคลากรใหม่',
            'email' => 'newcomer@example.test',
            'password' => 'temporary-pass-1234',
            'role_id' => $this->employeeRole->id,
            'org_unit_id' => $this->unit->id,
            'personnel_type_id' => $this->contractType->id,
        ], $override);
    }

    public function test_สร้างบัญชีโดยไม่เลือกประเภทบุคลากรไม่ได้(): void
    {
        $payload = $this->payload();
        unset($payload['personnel_type_id']);

        $this->actingAs($this->admin->fresh())
            ->postJson('/api/admin/users', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('personnel_type_id');
    }

    public function test_ประเภทที่เลือกถูกบันทึกจริง_ไม่ถูกเดาทับเป็นข้าราชการ(): void
    {
        $this->actingAs($this->admin->fresh())
            ->postJson('/api/admin/users', $this->payload())
            ->assertCreated();

        $created = User::where('email', 'newcomer@example.test')->firstOrFail();

        $this->assertSame($this->contractType->id, $created->personnel_type_id);
    }

    public function test_แก้ประเภทบุคลากรจากหน้าแก้ไขผู้ใช้ได้(): void
    {
        // เดิมหน้านี้ไม่รับค่านี้เลย ค่าที่เดาผิดตอนสร้างจึงติดอยู่ถาวร
        $civilServant = PersonnelType::firstOrCreate(
            ['code' => 'civil_servant'],
            ['name' => 'ข้าราชการ', 'is_active' => true],
        );

        $staff = User::factory()->create(['personnel_type_id' => $civilServant->id]);

        $this->actingAs($this->admin->fresh())
            ->putJson("/api/admin/users/{$staff->id}", [
                'name' => $staff->name,
                'email' => $staff->email,
                'role_id' => $this->employeeRole->id,
                'org_unit_id' => $this->unit->id,
                'personnel_type_id' => $this->contractType->id,
            ])
            ->assertOk();

        $this->assertSame($this->contractType->id, $staff->fresh()->personnel_type_id);
    }

    public function test_นำเข้า_csv_ที่ไม่มีคอลัมน์ประเภทบุคลากรถูกปฏิเสธ(): void
    {
        /*
         * ปฏิเสธทั้งไฟล์พร้อมบอกชื่อคอลัมน์ที่ขาด ดีกว่านำเข้าสำเร็จแล้วทุกคนได้
         * โควตาของข้าราชการไปเงียบ ๆ ซึ่งกว่าจะรู้ก็ตอนคนใช้วันลาเกินสิทธิไปแล้ว
         */
        $csv = "name,email,role_id,org_unit_id\n"
            ."นำเข้า ทดสอบ,imported@example.test,{$this->employeeRole->id},{$this->unit->id}\n";

        $this->actingAs($this->admin->fresh())
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_นำเข้า_csv_ที่ระบุประเภทบุคลากรใช้ค่านั้นจริง(): void
    {
        $csv = "name,email,role_id,org_unit_id,personnel_type_id\n"
            ."นำเข้า ทดสอบ,imported@example.test,{$this->employeeRole->id},{$this->unit->id},{$this->contractType->id}\n";

        $this->actingAs($this->admin->fresh())
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertOk();

        $this->assertSame(
            $this->contractType->id,
            User::where('email', 'imported@example.test')->firstOrFail()->personnel_type_id,
        );
    }
}
