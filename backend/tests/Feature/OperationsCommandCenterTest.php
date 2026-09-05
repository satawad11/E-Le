<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\FeatureFlag;
use App\Models\NotificationCenter\NotificationDelivery;
use App\Models\ScheduledTaskHeartbeat;
use App\Models\SystemException;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\Operations\ExceptionQueue;
use App\Services\Operations\FeatureFlags;
use App\Services\Operations\SchedulerHeartbeat;
use App\Services\Saraban\DistributionException;
use App\Services\Saraban\RecipientResolver;
use App\Services\Webhooks\WebhookCatalogue;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-17 — คิวข้อผิดพลาด ศูนย์เฝ้าระวัง และธงความสามารถ (SoT §19, §20, §21)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **ปัญหาเดิมที่เกิดซ้ำเป็นแถวเดียว** — คิวที่ท่วมเท่ากับคิวที่ไม่มีใครอ่าน
 *   2. **ปัญหาที่ปิดแล้วกลับมา = เปิดใหม่** ไม่ใช่เพิ่มจำนวนครั้งเงียบ ๆ
 *   3. **assign / ack / retry / resolve ครบทั้งสี่ และลง audit ทุกข้อ** (SoT §19)
 *   4. **สั่งซ้ำไม่ปิดรายการให้เอง** — สั่งซ้ำไม่ได้แปลว่าสำเร็จ
 *   5. **Feature Flag ≠ Permission** (SoT §21) — ธงเปิดไม่ให้สิทธิ์ใครเพิ่ม
 *   6. **ชีพจรจับ "งานที่ไม่ทำงาน" ได้** (ปิด RISK-40)
 *   7. **แยกสิทธิ์ดูออกจากสิทธิ์แก้**
 */
class OperationsCommandCenterTest extends TestCase
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

    private function raise(string $type = 'test.failure', ?string $dedupeKey = null): SystemException
    {
        return app(ExceptionQueue::class)->raise(
            type: $type,
            title: 'มีบางอย่างล้มเหลว',
            detail: ['reason' => 'ทดสอบ'],
            dedupeKey: $dedupeKey ?? $type,
        );
    }

    // ── คิวข้อผิดพลาด (SoT §19) ─────────────────────────────────────────────

    /** คิวที่ท่วมเท่ากับคิวที่ไม่มีใครอ่าน */
    public function test_the_same_problem_raised_twice_stays_one_row_and_counts_up(): void
    {
        $this->raise();
        $this->raise();

        $this->assertSame(1, SystemException::query()->count());
        $this->assertSame(2, SystemException::query()->firstOrFail()->occurrence_count);
    }

    /** ปัญหาที่ปิดไปแล้วกลับมาอีก = ปัญหาใหม่ที่ต้องมีคนดู */
    public function test_a_resolved_problem_that_happens_again_is_reopened(): void
    {
        $exception = $this->raise();
        app(ExceptionQueue::class)->resolve($exception, $this->userWith([]));

        $this->assertSame(SystemException::STATUS_RESOLVED, $exception->fresh()->status);

        $this->raise();

        $this->assertSame(SystemException::STATUS_OPEN, $exception->fresh()->status);
        $this->assertNull($exception->fresh()->resolved_at);
    }

    public function test_acknowledge_assign_and_resolve_are_all_audited(): void
    {
        $exception = $this->raise();
        $operator = $this->userWith(['saraban.operations.retry']);
        $assignee = $this->userWith([]);

        $this->actingAs($operator);

        $this->postJson("/api/saraban/operations/exceptions/{$exception->id}/acknowledge")->assertOk();
        $this->postJson("/api/saraban/operations/exceptions/{$exception->id}/assign", ['user_id' => $assignee->id])->assertOk();
        $this->postJson("/api/saraban/operations/exceptions/{$exception->id}/resolve", ['note' => 'แก้ที่ต้นเหตุแล้ว'])->assertOk();

        foreach ([
            'system.exception.acknowledged',
            'system.exception.assigned',
            'system.exception.resolved',
        ] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }

        $exception->refresh();
        $this->assertSame($assignee->id, $exception->assigned_to);
        $this->assertSame('แก้ที่ต้นเหตุแล้ว', $exception->resolution_note);
    }

    /** สั่งซ้ำไม่ได้แปลว่าสำเร็จ — คนต้องกลับมาปิดเอง */
    public function test_retrying_requeues_the_work_but_does_not_close_the_item(): void
    {
        $endpoint = WebhookEndpoint::create([
            'name' => 'ปลายทางทดสอบ',
            'url' => 'https://partner.test/hook',
            'secret' => 'secret-value',
            'events' => [WebhookCatalogue::DOCUMENT_FINALIZED],
            'is_active' => true,
            'created_by' => $this->userWith([])->id,
        ]);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => WebhookCatalogue::DOCUMENT_FINALIZED,
            'payload' => [],
            'status' => 'failed',
            'attempt_count' => 5,
            'dead_lettered_at' => now(),
        ]);

        app(ExceptionQueue::class)->sweepDeadLetters();
        $exception = SystemException::query()->where('type', 'webhook.dead_letter')->firstOrFail();

        Queue::fake();
        $this->actingAs($this->userWith(['saraban.operations.retry']))
            ->postJson("/api/saraban/operations/exceptions/{$exception->id}/retry")
            ->assertOk();

        Queue::assertPushed(DeliverWebhook::class);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->dead_lettered_at);

        // รายการยังเปิดอยู่ — ผู้ใช้ต้องยืนยันผลเองก่อนปิด
        $this->assertTrue($exception->fresh()->isOpen());
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.exception.retried']);
    }

    public function test_an_item_without_a_retry_target_cannot_be_retried(): void
    {
        $exception = $this->raise();

        $this->actingAs($this->userWith(['saraban.operations.retry']))
            ->postJson("/api/saraban/operations/exceptions/{$exception->id}/retry")
            ->assertStatus(422);
    }

    /** dead-letter ที่บันทึกไว้ตั้งแต่ INT-14/15 ต้องกลายเป็นงานที่มีเจ้าของ */
    public function test_the_sweeper_turns_existing_dead_letters_into_queue_items(): void
    {
        Queue::fake();

        $event = app(NotificationCenter::class)->emit('leave.submitted', [User::factory()->create()]);
        $delivery = NotificationDelivery::query()
            ->whereIn('notification_recipient_id', $event->recipients()->select('id'))
            ->firstOrFail();
        $delivery->forceFill(['status' => 'failed', 'dead_lettered_at' => now()])->save();

        $this->artisan('operations:sweep-exceptions')->assertSuccessful();

        $this->assertDatabaseHas('system_exceptions', [
            'type' => 'notification.dead_letter',
            'retry_type' => SystemException::RETRY_NOTIFICATION,
            'retry_id' => $delivery->id,
        ]);
    }

    public function test_the_sweeper_is_safe_to_run_repeatedly(): void
    {
        Queue::fake();

        $event = app(NotificationCenter::class)->emit('leave.submitted', [User::factory()->create()]);
        NotificationDelivery::query()
            ->whereIn('notification_recipient_id', $event->recipients()->select('id'))
            ->firstOrFail()
            ->forceFill(['status' => 'failed', 'dead_lettered_at' => now()])
            ->save();

        $this->artisan('operations:sweep-exceptions')->assertSuccessful();
        $this->artisan('operations:sweep-exceptions')->assertSuccessful();

        $this->assertSame(1, SystemException::query()->where('type', 'notification.dead_letter')->count());
    }

    // ── สิทธิ์ (SoT §19, §20) ───────────────────────────────────────────────

    public function test_viewing_and_acting_are_separate_permissions(): void
    {
        $exception = $this->raise();

        // ผู้ที่ดูได้อย่างเดียวต้องกดสั่งงานไม่ได้
        $this->actingAs($this->userWith(['saraban.operations.view']));
        $this->getJson('/api/saraban/operations/exceptions')->assertOk();
        $this->postJson("/api/saraban/operations/exceptions/{$exception->id}/resolve")->assertForbidden();

        $this->actingAs($this->userWith(['saraban.operations.retry']));
        $this->postJson("/api/saraban/operations/exceptions/{$exception->id}/resolve")->assertOk();
    }

    public function test_an_ordinary_user_sees_nothing_of_the_command_center(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/saraban/operations/command-center')
            ->assertForbidden();
    }

    // ── ศูนย์เฝ้าระวัง (SoT §20) ────────────────────────────────────────────

    public function test_the_command_center_reports_every_section_the_master_requires(): void
    {
        $body = $this->actingAs($this->userWith(['saraban.operations.view']))
            ->getJson('/api/saraban/operations/command-center')
            ->assertOk()
            ->json();

        foreach (['exceptions', 'queues', 'notifications', 'webhooks', 'scheduler', 'storage', 'feature_flags'] as $section) {
            $this->assertArrayHasKey($section, $body, "ศูนย์เฝ้าระวังต้องรายงานหัวข้อ {$section}");
        }
    }

    /**
     * ปิด RISK-40 — งานตามเวลาที่หยุดทำงานจะเงียบสนิท ไม่มี error ให้จับ
     *
     * ทางเดียวที่รู้ได้คือเทียบเวลาที่มันเงียบไปกับรอบที่คาดไว้
     */
    public function test_a_scheduled_task_that_stops_running_is_detected_as_stale(): void
    {
        $heartbeat = app(SchedulerHeartbeat::class);
        $heartbeat->record('notifications:dispatch-outbox', 5);

        $this->assertSame([], $heartbeat->staleTasks());

        ScheduledTaskHeartbeat::query()
            ->where('task_key', 'notifications:dispatch-outbox')
            ->update(['last_run_at' => now()->subHour()]);

        $this->assertSame(['notifications:dispatch-outbox'], $heartbeat->staleTasks());
    }

    public function test_running_the_outbox_command_records_its_heartbeat(): void
    {
        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();

        $this->assertDatabaseHas('scheduled_task_heartbeats', ['task_key' => 'notifications:dispatch-outbox']);
    }

    // ── ธงความสามารถ (SoT §21) ──────────────────────────────────────────────

    /**
     * **Feature Flag ≠ Permission** — ข้อบังคับที่ SoT §21 เขียนไว้ตรง ๆ
     *
     * เปิดธงทุกตัวแล้วผู้ใช้ที่ไม่มีสิทธิ์ต้องยังเข้าไม่ได้เหมือนเดิม
     */
    public function test_turning_a_flag_on_grants_no_permission_to_anyone(): void
    {
        $admin = $this->userWith(['saraban.feature_flags.manage']);

        foreach (array_keys(FeatureFlags::catalogue()) as $key) {
            $this->actingAs($admin)->putJson('/api/saraban/feature-flags/'.$key, ['enabled' => true])->assertOk();
        }

        $this->actingAs(User::factory()->create())
            ->getJson('/api/saraban/documents')
            ->assertForbidden();

        // และผู้ที่จัดการธงได้ ก็ยังเข้าทะเบียนหนังสือไม่ได้ถ้าไม่มีสิทธิ์นั้น
        $this->actingAs($admin)->getJson('/api/saraban/documents')->assertForbidden();
    }

    public function test_turning_a_flag_off_closes_the_capability_for_everyone(): void
    {
        Queue::fake();

        WebhookEndpoint::create([
            'name' => 'ปลายทางทดสอบ',
            'url' => 'https://partner.test/hook',
            'secret' => 'secret-value',
            'events' => [WebhookCatalogue::DOCUMENT_FINALIZED],
            'is_active' => true,
            'created_by' => $this->userWith([])->id,
        ]);

        $dispatcher = app(WebhookDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatch(WebhookCatalogue::DOCUMENT_FINALIZED, []));

        app(FeatureFlags::class)->set('saraban.webhooks', false, $this->userWith([]));

        $this->assertSame(0, $dispatcher->dispatch(WebhookCatalogue::DOCUMENT_FINALIZED, []));
    }

    public function test_every_flag_change_is_audited(): void
    {
        $admin = $this->userWith(['saraban.feature_flags.manage']);

        $this->actingAs($admin)
            ->putJson('/api/saraban/feature-flags/saraban.webhooks', ['enabled' => false])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'system.feature_flag.changed']);
        $this->assertDatabaseHas('feature_flags', ['key' => 'saraban.webhooks', 'enabled' => false]);
    }

    /** ชื่อธงที่พิมพ์ผิดต้องถือว่าปิด ไม่ใช่เปิด */
    public function test_an_unknown_flag_defaults_to_off_and_cannot_be_set(): void
    {
        $this->assertFalse(app(FeatureFlags::class)->enabled('made.up.flag'));

        $this->actingAs($this->userWith(['saraban.feature_flags.manage']))
            ->putJson('/api/saraban/feature-flags/made.up.flag', ['enabled' => true])
            ->assertStatus(422);
    }

    public function test_changing_a_flag_requires_the_manage_permission(): void
    {
        $this->actingAs($this->userWith(['saraban.operations.view']))
            ->putJson('/api/saraban/feature-flags/saraban.webhooks', ['enabled' => false])
            ->assertForbidden();
    }

    /** ธงต้องมีผลทันทีหลังเปลี่ยน ไม่ใช่รอ cache หมดอายุ */
    public function test_changing_a_flag_takes_effect_immediately(): void
    {
        $flags = app(FeatureFlags::class);
        $this->assertTrue($flags->enabled('notification.web_push'));

        $flags->set('notification.web_push', false, $this->userWith([]));

        $this->assertFalse($flags->enabled('notification.web_push'));
        $this->assertFalse(Cache::has('system.feature_flags') && ($flags->all()['notification.web_push'] ?? true));
        $this->assertDatabaseHas('feature_flags', ['key' => 'notification.web_push', 'enabled' => false]);
    }

    /** ธง `all_authorized` ปิดแล้ว ต้องเวียนถึงทุกคนไม่ได้จริง ไม่ใช่แค่ซ่อนปุ่ม */
    public function test_the_distribution_flag_actually_blocks_the_target(): void
    {
        app(FeatureFlags::class)->set('saraban.distribution.all_authorized', false, $this->userWith([]));

        $this->expectException(DistributionException::class);

        app(RecipientResolver::class)->resolve(
            [['type' => 'all_authorized']],
            $this->userWith(['saraban.distribution.send']),
        );
    }

    public function test_the_flag_overview_marks_which_values_were_overridden(): void
    {
        app(FeatureFlags::class)->set('saraban.webhooks', false, $this->userWith([]));

        $overview = collect(app(FeatureFlags::class)->overview());

        $this->assertTrue($overview->firstWhere('key', 'saraban.webhooks')['overridden']);
        $this->assertFalse($overview->firstWhere('key', 'notification.web_push')['overridden']);
        $this->assertSame(FeatureFlag::query()->count(), $overview->where('overridden', true)->count());
    }
}
