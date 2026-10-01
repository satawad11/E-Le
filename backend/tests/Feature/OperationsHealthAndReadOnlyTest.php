<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskHeartbeat;
use App\Models\SystemException;
use App\Models\User;
use App\Services\Operations\ComplianceEvidence;
use App\Services\Operations\ExceptionQueue;
use App\Services\Operations\FeatureFlags;
use App\Services\Operations\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * INT-21 — สุขภาพระบบ โหมดอ่านอย่างเดียว และหลักฐานการกู้คืน (SoT §27)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **จุดตรวจสาธารณะไม่เปิดเผยรายละเอียดภายใน** — monitor ต้องเรียกได้
 *      แต่ต้องไม่บอกผู้โจมตีว่าระบบใช้อะไรและตรงไหนอ่อนแอ
 *   2. **ตรวจไม่ได้ ≠ ปกติ** — ตัวตรวจที่กลืนข้อผิดพลาดแล้วบอกว่าปกติ
 *      อันตรายกว่าการไม่มีตัวตรวจ
 *   3. **โหมดอ่านอย่างเดียว: อ่านได้ เขียนไม่ได้** และไม่ยกเว้นใครทั้งสิ้น
 *   4. **503 ไม่ใช่ 403** — ผู้เรียกต้องแยก "ไม่มีสิทธิ์" ออกจาก "ลองใหม่ทีหลัง"
 *   5. **หลักฐานการกู้คืนหมดอายุได้** — กู้ได้เมื่อปีที่แล้วไม่ได้บอกอะไรวันนี้
 */
class OperationsHealthAndReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int,string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    // ── จุดตรวจสุขภาพ (SoT §27) ─────────────────────────────────────────────

    /** monitor ภายนอกต้องเรียกได้โดยไม่มีบัญชีผู้ใช้ (ปิด RISK-54 ในส่วนที่โค้ดทำได้) */
    public function test_the_public_health_endpoint_needs_no_account(): void
    {
        $body = $this->getJson('/api/health')->json();

        $this->assertContains($body['status'], [SystemHealth::OK, SystemHealth::DEGRADED, SystemHealth::DOWN]);
    }

    /** **ข้อสำคัญ** — จุดตรวจสาธารณะต้องไม่เปิดเผยสถาปัตยกรรมภายใน */
    public function test_the_public_health_endpoint_leaks_nothing_about_the_internals(): void
    {
        $body = $this->getJson('/api/health')->assertOk()->json();

        $this->assertSame(['status'], array_keys($body));

        $raw = json_encode($body);
        foreach (['mysql', 'redis', 'driver', 'private_uploads', 'latency', 'error'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw);
        }
    }

    public function test_the_detailed_health_endpoint_requires_a_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/operations/health')
            ->assertForbidden();

        $this->actingAs($this->userWith(['system.operations.view']))
            ->getJson('/api/admin/operations/health')
            ->assertOk();
    }

    public function test_the_detailed_health_report_covers_every_area_the_master_requires(): void
    {
        $checks = $this->actingAs($this->userWith(['system.operations.view']))
            ->getJson('/api/admin/operations/health')
            ->assertOk()
            ->json('checks');

        $names = array_column($checks, 'name');

        foreach ([
            'database', 'cache', 'queue', 'storage',
            'pdf_renderer', 'notification_providers', 'scheduler', 'exceptions',
        ] as $area) {
            $this->assertContains($area, $names, "การตรวจสุขภาพต้องครอบคลุม {$area}");
        }
    }

    /** ยังไม่เคยมีชีพจรเลย = ไม่รู้ ไม่ใช่ปกติ */
    public function test_a_system_that_never_ran_its_scheduler_is_not_reported_healthy(): void
    {
        $checks = collect(app(SystemHealth::class)->checks())->keyBy('name');

        $this->assertSame(SystemHealth::DEGRADED, $checks['scheduler']['status']);
    }

    /** งานตามเวลาที่ตายแล้ว = ระบบใช้งานไม่ได้จริง แม้หน้าเว็บยังเปิดได้ */
    public function test_a_dead_scheduler_makes_the_whole_system_report_down(): void
    {
        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();

        ScheduledTaskHeartbeat::query()->update(['last_run_at' => now()->subDay()]);

        $this->assertSame(SystemHealth::DOWN, app(SystemHealth::class)->summary()['status']);
        $this->getJson('/api/health')->assertStatus(503);
    }

    /** ข้อผิดพลาดระดับวิกฤตที่ค้างอยู่ทำให้สถานะรวมเป็น degraded */
    public function test_an_open_critical_incident_degrades_the_summary(): void
    {
        /*
         * ตรวจที่ชิ้น `exceptions` โดยตรง ไม่ใช่สถานะรวม — สถานะรวมในชุดทดสอบ
         * เป็น degraded อยู่แล้วจากชิ้นอื่น (เช่นยังไม่ได้ติดตั้งฟอนต์ไทย)
         * การยืนยันด้วยสถานะรวมจึงผ่านได้ด้วยเหตุผลที่ผิด
         */
        $before = collect(app(SystemHealth::class)->checks())->keyBy('name');
        $this->assertSame(SystemHealth::OK, $before['exceptions']['status']);

        app(ExceptionQueue::class)->raise(
            type: 'test.critical',
            title: 'เรื่องวิกฤต',
            severity: SystemException::SEVERITY_CRITICAL,
            dedupeKey: 'test.critical',
        );

        $after = collect(app(SystemHealth::class)->checks())->keyBy('name');
        $this->assertSame(SystemHealth::DEGRADED, $after['exceptions']['status']);
        $this->assertSame(1, $after['exceptions']['detail']['open_critical']);
        $this->assertSame(SystemHealth::DEGRADED, app(SystemHealth::class)->summary()['status']);
    }

    // ── โหมดอ่านอย่างเดียว (SoT §27) ────────────────────────────────────────

    public function test_read_only_mode_blocks_writes_but_keeps_reads_working(): void
    {
        $user = $this->userWith(['document.template.view', 'document.template.manage']);

        app(FeatureFlags::class)->set('system.read_only', true, $user);

        // อ่านได้ตามปกติ — งานราชการหยุดรอไม่ได้
        $this->actingAs($user)->getJson('/api/admin/document-engine/templates')->assertOk();

        // เขียนไม่ได้ และต้องเป็น 503 ไม่ใช่ 403
        $response = $this->actingAs($user)->postJson('/api/admin/document-engine/templates', []);
        $response->assertStatus(503);
        $response->assertHeader('Retry-After');
        $this->assertTrue($response->json('read_only'));
    }

    /** ไม่ยกเว้นผู้ดูแลสูงสุด — ไม่งั้นข้อมูลที่เพิ่งกู้มาจะถูกทับ */
    public function test_read_only_mode_does_not_exempt_the_super_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));

        app(FeatureFlags::class)->set('system.read_only', true, $admin);

        $this->actingAs($admin)->postJson('/api/admin/document-engine/templates', [])->assertStatus(503);
    }

    /** ต้องปิดโหมดนี้ได้จากในระบบ ไม่งั้นต้องแก้ฐานข้อมูลด้วยมือ */
    public function test_the_flag_that_turns_read_only_off_still_works_while_it_is_on(): void
    {
        $admin = $this->userWith(['system.feature_flags.manage']);

        app(FeatureFlags::class)->set('system.read_only', true, $admin);

        $this->actingAs($admin)
            ->putJson('/api/admin/feature-flags/system.read_only', ['enabled' => false])
            ->assertOk();

        $this->assertFalse(app(FeatureFlags::class)->enabled('system.read_only'));
    }

    public function test_logging_out_still_works_in_read_only_mode(): void
    {
        $user = $this->userWith([]);

        app(FeatureFlags::class)->set('system.read_only', true, $user);

        $this->actingAs($user)->postJson('/api/logout')->assertOk();
    }

    public function test_read_only_mode_is_off_by_default(): void
    {
        $this->assertFalse(app(FeatureFlags::class)->enabled('system.read_only'));

        $user = $this->userWith(['document.template.view', 'document.template.manage']);
        $this->actingAs($user)->postJson('/api/admin/document-engine/templates', [])->assertStatus(422);
    }

    // ── หลักฐานการกู้คืน (SoT §23, §27) ─────────────────────────────────────

    /** ยังไม่เคยทดสอบกู้คืน = ไม่มีหลักฐาน และกรอกเองไม่ได้ */
    public function test_restore_reports_no_evidence_until_the_check_actually_runs(): void
    {
        $topics = collect(app(ComplianceEvidence::class)->collect())->keyBy('key');

        $this->assertSame(ComplianceEvidence::NO_EVIDENCE, $topics['restore']['status']);
        $this->assertStringContainsString('backup:verify-restore', $topics['restore']['method']);
    }

    public function test_restore_reports_pass_only_after_a_real_verification(): void
    {
        ScheduledTaskHeartbeat::create([
            'task_key' => 'backup:verify-restore',
            'last_run_at' => now()->subDay(),
            'expected_interval_minutes' => 10080,
            'meta' => ['restore_seconds' => 12.4, 'archive' => '/backups/2026-09-05.zip'],
        ]);

        $topics = collect(app(ComplianceEvidence::class)->collect())->keyBy('key');

        $this->assertSame(ComplianceEvidence::PASS, $topics['restore']['status']);
        $this->assertSame(12.4, $topics['restore']['evidence']['restore_seconds']);
    }

    /**
     * **หลักฐานหมดอายุได้** — ไฟล์สำรองที่กู้ได้เมื่อปีที่แล้วไม่ได้บอกอะไร
     * เกี่ยวกับไฟล์ของสัปดาห์นี้
     */
    public function test_a_stale_restore_verification_drops_back_to_partial(): void
    {
        ScheduledTaskHeartbeat::create([
            'task_key' => 'backup:verify-restore',
            'last_run_at' => now()->subDays(120),
            'expected_interval_minutes' => 10080,
            'meta' => ['restore_seconds' => 12.4],
        ]);

        $topics = collect(app(ComplianceEvidence::class)->collect())->keyBy('key');

        $this->assertSame(ComplianceEvidence::PARTIAL, $topics['restore']['status']);
        $this->assertTrue($topics['restore']['evidence']['stale']);
    }

    /** คำสั่งกู้คืนต้องปฏิเสธที่จะทำงานบน SQLite แทนที่จะทำแล้วรายงานผลลวง */
    public function test_the_restore_check_refuses_to_pretend_on_sqlite(): void
    {
        $this->artisan('backup:verify-restore')
            ->expectsOutputToContain('ข้าม')
            ->assertSuccessful();

        $this->assertNull(
            ScheduledTaskHeartbeat::query()->where('task_key', 'backup:verify-restore')->first(),
        );
    }

    // ── ด่านของระบบ ─────────────────────────────────────────────────────────

    /** จุดตรวจสาธารณะต้องมีเพียงจุดเดียว และต้องเป็น GET เท่านั้น */
    public function test_the_public_health_endpoint_is_read_only(): void
    {
        $writable = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === 'api/health')
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            ->values()
            ->all();

        $this->assertSame([], $writable);
    }
}
