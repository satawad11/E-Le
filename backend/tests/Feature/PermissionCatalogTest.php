<?php

namespace Tests\Feature;

use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * CLEANUP-01 (D5) — ชื่อสิทธิ์ต้องมีที่เดียวและต้องมีอยู่จริง
 *
 * ตอนสำรวจพบว่าโค้ดบังคับใช้ชื่อที่ไม่เคยถูกสร้าง (`leave.management.manage`,
 * `system.role.manage`) endpoint และเมนูที่อ้างชื่อเหล่านั้นจึงใช้ได้เฉพาะ Super Admin
 * ที่ผ่าน `Gate::before` ส่วนคนที่ควรมีสิทธิ์ถูกปฏิเสธเงียบ ๆ โดยไม่มีอะไรจับได้
 */
class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_permission_a_route_enforces_exists_in_the_catalogue(): void
    {
        $catalogue = array_keys(PermissionCatalogSeeder::all());

        $missing = collect(RouteFacade::getRoutes()->getRoutes())
            ->flatMap(fn (Route $route) => $route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware))
            ->flatMap(function (string $middleware): array {
                if (str_starts_with($middleware, 'can:')) {
                    return [substr($middleware, 4)];
                }
                if (str_starts_with($middleware, 'permission:')) {
                    return explode('|', substr($middleware, 11));
                }

                return [];
            })
            // `can:` ใช้กับ policy ability ด้วย ไม่ใช่ชื่อสิทธิ์เสมอไป — สนใจเฉพาะรูปแบบชื่อสิทธิ์
            ->filter(fn (string $name) => preg_match('/^[a-z][a-z_.]*$/', $name) === 1)
            ->unique()
            ->reject(fn (string $name) => in_array($name, $catalogue, true))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $missing,
            'route บังคับใช้สิทธิ์ที่ไม่มีในสารบัญ: '.implode(', ', $missing)
        );
    }

    public function test_every_permission_the_application_code_checks_exists_in_the_catalogue(): void
    {
        $catalogue = array_keys(PermissionCatalogSeeder::all());
        $referenced = [];

        foreach (Finder::create()->files()->name('*.php')->in(app_path()) as $file) {
            preg_match_all("/(?:can|hasPermissionTo)\('([a-z][a-z_.]*)'\)/", $file->getContents(), $matches);
            $referenced = array_merge($referenced, $matches[1]);
        }

        $missing = array_values(array_diff(array_unique($referenced), $catalogue));

        $this->assertSame(
            [],
            $missing,
            'โค้ดตรวจสิทธิ์ที่ไม่มีในสารบัญ: '.implode(', ', $missing)
        );
    }

    public function test_every_permission_a_menu_requires_exists_in_the_catalogue(): void
    {
        $catalogue = array_keys(PermissionCatalogSeeder::all());

        $referenced = collect(MenuSeeder::TREE)
            ->flatMap(fn (array $item) => array_merge(
                $item['permissions'] ?? [],
                collect($item['children'] ?? [])->flatMap(fn (array $child) => $child['permissions'] ?? [])->all(),
            ))
            ->unique();

        $missing = $referenced->reject(fn (string $name) => in_array($name, $catalogue, true))->values()->all();

        $this->assertSame(
            [],
            $missing,
            'เมนูอ้างสิทธิ์ที่ไม่มีในสารบัญ: '.implode(', ', $missing)
        );
    }

    /**
     * INT-02 — สารบัญสิทธิ์กับ manifest ของโมดูลต้องไม่แตกคอกัน
     *
     * ชื่อสิทธิ์ถูก "สร้าง" จากสองทางที่ไม่เคยคุยกัน: `PermissionCatalogSeeder`
     * ซึ่งประกาศตัวเองเป็นแหล่งความจริงเดียว และ `ModulePermissionSeeder` ที่สร้าง
     * จาก `config('modules.*.permissions')` อีกทางหนึ่ง ทั้งสองทางถูกเรียกใน
     * `DatabaseSeeder` เหมือนกัน แต่ไม่มีอะไรบังคับว่าต้องตรงกัน
     *
     * ผลที่ตามมาถ้าปล่อยไว้: โมดูลประกาศสิทธิ์ที่ไม่อยู่ในสารบัญ ชื่อนั้นจะถูกสร้าง
     * ตอนติดตั้งจริง แต่ `PermissionCatalogTest` สามข้อบนไม่เห็น จึงตรวจไม่เจอ
     * เวลาที่ route หรือเมนูอ้างถึงมัน — เป็นรอยรั่วแบบเดียวกับที่ CLEANUP-01 (D5) แก้ไป
     */
    public function test_every_permission_a_module_manifest_declares_exists_in_the_catalogue(): void
    {
        $catalogue = array_keys(PermissionCatalogSeeder::all());

        $missing = collect(config('modules', []))
            ->flatMap(fn ($module) => array_keys($module['permissions'] ?? []))
            ->unique()
            ->reject(fn (string $name) => in_array($name, $catalogue, true))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $missing,
            'manifest ของโมดูลประกาศสิทธิ์ที่ไม่มีในสารบัญ: '.implode(', ', $missing)
        );
    }

    public function test_seeding_the_catalogue_creates_every_name_without_granting_anything(): void
    {
        $this->seed(PermissionCatalogSeeder::class);

        foreach (array_keys(PermissionCatalogSeeder::all()) as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name, 'guard_name' => 'web']);
        }

        // สร้างชื่อเท่านั้น ต้องไม่ผูกกับบทบาทใดเลย
        $this->assertDatabaseCount('role_has_permissions', 0);
    }

    public function test_the_names_the_code_relies_on_are_present_after_a_normal_install(): void
    {
        $this->seed(PermissionCatalogSeeder::class);

        foreach (['leave.management.manage', 'system.role.manage', 'leave.report.view', 'system.setting.manage'] as $name) {
            $this->assertNotNull(
                Permission::where('name', $name)->first(),
                "ชื่อสิทธิ์ {$name} ต้องมีอยู่หลังติดตั้ง"
            );
        }
    }
}
