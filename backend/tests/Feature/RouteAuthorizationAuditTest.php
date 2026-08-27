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

    public function test_sensitive_exports_and_downloads_have_specific_throttles(): void
    {
        $sensitiveUris = [
            'api/profile/personal-data',
            'api/leaves/{leave}/pdf',
            'api/leaves/{leave}/word',
            'api/admin/attendances/export/pdf',
            'api/admin/attendances/export/word',
            'api/reports/export-excel',
            'api/reports/export-pdf',
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
