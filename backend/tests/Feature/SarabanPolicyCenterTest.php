<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\NumberSeries;
use App\Models\Saraban\Policy;
use App\Models\Saraban\PolicyVersion;
use App\Models\User;
use App\Services\Saraban\PolicyCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-16 — ศูนย์นโยบาย ตารางอำนาจ และการตั้งชุดเลขหนังสือ (SoT §6, §9, §18)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **versioned / published / rollback / audit ครบทั้งสี่ข้อ** (SoT §18)
 *   2. **เผยแพร่แล้วแก้ไม่ได้** — เปลี่ยนกติกาต้องสร้างเวอร์ชันใหม่
 *   3. **ย้อนกลับ = เวอร์ชันใหม่** ไม่ใช่ปลุกของเก่า ไทม์ไลน์เดินหน้าอย่างเดียว
 *   4. **นโยบายมีผลกับพฤติกรรมจริง** — ไม่ใช่ตารางที่ไม่มีใครอ่าน (ปิด RISK-32)
 *   5. **เผยแพร่ต้องยืนยันตัวตนซ้ำ** (SoT §24)
 *   6. **ตารางอำนาจอ่านจากตัวตัดสินเดียวกับที่ระบบใช้จริง**
 *   7. **ชุดเลขที่ออกเลขไปแล้วลบไม่ได้** (ปิด RISK-21)
 */
class SarabanPolicyCenterTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private OrgUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $this->unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-POLICY',
            'name' => 'ฝ่ายอำนวยการ',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    /** @param array<int,string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create([
            'org_unit_id' => $this->unit->id,
            'password' => Hash::make(self::PASSWORD),
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    private function actAs(User $user, bool $recentlyAuthenticated = true): void
    {
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('test-token');
        $token->accessToken->forceFill([
            'last_authenticated_at' => $recentlyAuthenticated ? now() : now()->subHours(3),
        ])->save();

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken]);
    }

    private function manager(): User
    {
        return $this->userWith(['saraban.document.view', 'saraban.policy.manage']);
    }

    // ── ศูนย์นโยบาย (SoT §18) ───────────────────────────────────────────────

    /** ระบบต้องทำงานได้ตั้งแต่วันแรก ก่อนที่ผู้ดูแลจะตั้งนโยบายใด ๆ */
    public function test_defaults_apply_before_any_policy_is_published(): void
    {
        $this->actAs($this->manager());

        $policies = $this->getJson('/api/saraban/policies')->assertOk()->json('policies');

        $layout = collect($policies)->firstWhere('key', Policy::KEY_DOCUMENT_LAYOUT);

        $this->assertNull($layout['published_version']);
        $this->assertSame(470, $layout['effective']['qr']['x']);
    }

    public function test_a_draft_does_not_take_effect_until_it_is_published(): void
    {
        $manager = $this->manager();
        $this->actAs($manager);

        $this->postJson('/api/saraban/policies/'.Policy::KEY_DOCUMENT_LAYOUT.'/draft', [
            'payload' => ['qr' => ['page' => 1, 'x' => 100, 'y' => 100, 'size' => 60]],
        ])->assertCreated();

        $this->assertSame(470, app(PolicyCenter::class)->value(Policy::KEY_DOCUMENT_LAYOUT, 'qr.x'));

        $this->postJson('/api/saraban/policies/'.Policy::KEY_DOCUMENT_LAYOUT.'/publish')->assertOk();

        $this->assertSame(100, app(PolicyCenter::class)->value(Policy::KEY_DOCUMENT_LAYOUT, 'qr.x'));
    }

    /** ร่างซ้อนกันหลายฉบับทำให้ไม่มีใครรู้ว่าฉบับไหนคือสิ่งที่กำลังจะประกาศ */
    public function test_saving_a_draft_twice_updates_the_same_draft(): void
    {
        $this->actAs($this->manager());

        foreach ([100, 200] as $x) {
            $this->postJson('/api/saraban/policies/'.Policy::KEY_DOCUMENT_LAYOUT.'/draft', [
                'payload' => ['qr' => ['x' => $x]],
            ])->assertCreated();
        }

        $this->assertSame(1, PolicyVersion::query()->count());
        $this->assertSame(200, PolicyVersion::query()->firstOrFail()->payload['qr']['x']);
    }

    public function test_a_published_version_cannot_be_edited(): void
    {
        $this->actAs($this->manager());

        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/draft', [
            'payload' => ['acknowledgement_due_days' => 7],
        ])->assertCreated();
        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/publish')->assertOk();

        $published = PolicyVersion::query()->where('status', PolicyVersion::STATUS_PUBLISHED)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $published->forceFill(['payload' => ['acknowledgement_due_days' => 99]])->save();
    }

    public function test_publishing_archives_the_previous_version_instead_of_deleting_it(): void
    {
        $this->actAs($this->manager());

        foreach ([5, 9] as $days) {
            $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/draft', [
                'payload' => ['acknowledgement_due_days' => $days],
            ])->assertCreated();
            $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/publish')->assertOk();
        }

        $history = $this->getJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/history')
            ->assertOk()
            ->json('versions');

        $this->assertCount(2, $history);
        $this->assertSame(PolicyVersion::STATUS_PUBLISHED, $history[0]['status']);
        $this->assertSame(PolicyVersion::STATUS_ARCHIVED, $history[1]['status']);
    }

    /**
     * ย้อนกลับต้องสร้างเวอร์ชันใหม่ ไม่ใช่ปลุกเวอร์ชันเก่า
     *
     * ถ้าปลุกของเก่า ไทม์ไลน์จะมีช่วงที่เวอร์ชันเดียวมีผลสองครั้ง
     * โดยไม่มีทางบอกได้ว่าหนังสือฉบับหนึ่งอยู่ในช่วงไหน
     */
    public function test_rolling_back_publishes_a_new_version_with_the_old_content(): void
    {
        $this->actAs($this->manager());

        foreach ([5, 9] as $days) {
            $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/draft', [
                'payload' => ['acknowledgement_due_days' => $days],
            ])->assertCreated();
            $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/publish')->assertOk();
        }

        $rolled = $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/rollback', [
            'version_no' => 1,
        ])->assertOk()->json('version');

        $this->assertSame(3, $rolled['version_no']);
        $this->assertSame(5, $rolled['payload']['acknowledgement_due_days']);

        // เวอร์ชันที่ 1 ยังอยู่ในคลังตามเดิม ไม่ถูกปลุกขึ้นมาใช้ซ้ำ
        $this->assertSame(
            PolicyVersion::STATUS_ARCHIVED,
            PolicyVersion::query()->where('version_no', 1)->firstOrFail()->status,
        );
        $this->assertSame(5, app(PolicyCenter::class)->value(Policy::KEY_DISTRIBUTION, 'acknowledgement_due_days'));
    }

    public function test_publishing_and_rolling_back_are_written_to_the_central_audit_log(): void
    {
        $this->actAs($this->manager());

        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/draft', [
            'payload' => ['acknowledgement_due_days' => 5],
        ])->assertCreated();
        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/publish')->assertOk();

        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/draft', [
            'payload' => ['acknowledgement_due_days' => 9],
        ])->assertCreated();
        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/publish')->assertOk();

        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/rollback', ['version_no' => 1])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'saraban.policy.published']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'saraban.policy.rolled_back']);
    }

    /** SoT §24 — การเผยแพร่นโยบายเป็นการกระทำที่อ่อนไหว */
    public function test_publishing_requires_recent_authentication(): void
    {
        $manager = $this->manager();
        $this->actAs($manager);

        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/draft', [
            'payload' => ['acknowledgement_due_days' => 5],
        ])->assertCreated();

        $this->actAs($manager, recentlyAuthenticated: false);

        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/publish')->assertStatus(423);
    }

    public function test_reading_policies_does_not_require_the_manage_permission(): void
    {
        $this->actAs($this->userWith(['saraban.document.view']));

        $this->getJson('/api/saraban/policies')->assertOk();
        $this->postJson('/api/saraban/policies/'.Policy::KEY_DISTRIBUTION.'/draft', ['payload' => []])
            ->assertForbidden();
    }

    public function test_an_unknown_policy_key_is_refused(): void
    {
        $this->actAs($this->manager());

        $this->postJson('/api/saraban/policies/made-up/draft', ['payload' => []])->assertNotFound();
    }

    // ── ตารางอำนาจหน้าที่ (SoT §6) ──────────────────────────────────────────

    /** ทุกคนตรวจสอบอำนาจของตนเองได้โดยไม่ต้องขอสิทธิ์เพิ่ม */
    public function test_anyone_can_read_their_own_authority(): void
    {
        $user = $this->userWith(['saraban.document.view']);
        $this->actAs($user);

        $matrix = $this->getJson('/api/saraban/authority')->assertOk()->json('matrix');

        $this->assertTrue($matrix['abilities']['saraban.document.view']['granted']);
        $this->assertFalse($matrix['abilities']['saraban.signature.sign']['granted']);
    }

    public function test_reading_someone_elses_authority_needs_the_authority_permission(): void
    {
        $other = $this->userWith(['saraban.signature.sign']);

        $this->actAs($this->userWith(['saraban.document.view']));
        $this->getJson('/api/saraban/authority?user_id='.$other->id)->assertForbidden();

        $this->actAs($this->userWith(['saraban.authority.view']));
        $this->getJson('/api/saraban/authority?user_id='.$other->id)->assertOk();
    }

    /** รายชื่อความสามารถอ่านจาก manifest ที่เดียว ไม่เขียนซ้ำใน controller */
    public function test_the_matrix_covers_every_ability_in_the_module_manifest(): void
    {
        $this->actAs($this->userWith(['saraban.document.view']));

        $abilities = array_keys($this->getJson('/api/saraban/authority')->assertOk()->json('matrix.abilities'));

        $this->assertContains('saraban.inbox.view', $abilities);
        $this->assertContains('saraban.policy.manage', $abilities);
    }

    public function test_holders_can_be_listed_for_an_ability(): void
    {
        $signer = $this->userWith(['saraban.signature.sign']);

        $this->actAs($this->userWith(['saraban.authority.view']));

        $holders = $this->getJson('/api/saraban/authority/holders?ability=saraban.signature.sign')
            ->assertOk()
            ->json('holders');

        $this->assertContains($signer->id, array_column($holders, 'user_id'));
    }

    public function test_an_ability_outside_the_module_is_refused(): void
    {
        $this->actAs($this->userWith(['saraban.authority.view']));

        $this->getJson('/api/saraban/authority/holders?ability=system.setting.manage')->assertStatus(422);
    }

    // ── ชุดเลขหนังสือ · ปิด RISK-21 (SoT §9) ────────────────────────────────

    public function test_an_administrator_can_create_a_number_series_from_the_screen(): void
    {
        $this->actAs($this->userWith(['saraban.config.manage']));

        $this->postJson('/api/saraban/number-series', [
            'code' => 'outgoing-main',
            'name' => 'หนังสือส่งออก',
            'category' => DocumentType::CATEGORY_OUTGOING,
            'prefix' => 'ศธ 04001',
        ])->assertCreated();

        $this->assertDatabaseHas('saraban_number_series', ['code' => 'outgoing-main']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'saraban.number_series.created']);
    }

    public function test_a_series_that_has_issued_numbers_cannot_be_deleted(): void
    {
        $series = NumberSeries::create([
            'code' => 'outgoing-main',
            'name' => 'หนังสือส่งออก',
            'category' => DocumentType::CATEGORY_OUTGOING,
            'prefix' => 'ศธ 04001',
        ]);

        DocumentNumber::create([
            'number_series_id' => $series->id,
            'buddhist_year' => 2569,
            'running_number' => 1,
            'formatted' => 'ศธ 04001/1',
            'status' => 'issued',
        ]);

        $this->actAs($this->userWith(['saraban.config.manage']));

        $this->deleteJson("/api/saraban/number-series/{$series->id}")->assertStatus(422);
        $this->assertDatabaseHas('saraban_number_series', ['id' => $series->id]);
    }

    public function test_managing_number_series_requires_the_config_permission(): void
    {
        $this->actAs($this->userWith(['saraban.document.view']));

        $this->getJson('/api/saraban/number-series')->assertForbidden();
    }
}
