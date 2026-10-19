<?php

namespace Tests\Feature\Core;

use App\Contracts\Personnel\PersonnelFieldAccess;
use App\Models\FieldClassification;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\SensitiveAccessLog;
use App\Models\User;
use App\Services\Personnel\DenyByDefaultPersonnelFieldAccess;
use App\Services\Personnel\FieldClassificationRegistry;
use App\Services\Personnel\PersonnelPresenter;
use App\Services\Personnel\SensitiveReveal;
use Database\Seeders\FieldClassificationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\FakePersonnelFieldAccess;
use Tests\TestCase;

/**
 * CORE-02 Tests ขั้นต่ำ: ปิดบังถูกชั้นทุกระดับ L1–L5 · reveal ไม่มีเหตุผล = 422 · reveal ถูกบันทึก
 *
 * ชั้นที่แต่ละระดับเห็นเป็น matrix ตั้งต้นสำหรับ presenter — R-04 ให้ PermissionEngine เป็นผู้ตอบ visibleClasses()
 * ตามสิทธิ์ personnel.profile.view + scope จริง และ persona tests ของ R-04 ต้องได้ผลเท่าตารางนี้
 */
class PersonnelPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Personnel $subject;

    private FakePersonnelFieldAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        (new FieldClassificationSeeder)->run();

        $this->subject = Personnel::factory()->create(['personnel_code' => 'T900', 'first_name' => 'สมหญิง', 'last_name' => 'ข้อมูลจริง', 'academic_standing' => 'ชำนาญการ']);
        PersonnelProfile::create([
            'personnel_id' => $this->subject->id,
            'email_work' => 'somying@college.test',
            'phone_mobile' => '0812345678',
            'birth_date' => '1985-02-03',
            'emergency_contact_name' => 'สมชาย ข้อมูลจริง',
            'national_id' => '1103700012345',
            'bank_account' => '1234567890',
            'address_current' => '99 ถนนประชาชื่น',
        ]);

        $this->access = new FakePersonnelFieldAccess;
        $this->app->instance(PersonnelFieldAccess::class, $this->access);
    }

    /** @return array<string, array{array<int, string>, array<string, bool>}> persona => [ชั้นที่เห็น, ฟิลด์ตัวอย่าง => เห็นค่าจริงไหม] */
    public static function personas(): array
    {
        $full = ['public', 'internal', 'confidential'];

        return [
            'L1 ผู้อำนวยการ (ทั้งวิทยาลัย)' => [$full, ['first_name' => true, 'personnel_code' => true, 'profile.phone_mobile' => true, 'profile.birth_date' => true]],
            'L2 รองผู้อำนวยการ (ใน subtree)' => [$full, ['first_name' => true, 'personnel_code' => true, 'profile.phone_mobile' => true, 'profile.birth_date' => true]],
            'L3 หัวหน้าแผนก (ใน subtree)' => [['public', 'internal'], ['first_name' => true, 'personnel_code' => true, 'profile.phone_mobile' => true, 'profile.birth_date' => false]],
            'L4 หัวหน้าสาขางาน (ใน subtree)' => [['public', 'internal'], ['first_name' => true, 'personnel_code' => true, 'profile.phone_mobile' => true, 'profile.birth_date' => false]],
            'L5 บุคลากรอื่น' => [['public'], ['first_name' => true, 'personnel_code' => false, 'profile.phone_mobile' => false, 'profile.birth_date' => false]],
        ];
    }

    /**
     * @param  array<int, string>  $classes
     * @param  array<string, bool>  $expect
     */
    #[DataProvider('personas')]
    public function test_presenter_masks_by_class_for_every_level(array $classes, array $expect): void
    {
        $viewer = User::factory()->withPersonnel()->create();
        $this->access->grant($viewer, ['classes' => $classes, 'reveal' => true]);

        $data = app(PersonnelPresenter::class)->present($this->subject->fresh(['type', 'profile']), $viewer);

        foreach ($expect as $path => $visible) {
            [$section, $field] = str_starts_with($path, 'profile.') ? ['profile', substr($path, 8)] : ['personnel', $path];
            if ($visible) {
                $this->assertNotNull($data[$section][$field], "{$path} ต้องเห็นค่าจริง");
                $this->assertNotContains($path, $data['masked']);
            } else {
                $this->assertNull($data[$section][$field], "{$path} ต้องถูกปิด");
                $this->assertContains($path, $data['masked']);
            }
        }

        // sensitive ไม่ออกจาก presenter เป็นค่าจริงในทุกระดับ แม้มีสิทธิ์ reveal
        $this->assertSame('••••••••2345', $data['profile']['national_id']);
        $this->assertSame('••••••7890', $data['profile']['bank_account']);
        $this->assertContains('profile.national_id', $data['masked']);
        $this->assertStringNotContainsString('1103700012345', json_encode($data));
        $this->assertStringNotContainsString('ประชาชื่น', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function test_unclassified_fields_are_treated_as_sensitive(): void
    {
        FieldClassification::where('entity', 'personnel_profile')->where('field', 'phone_mobile')->delete();
        app(FieldClassificationRegistry::class)->flush();

        $viewer = User::factory()->create();
        $this->access->grant($viewer, ['classes' => ['public', 'internal', 'confidential']]);

        $data = app(PersonnelPresenter::class)->present($this->subject->fresh(['profile']), $viewer);

        $this->assertNotSame('0812345678', $data['profile']['phone_mobile']);
        $this->assertContains('profile.phone_mobile', $data['masked']);
    }

    public function test_default_binding_shows_others_public_only_and_owner_their_own_record(): void
    {
        $this->app->instance(PersonnelFieldAccess::class, new DenyByDefaultPersonnelFieldAccess);
        $owner = User::factory()->create(['personnel_id' => $this->subject->id]);
        $other = User::factory()->withPersonnel()->create();

        $asOther = app(PersonnelPresenter::class)->present($this->subject->fresh(['profile']), $other);
        $asOwner = app(PersonnelPresenter::class)->present($this->subject->fresh(['profile']), $owner);

        $this->assertNull($asOther['personnel']['personnel_code']);
        $this->assertNull($asOther['profile']['phone_mobile']);
        $this->assertFalse($asOther['can_reveal']);
        $this->assertSame('T900', $asOwner['personnel']['personnel_code']);
        $this->assertSame('1985-02-03', $asOwner['profile']['birth_date']);
        $this->assertTrue($asOwner['can_reveal']);
    }

    public function test_reveal_without_reason_is_422_and_not_logged(): void
    {
        $viewer = User::factory()->create();
        $this->access->grant($viewer, ['reveal' => true]);

        foreach ([null, '', 'สั้นไป'] as $reason) {
            try {
                app(SensitiveReveal::class)->reveal($this->subject, ['national_id'], $reason, $viewer);
                $this->fail('ต้องระบุเหตุผล');
            } catch (ValidationException $e) {
                $this->assertSame(422, $e->status);
                $this->assertArrayHasKey('reason', $e->errors());
            }
        }

        $this->assertSame(0, SensitiveAccessLog::count());
    }

    public function test_reveal_only_sensitive_fields_and_only_with_permission(): void
    {
        $viewer = User::factory()->create();

        try {
            app(SensitiveReveal::class)->reveal($this->subject, ['national_id'], 'ตรวจสอบสิทธิสวัสดิการรักษาพยาบาล', $viewer);
            $this->fail('ไม่มีสิทธิ์ reveal');
        } catch (AuthorizationException) {
        }

        $this->access->grant($viewer, ['reveal' => true]);
        $this->expectException(ValidationException::class);
        app(SensitiveReveal::class)->reveal($this->subject, ['phone_mobile'], 'ตรวจสอบสิทธิสวัสดิการรักษาพยาบาล', $viewer);
    }

    public function test_reveal_is_logged_before_values_are_returned(): void
    {
        $viewer = User::factory()->create();
        $this->access->grant($viewer, ['reveal' => true]);

        $values = app(SensitiveReveal::class)->reveal(
            $this->subject->fresh('profile'),
            ['national_id', 'bank_account'],
            'จัดทำเอกสารเบิกค่ารักษาพยาบาล',
            $viewer,
            ['ip' => '10.0.0.5', 'user_agent' => 'phpunit'],
        );

        $this->assertSame(['national_id' => '1103700012345', 'bank_account' => '1234567890'], $values);

        $log = SensitiveAccessLog::sole();
        $this->assertSame($viewer->id, $log->actor_user_id);
        $this->assertSame($this->subject->id, $log->personnel_id);
        $this->assertSame(['national_id', 'bank_account'], $log->fields);
        $this->assertSame('จัดทำเอกสารเบิกค่ารักษาพยาบาล', $log->reason);
        $this->assertSame('10.0.0.5', $log->ip);
    }

    public function test_every_presented_field_has_a_classification(): void
    {
        $registry = app(FieldClassificationRegistry::class);
        $data = app(PersonnelPresenter::class)->present($this->subject->fresh(['profile']), User::factory()->create());

        foreach (array_keys($data['personnel']) as $field) {
            if (! in_array($field, ['type', 'full_name'], true)) {
                $this->assertTrue($registry->isClassified('personnel', $field), "personnel.{$field} ยังไม่จัดชั้น");
            }
        }
        foreach (array_keys($data['profile']) as $field) {
            $this->assertTrue($registry->isClassified('personnel_profile', $field), "personnel_profile.{$field} ยังไม่จัดชั้น");
        }
        foreach (PersonnelProfile::SENSITIVE as $field) {
            $this->assertSame('sensitive', $registry->classOf('personnel_profile', $field));
        }
    }
}
