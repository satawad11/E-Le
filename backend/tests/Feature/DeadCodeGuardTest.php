<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * CLEANUP-01 — ด่านกันโค้ดตายกลับเข้ามาใหม่
 *
 * รายงาน `docs/audits/MENU-FEATURE-INVENTORY-2026-08-27.md` (D9) พบ controller 4 ตัว
 * และ model 3 ตัวที่ไม่มีใครเรียกเลย ค้างอยู่จนอ่านโค้ดแล้วเข้าใจผิดว่ายังใช้งาน
 * เทสต์นี้ทำให้ของแบบนั้นถูกจับได้ตั้งแต่ CI ไม่ต้องรอให้คนมาไล่อ่านเอง
 */
class DeadCodeGuardTest extends TestCase
{
    /**
     * Controller ที่ยังไม่ผูก route ได้ชั่วคราว ต้องมีเหตุผลกำกับเสมอ
     *
     * @var array<string,string>
     */
    private const CONTROLLERS_WITHOUT_ROUTES = [
        'App\Http\Controllers\Controller' => 'base class ของ controller ทุกตัว',
    ];

    public function test_every_controller_is_reachable_through_a_route(): void
    {
        $routed = collect(RouteFacade::getRoutes()->getRoutes())
            ->map(fn (Route $route) => $route->getAction('controller'))
            ->filter()
            ->map(fn (string $action) => explode('@', $action)[0])
            ->unique()
            ->all();

        $orphans = collect($this->classesIn(app_path('Http/Controllers'), 'App\Http\Controllers'))
            ->reject(fn (string $class) => array_key_exists($class, self::CONTROLLERS_WITHOUT_ROUTES))
            ->reject(fn (string $class) => in_array($class, $routed, true))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $orphans,
            'พบ controller ที่ไม่มี route เรียกถึง — ลบทิ้งหรือผูก route ให้เรียบร้อย: '.implode(', ', $orphans)
        );
    }

    /*
     * CLEANUP-01 — route ที่ชี้ไปยังเมธอดที่ไม่มีอยู่จริง
     *
     * พบ 3 เส้นทางแบบนี้ตอนสำรวจ (`/admin/departments-list`, `/admin/users/import`,
     * `GET /admin/roles/{role}`) ทุกเส้นทางพังเงียบ ๆ — หน้าเว็บมีปุ่มให้กดแต่กดแล้วพัง
     * เพราะไม่มีอะไรตรวจว่าเมธอดปลายทางมีจริง
     */
    public function test_every_route_points_at_a_method_that_exists(): void
    {
        $broken = collect(RouteFacade::getRoutes()->getRoutes())
            ->map(fn (Route $route) => [$route, $route->getAction('controller')])
            ->filter(fn (array $pair) => is_string($pair[1]) && str_contains($pair[1], '@'))
            ->reject(function (array $pair) {
                [$class, $method] = explode('@', $pair[1]);

                return class_exists($class) && method_exists($class, $method);
            })
            ->map(fn (array $pair) => implode('|', $pair[0]->methods()).' '.$pair[0]->uri().' → '.$pair[1])
            ->values()
            ->all();

        $this->assertSame(
            [],
            $broken,
            'พบ route ที่ชี้ไปยังเมธอดที่ไม่มีอยู่: '.implode(', ', $broken)
        );
    }

    public function test_every_model_is_referenced_by_application_code(): void
    {
        $sources = $this->sourceContents();

        $orphans = collect($this->classesIn(app_path('Models'), 'App\Models'))
            ->reject(function (string $class) use ($sources) {
                $short = class_basename($class);

                foreach ($sources as $path => $contents) {
                    if (str_ends_with(str_replace('\\', '/', $path), 'Models/'.$short.'.php')) {
                        continue;
                    }

                    if (preg_match('/\b'.preg_quote($short, '/').'\b/', $contents) === 1) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();

        $this->assertSame(
            [],
            $orphans,
            'พบ model ที่ไม่มีโค้ดส่วนใดอ้างถึง — ลบทิ้งหรือนำไปใช้จริง: '.implode(', ', $orphans)
        );
    }

    /**
     * @return array<int,string>
     */
    private function classesIn(string $directory, string $namespace): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            /** @var SplFileInfo $file */
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $classes[] = $namespace.'\\'.$relative;
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return array<string,string>
     */
    private function sourceContents(): array
    {
        $contents = [];

        $finder = Finder::create()->files()->name('*.php')
            ->in([app_path(), base_path('routes'), base_path('database'), base_path('config')]);

        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            $contents[$file->getPathname()] = $file->getContents();
        }

        return $contents;
    }
}
