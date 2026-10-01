<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskHeartbeat;
use App\Models\SystemException;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Operations\ComplianceEvidence;
use App\Services\Operations\ExceptionQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ทะเบียนหลักฐานและการเฝ้าระวังงานตามเวลา (SoT §20, §23)
 *
 * ย้ายมาจาก SarabanSecurityAuditTest เมื่อถอดโมดูลสารบรรณ — ทั้งสองเรื่องเป็นของศูนย์ปฏิบัติการระบบ
 */
class ComplianceEvidenceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array<string,mixed>> */
    private function topics(): array
    {
        return collect(app(ComplianceEvidence::class)->collect())->keyBy('key')->all();
    }

    public function test_a_stale_scheduled_task_is_raised_into_the_exception_queue(): void
    {
        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();

        ScheduledTaskHeartbeat::query()
            ->where('task_key', 'notifications:dispatch-outbox')
            ->update(['last_run_at' => now()->subHours(3)]);

        $this->artisan('operations:check-heartbeats')->assertSuccessful();

        $this->assertDatabaseHas('system_exceptions', [
            'type' => 'scheduler.task_stale',
            'severity' => SystemException::SEVERITY_CRITICAL,
        ]);
    }

    public function test_a_healthy_scheduled_task_raises_nothing(): void
    {
        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();
        $this->artisan('operations:check-heartbeats')->assertSuccessful();

        $this->assertSame(0, SystemException::query()->where('type', 'scheduler.task_stale')->count());
    }

    /** หัวข้อที่ระบบตรวจแทนคนไม่ได้ต้องไม่มีวันเป็น PASS */
    public function test_topics_that_need_a_human_can_never_report_pass(): void
    {
        $topics = $this->topics();

        foreach (['restore', 'disaster_recovery', 'uat', 'accessibility'] as $key) {
            $this->assertSame(ComplianceEvidence::NO_EVIDENCE, $topics[$key]['status'], "หัวข้อ {$key} ต้องเป็น NO_EVIDENCE เสมอ");
            $this->assertNotEmpty($topics[$key]['method']);
        }
    }

    public function test_every_topic_states_how_it_was_checked(): void
    {
        foreach (app(ComplianceEvidence::class)->collect() as $topic) {
            $this->assertArrayHasKey('evidence', $topic);
            $this->assertNotEmpty($topic['method'], "หัวข้อ {$topic['key']} ต้องบอกวิธีตรวจ");
            $this->assertContains($topic['status'], [
                ComplianceEvidence::PASS, ComplianceEvidence::PARTIAL, ComplianceEvidence::FAIL,
                ComplianceEvidence::NO_EVIDENCE, ComplianceEvidence::NOT_APPLICABLE,
            ]);
        }
    }

    public function test_the_permission_topic_counts_every_module_not_only_one(): void
    {
        $evidence = $this->topics()['permission']['evidence'];

        $this->assertGreaterThan(0, $evidence['declared']);
    }

    public function test_a_tampered_audit_chain_reports_fail(): void
    {
        app(AuditLogger::class)->log('compliance.test', 'Test', 1, after: ['ok' => true]);
        app(AuditLogger::class)->log('compliance.test', 'Test', 2, after: ['ok' => true]);
        $this->assertSame(ComplianceEvidence::PASS, $this->topics()['audit']['status']);

        DB::table('audit_logs')->orderByDesc('id')->limit(1)->update(['previous_hash' => str_repeat('0', 64)]);

        $this->assertSame(ComplianceEvidence::FAIL, $this->topics()['audit']['status']);
    }

    public function test_open_incidents_make_the_register_report_fail(): void
    {
        app(ExceptionQueue::class)->raise(type: 'test.failure', title: 'มีบางอย่างล้มเหลว', dedupeKey: 'test.failure');

        $incidents = $this->topics()['incidents'];

        $this->assertSame(ComplianceEvidence::FAIL, $incidents['status']);
        $this->assertSame(1, $incidents['evidence']['open_exceptions']);
    }

    public function test_the_compliance_register_requires_a_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/operations/compliance')->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::findOrCreate('system.operations.view', 'web'));

        $this->actingAs($viewer)->getJson('/api/admin/operations/compliance')->assertOk();
    }

    public function test_no_route_can_write_a_compliance_status(): void
    {
        $writable = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'compliance'))
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $writable);
    }
}
