<?php

namespace Tests\Unit;

use App\Support\ProductionEnvironmentGuard;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductionEnvironmentGuardTest extends TestCase
{
    /** ค่าที่ปลอดภัยครบทุกข้อ ใช้เป็นฐานแล้วค่อยทำให้พังทีละข้อ */
    private const SAFE_CONTEXT = [
        'app_key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'serve_local_disk' => false,
        'app_url' => 'https://ela.example.go.th',
    ];

    public function test_it_rejects_debug_mode_in_production(): void
    {
        $this->expectException(LogicException::class);

        ProductionEnvironmentGuard::validate('production', true, self::SAFE_CONTEXT);
    }

    public function test_it_rejects_a_missing_app_key_in_production(): void
    {
        $problems = ProductionEnvironmentGuard::problems('production', false, [
            ...self::SAFE_CONTEXT,
            'app_key' => null,
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('APP_KEY', $problems[0]);
    }

    /**
     * SEC-021/SEC-022 — `filesystems.disks.local.serve` เปิด route ที่เสิร์ฟไฟล์
     * ใต้ storage โดยไม่ผ่าน authentication ทับทางเข้าไฟล์ที่รับรอง
     */
    public function test_it_rejects_a_served_local_disk_in_production(): void
    {
        $problems = ProductionEnvironmentGuard::problems('production', false, [
            ...self::SAFE_CONTEXT,
            'serve_local_disk' => true,
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('serve', $problems[0]);
    }

    public function test_it_rejects_a_plain_http_app_url_in_production(): void
    {
        $problems = ProductionEnvironmentGuard::problems('production', false, [
            ...self::SAFE_CONTEXT,
            'app_url' => 'http://ela.example.go.th',
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('APP_URL', $problems[0]);
    }

    /**
     * APP_ENV ที่พิมพ์ผิด (เช่น "produciton") ทำให้การป้องกันทุกข้อของ production
     * เงียบไปทั้งหมด จึงต้องถูกปฏิเสธแทนที่จะปล่อยผ่านเป็น environment ที่ไม่รู้จัก
     */
    public function test_it_rejects_an_unknown_environment_name(): void
    {
        $problems = ProductionEnvironmentGuard::problems('produciton', true, self::SAFE_CONTEXT);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('APP_ENV', $problems[0]);
    }

    public function test_it_reports_every_problem_not_just_the_first(): void
    {
        $problems = ProductionEnvironmentGuard::problems('production', true, [
            'app_key' => null,
            'serve_local_disk' => true,
            'app_url' => 'http://ela.example.go.th',
        ]);

        $this->assertCount(4, $problems);
    }

    #[DataProvider('safeEnvironmentProvider')]
    public function test_it_accepts_safe_environment_combinations(string $environment, bool $debug): void
    {
        ProductionEnvironmentGuard::validate($environment, $debug, self::SAFE_CONTEXT);

        $this->addToAssertionCount(1);
    }

    public static function safeEnvironmentProvider(): array
    {
        return [
            'production without debug' => ['production', false],
            'local with debug' => ['local', true],
            'testing with debug' => ['testing', true],
            'staging with debug' => ['staging', true],
        ];
    }

    /**
     * นอก production ค่าที่ไม่ปลอดภัยของ production ไม่ถูกบังคับ
     * เครื่องพัฒนาจึงใช้ http และ debug ได้ตามปกติ
     */
    public function test_non_production_environments_are_not_held_to_production_rules(): void
    {
        $this->assertSame([], ProductionEnvironmentGuard::problems('local', true, [
            'app_key' => null,
            'serve_local_disk' => true,
            'app_url' => 'http://localhost:5173',
        ]));
    }
}
