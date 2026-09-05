<?php

namespace Tests\Feature;

use App\Contracts\Files\MalwareScanner;
use App\Services\Files\ScanResult;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * สิ่งที่ต้อง "ถูกลงทะเบียนไว้" จึงจะทำงานได้ — limiter และ binding ของ container
 *
 * **ที่มาของเทสต์นี้ (INT-22)**
 *
 * ระหว่าง INT-21 มีการกู้ไฟล์ `AppServiceProvider.php` ผิดพลาดจน limiter สองตัว
 * (`password-change`, `personal-record`) หายไป · ผลคือ **ทุกคำขอที่ผ่าน route
 * เหล่านั้นตอบ 500** ด้วย `MissingRateLimiterException` — ไม่ใช่ 429 ไม่ใช่ 403
 * แต่พังทั้งเส้นทาง
 *
 * เทสต์เดิมจับได้เพราะบังเอิญมีเทสต์ที่ยิงเข้า endpoint เหล่านั้นอยู่แล้ว ·
 * endpoint ที่ยังไม่มีเทสต์จะพังเงียบจนกว่าผู้ใช้จะเจอ
 *
 * เทสต์นี้ตรวจ **ความสัมพันธ์โดยตรง** ระหว่าง route กับ limiter ที่ลงทะเบียน
 * จึงครอบคลุมทุก endpoint รวมถึงที่ยังไม่มีเทสต์ของตัวเอง
 */
class RateLimiterRegistrationTest extends TestCase
{
    /**
     * ทุกสัญญา (interface) ที่โค้ดเรียกผ่าน container ต้องผูกไว้จริง
     *
     * **ที่มา (INT-22):** `MalwareScanner` ไม่ถูกผูกไว้เลยหลังการกู้ไฟล์ผิดพลาด
     * ใน INT-21 · ผลคือ **ทุกการอัปโหลดไฟล์ตอบ 500** — แต่ไม่มีเทสต์ใดจับได้
     * นานหลายชั่วโมง เพราะเทสต์ที่อัปโหลดไฟล์ส่วนใหญ่ใช้ค่าที่ผ่าน validation
     * ชั้นก่อนหน้าไปก่อน และเทสต์ที่จับได้จริงกำลัง `skip` อยู่พอดี
     *
     * เทสต์นี้ถามคอนเทนเนอร์ตรง ๆ จึงไม่ขึ้นกับว่ามีเทสต์อื่นเดินผ่านเส้นทางนั้นไหม
     */
    public function test_every_contract_the_code_resolves_is_bound(): void
    {
        $this->assertInstanceOf(
            MalwareScanner::class,
            app(MalwareScanner::class),
            'MalwareScanner ต้องผูกไว้ ไม่งั้นการอัปโหลดไฟล์ทุกครั้งจะตอบ 500',
        );

        // ตัวตรวจต้องทำงานได้จริง ไม่ใช่แค่สร้าง object ได้
        $this->assertInstanceOf(ScanResult::class, app(MalwareScanner::class)->scan('probe'));
    }

    public function test_every_named_throttle_used_by_a_route_is_registered(): void
    {
        $used = collect(RouteFacade::getRoutes()->getRoutes())
            ->flatMap(fn (Route $route) => $route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'))
            ->map(fn (string $middleware) => substr($middleware, strlen('throttle:')))
            /*
             * `throttle:60,1` แบบใส่ตัวเลขตรง ๆ ไม่ต้องลงทะเบียน — คัดออก
             * ด้วยการดูว่ามีตัวเลขและจุลภาคหรือไม่
             */
            ->reject(fn (string $name) => (bool) preg_match('/^\d+,\d+$/', $name))
            ->unique()
            ->values();

        $this->assertNotEmpty($used, 'ไม่พบ named throttle เลย — เทสต์นี้คงกำลังตรวจผิดที่');

        $missing = $used
            ->reject(fn (string $name) => RateLimiter::limiter($name) !== null)
            ->values()
            ->all();

        $this->assertSame(
            [],
            $missing,
            'route ใช้ชื่อ throttle ที่ยังไม่ได้ลงทะเบียน — คำขอจะตอบ 500: '.implode(', ', $missing),
        );
    }

    /**
     * limiter ที่ลงทะเบียนแล้วแต่ไม่มี route ใดใช้ = โค้ดที่ตายแล้ว
     *
     * ไม่ทำให้ระบบพัง แต่ทำให้คนอ่านเข้าใจผิดว่ามีการจำกัดอัตราตรงนั้นอยู่
     */
    public function test_no_registered_limiter_is_left_unused(): void
    {
        $used = collect(RouteFacade::getRoutes()->getRoutes())
            ->flatMap(fn (Route $route) => $route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'))
            ->map(fn (string $middleware) => substr($middleware, strlen('throttle:')))
            ->unique();

        $registered = [
            'api', 'public-api', 'verify', 'login', 'password', 'token-refresh',
            'password-change', 'personal-data', 'personal-record',
            'documents', 'exports', 'files',
        ];

        $unused = collect($registered)
            ->reject(fn (string $name) => $used->contains($name))
            ->values()
            ->all();

        $this->assertSame([], $unused, 'มี limiter ที่ลงทะเบียนไว้แต่ไม่มี route ใดใช้: '.implode(', ', $unused));
    }
}
