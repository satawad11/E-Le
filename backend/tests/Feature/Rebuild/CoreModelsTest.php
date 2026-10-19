<?php

namespace Tests\Feature\Rebuild;

use App\Models\Assignment;
use App\Models\Authz\PermissionAuditLog;
use App\Models\LoginEvent;
use App\Models\MfaCredential;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\Position;
use App\Models\SensitiveAccessLog;
use App\Models\User;
use Database\Seeders\OrganizationBaselineSeeder;
use Database\Seeders\OrgUnitTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * R02-10 — Model ใหม่ของ core: เข้ารหัสฟิลด์อ่อนไหว · บันทึกเพิ่มได้อย่างเดียว · บัญชีตาม CORE-03
 */
class CoreModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_profile_fields_are_encrypted_at_rest_and_searchable_by_hash(): void
    {
        $personnel = Personnel::factory()->create();
        $profile = PersonnelProfile::create([
            'personnel_id' => $personnel->id,
            'national_id' => '1-1037-00012-34-5',
            'bank_account' => '123-4-56789-0',
            'address_current' => '99 ถนนตัวอย่าง',
            'phone_mobile' => '0800000000',
        ]);

        $raw = DB::table('personnel_profiles')->where('id', $profile->id)->first();
        foreach (['national_id', 'bank_account', 'address_current'] as $field) {
            $this->assertNotSame($profile->{$field}, $raw->{$field}, "{$field} ต้องเข้ารหัสที่ฐานข้อมูล");
            $this->assertStringNotContainsString('99', (string) $raw->address_current);
        }
        $this->assertSame('0800000000', $raw->phone_mobile, 'ฟิลด์ internal เก็บแบบปกติ');

        $this->assertSame($profile->id, PersonnelProfile::where('national_id_hash', PersonnelProfile::hashNationalId('1103700012345'))->value('id'));

        $serialized = $profile->fresh()->toArray();
        foreach (PersonnelProfile::SENSITIVE as $field) {
            $this->assertArrayNotHasKey($field, $serialized, "{$field} ต้องไม่ถูก serialize ออก API");
        }
    }

    public function test_mfa_secrets_are_encrypted_and_hidden(): void
    {
        $user = User::factory()->create();
        $mfa = MfaCredential::create(['user_id' => $user->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'recovery_codes' => ['a1', 'b2']]);

        $raw = DB::table('mfa_credentials')->where('id', $mfa->id)->first();
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw->secret);
        $this->assertSame(['a1', 'b2'], $mfa->fresh()->recovery_codes);
        $this->assertArrayNotHasKey('secret', $mfa->toArray());
    }

    public function test_append_only_logs_cannot_be_updated_or_deleted(): void
    {
        $user = User::factory()->create();
        $personnel = Personnel::factory()->create();

        $logs = [
            LoginEvent::create(['user_id' => $user->id, 'username_attempted' => $user->username, 'result' => 'success']),
            SensitiveAccessLog::create(['actor_user_id' => $user->id, 'personnel_id' => $personnel->id, 'fields' => ['national_id'], 'reason' => 'ตรวจสอบสิทธิสวัสดิการ']),
            PermissionAuditLog::create(['actor_user_id' => $user->id, 'action' => 'grant.created', 'subject_type' => 'role_grant', 'subject_id' => 1]),
        ];

        foreach ($logs as $log) {
            $this->assertNotNull($log->fresh()->created_at);

            try {
                $log->update(['created_at' => now()->subYear()]);
                $this->fail(get_class($log).' ต้องแก้ไขไม่ได้');
            } catch (LogicException) {
            }

            try {
                $log->delete();
                $this->fail(get_class($log).' ต้องลบไม่ได้');
            } catch (LogicException) {
            }
        }

        $this->assertSame(1, LoginEvent::count());
    }

    public function test_assignment_effective_on_scope_respects_dates(): void
    {
        $this->seed([OrgUnitTypeSeeder::class, OrganizationBaselineSeeder::class]);
        $unit = OrgUnit::where('code', 'DIV-ACADEMIC')->firstOrFail();
        $position = Position::create(['code' => 'TEACHER', 'name' => 'ครู', 'kind' => Position::KIND_LINE]);
        $personnel = Personnel::factory()->create();

        $closed = Assignment::create(['personnel_id' => $personnel->id, 'kind' => Assignment::KIND_PRIMARY, 'org_unit_id' => $unit->id,
            'position_id' => $position->id, 'effective_from' => '2025-01-01', 'effective_to' => '2025-12-31']);
        $current = Assignment::create(['personnel_id' => $personnel->id, 'kind' => Assignment::KIND_PRIMARY, 'org_unit_id' => $unit->id,
            'position_id' => $position->id, 'effective_from' => '2026-01-01']);

        $this->assertSame([$closed->id], Assignment::effectiveOn('2025-06-01')->pluck('id')->all());
        $this->assertSame([$current->id], Assignment::effectiveOn('2026-10-19')->pluck('id')->all());
        $this->assertNotEmpty($current->uuid);
    }

    public function test_user_serialization_hides_internal_ids_and_secrets(): void
    {
        $user = User::factory()->withPersonnel()->create();

        $data = $user->toArray();
        foreach (['id', 'personnel_id', 'password', 'remember_token'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        $this->assertSame($user->personnel->id, $user->personnel_id);
        $this->assertSame($user->id, $user->personnel->user->id);
    }

    public function test_login_by_email_or_personnel_code_for_active_accounts_only(): void
    {
        $user = User::factory()->withPersonnel()->create(['email' => 'teacher@example.test', 'password' => 'correct-horse-battery']);
        $code = $user->personnel->personnel_code;

        $this->postJson('/api/login', ['email' => 'teacher@example.test', 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('user.uuid', $user->uuid)
            ->assertJsonPath('user.permission_names', []);

        $this->postJson('/api/login', ['login' => $code, 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('user.uuid', $user->uuid);

        $this->postJson('/api/login', ['login' => $code, 'password' => 'wrong-password'])->assertStatus(422);

        $this->assertNotNull($user->fresh()->last_login_at);

        foreach ([User::STATUS_PENDING, User::STATUS_SUSPENDED, User::STATUS_SEPARATED, User::STATUS_LOCKED] as $status) {
            $user->forceFill(['status' => $status])->saveQuietly();
            $this->postJson('/api/login', ['email' => 'teacher@example.test', 'password' => 'correct-horse-battery'])
                ->assertStatus(422);
        }
    }

    public function test_national_id_login_is_disabled_by_default(): void
    {
        $this->assertFalse(config('auth.allow_national_id_login'));

        $user = User::factory()->withPersonnel()->create(['password' => 'correct-horse-battery']);
        PersonnelProfile::create(['personnel_id' => $user->personnel_id, 'national_id' => '1103700012345']);

        $this->postJson('/api/login', ['login' => '1103700012345', 'password' => 'correct-horse-battery'])
            ->assertStatus(422);
    }

    public function test_national_id_login_works_only_when_enabled(): void
    {
        config(['auth.allow_national_id_login' => true]);

        $user = User::factory()->withPersonnel()->create(['password' => 'correct-horse-battery']);
        PersonnelProfile::create(['personnel_id' => $user->personnel_id, 'national_id' => '1103700012345']);

        $this->postJson('/api/login', ['login' => '1103700012345', 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('user.uuid', $user->uuid);
    }

    public function test_tokens_are_revoked_when_account_leaves_active_status(): void
    {
        $user = User::factory()->withPersonnel()->create();
        $user->createToken('t');
        $this->assertSame(1, $user->tokens()->count());

        $user->update(['status' => User::STATUS_SEPARATED]);

        $this->assertSame(0, $user->tokens()->count());
    }
}
