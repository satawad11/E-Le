<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class RouteAuthorizationAuditTest extends TestCase
{
    private const PUBLIC_API_URIS = [
        'api/ping',
        'api/login',
        'api/forgot-password',
        'api/reset-password',
        'api/leaves/verify/{hash}',
        'api/leaves/verify/{hash}/qrcode',
        /*
         * INT-21 — สุขภาพระบบสำหรับ uptime monitor ภายนอก (SoT §27)
         *
         * เปิดสาธารณะโดยเจตนา · คืนแค่ ok/degraded/down ไม่มีรายละเอียดใด ๆ
         * — monitor ต้องเรียกได้โดยไม่มีบัญชีผู้ใช้ · รายละเอียดอยู่หลังด่านสิทธิ์
         * ที่ `api/admin/operations/health`
         */
        'api/health',
    ];

    public function test_every_non_public_api_route_requires_authentication(): void
    {
        $unprotected = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'))
            ->reject(fn (Route $route) => in_array($route->uri(), self::PUBLIC_API_URIS, true))
            ->reject(fn (Route $route) => $route->uri() === 'api/up')
            ->filter(fn (Route $route) => ! in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unprotected, 'พบ API ที่ไม่มี auth:sanctum: '.implode(', ', $unprotected));
    }

    /**
     * ทุกเส้นทางของศูนย์ปฏิบัติการระบบต้องประกาศความสามารถที่ต้องใช้
     *
     * เดิมตรวจกับเส้นทาง `api/saraban/*` ซึ่งศูนย์ปฏิบัติการเคยอยู่ใต้นั้น · ย้ายมาตรวจที่ใหม่
     * เมื่อถอดโมดูลสารบรรณ — คิวงาน ธง และหลักฐานต้องไม่เปิดให้ผู้ใช้ที่ล็อกอินแล้วทุกคน
     */
    public function test_every_operations_route_declares_the_ability_it_requires(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/admin/operations/')
                || str_starts_with($route->uri(), 'api/admin/feature-flags'));

        $this->assertNotEmpty($routes->all(), 'ต้องพบเส้นทางของศูนย์ปฏิบัติการ');

        $undeclared = $routes
            ->reject(fn (Route $route) => collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'ability:system.')))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $undeclared, 'เส้นทางศูนย์ปฏิบัติการที่ไม่ได้ประกาศความสามารถ: '.implode(', ', $undeclared));
    }

    public function test_no_saraban_route_remains(): void
    {
        $left = collect(RouteFacade::getRoutes()->getRoutes())
            ->map(fn (Route $route) => $route->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'saraban'))
            ->values()
            ->all();

        $this->assertSame([], $left, 'โมดูลสารบรรณถูกถอดแล้ว แต่ยังมีเส้นทางเหลืออยู่');
    }

    public function test_sensitive_exports_and_downloads_have_specific_throttles(): void
    {
        $sensitiveUris = [
            'api/profile/personal-data',
            'api/leaves/{leave}/pdf',
            'api/leaves/{leave}/word',
            'api/admin/attendances/export/pdf',
            'api/admin/attendances/export/word',
            // CLEANUP-01 (D3) — `/reports/export-*` ของชั้นรายงานเดิมถูกถอดออกแล้ว
            'api/reporting/{report}/export/{format}',
            'api/leave-evidences/{evidence}/download',
        ];

        /*
         * SEC-019 — ต้องเป็น named limiter ไม่ใช่ `throttle:10,1` แบบ inline
         *
         * inline throttle ตั้งกุญแจเป็น sha1(user id) เท่านั้น ทุก route จึงใช้ถังร่วมกัน
         * และถูกนับเพิ่มโดย throttle ของกลุ่มใหญ่ด้วย เรียก API ปกติไม่กี่ครั้ง
         * ก็ทำให้ปุ่มพิมพ์/ส่งออกตอบ 429 ทั้งที่ยังไม่เคยสั่งพิมพ์เลย
         */
        $dedicatedLimiters = ['documents', 'exports', 'personal-data'];

        foreach ($sensitiveUris as $uri) {
            $route = collect(RouteFacade::getRoutes()->getRoutes())->first(fn (Route $route) => $route->uri() === $uri);
            $this->assertNotNull($route, "ไม่พบ route {$uri}");

            $limiters = collect($route->gatherMiddleware())
                ->filter(fn (string $middleware) => str_starts_with($middleware, 'throttle:'))
                ->map(fn (string $middleware) => substr($middleware, strlen('throttle:')))
                ->all();

            $this->assertNotEmpty(
                array_intersect($dedicatedLimiters, $limiters),
                "route {$uri} ไม่มี throttle เฉพาะของตัวเอง (พบ: ".implode(', ', $limiters).')'
            );
        }
    }
}
