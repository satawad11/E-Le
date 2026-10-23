<?php

namespace Tests\Feature\Core;

use App\Models\Authz\Permission;
use App\Models\Authz\PermissionOverride;
use App\Models\PersonnelDocument;
use App\Models\PersonnelEducation;
use App\Models\PersonnelLicense;
use App\Models\PersonnelProfile;
use App\Models\SensitiveAccessLog;
use App\Services\Personnel\PersonnelChangeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * API ของหน้าจอบุคลากร R-06 (ต้นแบบหน้า 2–6, 12) — scopeQuery · PersonnelPresenter · hr_field_guard · signed URL
 */
class PersonnelScreensApiTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CollegeFixture::build()->withAccessPersonas()->withLimitedHrPersona();
    }

    private function as(string $persona): void
    {
        Sanctum::actingAs($this->f->users[$persona]->fresh());
    }

    /** @return array<int, string> */
    private function listedCodes(string $query = ''): array
    {
        return collect($this->getJson('/api/personnel?per_page=100'.$query)->assertOk()->json('data'))->pluck('full_name')->all();
    }

    // ------------------------------------------------------------------ รายชื่อ (หน้า 3, 12)

    public function test_directory_is_scoped_by_the_engine_for_every_persona(): void
    {
        $this->as('hr');
        $this->getJson('/api/personnel')->assertOk()->assertJsonPath('meta.counts.all', 10)->assertJsonPath('meta.capabilities.view_scope', 'all');

        $this->as('section_head');
        $this->getJson('/api/personnel?per_page=100')->assertJsonPath('meta.counts.all', 3); // งานบุคลากร: S001 S002 S003

        $this->as('teacher');
        $response = $this->getJson('/api/personnel')->assertOk()->assertJsonPath('meta.counts.all', 1);
        $this->assertTrue($response->json('data.0.is_self'));

        $this->as('trainer');
        $response = $this->getJson('/api/personnel?per_page=100')->assertOk()->assertJsonPath('meta.counts.all', 4);
        $names = collect($response->json('data'))->pluck('full_name');
        $this->assertTrue($names->contains($this->f->people['teacher']->full_name));
        $this->assertFalse($names->contains($this->f->people['hr_staff']->full_name), 'ไม่เห็นแผนกอื่น');
    }

    public function test_limited_hr_persona_sees_their_grant_and_capabilities_like_page_12(): void
    {
        $this->as('trainer');
        $meta = $this->getJson('/api/personnel?per_page=100')->json('meta');

        $access = collect($meta['access'])->firstWhere('template', 'personnel.hr_development');
        $this->assertSame(['แผนกวิชาช่างยนต์'], $access['units']);
        $this->assertSame('คำสั่ง 503/2569', $access['order_number']);
        $this->assertSame('2027-03-31', $access['expires_at']);
        $this->assertNotNull($access['approved_by']);

        $this->assertTrue($meta['capabilities']['manage_training']);
        $this->assertFalse($meta['capabilities']['view_confidential']);
        $this->assertFalse($meta['capabilities']['reveal']);
        $this->assertFalse($meta['capabilities']['report']);

        $rows = collect($this->getJson('/api/personnel?per_page=100')->json('data'))->keyBy('full_name');
        $this->assertTrue($rows[$this->f->people['teacher']->full_name]['abilities']['manage_training']);
        $this->assertFalse($rows[$this->f->people['trainer']->full_name]['abilities']['manage_training'], 'ของตนเองบันทึกไม่ได้');

        $this->getJson('/api/me/menu')->assertOk()->assertJsonFragment(['name' => 'บุคลากรแผนกวิชาช่างยนต์']);
    }

    public function test_directory_chips_and_filters_use_the_same_scope(): void
    {
        PersonnelLicense::create(['personnel_id' => $this->f->people['teacher']->id, 'license_type' => 'ใบอนุญาตประกอบวิชาชีพครู', 'license_number' => 'L-1', 'expires_at' => today()->addDays(40)]);
        PersonnelLicense::create(['personnel_id' => $this->f->people['hr_staff']->id, 'license_type' => 'ใบอนุญาตประกอบวิชาชีพครู', 'license_number' => 'L-2', 'expires_at' => today()->addDays(20)]);

        $this->as('hr');
        $this->getJson('/api/personnel')->assertJsonPath('meta.chips.license_expiring', 2);
        $this->assertCount(2, $this->getJson('/api/personnel?chip=license_expiring')->json('data'));
        $this->assertCount(4, $this->getJson('/api/personnel?org_unit='.$this->f->units['auto']->uuid)->json('data'));

        $this->as('trainer');
        $this->getJson('/api/personnel')->assertJsonPath('meta.chips.license_expiring', 1);
        $row = collect($this->getJson('/api/personnel?chip=license_expiring')->json('data'))->first();
        $this->assertSame(40, $row['license']['days_left']);
    }

    // ------------------------------------------------------------------ แก้ข้อมูลรายคน + hr_field_guard

    public function test_hr_edits_others_but_never_hr_only_fields_of_their_own_record(): void
    {
        $teacher = $this->f->people['teacher'];

        $this->as('hr');
        $this->patchJson("/api/personnel/{$teacher->uuid}", ['entity' => 'personnel_profile', 'changes' => ['phone_mobile' => '0812345678']])->assertOk();
        $this->patchJson("/api/personnel/{$teacher->uuid}", ['entity' => 'personnel', 'changes' => ['academic_standing' => 'ครูชำนาญการ']])->assertOk();
        $this->assertSame('ครูชำนาญการ', $teacher->fresh()->academic_standing);

        $own = $this->f->people['hr_staff'];
        $this->patchJson("/api/personnel/{$own->uuid}", ['entity' => 'personnel', 'changes' => ['academic_standing' => 'ครูเชี่ยวชาญ']])
            ->assertForbidden(); // hr_field_guard
        $this->assertNull($own->fresh()->academic_standing);

        $this->as('section_head'); // ดูได้แต่ไม่มีสิทธิ์แก้
        $this->patchJson("/api/personnel/{$own->uuid}", ['entity' => 'personnel_profile', 'changes' => ['phone_mobile' => '1']])->assertForbidden();
        $this->as('program_head');
        $this->patchJson("/api/personnel/{$own->uuid}", ['entity' => 'personnel_profile', 'changes' => ['phone_mobile' => '1']])->assertNotFound();
    }

    public function test_owner_edits_self_fields_directly_and_must_request_the_rest(): void
    {
        $teacher = $this->f->people['teacher'];
        $this->as('teacher');

        $this->patchJson("/api/personnel/{$teacher->uuid}", ['entity' => 'personnel_profile', 'changes' => ['phone_mobile' => '0899999999']])->assertOk();
        $this->assertSame('0899999999', PersonnelProfile::where('personnel_id', $teacher->id)->value('phone_mobile'));

        $this->patchJson("/api/personnel/{$teacher->uuid}", ['entity' => 'personnel', 'changes' => ['first_name' => 'สมหญิงใหม่']])->assertStatus(422);
        $this->patchJson("/api/personnel/{$teacher->uuid}", ['entity' => 'personnel', 'changes' => ['status' => 'separated']])->assertForbidden();
        $this->assertSame('active', $teacher->fresh()->status);
    }

    // ------------------------------------------------------------------ ประวัติรายหมวด

    public function test_records_are_written_by_hr_or_delegated_trainers_and_never_by_the_owner(): void
    {
        $teacher = $this->f->people['teacher'];

        $this->as('hr');
        $this->postJson("/api/personnel/{$teacher->uuid}/records/educations", ['level' => 'ปริญญาตรี', 'degree' => 'ค.อ.บ.', 'graduated_year' => 2555])->assertCreated();
        $this->assertNotNull(PersonnelEducation::where('personnel_id', $teacher->id)->value('verified_at'));
        $this->postJson("/api/personnel/{$this->f->people['hr_staff']->uuid}/records/licenses", ['license_type' => 'ครู', 'license_number' => 'X'])
            ->assertForbidden(); // ข้อมูลของตนเอง

        $this->as('trainer');
        $this->postJson("/api/personnel/{$teacher->uuid}/records/trainings", ['course' => 'อบรมยานยนต์ไฟฟ้า', 'hours' => 12, 'start_date' => today()->toDateString()])->assertCreated();
        $this->postJson("/api/personnel/{$teacher->uuid}/records/educations", ['level' => 'ปริญญาโท'])->assertForbidden();
        $this->postJson("/api/personnel/{$this->f->people['hr_staff']->uuid}/records/trainings", ['course' => 'x'])->assertNotFound();
        $this->postJson("/api/personnel/{$this->f->people['trainer']->uuid}/records/trainings", ['course' => 'ของตนเอง'])->assertForbidden();

        $this->as('teacher');
        $this->postJson("/api/personnel/{$teacher->uuid}/records/trainings", ['course' => 'แก้เอง'])->assertForbidden();

        $records = $this->getJson("/api/personnel/{$teacher->uuid}/records")->assertOk();
        $this->assertSame('อบรมยานยนต์ไฟฟ้า', $records->json('trainings.0.course'));
        $this->assertTrue($records->json('can.request_change'));
    }

    public function test_owner_requests_a_new_record_and_hr_approval_creates_it(): void
    {
        $teacher = $this->f->people['teacher'];
        $this->as('teacher');
        $uuid = $this->postJson("/api/personnel/{$teacher->uuid}/change-requests", [
            'entity' => 'personnel_education', 'payload' => ['level' => 'ปริญญาโท', 'degree' => 'ค.อ.ม.', 'major' => 'เทคโนโลยีเครื่องกล'],
        ])->assertCreated()->json('uuid');
        $this->assertSame(0, PersonnelEducation::where('personnel_id', $teacher->id)->count(), 'ค่าเดิมคงอยู่จนกว่าอนุมัติ');

        $other = PersonnelEducation::create(['personnel_id' => $this->f->people['hr_staff']->id, 'level' => 'ปริญญาตรี']);
        $this->postJson("/api/personnel/{$teacher->uuid}/change-requests", ['entity' => 'personnel_education', 'record_uuid' => $other->uuid, 'payload' => ['level' => 'x']])
            ->assertStatus(422);

        $this->as('hr');
        $queue = $this->getJson('/api/personnel/change-requests')->assertOk()->json('data');
        $item = collect($queue)->firstWhere('uuid', $uuid);
        $this->assertTrue($item['is_new_record']);
        $this->assertTrue($item['can_review']);
        $this->assertSame('ค.อ.ม.', collect($item['fields'])->firstWhere('field', 'degree')['requested']);

        $this->postJson("/api/personnel/change-requests/{$uuid}/review", ['decision' => 'approved'])->assertOk();
        $this->assertSame('ค.อ.ม.', PersonnelEducation::where('personnel_id', $teacher->id)->value('degree'));
    }

    public function test_review_queue_masks_sensitive_values_and_locks_own_requests(): void
    {
        $hrStaff = $this->f->people['hr_staff'];
        PersonnelProfile::create(['personnel_id' => $hrStaff->id, 'national_id' => '1103700012346']);
        $own = app(PersonnelChangeRequestService::class)->submit($this->f->users['hr'], $hrStaff, 'personnel_profile', ['national_id' => '3101700012340']);

        $this->as('hr');
        $item = collect($this->getJson('/api/personnel/change-requests')->json('data'))->firstWhere('uuid', $own->uuid);
        $field = $item['fields'][0];
        $this->assertTrue($field['masked']);
        $this->assertStringEndsWith('2346', $field['current']);
        $this->assertStringNotContainsString('3101700012340', json_encode($item));
        $this->assertFalse($item['can_review']);
        $this->assertNotNull($item['locked_reason']);

        $this->as('teacher');
        $this->getJson('/api/personnel/change-requests')->assertForbidden();
    }

    // ------------------------------------------------------------------ เอกสาร: private storage + signed URL

    public function test_documents_live_on_private_storage_and_download_needs_a_signed_link_issued_to_the_caller(): void
    {
        Storage::fake('local');
        $teacher = $this->f->people['teacher'];

        $this->as('teacher');
        $uuid = $this->post("/api/personnel/{$teacher->uuid}/documents", [
            'file' => UploadedFile::fake()->createWithContent('degree.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"), 'category' => 'evidence',
        ], ['Accept' => 'application/json'])->assertCreated()->json('uuid');
        $document = PersonnelDocument::where('uuid', $uuid)->firstOrFail();
        $this->assertStringStartsWith('personnel/'.$teacher->uuid.'/', $document->storage_path);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertNull($document->verified_at, 'หลักฐานที่เจ้าของส่งยังไม่ถูกตรวจ');

        $this->as('hr');
        $url = $this->getJson("/api/personnel/{$teacher->uuid}/documents/{$uuid}/link")->assertOk()->json('url');
        $this->assertStringStartsWith('/api/personnel-documents/', $url);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        $this->get('/api/personnel-documents/'.$uuid.'/download?u='.$this->f->users['hr']->id)->assertForbidden();

        // ลิงก์ของคนหนึ่งใช้กับอีกคนไม่ได้ · ผู้ที่ไม่เห็นข้อมูลชั้นลับขอลิงก์ไม่ได้
        $this->as('director');
        $this->get($url)->assertForbidden();
        $this->as('program_head');
        $this->getJson("/api/personnel/{$teacher->uuid}/documents/{$uuid}/link")->assertForbidden();
    }

    // ------------------------------------------------------------------ ประวัติการเข้าถึง · ข้อมูลของฉัน

    public function test_owner_always_sees_their_access_log_and_others_need_reveal_rights(): void
    {
        $teacher = $this->f->people['teacher'];
        SensitiveAccessLog::create(['actor_user_id' => $this->f->users['hr']->id, 'acting_as' => ['permission' => 'personnel.profile.reveal'],
            'personnel_id' => $teacher->id, 'fields' => ['national_id'], 'reason' => 'ตรวจเอกสารประกันสังคม', 'created_at' => now()]);

        $this->as('teacher');
        $this->getJson("/api/personnel/{$teacher->uuid}/access-log")->assertOk()->assertJsonPath('data.0.reason', 'ตรวจเอกสารประกันสังคม');
        $this->as('program_head');
        $this->getJson("/api/personnel/{$teacher->uuid}/access-log")->assertForbidden();
        $this->as('hr');
        $this->getJson("/api/personnel/{$teacher->uuid}/access-log")->assertOk();
        $this->as('auditor');
        $this->getJson("/api/personnel/{$teacher->uuid}/access-log")->assertNotFound(); // ไม่อยู่ใน scope การดูประวัติ
    }

    public function test_field_rules_and_own_change_requests_drive_the_me_page(): void
    {
        $this->as('teacher');
        $rules = $this->getJson('/api/personnel/field-rules')->assertOk()->json();
        $this->assertSame(['class' => 'public', 'editable_by' => 'self_with_review'], $rules['personnel']['first_name']);
        $this->assertSame('self', $rules['personnel_profile']['phone_mobile']['editable_by']);
        $this->assertArrayHasKey('personnel_training', $rules);

        $teacher = $this->f->people['teacher'];
        $this->postJson("/api/personnel/{$teacher->uuid}/change-requests", ['entity' => 'personnel', 'payload' => ['last_name' => 'นามสกุลใหม่']])->assertCreated();
        $this->getJson("/api/personnel/{$teacher->uuid}/change-requests")->assertOk()->assertJsonPath('data.0.status', 'pending');
        $this->getJson("/api/personnel/{$this->f->people['hr_staff']->uuid}/change-requests")->assertNotFound();
    }

    // ------------------------------------------------------------------ หน้าหลัก · องค์กร · รายงาน

    public function test_home_shows_only_what_each_persona_may_see(): void
    {
        $this->as('teacher');
        $home = $this->getJson('/api/me/home')->assertOk()->json();
        $this->assertSame([], $home['cards']);
        $this->assertSame([], $home['work']);
        $this->assertSame('primary', $home['positions'][0]['kind']);

        app(PersonnelChangeRequestService::class)->submit($this->f->users['teacher'], $this->f->people['teacher'], 'personnel', ['last_name' => 'ใหม่']);
        $this->as('hr');
        $home = $this->getJson('/api/me/home')->assertOk()->json();
        $this->assertSame(['personnel', 'licenses', 'change_requests', 'accounts_pending'], array_column($home['cards'], 'code'));
        $this->assertSame(1, collect($home['cards'])->firstWhere('code', 'change_requests')['value']);
        $this->assertSame(1, $home['work'][0]['total']);
        $this->assertSame('grant', collect($home['positions'])->last()['kind']);
    }

    public function test_org_detail_shows_the_chain_computed_by_the_resolver(): void
    {
        $this->as('teacher');
        $detail = $this->getJson("/api/org/units/{$this->f->units['auto_program']->uuid}")->assertOk()->json();

        $this->assertSame([4, 3, 2, 1], array_column($detail['approval_chain'], 'level'));
        $this->assertSame($this->f->people['program_head']->full_name, $detail['approval_chain'][0]['approvers'][0]['name']);
        $this->assertSame($this->f->people['multi']->full_name, $detail['approval_chain'][1]['approvers'][0]['name']);
        $this->assertSame($this->f->people['deputy_academic']->full_name, $detail['approval_chain'][2]['approvers'][0]['name']);
        $this->assertSame($this->f->people['director']->full_name, $detail['approval_chain'][3]['approvers'][0]['name']);
        $this->assertSame(['วิทยาลัยเทคนิคดอนเมือง', 'ฝ่ายวิชาการ', 'แผนกวิชาช่างยนต์', 'สาขางานยานยนต์'], $detail['path']);

        // สาขางานทดลองไม่มีหัวหน้า → ชั้น L4 ถูกข้าม (ไม่จำลองฝั่งหน้าเว็บ) · ไม่มีชั้น L3 เพราะอยู่ใต้ฝ่ายโดยตรง
        $pilot = $this->getJson("/api/org/units/{$this->f->units['pilot_program']->uuid}")->assertOk()->json();
        $this->assertTrue($pilot['approval_chain'][0]['skipped']);
        $this->assertSame('ไม่พบผู้ดำรงตำแหน่ง', $pilot['approval_chain'][0]['skip_reason']);
        $this->assertNull($pilot['head']);

        $units = collect($this->getJson('/api/org/units')->assertOk()->json())->keyBy('code');
        $this->assertSame($this->f->people['program_head']->full_name, $units['PRG-AUTO']['head']['name']);
        $this->assertSame(2, $units['PRG-AUTO']['members']);
    }

    public function test_reports_and_assignment_register_follow_their_permissions(): void
    {
        $this->as('hr');
        $this->getJson('/api/personnel/reports')->assertOk()->assertJsonPath('headcount.total', 10);
        $this->getJson('/api/org/assignments')->assertOk()->assertJsonStructure(['data' => [['uuid', 'kind', 'level', 'personnel' => ['full_name']]]]);

        $this->as('deputy_academic');
        $this->assertSame(4 + 1, $this->getJson('/api/personnel/reports')->assertOk()->json('headcount.total')); // ฝ่ายวิชาการ: T001 T002 T003 T004 + ตนเอง

        foreach (['teacher', 'trainer'] as $persona) {
            $this->as($persona);
            $this->getJson('/api/personnel/reports')->assertForbidden();
            $this->getJson('/api/org/assignments')->assertForbidden();
        }
    }

    public function test_unit_limited_deny_is_honoured_by_the_directory_too(): void
    {
        PermissionOverride::create(['user_id' => $this->f->users['hr']->id, 'permission_id' => Permission::where('name', 'personnel.profile.view')->value('id'),
            'effect' => 'deny', 'org_unit_id' => $this->f->units['academic']->id, 'reason' => 'ส่วนได้เสีย', 'granted_by' => $this->f->users['admin']->id]);

        $this->as('hr');
        $this->getJson('/api/personnel')->assertJsonPath('meta.counts.all', 5);
        $this->assertNotContains($this->f->people['teacher']->full_name, $this->listedCodes());
    }
}
