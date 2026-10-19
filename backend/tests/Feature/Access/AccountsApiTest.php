<?php

namespace Tests\Feature\Access;

use App\Models\AuditLog;
use App\Models\Authz\Permission;
use App\Models\Authz\PermissionOverride;
use App\Models\MfaCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * หน้าบัญชีผู้ใช้ (ต้นแบบหน้า 7) — รายการตาม scope ที่ engine อนุญาต · ปุ่มจัดการตามสิทธิ์จริง · จัดการบัญชี (not_own)
 *
 * แทน tests/Legacy UserAccountGovernanceTest (ระงับบัญชีมี audit และตัดการใช้ API · จัดการบัญชีตนเองไม่ได้ · ไม่มีการลบผู้ใช้)
 */
class AccountsApiTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->f = CollegeFixture::build()->withAccessPersonas();
    }

    public function test_admin_lists_accounts_with_counts_mfa_and_rights_sources(): void
    {
        $this->f->users['teacher']->forceFill(['status' => User::STATUS_SUSPENDED, 'status_reason' => 'personnel:study_leave'])->saveQuietly();
        MfaCredential::create(['user_id' => $this->f->users['director']->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);
        Sanctum::actingAs($this->f->users['admin']);

        $response = $this->getJson('/api/access/accounts?per_page=50')->assertOk()
            ->assertJsonPath('meta.counts.all', 9)
            ->assertJsonPath('meta.counts.suspended', 1)
            ->assertJsonPath('meta.can.manage', true);

        $rows = collect($response->json('data'))->keyBy('username');
        $this->assertSame(['enabled' => true, 'required' => true], array_intersect_key($rows['director']['mfa'], ['enabled' => 1, 'required' => 1]));
        $this->assertFalse($rows['teacher']['mfa']['required']);
        $this->assertSame(['positions' => 2, 'acting' => 0, 'grants' => 0, 'overrides' => 0], $rows['director']['sources']);
        $this->assertSame(1, $rows['hr_staff']['sources']['grants']);
        $this->assertTrue($rows['teacher']['abilities']['manage']);
        $this->assertFalse($rows['admin']['abilities']['manage'], 'ปุ่มจัดการบัญชีตนเองไม่แสดง');
        $this->assertArrayNotHasKey('password', $rows['teacher']);
        $this->assertArrayNotHasKey('id', $rows['teacher']);

        $this->getJson('/api/access/accounts?status=suspended')->assertJsonCount(1, 'data')->assertJsonPath('data.0.username', 'teacher');
        $this->getJson('/api/access/accounts?q=S001')->assertJsonCount(1, 'data')->assertJsonPath('data.0.username', 'hr_staff');
    }

    public function test_read_only_viewers_see_the_list_without_manage_rights(): void
    {
        foreach (['hr', 'auditor'] as $persona) {
            Sanctum::actingAs($this->f->users[$persona]);
            $response = $this->getJson('/api/access/accounts')->assertOk()->assertJsonPath('meta.can.manage', false);
            $this->assertFalse(collect($response->json('data'))->contains(fn (array $row) => $row['abilities']['manage']), $persona);
            $this->postJson("/api/access/accounts/{$this->f->users['teacher']->uuid}/suspend", ['note' => 'x'])->assertForbidden();
        }

        foreach (['teacher', 'program_head', 'director'] as $persona) {
            Sanctum::actingAs($this->f->users[$persona]);
            $this->getJson('/api/access/accounts')->assertForbidden();
        }
    }

    public function test_list_is_filtered_by_the_engine_scope_including_unit_denies(): void
    {
        PermissionOverride::create([
            'user_id' => $this->f->users['hr']->id, 'permission_id' => Permission::where('name', 'access.account.view')->value('id'),
            'effect' => 'deny', 'org_unit_id' => $this->f->units['academic']->id, 'reason' => 'แยกหน้าที่', 'granted_by' => $this->f->users['admin']->id,
        ]);
        Sanctum::actingAs($this->f->users['hr']->fresh());

        $usernames = collect($this->getJson('/api/access/accounts?per_page=50')->assertOk()->json('data'))->pluck('username')->all();

        // ฝ่ายวิชาการ: teacher, program_head, deputy_academic, multi (ครูแผนกช่างยนต์) — ถูกตัดทั้งรายการและจำนวน
        foreach (['teacher', 'program_head', 'deputy_academic', 'multi'] as $hidden) {
            $this->assertNotContains($hidden, $usernames);
        }
        $this->assertContains('hr_staff', $usernames);
        $this->getJson('/api/access/accounts')->assertJsonPath('meta.counts.all', 5);
        $this->getJson("/api/access/accounts/{$this->f->users['teacher']->uuid}/access")->assertNotFound();
        $this->getJson("/api/access/accounts/{$this->f->users['section_head']->uuid}/access")->assertOk()->assertJsonStructure(['account', 'assignments', 'role_grants', 'overrides', 'recent_logins']);
    }

    public function test_admin_suspends_and_reactivates_with_audit_and_token_revocation(): void
    {
        $teacher = $this->f->users['teacher'];
        $token = $teacher->createToken('session')->plainTextToken;
        Sanctum::actingAs($this->f->users['admin']);

        $this->postJson("/api/access/accounts/{$teacher->uuid}/suspend", [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/access/accounts/{$teacher->uuid}/suspend", ['note' => 'แจ้งทำโทรศัพท์หาย'])
            ->assertOk()->assertJsonPath('status', 'suspended')->assertJsonPath('status_reason', 'manual');
        $this->assertSame(0, $teacher->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'account.status_changed')->where('resource_id', (string) $teacher->id)->where('reason', 'แจ้งทำโทรศัพท์หาย')->exists());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();

        Sanctum::actingAs($this->f->users['admin']);
        $this->postJson("/api/access/accounts/{$teacher->uuid}/reactivate", ['note' => 'ยืนยันตัวตนแล้ว'])->assertOk()->assertJsonPath('status', 'active');
        $this->postJson("/api/access/accounts/{$teacher->uuid}/unlock", ['note' => 'x'])->assertStatus(422);
    }

    public function test_reactivation_is_refused_when_the_personnel_record_is_not_working(): void
    {
        $this->f->people['teacher']->update(['status' => 'study_leave']);
        Sanctum::actingAs($this->f->users['admin']);

        $this->postJson("/api/access/accounts/{$this->f->users['teacher']->uuid}/reactivate", ['note' => 'ขอเปิด'])->assertStatus(422);
    }

    public function test_admin_unlocks_and_resets_mfa_but_never_their_own_account(): void
    {
        $director = $this->f->users['director'];
        $director->forceFill(['status' => User::STATUS_LOCKED, 'locked_until' => now()->addMinutes(20), 'failed_login_attempts' => 5])->saveQuietly();
        MfaCredential::create(['user_id' => $director->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);
        Sanctum::actingAs($this->f->users['admin']);

        $this->postJson("/api/access/accounts/{$director->uuid}/unlock", ['note' => 'ยืนยันทางโทรศัพท์'])->assertOk()->assertJsonPath('status', 'active');
        $this->assertSame(0, $director->fresh()->failed_login_attempts);
        $this->postJson("/api/access/accounts/{$director->uuid}/reset-mfa", ['note' => 'โทรศัพท์หาย'])->assertOk()->assertJsonPath('mfa.enabled', false);
        $this->assertTrue(AuditLog::where('action', 'account.mfa_reset')->exists());

        $this->postJson("/api/access/accounts/{$this->f->users['admin']->uuid}/suspend", ['note' => 'x'])->assertForbidden();
        $this->postJson("/api/access/accounts/{$this->f->users['admin']->uuid}/reset-mfa", ['note' => 'x'])->assertForbidden();
    }

    public function test_there_is_no_endpoint_that_deletes_an_account(): void
    {
        $deletes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/access/accounts') && in_array('DELETE', $route->methods(), true));

        $this->assertCount(0, $deletes, 'บัญชีปิดได้ด้วยสถานะเท่านั้น ประวัติการใช้งานต้องคงอยู่');
    }

    public function test_own_security_page_is_available_to_staff_and_lists_devices(): void
    {
        $teacher = $this->f->users['teacher'];
        $token = $this->postJson('/api/login', ['login' => $teacher->email, 'password' => 'password', 'device_id' => 'desk-1'])->json('access_token');

        $this->withToken($token)->getJson('/api/me/security')->assertOk()
            ->assertJsonPath('mfa.required', false)
            ->assertJsonCount(1, 'devices')
            ->assertJsonPath('recent_logins.0.result', 'success');

        $device = $teacher->knownDevices()->firstOrFail();
        $other = User::factory()->create();
        $foreign = $other->knownDevices()->create(['device_hash' => str_repeat('a', 64), 'first_seen_at' => now(), 'last_seen_at' => now(), 'trusted_until' => now()->addDay()]);
        $this->withToken($token)->deleteJson("/api/me/security/devices/{$foreign->uuid}")->assertNotFound();
        $this->withToken($token)->deleteJson("/api/me/security/devices/{$device->uuid}")->assertOk();
    }
}
