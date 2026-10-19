<?php

namespace Tests\Feature\Authz;

use App\Models\Authz\Permission;
use App\Models\Authz\PermissionOverride;
use App\Models\PersonnelChangeRequest;
use App\Models\PersonnelProfile;
use App\Models\SensitiveAccessLog;
use App\Support\Spreadsheet\SheetWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * API องค์กร/บุคลากรต่อ PermissionEngine แล้ว (R04-5, R04-8)
 * IDOR: รายการนอก scope ตอบ 404 (ไม่บอกว่ามีอยู่) · ไม่มีสิทธิ์เลยตอบ 403 · ผิดกฎกลางตอบ 403
 */
class CoreApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CollegeFixture::build()->withAccessPersonas();
    }

    public function test_personnel_profile_in_scope_is_shown_out_of_scope_is_404(): void
    {
        Sanctum::actingAs($this->f->users['section_head']);

        $this->getJson("/api/personnel/{$this->f->people['hr_staff']->uuid}")->assertOk()->assertJsonPath('personnel.personnel_code', 'S001');
        $this->getJson("/api/personnel/{$this->f->people['teacher']->uuid}")->assertNotFound();
        $this->getJson('/api/personnel/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    public function test_staff_sees_own_record_only_and_admin_cannot_browse_personnel(): void
    {
        Sanctum::actingAs($this->f->users['teacher']);
        $this->getJson("/api/personnel/{$this->f->people['teacher']->uuid}")->assertOk();
        $this->getJson("/api/personnel/{$this->f->people['hr_staff']->uuid}")->assertNotFound();

        Sanctum::actingAs($this->f->users['admin']);
        $this->getJson("/api/personnel/{$this->f->people['teacher']->uuid}")->assertNotFound();
        $this->getJson('/api/org/units')->assertOk(); // โครงสร้างองค์กรดูได้แบบบุคลากรทั่วไป (ADR-R-015 ฉบับแก้ไข)
        $this->postJson("/api/personnel/{$this->f->people['teacher']->uuid}/reveal", ['fields' => ['national_id'], 'reason' => 'ตรวจข้อมูลของผู้อื่น'])->assertNotFound();
    }

    public function test_user_without_any_scope_gets_403_from_the_route(): void
    {
        Sanctum::actingAs($this->f->users['teacher']);

        $this->getJson('/api/org/positions')->assertForbidden();
        $this->postJson('/api/org/units', [])->assertForbidden();
        $this->getJson("/api/personnel/{$this->f->people['teacher']->uuid}/assignments")->assertOk();
        $this->postJson("/api/personnel/{$this->f->people['hr_staff']->uuid}/assignments", [])->assertForbidden();
    }

    public function test_hr_manages_org_and_assignments_but_not_their_own(): void
    {
        Sanctum::actingAs($this->f->users['hr']);

        $this->putJson("/api/org/units/{$this->f->units['hr']->uuid}", ['name' => 'งานบริหารงานบุคคล'])->assertOk();
        $this->assertSame('งานบริหารงานบุคคล', $this->f->units['hr']->fresh()->name);

        $payload = ['kind' => 'duty', 'org_unit_uuid' => $this->f->units['hr']->uuid, 'position_uuid' => $this->f->positions['staff']->uuid,
            'order_number' => 'คำสั่ง 50/2569', 'order_date' => '2026-10-01', 'effective_from' => '2026-10-01'];
        $this->postJson("/api/personnel/{$this->f->people['teacher']->uuid}/assignments", $payload)->assertCreated();
        $this->postJson("/api/personnel/{$this->f->people['hr_staff']->uuid}/assignments", $payload)->assertForbidden();
    }

    public function test_reveal_requires_scope_reason_and_is_logged(): void
    {
        PersonnelProfile::create(['personnel_id' => $this->f->people['teacher']->id, 'national_id' => '1103700012346']);

        Sanctum::actingAs($this->f->users['section_head']);
        $this->postJson("/api/personnel/{$this->f->people['teacher']->uuid}/reveal", ['fields' => ['national_id'], 'reason' => 'ตรวจสอบสิทธิสวัสดิการ'])
            ->assertNotFound();

        Sanctum::actingAs($this->f->users['hr']);
        $this->postJson("/api/personnel/{$this->f->people['teacher']->uuid}/reveal", ['fields' => ['national_id']])->assertStatus(422);
        $this->postJson("/api/personnel/{$this->f->people['teacher']->uuid}/reveal", ['fields' => ['national_id'], 'reason' => 'ตรวจสอบสิทธิสวัสดิการ'])
            ->assertOk()->assertJsonPath('fields.national_id', '1103700012346');

        $this->assertSame(1, SensitiveAccessLog::where('actor_user_id', $this->f->users['hr']->id)->count());
    }

    public function test_change_request_review_enforces_maker_checker_through_the_api(): void
    {
        Sanctum::actingAs($this->f->users['teacher']);
        $uuid = $this->postJson("/api/personnel/{$this->f->people['teacher']->uuid}/change-requests", ['entity' => 'personnel', 'payload' => ['last_name' => 'หลังสมรส']])
            ->assertCreated()->json('uuid');
        $this->postJson("/api/personnel/{$this->f->people['hr_staff']->uuid}/change-requests", ['entity' => 'personnel', 'payload' => ['last_name' => 'x']])
            ->assertNotFound();

        // งานบุคคลยื่นคำขอแก้ข้อมูลตนเอง แล้วพยายามอนุมัติเอง
        Sanctum::actingAs($this->f->users['hr']);
        $own = PersonnelChangeRequest::create(['personnel_id' => $this->f->people['hr_staff']->id, 'requested_by' => $this->f->users['hr']->id,
            'entity' => 'personnel', 'payload' => ['last_name' => 'x'], 'status' => 'pending']);
        $this->postJson("/api/personnel/change-requests/{$own->uuid}/review", ['decision' => 'approved'])->assertForbidden();

        $this->postJson("/api/personnel/change-requests/{$uuid}/review", ['decision' => 'approved'])->assertOk();
        $this->assertSame('หลังสมรส', $this->f->people['teacher']->fresh()->last_name);
    }

    public function test_admin_reveals_own_sensitive_data_with_a_log_but_never_others(): void
    {
        foreach (['admin', 'hr_staff'] as $key) {
            PersonnelProfile::create(['personnel_id' => $this->f->people[$key]->id, 'national_id' => $key === 'admin' ? '1103700012346' : '3101700012340']);
        }

        // ผู้ดูแลระบบทั่วไป: ของตนเองได้ + log · ของผู้อื่นไม่อยู่ใน scope การดู = 404 (ไม่บอกว่ามีอยู่)
        Sanctum::actingAs($this->f->users['admin']);
        $this->postJson("/api/personnel/{$this->f->people['admin']->uuid}/reveal", ['fields' => ['national_id'], 'reason' => 'ตรวจข้อมูลของตนเองก่อนยื่นเอกสาร'])
            ->assertOk()->assertJsonPath('fields.national_id', '1103700012346');
        $this->assertSame(1, SensitiveAccessLog::where('actor_user_id', $this->f->users['admin']->id)->where('personnel_id', $this->f->people['admin']->id)->count());

        // ผู้ดูแลระบบที่เป็นหัวหน้างานด้วย: ดูประวัติคนในงานได้ แต่เปิดดูข้อมูลอ่อนไหวของผู้อื่น = 403
        $this->f->grant('section_head', 'system.admin', 'คำสั่ง 43/2569');
        Sanctum::actingAs($this->f->users['section_head']->fresh());
        $this->getJson("/api/personnel/{$this->f->people['hr_staff']->uuid}")->assertOk();
        $this->postJson("/api/personnel/{$this->f->people['hr_staff']->uuid}/reveal", ['fields' => ['national_id'], 'reason' => 'ตรวจข้อมูลของผู้อื่นในงาน'])
            ->assertForbidden();
        $this->assertSame(0, SensitiveAccessLog::where('personnel_id', $this->f->people['hr_staff']->id)->count());
    }

    public function test_unit_denied_owner_still_views_and_reveals_own_record_through_the_api(): void
    {
        PersonnelProfile::create(['personnel_id' => $this->f->people['section_head']->id, 'national_id' => '1103700012346']);
        foreach (['personnel.profile.view', 'personnel.profile.reveal'] as $permission) {
            PermissionOverride::create(['user_id' => $this->f->users['section_head']->id, 'permission_id' => Permission::where('name', $permission)->value('id'),
                'effect' => 'deny', 'org_unit_id' => $this->f->units['hr']->id, 'reason' => 'ส่วนได้เสีย', 'granted_by' => $this->f->users['admin']->id]);
        }

        Sanctum::actingAs($this->f->users['section_head']->fresh());
        $this->getJson("/api/personnel/{$this->f->people['section_head']->uuid}")->assertOk();
        $this->postJson("/api/personnel/{$this->f->people['section_head']->uuid}/reveal", ['fields' => ['national_id'], 'reason' => 'ตรวจข้อมูลของตนเอง'])
            ->assertOk()->assertJsonPath('fields.national_id', '1103700012346');
        $this->getJson("/api/personnel/{$this->f->people['hr_staff']->uuid}")->assertNotFound();
    }

    public function test_reveal_is_throttled(): void
    {
        PersonnelProfile::create(['personnel_id' => $this->f->people['teacher']->id, 'national_id' => '1103700012346']);
        Sanctum::actingAs($this->f->users['hr']);
        $uri = "/api/personnel/{$this->f->people['teacher']->uuid}/reveal";

        for ($i = 0; $i < 10; $i++) {
            $this->postJson($uri, ['fields' => ['national_id'], 'reason' => 'ตรวจสอบสิทธิสวัสดิการ'])->assertOk();
        }
        $this->postJson($uri, ['fields' => ['national_id'], 'reason' => 'ตรวจสอบสิทธิสวัสดิการ'])->assertStatus(429);
    }

    public function test_import_is_throttled_and_uses_the_detected_file_type(): void
    {
        Sanctum::actingAs($this->f->users['hr']);
        $csv = "personnel_code,first_name,last_name,personnel_type_code\nT900,สมใจ,นำเข้า,permanent_employee\n";

        // ชื่อไฟล์บอกว่า .xlsx แต่เนื้อเป็น csv — อ่านตามชนิดที่ตรวจได้จากเนื้อไฟล์ ไม่เชื่อนามสกุลที่ผู้ใช้ส่ง
        $csvPath = tempnam(sys_get_temp_dir(), 'r04csv');
        file_put_contents($csvPath, $csv);
        $file = new UploadedFile($csvPath, 'personnel.xlsx', null, null, true);
        $this->post('/api/personnel/import', ['file' => $file, 'sheet' => 'personnel'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('sheets.personnel.created', 1);

        // ไฟล์ไบนารีที่ตั้งชื่อเป็น .xlsx แต่ไม่ใช่ Excel ถูกปฏิเสธ
        $binPath = tempnam(sys_get_temp_dir(), 'r04bin');
        file_put_contents($binPath, "\x00\x01\x02".random_bytes(256));
        $this->post('/api/personnel/import', ['file' => new UploadedFile($binPath, 'evil.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertStatus(422);
        @unlink($csvPath);
        @unlink($binPath);

        $path = tempnam(sys_get_temp_dir(), 'r04').'.xlsx';
        app(SheetWriter::class)->write($path, ['personnel' => [['personnel_code', 'first_name', 'last_name', 'personnel_type_code'], ['T901', 'สมใจ', 'ทดสอบ', 'permanent_employee']]]);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/api/personnel/import', ['file' => new UploadedFile($path, 'import.xlsx', null, null, true)], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('dry_run', true);
        }
        $this->post('/api/personnel/import', ['file' => new UploadedFile($path, 'import.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertStatus(429);
        @unlink($path);
    }
}
