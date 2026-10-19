<?php

namespace Tests\Feature\Core;

use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\User;
use App\Services\Personnel\PersonnelChangeRequestService;
use App\Services\Personnel\PersonnelEditor;
use Database\Seeders\FieldClassificationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\FakePersonnelFieldAccess;
use Tests\TestCase;

/**
 * CORE-02 Tests ขั้นต่ำ: Maker–Checker · แก้ฟิลด์ hr_only ของตนเองไม่ได้แม้มีสิทธิ์ HR
 */
class PersonnelChangeControlTest extends TestCase
{
    use RefreshDatabase;

    private FakePersonnelFieldAccess $access;

    private Personnel $teacher;

    private User $teacherUser;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        (new FieldClassificationSeeder)->run();
        $this->access = new FakePersonnelFieldAccess;
        $this->app->instance(PersonnelFieldAccess::class, $this->access);

        $this->teacher = Personnel::factory()->create(['first_name' => 'สมหญิง', 'last_name' => 'ก่อนแก้', 'status' => 'active']);
        PersonnelProfile::create(['personnel_id' => $this->teacher->id, 'phone_mobile' => '0800000000', 'national_id' => '1103700012345']);
        $this->teacherUser = User::factory()->create(['personnel_id' => $this->teacher->id]);
        $this->hr = User::factory()->withPersonnel()->create();
        $this->access->grant($this->hr, ['hr' => true, 'review' => true]);
    }

    public function test_owner_edits_self_fields_directly(): void
    {
        app(PersonnelEditor::class)->update($this->teacherUser, $this->teacher, 'personnel_profile', ['phone_mobile' => '0899999999']);

        $this->assertSame('0899999999', $this->teacher->profile()->value('phone_mobile'));
    }

    public function test_owner_must_request_review_for_self_with_review_fields(): void
    {
        $this->expectException(ValidationException::class);

        app(PersonnelEditor::class)->update($this->teacherUser, $this->teacher, 'personnel', ['last_name' => 'หลังแก้']);
    }

    public function test_hr_cannot_edit_hr_only_fields_of_their_own_record(): void
    {
        $hrSelf = $this->hr->personnel;
        $this->access->grant($this->hr, ['hr' => true, 'review' => true]);

        try {
            app(PersonnelEditor::class)->update($this->hr, $hrSelf, 'personnel', ['status' => 'study_leave']);
            $this->fail('hr_field_guard ต้องปฏิเสธ');
        } catch (AuthorizationException) {
        }

        $this->assertSame('active', $hrSelf->fresh()->status);
    }

    public function test_hr_edits_hr_only_fields_of_others_and_sensitive_values_stay_out_of_audit(): void
    {
        app(PersonnelEditor::class)->update($this->hr, $this->teacher, 'personnel', ['academic_standing' => 'ชำนาญการพิเศษ']);
        app(PersonnelEditor::class)->update($this->hr, $this->teacher, 'personnel_profile', ['national_id' => '3101700012340']);

        $this->assertSame('ชำนาญการพิเศษ', $this->teacher->fresh()->academic_standing);
        $this->assertSame(PersonnelProfile::hashNationalId('3101700012340'), $this->teacher->profile()->value('national_id_hash'));

        $audit = AuditLog::where('action', 'personnel.updated')->latest('id')->first();
        $this->assertStringNotContainsString('3101700012340', json_encode($audit->toArray()));
    }

    public function test_others_without_hr_right_cannot_edit(): void
    {
        $this->expectException(AuthorizationException::class);

        app(PersonnelEditor::class)->update(User::factory()->withPersonnel()->create(), $this->teacher, 'personnel_profile', ['phone_mobile' => '0811111111']);
    }

    public function test_change_request_value_applies_only_after_approval_by_a_different_person(): void
    {
        $service = app(PersonnelChangeRequestService::class);
        $request = $service->submit($this->teacherUser, $this->teacher, 'personnel', ['last_name' => 'หลังแก้']);

        $this->assertSame('pending', $request->status);
        $this->assertSame('ก่อนแก้', $this->teacher->fresh()->last_name, 'ค่าเดิมแสดงจนกว่าอนุมัติ');

        $service->review($this->hr, $request, 'approved');

        $this->assertSame('หลังแก้', $this->teacher->fresh()->last_name);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($this->hr->id, $request->fresh()->reviewed_by);
    }

    public function test_requester_and_data_owner_can_never_review(): void
    {
        $service = app(PersonnelChangeRequestService::class);
        $request = $service->submit($this->teacherUser, $this->teacher, 'personnel', ['last_name' => 'หลังแก้']);

        // แม้เจ้าของข้อมูลมีสิทธิ์พิจารณา (เช่นเป็นงานบุคคลเอง) ก็พิจารณาคำขอของตนเองไม่ได้
        $this->access->grant($this->teacherUser, ['review' => true, 'hr' => true]);
        $other = User::factory()->withPersonnel()->create();

        try {
            $service->review($this->teacherUser, $request, 'approved');
            $this->fail('Maker–Checker');
        } catch (AuthorizationException) {
        }

        try {
            $service->review($other, $request, 'approved');
            $this->fail('ไม่มีสิทธิ์พิจารณา');
        } catch (AuthorizationException) {
        }

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('ก่อนแก้', $this->teacher->fresh()->last_name);
    }

    public function test_hr_only_and_unknown_fields_cannot_be_requested_and_only_owner_submits(): void
    {
        $service = app(PersonnelChangeRequestService::class);

        foreach ([['status' => 'separated'], ['phone_mobile' => '0811111111'], ['nonexistent' => 'x']] as $payload) {
            $entity = array_key_first($payload) === 'phone_mobile' ? 'personnel_profile' : 'personnel';
            try {
                $service->submit($this->teacherUser, $this->teacher, $entity, $payload);
                $this->fail('ต้องปฏิเสธ '.array_key_first($payload));
            } catch (ValidationException) {
            }
        }

        $this->expectException(AuthorizationException::class);
        $service->submit($this->hr, $this->teacher, 'personnel', ['last_name' => 'แอบแก้']);
    }

    public function test_return_and_reject_need_a_note_and_do_not_apply(): void
    {
        $service = app(PersonnelChangeRequestService::class);
        $request = $service->submit($this->teacherUser, $this->teacher, 'personnel', ['last_name' => 'หลังแก้']);

        try {
            $service->review($this->hr, $request, 'returned');
            $this->fail('ต้องมีเหตุผล');
        } catch (ValidationException) {
        }

        $service->review($this->hr, $request, 'returned', 'แนบสำเนาทะเบียนสมรส');
        $this->assertSame('returned', $request->fresh()->status);
        $this->assertSame('ก่อนแก้', $this->teacher->fresh()->last_name);

        $this->expectException(ValidationException::class);
        $service->review($this->hr, $request->fresh(), 'approved');
    }
}
