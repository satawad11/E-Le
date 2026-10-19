<?php

namespace Tests\Feature\Rebuild;

use App\Authz\Manifest\ModuleRegistry;
use App\Authz\Manifest\ModuleSynchronizer;
use App\Http\Middleware\EnsureRouteUsesPermissionEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * deny by default (PROJECT_RULES ข้อ 4) — R-04 แทนการปฏิเสธทั้งหมดของ R-02 ด้วยกติการะดับ route
 *
 * ทุก route ที่ต้องยืนยันตัวตน: เป็น self-service · หรือประกาศ ->permission() ที่มีใน manifest (ต่อ PermissionEngine)
 * · หรือถูกปฏิเสธ 403 (โมดูลเดิมที่ยังไม่ย้าย — R-08)
 */
class DenyByDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleSynchronizer::class)->sync();
    }

    /** @return array<int, Route> */
    private function authenticatedApiRoutes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'))
            ->filter(fn (Route $route) => EnsureRouteUsesPermissionEngine::requiresAuthentication($route->gatherMiddleware()))
            ->values()
            ->all();
    }

    private function concreteUri(Route $route): string
    {
        return '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function (array $m) use ($route) {
            $name = explode(':', $m[1])[0];
            $pattern = $route->wheres[$name] ?? null;
            if ($pattern !== null && str_contains($pattern, '|')) {
                $literal = collect(explode('|', trim($pattern, '()')))->first(fn ($c) => preg_match('/^[A-Za-z0-9_-]+$/', $c));

                return $literal ?? '1';
            }

            return in_array($name, ['id', 'unit', 'position', 'assignment', 'personnel', 'changeRequest'], true) || str_contains($m[1], ':uuid')
                ? '00000000-0000-0000-0000-000000000000'
                : '1';
        }, $route->uri());
    }

    public function test_every_authenticated_route_denies_a_user_without_any_permission(): void
    {
        // บัญชีที่ไม่มีบุคลากร/ตำแหน่ง/grant = ไม่มีสิทธิ์ใดเลย
        Sanctum::actingAs(User::factory()->create());
        $gate = app(EnsureRouteUsesPermissionEngine::class);

        $checked = 0;
        foreach ($this->authenticatedApiRoutes() as $route) {
            if ($gate->isSelfService($route)) {
                continue;
            }
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $status = $this->json($method, $this->concreteUri($route))->status();

            $this->assertContains($status, [403, 404], "{$method} {$route->uri()} ต้องไม่ผ่านสำหรับผู้ไม่มีสิทธิ์ (ได้ {$status})");
            $checked++;
        }

        $this->assertGreaterThan(100, $checked);
    }

    public function test_legacy_routes_without_engine_permission_are_blocked_before_the_controller(): void
    {
        Sanctum::actingAs(User::factory()->withPersonnel()->create());

        foreach (['/api/leaves', '/api/dashboard', '/api/settings', '/api/notifications', '/api/admin/attendances'] as $uri) {
            $this->getJson($uri)->assertForbidden()->assertJsonPath('message', EnsureRouteUsesPermissionEngine::MESSAGE);
        }
    }

    public function test_every_route_that_passes_the_gate_declares_permissions_that_exist_in_manifests(): void
    {
        $gate = app(EnsureRouteUsesPermissionEngine::class);
        $known = array_keys(app(ModuleRegistry::class)->permissions());

        foreach ($this->authenticatedApiRoutes() as $route) {
            if (! $gate->declaresEnginePermission($route)) {
                continue;
            }
            $declared = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))->first();
            foreach (explode('|', substr($declared, strlen('permission:'))) as $name) {
                $this->assertContains($name, $known, "{$route->uri()} ประกาศ {$name} ที่ไม่มีใน manifest");
            }
        }
    }

    public function test_unauthenticated_requests_still_get_401(): void
    {
        $this->getJson('/api/leaves')->assertUnauthorized();
        $this->getJson('/api/me/menu')->assertUnauthorized();
    }

    public function test_self_service_routes_work_for_any_active_account(): void
    {
        $user = User::factory()->withPersonnel()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('uuid', $user->uuid)->assertJsonMissingPath('id');
        $this->getJson('/api/me/permissions')->assertOk();
        $this->getJson('/api/me/menu')->assertOk();
        $this->postJson('/api/logout')->assertOk();
    }

    public function test_public_routes_are_not_affected(): void
    {
        $this->getJson('/api/ping')->assertOk()->assertJson(['message' => 'pong']);
    }

    public function test_permission_middleware_asks_the_engine(): void
    {
        RouteFacade::middleware(['api', 'auth:sanctum'])->get('/api/__r04_probe', fn () => 'reached')->permission('org.unit.view');
        RouteFacade::middleware(['api', 'auth:sanctum'])->get('/api/__r04_unknown', fn () => 'reached')->permission('no.such.permission');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/__r04_probe')->assertForbidden();
        $this->getJson('/api/__r04_unknown')->assertForbidden();
    }

    public function test_gate_grants_nothing_and_has_no_before_hook(): void
    {
        $user = User::factory()->withPersonnel()->create(['is_break_glass' => true]);

        foreach (['leave.management.manage', 'system.setting.manage', 'anything'] as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability), "ไม่มีบัญชีใดข้ามการตรวจสิทธิ์: {$ability}");
        }
    }
}
