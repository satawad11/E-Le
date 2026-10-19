<?php

namespace Tests\Legacy\Feature;

use App\Contracts\NotificationCenter\NotificationChannelAdapter;
use App\Jobs\DeliverNotification;
use App\Models\NotificationCenter\NotificationPreference;
use App\Models\NotificationCenter\NotificationType;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use App\Services\NotificationCenter\DeliveryResult;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\NotificationCenter\NotificationDeliveryService;
use App\Services\NotificationCenter\NotificationMessage;
use App\Services\NotificationCenter\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_domain_event_types_and_channel_templates_are_seeded(): void
    {
        $codes = NotificationType::query()->pluck('code')->all();

        $this->assertEqualsCanonicalizing([
            'leave.submitted', 'leave.approval_requested', 'leave.approved',
            'leave.rejected', 'leave.returned', 'attendance.correction',
            'attendance.late', 'attendance.early_leave', 'overtime.approval', 'saraban.task',
            'leave.cancellation_requested', 'leave.cancellation_approved', 'leave.cancellation_rejected',
            'leave.substitute_requested', 'leave.substitute_responded',
        ], $codes);
        $this->assertDatabaseCount('notification_templates', 60);
    }

    public function test_emit_is_atomic_deduplicates_recipients_and_removes_sensitive_payload(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);

        $event = app(NotificationCenter::class)->emit(
            'leave.submitted', [$recipient, $recipient->id], $actor,
            ['status' => 'pending', 'national_id' => '1234567890123', 'token' => 'secret', 'nested' => ['password' => 'hidden']],
            $actor,
        );

        $this->assertSame(['status' => 'pending', 'nested' => []], $event->payload);
        $this->assertCount(1, $event->recipients);
        $this->assertEqualsCanonicalizing(['in_app', 'email'], $event->recipients->first()->deliveries->pluck('channel')->all());
        $this->assertSame($actor->getMorphClass(), $event->related_type);
        $this->assertSame($actor->id, $event->related_id);
        Queue::assertPushed(DeliverNotification::class, 2);
    }

    public function test_in_app_response_contract_and_read_scope_are_limited_to_owner(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $event = app(NotificationCenter::class)->emit('leave.approved', [$owner], null, ['status' => 'approved']);
        $uuid = $event->recipients->first()->uuid;

        $this->actingAs($owner)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('0.id', $uuid)
            ->assertJsonPath('0.type', 'leave.approved')
            ->assertJsonStructure([['id', 'type', 'data' => ['title', 'message', 'related_type', 'related_id'], 'read_at', 'created_at']]);

        $this->actingAs($other)->putJson("/api/notifications/{$uuid}/read")->assertNotFound();
        $this->assertDatabaseHas('notification_recipients', ['uuid' => $uuid, 'read_at' => null]);
        $this->actingAs($owner)->putJson("/api/notifications/{$uuid}/read")->assertOk();
        $this->assertDatabaseMissing('notification_recipients', ['uuid' => $uuid, 'read_at' => null]);
    }

    public function test_legacy_database_notifications_remain_visible_and_readable(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->notifyNow(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['database'];
            }

            public function toArray(object $notifiable): array
            {
                return ['message' => 'Legacy message'];
            }
        });
        $legacy = $user->notifications()->firstOrFail();

        $this->actingAs($user)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment(['id' => $legacy->id, 'message' => 'Legacy message']);
        $this->actingAs($user)->putJson("/api/notifications/{$legacy->id}/read")->assertOk();
        $this->assertNotNull($legacy->fresh()->read_at);
    }

    public function test_preferences_can_disable_email_and_enable_optional_channel(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->putJson('/api/notification-preferences', [
            'type_code' => 'leave.approved', 'channel' => 'email', 'enabled' => false,
        ])->assertOk();
        $this->actingAs($user)->putJson('/api/notification-preferences', [
            'type_code' => 'leave.approved', 'channel' => 'line_oa', 'enabled' => true,
        ])->assertOk();

        $event = app(NotificationCenter::class)->emit('leave.approved', [$user]);
        $deliveries = $event->recipients->first()->deliveries->keyBy('channel');
        $this->assertSame('skipped', $deliveries['email']->status);
        $this->assertSame('pending', $deliveries['line_oa']->status);
        $this->assertSame('pending', $deliveries['in_app']->status);
    }

    public function test_email_adapter_sends_and_unconfigured_optional_adapter_skips_safely(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->create(['is_active' => true, 'line_user_id' => null]);
        $type = NotificationType::where('code', 'leave.approved')->firstOrFail();
        NotificationPreference::create([
            'user_id' => $user->id, 'notification_type_id' => $type->id,
            'channel' => 'line_oa', 'enabled' => true,
        ]);
        $event = app(NotificationCenter::class)->emit('leave.approved', [$user]);
        $deliveries = $event->recipients->first()->deliveries->keyBy('channel');

        app(NotificationDeliveryService::class)->deliver($deliveries['email']->id, false);
        app(NotificationDeliveryService::class)->deliver($deliveries['line_oa']->id, false);

        Mail::assertSentCount(1);
        $this->assertSame('sent', $deliveries['email']->fresh()->status);
        $this->assertSame('skipped', $deliveries['line_oa']->fresh()->status);
    }

    public function test_disabled_module_never_delivers_an_already_queued_message(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->create(['is_active' => true]);
        $event = app(NotificationCenter::class)->emit('leave.approved', [$user]);
        $delivery = $event->recipients->first()->deliveries->firstWhere('channel', 'email');

        config(['modules.notification.enabled' => false]);
        app(NotificationDeliveryService::class)->deliver($delivery->id, false);

        Mail::assertNothingSent();
        $this->assertSame('pending', $delivery->fresh()->status);
    }

    public function test_failed_provider_uses_bounded_exponential_retry_then_fails(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $event = app(NotificationCenter::class)->emit('leave.approved', [$user]);
        $delivery = $event->recipients->first()->deliveries->firstWhere('channel', 'email');
        $adapter = new class implements NotificationChannelAdapter
        {
            public function channel(): string
            {
                return 'email';
            }

            public function isConfiguredFor(User $recipient): bool
            {
                return true;
            }

            public function deliver(NotificationMessage $message, User $recipient): DeliveryResult
            {
                return DeliveryResult::failed('<b>temporary provider failure</b>');
            }
        };
        $service = new NotificationDeliveryService(
            ['email' => $adapter],
            app(TemplateRenderer::class),
            app(ModuleRegistry::class),
        );

        $service->deliver($delivery->id, false);
        $this->assertSame('retrying', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->next_attempt_at);
        $service->deliver($delivery->id, false);
        $service->deliver($delivery->id, false);

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(3, $delivery->attempt_count);
        $this->assertSame('temporary provider failure', $delivery->last_error);
        $this->assertNull($delivery->next_attempt_at);
    }
}
