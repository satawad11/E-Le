<?php

namespace Tests\Feature\Rebuild;

use App\Http\Middleware\DenyUntilPermissionEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * R02-11 — ระหว่าง R-02–R-04 ทุก route ที่ต้องยืนยันตัวตนตอบ 403 (deny by default)
 * ยกเว้นการออกจากระบบ ต่ออายุ token และข้อมูลบัญชีของตนเอง
 */
class DenyByDefaultTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, Route> */
    private function authenticatedApiRoutes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'))
            ->filter(fn (Route $route) => DenyUntilPermissionEngine::requiresAuthentication($route->gatherMiddleware()))
            ->values()
            ->all();
    }

    private function concreteUri(Route $route): string
    {
        // ใส่ค่าให้ทุก parameter — middleware ตอบก่อน route model binding จึงไม่ต้องมีข้อมูลจริง
        return '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function (array $m) use ($route) {
            $pattern = $route->wheres[$m[1]] ?? null;

            if ($pattern !== null && str_contains($pattern, '|')) {
                // whereIn(...) หรือ pattern ผสม — เลือกตัวเลือกที่เป็นค่าตายตัว ไม่ใช่ regex
                $literal = collect(explode('|', trim($pattern, '()')))->first(fn ($c) => preg_match('/^[A-Za-z0-9_-]+$/', $c));

                return $literal ?? '1';
            }

            return $m[1] === 'id' ? '00000000-0000-0000-0000-000000000000' : '1';
        }, $route->uri());
    }

    public function test_every_authenticated_api_route_is_forbidden_for_an_active_user(): void
    {
        Sanctum::actingAs(User::factory()->withPersonnel()->create());

        $routes = $this->authenticatedApiRoutes();
        $this->assertGreaterThan(100, count($routes));

        $checked = 0;
        foreach ($routes as $route) {
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            if (in_array($method.' '.$route->uri(), DenyUntilPermissionEngine::ALLOWED, true)) {
                continue;
            }

            $response = $this->json($method, $this->concreteUri($route));

            $this->assertSame(403, $response->status(), "{$method} {$route->uri()} ต้องตอบ 403 จนกว่า R-04");
            $this->assertSame(DenyUntilPermissionEngine::MESSAGE, $response->json('message'));
            $checked++;
        }

        $this->assertGreaterThan(100, $checked);
    }

    public function test_unauthenticated_requests_still_get_401(): void
    {
        $this->getJson('/api/leaves')->assertUnauthorized();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_allowed_self_service_routes_still_work(): void
    {
        $user = User::factory()->withPersonnel()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('uuid', $user->uuid)
            ->assertJsonPath('permission_names', [])
            ->assertJsonMissingPath('id')
            ->assertJsonMissingPath('password');

        $this->postJson('/api/logout')->assertOk();
    }

    public function test_public_routes_are_not_affected(): void
    {
        $this->getJson('/api/ping')->assertOk()->assertJson(['message' => 'pong']);
    }

    public function test_permission_middleware_alias_always_denies(): void
    {
        RouteFacade::middleware(['api', 'permission:anything.at.all'])->get('/api/__r02_probe', fn () => 'reached');

        $this->getJson('/api/__r02_probe')->assertForbidden();
    }

    public function test_no_ability_is_granted_without_permission_engine(): void
    {
        $user = User::factory()->withPersonnel()->create(['is_break_glass' => true]);

        foreach (['leave.management.manage', 'system.setting.manage', 'admin.users.manage_permissions', 'anything'] as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability), "ไม่มีบัญชีใดข้ามการตรวจสิทธิ์: {$ability}");
            $this->assertFalse($user->can($ability));
        }
    }
}
