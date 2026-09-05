<?php

namespace Tests\Feature;

use App\Contracts\NotificationCenter\NotificationChannelAdapter;
use App\Jobs\DeliverNotification;
use App\Models\NotificationCenter\NotificationDelivery;
use App\Models\NotificationCenter\NotificationType;
use App\Models\NotificationCenter\WebPushSubscription;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use App\Services\NotificationCenter\Adapters\WebPushChannelAdapter;
use App\Services\NotificationCenter\ChannelAdapterRegistry;
use App\Services\NotificationCenter\DeliveryResult;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\NotificationCenter\NotificationDeliveryService;
use App\Services\NotificationCenter\NotificationMessage;
use App\Services\NotificationCenter\Providers\WebPushGateway;
use App\Services\NotificationCenter\TemplateRenderer;
use App\Services\Operations\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-14 — ช่องทางส่ง outbox dead-letter และการติดตามผล (SoT §16)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **ช่องทางใหม่ต่อเข้าศูนย์แจ้งเตือนกลาง ไม่ใช่ชั้นแจ้งเตือนใหม่**
 *   2. **outbox กู้ข้อความที่งานหายไปจากคิวได้** — ไม่งั้นตารางนี้ไม่ใช่ outbox
 *   3. **dead-letter มองเห็นได้และสั่งส่งใหม่ได้**
 *   4. **การสมัครรับ push เป็นของผู้เรียกเสมอ** ไม่มีทางสมัครหรือถอนแทนคนอื่น
 *   5. กุญแจส่วนตัวของ VAPID ไม่รั่วออกทาง API
 */
class NotificationProvidersTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc-123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.web_push.public_key' => 'BDcpwLtvCDV54oZoinIeLFR908wxQ7VTvpBnN_tnZlwHw6Z8x34IddZAJddk_-E8k-sf6rA1FKWma16KZg8iWDs',
            'services.web_push.private_key' => 'SpfyvNL70BmAaaTvs6iYRJcLKqAX8sYEIaG1enqaBGs',
            'services.web_push.subject' => 'https://example.test',
        ]);
    }

    // ── ช่องทาง Web Push (SoT §16) ──────────────────────────────────────────

    public function test_web_push_is_registered_as_a_channel_of_the_core_notification_center(): void
    {
        $this->assertContains('web_push', NotificationCenter::CHANNELS);
        $this->assertTrue(app(ChannelAdapterRegistry::class)->has('web_push'));
    }

    /** ทุกชนิดการแจ้งเตือนต้องมี template ของช่องทางใหม่ ไม่งั้นจะถูก skip เงียบ ๆ */
    public function test_every_notification_type_has_a_web_push_template(): void
    {
        $withTemplate = DB::table('notification_templates')
            ->where('channel', 'web_push')
            ->pluck('notification_type_id');

        $typesWithout = NotificationType::query()
            ->whereNotIn('id', $withTemplate)
            ->pluck('code')
            ->all();

        $this->assertSame([], $typesWithout);
    }

    public function test_subscribing_stores_the_browser_endpoint_for_the_calling_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/notifications/web-push/subscribe', [
                'endpoint' => self::ENDPOINT,
                'keys' => ['p256dh' => 'public-key-value', 'auth' => 'auth-secret'],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('web_push_subscriptions', [
            'user_id' => $user->id,
            'endpoint' => self::ENDPOINT,
        ]);
    }

    /** สมัครซ้ำจากเบราว์เซอร์เดิมต้องได้แถวเดิม ไม่ใช่แถวใหม่ทุกครั้งที่กด */
    public function test_subscribing_twice_from_the_same_browser_keeps_one_row(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($user)
                ->postJson('/api/notifications/web-push/subscribe', [
                    'endpoint' => self::ENDPOINT,
                    'keys' => ['p256dh' => 'public-key-value', 'auth' => 'auth-secret'],
                ])
                ->assertCreated();
        }

        $this->assertSame(1, WebPushSubscription::query()->count());
    }

    /** เครื่องที่ใช้ร่วมกันเปลี่ยนคนใช้ได้ — เจ้าของแถวต้องย้ายตาม ไม่งั้นคนใหม่ได้ข่าวของคนเก่า */
    public function test_resubscribing_as_another_user_moves_ownership_of_the_endpoint(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        foreach ([$first, $second] as $user) {
            $this->actingAs($user)->postJson('/api/notifications/web-push/subscribe', [
                'endpoint' => self::ENDPOINT,
                'keys' => ['p256dh' => 'public-key-value', 'auth' => 'auth-secret'],
            ])->assertCreated();
        }

        $this->assertSame($second->id, (int) WebPushSubscription::query()->firstOrFail()->user_id);
    }

    public function test_a_user_cannot_unsubscribe_someone_elses_browser(): void
    {
        $owner = User::factory()->create();
        WebPushSubscription::create([
            'user_id' => $owner->id,
            'endpoint' => self::ENDPOINT,
            'p256dh_key' => 'public-key-value',
            'auth_token' => 'auth-secret',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/notifications/web-push/unsubscribe', ['endpoint' => self::ENDPOINT])
            ->assertOk();

        $this->assertDatabaseHas('web_push_subscriptions', ['endpoint' => self::ENDPOINT]);
    }

    public function test_the_public_key_endpoint_never_exposes_the_private_key(): void
    {
        $body = $this->actingAs(User::factory()->create())
            ->getJson('/api/notifications/web-push/key')
            ->assertOk()
            ->json();

        $this->assertSame(config('services.web_push.public_key'), $body['public_key']);
        $this->assertStringNotContainsString(
            (string) config('services.web_push.private_key'),
            json_encode($body, JSON_UNESCAPED_UNICODE),
        );
    }

    /** รายการเบราว์เซอร์ของตัวเองต้องไม่คืนกุญแจหรือ endpoint ออกไป */
    public function test_listing_subscriptions_hides_the_browser_keys(): void
    {
        $user = User::factory()->create();
        WebPushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => self::ENDPOINT,
            'p256dh_key' => 'public-key-value',
            'auth_token' => 'auth-secret',
        ]);

        $body = json_encode(
            $this->actingAs($user)->getJson('/api/notifications/web-push/subscriptions')->assertOk()->json(),
        );

        $this->assertStringNotContainsString('auth-secret', $body);
        $this->assertStringNotContainsString(self::ENDPOINT, $body);
    }

    /** ผู้ใช้ที่ยังไม่เปิดรับบนเครื่องใดเลย ต้องไม่ถูกนับว่าตั้งค่าช่องทางนี้แล้ว */
    public function test_the_adapter_reports_unconfigured_when_the_user_has_no_browser(): void
    {
        $adapter = app(WebPushChannelAdapter::class);

        $this->assertFalse($adapter->isConfiguredFor(User::factory()->create()));
    }

    /**
     * ส่งถึงหลายเบราว์เซอร์ และ **ถึงเครื่องเดียวก็นับว่าสำเร็จ**
     *
     * เครื่องที่ปิดทิ้งไว้ที่บ้านไม่ควรทำให้การแจ้งเตือนที่ถึงมือถือในกระเป๋าแล้ว
     * ถูกนับเป็นล้มเหลวและถูกส่งซ้ำจนไปกอง dead-letter
     */
    public function test_delivery_succeeds_when_at_least_one_browser_accepts(): void
    {
        $user = User::factory()->create();

        foreach (['https://push.test/a', 'https://push.test/b'] as $endpoint) {
            WebPushSubscription::create([
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'p256dh_key' => 'public-key-value',
                'auth_token' => 'auth-secret',
            ]);
        }

        $gateway = new class extends WebPushGateway
        {
            public function configured(): bool
            {
                return true;
            }

            public function send(WebPushSubscription $subscription, string $title, string $body, ?string $url = null): bool
            {
                return $subscription->endpoint === 'https://push.test/b';
            }
        };

        $result = (new WebPushChannelAdapter($gateway, app(FeatureFlags::class)))
            ->deliver(new NotificationMessage('เรื่อง', 'เนื้อความ'), $user);

        $this->assertTrue($result->successful);
    }

    // ── outbox (SoT §16) ────────────────────────────────────────────────────

    /**
     * ตารางเดลิเวอรีจะเป็น outbox ได้ต่อเมื่อกู้งานที่หายจากคิวได้
     *
     * จำลองกรณีที่ worker ถูกฆ่ากลางทาง: แถวยังค้าง `pending` แต่ไม่มี job ในคิว
     */
    public function test_the_outbox_sweeper_requeues_deliveries_whose_job_was_lost(): void
    {
        $delivery = $this->pendingDelivery();
        $delivery->forceFill(['created_at' => now()->subMinutes(30)])->save();

        Queue::fake();
        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();

        Queue::assertPushed(DeliverNotification::class);
    }

    /** แถวที่เพิ่งสร้างต้องไม่ถูกกวาดซ้ำ เพราะ job แรกยังกำลังจะทำงานอยู่ */
    public function test_the_outbox_sweeper_leaves_freshly_created_deliveries_alone(): void
    {
        $this->pendingDelivery();

        Queue::fake();
        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── dead-letter (SoT §16) ───────────────────────────────────────────────

    public function test_a_delivery_that_exhausts_its_attempts_enters_the_dead_letter(): void
    {
        $delivery = $this->pendingDelivery();
        $delivery->forceFill(['attempt_count' => 3, 'max_attempts' => 3])->save();

        $this->failWith($delivery);

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->dead_lettered_at);
    }

    public function test_a_delivery_that_still_has_attempts_left_is_not_dead_lettered(): void
    {
        $delivery = $this->pendingDelivery();

        $this->failWith($delivery);

        $delivery->refresh();
        $this->assertSame('retrying', $delivery->status);
        $this->assertNull($delivery->dead_lettered_at);
    }

    public function test_dead_letters_are_visible_and_can_be_requeued_by_an_administrator(): void
    {
        $delivery = $this->pendingDelivery();
        $delivery->forceFill(['status' => 'failed', 'attempt_count' => 3, 'dead_lettered_at' => now()])->save();

        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('system.setting.manage', 'web'));

        $listed = $this->actingAs($admin)
            ->getJson('/api/admin/notifications/dead-letters')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $listed);

        Queue::fake();
        $this->actingAs($admin)
            ->postJson("/api/admin/notifications/dead-letters/{$delivery->id}/requeue")
            ->assertOk();

        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(0, $delivery->attempt_count);
        $this->assertNull($delivery->dead_lettered_at);
        Queue::assertPushed(DeliverNotification::class);

        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.dead_letter.requeued']);
    }

    public function test_an_ordinary_user_cannot_see_or_requeue_dead_letters(): void
    {
        $delivery = $this->pendingDelivery();
        $delivery->forceFill(['status' => 'failed', 'dead_lettered_at' => now()])->save();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/notifications/dead-letters')
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/admin/notifications/dead-letters/{$delivery->id}/requeue")
            ->assertForbidden();
    }

    public function test_channel_health_reports_every_registered_channel(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('system.setting.manage', 'web'));

        $channels = $this->actingAs($admin)
            ->getJson('/api/admin/notifications/channel-health')
            ->assertOk()
            ->json('channels');

        $this->assertEqualsCanonicalizing(
            array_keys(app(ChannelAdapterRegistry::class)->all()),
            array_column($channels, 'channel'),
        );
    }

    /** แถวเดลิเวอรีจริงหนึ่งแถวที่ยังไม่ถูกส่ง */
    private function pendingDelivery(): NotificationDelivery
    {
        Queue::fake();

        $type = NotificationType::query()->where('code', 'leave.submitted')->firstOrFail();
        $event = app(NotificationCenter::class)->emit($type->code, [User::factory()->create()]);

        return NotificationDelivery::query()
            ->whereIn('notification_recipient_id', $event->recipients()->select('id'))
            ->where('status', 'pending')
            ->firstOrFail();
    }

    /** ให้ผู้ให้บริการปฏิเสธข้อความหนึ่งครั้ง โดยไม่ตั้งเวลา retry จริง */
    private function failWith(NotificationDelivery $delivery): void
    {
        /*
         * ประกอบบริการเองพร้อม adapter ที่ปฏิเสธเสมอ แทนการ mock ผู้ให้บริการจริง
         * — ทดสอบ *กติกาการนับครั้งและการเข้า dead-letter* ไม่ใช่ตัวผู้ให้บริการ
         */
        $service = new NotificationDeliveryService(
            ChannelAdapterRegistry::of([new FailingChannelAdapter($delivery->channel)]),
            app(TemplateRenderer::class),
            app(ModuleRegistry::class),
        );

        $service->deliver($delivery->id, scheduleRetry: false);
    }
}

/** ผู้ให้บริการที่ปฏิเสธข้อความเสมอ — ใช้ทดสอบเส้นทาง retry และ dead-letter */
class FailingChannelAdapter implements NotificationChannelAdapter
{
    public function __construct(private readonly string $channel) {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function isConfiguredFor(User $recipient): bool
    {
        return true;
    }

    public function deliver(NotificationMessage $message, User $recipient): DeliveryResult
    {
        return DeliveryResult::failed('ผู้ให้บริการปฏิเสธข้อความ');
    }
}
