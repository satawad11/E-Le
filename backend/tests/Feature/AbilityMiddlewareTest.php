<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * CLEANUP-02 (D5) — ด่านสิทธิ์ของ route เดินผ่าน Gate ทางเดียว
 *
 * เดิม route ใช้ `permission:` ของ Spatie ซึ่งตรวจชื่อสิทธิ์ตรง ๆ ไม่ผ่าน Gate จึง
 * ไม่รู้จักทั้ง "ชื่อที่เทียบเท่ากัน" และ explicit deny ทุกที่จึงต้องเขียนชื่อชุดเดิม
 * คู่กับชื่อชุดมาตรฐานเสมอ (`manage_settings|system.setting.manage`) และสิทธิ์ที่ถูก
 * ตัดเฉพาะรายก็ยังผ่านด่านนี้ได้
 *
 * เทสต์ชุดนี้ล็อกทั้งสองเรื่อง: ผู้ที่ถือชื่อชุดเดิมยังใช้งานได้เหมือนเดิม
 * และการตัดสิทธิ์เฉพาะรายมีผลกับ route จริง
 */
class AbilityMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_holder_of_the_legacy_name_still_passes_a_canonical_route(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('manage_settings', 'web'));

        // route ประกาศไว้เป็น `can:system.setting.manage` เท่านั้น
        $this->actingAs($user)->getJson('/api/admin/settings')->assertOk();
    }

    public function test_a_holder_of_the_canonical_name_passes_too(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('system.setting.manage', 'web'));

        $this->actingAs($user)->getJson('/api/admin/settings')->assertOk();
    }

    public function test_someone_without_either_name_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/settings')->assertForbidden();
    }

    public function test_an_explicit_deny_now_blocks_the_route(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('manage_settings', 'web'));

        DB::table('permission_denies')->insert([
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
            'permission_name' => 'system.setting.manage',
            'reason' => 'ทดสอบการตัดสิทธิ์เฉพาะราย',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/admin/settings')->assertForbidden();
    }

    public function test_the_any_ability_gate_accepts_either_of_two_different_abilities(): void
    {
        $viewSelf = User::factory()->create();
        $viewSelf->givePermissionTo(Permission::findOrCreate('attendance.view_self', 'web'));

        $viewAll = User::factory()->create();
        $viewAll->givePermissionTo(Permission::findOrCreate('attendance.view_all', 'web'));

        $this->actingAs($viewSelf)->getJson('/api/attendance-corrections')->assertOk();
        $this->actingAs($viewAll)->getJson('/api/attendance-corrections')->assertOk();
        $this->actingAs(User::factory()->create())->getJson('/api/attendance-corrections')->assertForbidden();
    }

    public function test_no_route_still_uses_the_alias_blind_permission_middleware(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->filter(fn ($route) => collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'permission:')))
            ->map(fn ($route) => $route->uri())
            ->unique()
            ->values()
            ->all();

        $this->assertSame(
            [],
            $routes,
            'route เหล่านี้ยังใช้ `permission:` ซึ่งไม่รู้จักชื่อที่เทียบเท่าและ explicit deny: '.implode(', ', $routes)
        );
    }
}
