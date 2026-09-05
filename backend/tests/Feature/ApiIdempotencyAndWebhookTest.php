<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\IdempotencyKey;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookCatalogue;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Webhooks\WebhookSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-15 — พื้นผิว API v1, กุญแจกันทำซ้ำ, webhook และขอบเขตของโทเคน (SoT §17)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **กุญแจเดิม + เนื้อเดิม = ผลเดิม โดยไม่ทำงานซ้ำ** (SEC-GAP-04)
 *   2. **กุญแจเดิม + เนื้อต่าง = ปฏิเสธ** ไม่ใช่คืนผลของคำขอเก่า
 *   3. **กุญแจเป็นของแต่ละคน** ไม่ใช่ของทั้งระบบ
 *   4. **ขอบเขตของโทเคนจำกัดได้ แต่ขยายไม่ได้** (SoT §17)
 *   5. **webhook บันทึกลง outbox ก่อนส่ง** และเซ็นด้วย HMAC พร้อมเวลา
 *   6. **v1 กับเส้นทางเดิมเป็นนิยามชุดเดียวกัน** ไม่ใช่สำเนาสองชุด
 */
class ApiIdempotencyAndWebhookTest extends TestCase
{
    use RefreshDatabase;

    // ── พื้นผิว API v1 (SoT §17) ────────────────────────────────────────────

    public function test_every_saraban_route_exists_under_both_prefixes(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

        $legacy = $uris->filter(fn (string $uri) => str_starts_with($uri, 'api/saraban/'))
            ->reject(fn (string $uri) => str_contains($uri, '/verify/'))
            ->map(fn (string $uri) => substr($uri, strlen('api/saraban/')))
            ->sort()->values();

        $versioned = $uris->filter(fn (string $uri) => str_starts_with($uri, 'api/v1/saraban/'))
            ->map(fn (string $uri) => substr($uri, strlen('api/v1/saraban/')))
            ->sort()->values();

        $this->assertSame($legacy->all(), $versioned->all());
    }

    public function test_the_machine_readable_descriptor_reports_abilities_and_step_up(): void
    {
        $body = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->json();

        $this->assertSame('v1', $body['info']['version']);

        $finalize = $body['paths']['/api/v1/saraban/documents/{document}/finalize']['post'];
        $this->assertContains('saraban.signature.sign', $finalize['security']['bearerAuth']);
        $this->assertTrue($finalize['security']['requires_recent_authentication']);

        $list = $body['paths']['/api/v1/saraban/documents']['get'];
        $this->assertFalse($list['security']['requires_recent_authentication']);
    }

    // ── กุญแจกันทำซ้ำ · SEC-GAP-04 (SoT §17) ────────────────────────────────

    public function test_replaying_the_same_key_returns_the_stored_response_without_working_twice(): void
    {
        $this->withIdempotentRoute();
        $user = User::factory()->create();

        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'order-12345678')
            ->postJson('/api/test-idempotent', ['value' => 'ก'])
            ->assertCreated()
            ->json();

        $second = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'order-12345678')
            ->postJson('/api/test-idempotent', ['value' => 'ก'])
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->json();

        // ตัวนับฝั่ง server เพิ่มครั้งเดียว = งานจริงไม่ถูกทำซ้ำ
        $this->assertSame($first['sequence'], $second['sequence']);
        $this->assertSame(1, cache()->get('idempotency-test-counter'));
    }

    public function test_the_same_key_with_a_different_body_is_rejected(): void
    {
        $this->withIdempotentRoute();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'order-12345678')
            ->postJson('/api/test-idempotent', ['value' => 'ก'])
            ->assertCreated();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'order-12345678')
            ->postJson('/api/test-idempotent', ['value' => 'ข'])
            ->assertStatus(422);
    }

    /** กุญแจของคนหนึ่งต้องไม่ปิดกั้นคำขอของอีกคน แม้จะเดากุญแจถูก */
    public function test_keys_are_scoped_per_user(): void
    {
        $this->withIdempotentRoute();

        foreach ([User::factory()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)
                ->withHeader('Idempotency-Key', 'order-12345678')
                ->postJson('/api/test-idempotent', ['value' => 'ก'])
                ->assertCreated();
        }

        $this->assertSame(2, IdempotencyKey::query()->count());
        $this->assertSame(2, cache()->get('idempotency-test-counter'));
    }

    /** คำขอที่ล้มเหลวต้องลองใหม่ด้วยกุญแจเดิมได้ ไม่ใช่ติดอยู่กับความล้มเหลวนั้น */
    public function test_a_failed_request_releases_its_key(): void
    {
        $this->withIdempotentRoute();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'order-12345678')
            ->postJson('/api/test-idempotent', ['value' => 'ก', 'fail' => true])
            ->assertStatus(500);

        $this->assertSame(0, IdempotencyKey::query()->count());

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'order-12345678')
            ->postJson('/api/test-idempotent', ['value' => 'ก'])
            ->assertCreated();
    }

    public function test_requests_without_a_key_are_untouched(): void
    {
        $this->withIdempotentRoute();
        $user = User::factory()->create();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($user)->postJson('/api/test-idempotent', ['value' => 'ก'])->assertCreated();
        }

        $this->assertSame(0, IdempotencyKey::query()->count());
        $this->assertSame(2, cache()->get('idempotency-test-counter'));
    }

    public function test_a_malformed_key_is_rejected_rather_than_ignored(): void
    {
        $this->withIdempotentRoute();

        $this->actingAs(User::factory()->create())
            ->withHeader('Idempotency-Key', 'สั้น')
            ->postJson('/api/test-idempotent', ['value' => 'ก'])
            ->assertStatus(422);
    }

    // ── ขอบเขตของโทเคน · บัญชีบริการ (SoT §17) ──────────────────────────────

    /**
     * **ขอบเขตจำกัดได้ แต่ขยายไม่ได้**
     *
     * โทเคนที่ออกมาเพื่ออ่านอย่างเดียว ต้องสร้างหนังสือไม่ได้ แม้เจ้าของบัญชี
     * จะมีสิทธิ์สร้างก็ตาม
     */
    public function test_a_narrow_token_cannot_reach_beyond_its_scope(): void
    {
        $user = User::factory()->create();

        foreach (['saraban.document.view', 'saraban.document.create'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $token = $user->fresh()->createToken('service-account', ['saraban.document.view']);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/saraban/documents')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/v1/saraban/documents', [])
            ->assertForbidden();
    }

    /** โทเคนที่ขอเกินสิทธิ์ของเจ้าของยังถูกปฏิเสธ — สองด่านต้องผ่านทั้งคู่ */
    public function test_a_token_scope_cannot_grant_authority_the_user_lacks(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('service-account', ['saraban.document.create']);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/v1/saraban/documents', [])
            ->assertForbidden();
    }

    // ── webhook (SoT §17) ───────────────────────────────────────────────────

    public function test_dispatching_writes_to_the_outbox_before_sending(): void
    {
        Queue::fake();
        $endpoint = $this->endpointFor([WebhookCatalogue::DOCUMENT_FINALIZED]);

        $sent = app(WebhookDispatcher::class)->dispatch(
            WebhookCatalogue::DOCUMENT_FINALIZED,
            ['document_uuid' => 'abc'],
        );

        $this->assertSame(1, $sent);
        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_endpoint_id' => $endpoint->id,
            'status' => 'pending',
        ]);
        Queue::assertPushed(DeliverWebhook::class);
    }

    /** ปลายทางได้เฉพาะเหตุการณ์ที่สมัครไว้ · ไม่สมัคร = ไม่ได้รับ */
    public function test_an_endpoint_only_receives_the_events_it_subscribed_to(): void
    {
        Queue::fake();
        $this->endpointFor([WebhookCatalogue::DOCUMENT_FINALIZED]);

        $sent = app(WebhookDispatcher::class)->dispatch(
            WebhookCatalogue::DOCUMENT_ACKNOWLEDGED,
            ['document_uuid' => 'abc'],
        );

        $this->assertSame(0, $sent);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_the_request_is_signed_with_hmac_over_the_timestamp_and_body(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $endpoint = $this->endpointFor([WebhookCatalogue::DOCUMENT_FINALIZED]);
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => WebhookCatalogue::DOCUMENT_FINALIZED,
            'payload' => ['document_uuid' => 'abc'],
            'status' => 'pending',
        ]);

        app(WebhookSender::class)->send($delivery->id, scheduleRetry: false);

        Http::assertSent(function ($request) use ($endpoint) {
            $timestamp = $request->header('X-Webhook-Timestamp')[0];
            $expected = hash_hmac('sha256', $timestamp.'.'.$request->body(), $endpoint->secret);

            return $request->header('X-Webhook-Signature')[0] === 'sha256='.$expected;
        });

        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_a_rejecting_endpoint_is_retried_and_then_dead_lettered(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $endpoint = $this->endpointFor([WebhookCatalogue::DOCUMENT_FINALIZED]);
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => WebhookCatalogue::DOCUMENT_FINALIZED,
            'payload' => [],
            'status' => 'pending',
            'max_attempts' => 2,
        ]);

        app(WebhookSender::class)->send($delivery->id, scheduleRetry: false);
        $this->assertSame('retrying', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->dead_lettered_at);

        $delivery->fresh()->forceFill(['status' => 'retrying'])->save();
        app(WebhookSender::class)->send($delivery->id, scheduleRetry: false);

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->dead_lettered_at);
    }

    public function test_the_signing_secret_is_shown_once_and_never_again(): void
    {
        $admin = $this->administrator();

        $created = $this->actingAs($admin)->postJson('/api/admin/webhooks', [
            'name' => 'ระบบภายนอก',
            'url' => 'https://partner.test/hook',
            'events' => [WebhookCatalogue::DOCUMENT_FINALIZED],
        ])->assertCreated()->json();

        $this->assertNotEmpty($created['secret']);

        $listed = json_encode($this->actingAs($admin)->getJson('/api/admin/webhooks')->assertOk()->json());
        $this->assertStringNotContainsString($created['secret'], $listed);
    }

    public function test_a_plain_http_endpoint_is_refused(): void
    {
        $this->actingAs($this->administrator())->postJson('/api/admin/webhooks', [
            'name' => 'ไม่ปลอดภัย',
            'url' => 'http://partner.test/hook',
            'events' => [WebhookCatalogue::DOCUMENT_FINALIZED],
        ])->assertStatus(422);
    }

    public function test_an_unknown_event_name_is_refused(): void
    {
        $this->actingAs($this->administrator())->postJson('/api/admin/webhooks', [
            'name' => 'พิมพ์ผิด',
            'url' => 'https://partner.test/hook',
            'events' => ['saraban.document.finalised'],
        ])->assertStatus(422);
    }

    public function test_an_ordinary_user_cannot_manage_webhooks(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/webhooks')
            ->assertForbidden();
    }

    /** @param array<int,string> $events */
    private function endpointFor(array $events): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'name' => 'ปลายทางทดสอบ',
            'url' => 'https://partner.test/hook',
            'secret' => 'test-signing-secret',
            'events' => $events,
            'is_active' => true,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function administrator(): User
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('system.setting.manage', 'web'));

        return $admin->fresh();
    }

    /**
     * route ทดสอบที่ผูก middleware จริง
     *
     * ตรวจ *กติกาของ middleware* ไม่ใช่ endpoint ใด endpoint หนึ่งของสารบรรณ —
     * ผูกกับ endpoint จริงจะทำให้เทสต์พังทุกครั้งที่ endpoint นั้นเปลี่ยนกติกา
     * ด้วยเหตุที่ไม่เกี่ยวกับการกันทำซ้ำเลย
     */
    private function withIdempotentRoute(): void
    {
        cache()->forget('idempotency-test-counter');

        Route::middleware(['auth:sanctum', 'idempotent'])->post('/api/test-idempotent', function () {
            abort_if(request()->boolean('fail'), 500, 'ตั้งใจให้ล้มเหลว');

            $sequence = cache()->increment('idempotency-test-counter') ?: 1;
            cache()->put('idempotency-test-counter', $sequence);

            return response()->json(['sequence' => $sequence], 201);
        });
    }
}
