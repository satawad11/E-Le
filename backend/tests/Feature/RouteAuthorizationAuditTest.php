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
         * INT-12 — ตรวจสอบหนังสือราชการจาก QR (SoT §12)
         *
         * เปิดสาธารณะโดยเจตนา เช่นเดียวกับการตรวจสอบใบลา — ผู้ตรวจสอบคือคนทั่วไป
         * ที่ถือกระดาษอยู่ในมือ ไม่ใช่ผู้ใช้ของระบบ · การเข้าถึงคุมด้วยโทเคนสุ่ม
         * 40 ตัวอักษรที่เดาไม่ได้ + `throttle:verify` และคืนเฉพาะข้อมูลที่ปรากฏ
         * บนหน้าเอกสารอยู่แล้ว
         */
        'api/saraban/verify/{hash}',
        'api/saraban/verify/{hash}/qrcode',
        /*
         * INT-21 — สุขภาพระบบสำหรับ uptime monitor ภายนอก (SoT §27)
         *
         * เปิดสาธารณะโดยเจตนา · คืนแค่ ok/degraded/down ไม่มีรายละเอียดใด ๆ
         * — monitor ต้องเรียกได้โดยไม่มีบัญชีผู้ใช้ · รายละเอียดอยู่หลังด่านสิทธิ์
         * ที่ `api/saraban/operations/health`
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
     * เส้นทางของโมดูลสารบรรณที่ **ตั้งใจ** ไม่มีด่านความสามารถ
     *
     * ทุกชื่อในรายการนี้ต้องมีเหตุผลกำกับ · การเพิ่มชื่อเข้ามาเท่ากับประกาศว่า
     * "ผู้ใช้ที่ล็อกอินแล้วทุกคนเรียกเส้นทางนี้ได้" ซึ่งต้องเป็นการตัดสินใจ
     * ที่มีคนเห็น ไม่ใช่การลืมใส่ middleware
     *
     * @var array<int,string>
     */
    private const SARABAN_URIS_WITHOUT_ABILITY = [
        /*
         * ดูอำนาจ **ของตัวเอง** ไม่ต้องมีสิทธิ์ — ทุกคนควรตรวจสอบได้ว่าตนทำอะไรได้บ้าง
         * การดูของผู้อื่นถูกกั้นด้วย `saraban.authority.view` ในตัว controller
         */
        'api/saraban/authority',

        /*
         * มุมมองที่บันทึกไว้เป็นของผู้ใช้รายคน ไม่ใช่ข้อมูลราชการ — เจ้าของเท่านั้น
         * ที่แก้ได้ (`assertOwner` ตอบ 404 ไม่ใช่ 403 เพื่อไม่ยืนยันว่ามีอยู่จริง)
         * และตัวมุมมองเก็บ "เงื่อนไขการค้น" ไม่ใช่ผลลัพธ์ · ผลที่ได้ยังผ่าน
         * `DocumentPolicy` รายฉบับตามปกติเสมอ
         */
        'api/saraban/saved-views',
        'api/saraban/saved-views/{view}',

        // เปิดสาธารณะโดยเจตนา — เหตุผลเดียวกับที่อธิบายไว้ใน PUBLIC_API_URIS
        'api/saraban/verify/{hash}',
        'api/saraban/verify/{hash}/qrcode',
    ];

    /**
     * ทุกเส้นทางของโมดูลสารบรรณต้องประกาศความสามารถที่ต้องใช้
     *
     * **ที่มา (ตรวจทาน 2026-09-05):** การตรวจทานรอบปิดงานพบช่องโหว่สิทธิ์รายวัตถุ
     * สองจุด ซึ่งทั้งคู่ **มีด่าน `ability:` ครบ** — ด่านระดับความสามารถจึงไม่พอ
     * แต่การ**ไม่มีเลย**ยิ่งแย่กว่า · เทสต์นี้กันกรณีหลัง ส่วนกรณีแรกกันด้วย
     * `DocumentPolicy` / `DocumentCasePolicy` และเทสต์ขอบเขตของแต่ละหน้าที่
     */
    public function test_every_saraban_route_declares_the_ability_it_requires(): void
    {
        $undeclared = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/saraban/'))
            ->reject(fn (Route $route) => in_array($route->uri(), self::SARABAN_URIS_WITHOUT_ABILITY, true))
            ->reject(fn (Route $route) => collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => is_string($middleware)
                    && str_starts_with($middleware, 'ability:')))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(
            [],
            $undeclared,
            "เส้นทางสารบรรณที่ไม่ได้ประกาศความสามารถ — ผู้ใช้ที่ล็อกอินแล้วทุกคนเรียกได้:\n  ".
            implode("\n  ", $undeclared),
        );
    }

    /** รายการยกเว้นต้องสะท้อนความจริง — เส้นทางที่หายไปแล้วต้องถูกถอดออก */
    public function test_the_ability_exemption_list_has_no_stale_entries(): void
    {
        $existing = collect(RouteFacade::getRoutes()->getRoutes())
            ->map(fn (Route $route) => $route->uri());

        $stale = collect(self::SARABAN_URIS_WITHOUT_ABILITY)
            ->reject(fn (string $uri) => $existing->contains($uri))
            ->values()
            ->all();

        $this->assertSame([], $stale, 'มีชื่อในรายการยกเว้นที่ไม่ตรงกับเส้นทางจริงแล้ว: '.implode(', ', $stale));
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
