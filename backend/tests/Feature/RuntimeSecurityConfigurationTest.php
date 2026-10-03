<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ตรึงการตั้งค่าความปลอดภัยระดับ runtime และ deployment ที่เคยหลุดกลับมาแล้ว
 *
 * การตั้งค่าเหล่านี้ไม่มี "หน้าจอ" ให้เห็นว่าเปลี่ยนไป การแก้กลับจึงเงียบสนิท
 * จนกว่าจะมีคนเจอด้วยตัวเอง เทสต์ชุดนี้ทำให้การแก้กลับล้ม CI ทันที
 */
class RuntimeSecurityConfigurationTest extends TestCase
{
    /**
     * อ่านไฟล์ที่อยู่ระดับ repo (นอกโฟลเดอร์ backend)
     *
     * บนเครื่องพัฒนา container ของ backend mount เฉพาะ `backend/` ไฟล์เหล่านี้จึง
     * เข้าถึงไม่ได้ — ข้ามไปพร้อมบอกเหตุผล ส่วนบน CI ที่ checkout ทั้ง repo
     * เทสต์ชุดนี้ทำงานเต็มรูปแบบและเป็นด่านจริง
     */
    private function repoFile(string $relative): string
    {
        $path = base_path('../'.$relative);

        if (! is_file($path)) {
            $this->markTestSkipped("ไฟล์ระดับ repo ({$relative}) เข้าถึงไม่ได้จากสภาพแวดล้อมนี้ — ด่านจริงอยู่ที่ CI");
        }

        return (string) file_get_contents($path);
    }

    /**
     * SEC-021 — `serve => true` ทำให้ Laravel ลงทะเบียน route ที่เสิร์ฟทุกไฟล์
     * ใต้ storage/app/private ออกทาง HTTP โดยไม่ผ่าน authentication
     */
    public function test_the_local_disk_does_not_serve_files_over_http(): void
    {
        $this->assertFalse(
            (bool) config('filesystems.disks.local.serve'),
            'filesystems.disks.local.serve ต้องเป็น false — ทางเข้าไฟล์มีทางเดียวคือ /api/files/{path}',
        );

        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter();

        $this->assertFalse(
            $names->contains('storage.local'),
            'route storage.local ไม่ควรถูกลงทะเบียน เพราะเสิร์ฟไฟล์โดยไม่ตรวจสิทธิ์',
        );
    }

    public function test_the_authenticated_file_route_is_the_only_way_in(): void
    {
        $this->getJson('/api/files/signatures/anything.png')->assertUnauthorized();
        $this->get('/storage/signatures/anything.png')->assertNotFound();
    }

    /**
     * SEC-022 — container ต้องตรวจ environment ก่อนเริ่มทำงาน ไม่ใช่ตรวจตอนมีคำขอเข้ามาแล้ว
     */
    public function test_every_php_service_verifies_its_environment_before_starting_work(): void
    {
        $compose = $this->repoFile('docker-compose.yml');

        $this->assertSame(
            3,
            substr_count($compose, 'php artisan app:verify-environment'),
            'backend / worker / scheduler ต้องเรียก app:verify-environment เป็นคำสั่งแรกทุกตัว',
        );

        // ต้องอยู่ "ก่อน" migrate เสมอ มิฉะนั้น schema เปลี่ยนไปแล้วก่อนจะรู้ว่าตั้งค่าผิด
        $this->assertLessThan(
            strpos($compose, 'php artisan migrate --force'),
            strpos($compose, 'php artisan app:verify-environment'),
        );
    }

    /**
     * DEPLOY-006 — Vite dev server ไม่มี authentication และเสิร์ฟ source ดิบทั้งโครงการ
     * จึงต้องไม่อยู่ใน compose ชุดที่เอกสาร deploy สั่งให้ใช้บน production
     */
    public function test_the_production_compose_file_exposes_no_vite_dev_server(): void
    {
        // ตัดบรรทัดคอมเมนต์ออกก่อน — คำอธิบายว่า "ทำไมถึงย้ายออก" ไม่ใช่การเปิดพอร์ต
        $compose = implode("\n", array_filter(
            preg_split('/\R/', $this->repoFile('docker-compose.yml')) ?: [],
            static fn (string $line) => ! str_starts_with(ltrim($line), '#'),
        ));

        $this->assertStringNotContainsString('5173', $compose);
        $this->assertStringNotContainsString('npm run dev', $compose);

        // dev server ยังมีอยู่ แต่ต้องสั่งใช้อย่างชัดเจนด้วยไฟล์แยก
        $dev = $this->repoFile('docker-compose.dev.yml');
        $this->assertStringContainsString('npm run dev', $dev);
        $this->assertStringContainsString('127.0.0.1:5173:5173', $dev);
    }

    /**
     * SEC-020 — dependency ที่มี advisory ต้องถูก pin ไว้ที่รุ่นแก้ไขแล้ว
     *
     * ตรวจจากไฟล์ประกาศ ไม่ใช่จากเครือข่าย เทสต์จึงทำงานได้แม้ CI ไม่มีอินเทอร์เน็ต
     * (การไล่ advisory ใหม่เป็นหน้าที่ของ `composer audit` / `npm audit` ใน CI)
     */
    public function test_dependencies_with_advisories_are_pinned_to_fixed_releases(): void
    {
        $package = json_decode($this->repoFile('frontend/package.json'), true);

        $this->assertSame('1.20.0', $package['dependencies']['axios'] ?? null, 'axios <= 1.19.0 มี advisory ระดับ high หลายรายการ (รอบล่าสุดประกาศ 2026-09-30)');
        $this->assertSame('3.3.18', $package['overrides']['nanoid'] ?? null, 'nanoid <= 3.3.17 มี advisory (GHSA-28wg-ghj8-5hjv)');
        $this->assertSame('8.5.26', $package['overrides']['postcss'] ?? null, 'postcss <= 8.5.22 มี advisory (GHSA-fxqj-rqcc-2cmp)');
    }

    /** ทุกเวอร์ชันของ dependency ต้อง pin แบบตายตัว ไม่ใช่ช่วงเวอร์ชัน */
    public function test_dependency_versions_are_pinned_exactly(): void
    {
        $package = json_decode($this->repoFile('frontend/package.json'), true);
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $ranges = [];

        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach ($package[$section] ?? [] as $name => $constraint) {
                if (preg_match('/[\^~*><|]/', (string) $constraint)) {
                    $ranges[] = "frontend: {$name} => {$constraint}";
                }
            }
        }

        foreach (['require', 'require-dev'] as $section) {
            foreach ($composer[$section] ?? [] as $name => $constraint) {
                if ($name === 'php') {
                    continue;
                }
                if (preg_match('/[\^~*><|]/', (string) $constraint)) {
                    $ranges[] = "backend: {$name} => {$constraint}";
                }
            }
        }

        $this->assertSame([], $ranges, 'เวอร์ชันต้อง pin ตายตัวเพื่อให้ build ซ้ำได้และรู้ตัวเมื่อมี advisory');
    }

    /**
     * CI ต้องเป็นตัวไล่ advisory ใหม่ ไม่ใช่รอให้คนนึกได้
     */
    public function test_ci_audits_dependencies_on_both_sides(): void
    {
        $workflow = $this->repoFile('.github/workflows/ci.yml');

        $this->assertStringContainsString('composer audit', $workflow);
        $this->assertStringContainsString('npm audit', $workflow);
        $this->assertStringContainsString('npm run lint', $workflow);
        $this->assertStringContainsString('npm run typecheck', $workflow);
    }

    /**
     * ES module (.mjs) ต้องถูกเสิร์ฟเป็น JavaScript
     *
     * `mime.types` ของ nginx ไม่รู้จัก `.mjs` จึงส่งเป็น `application/octet-stream`
     * และเบราว์เซอร์ปฏิเสธ module script นั้นทั้งไฟล์ตามข้อกำหนด HTML
     *
     * พบตอนซ้อมเปิดใช้งานจริง: worker ของ pdf.js ในหน้าออกแบบแบบฟอร์มโหลดไม่ได้
     * แบบฟอร์มต้นฉบับจึงไม่ขึ้นเป็นพื้นหลัง — อาการนี้ไม่เกิดกับ `npm run dev`
     * จึงหลุดไปถึงเครื่องจริงได้ง่ายถ้าไม่มีด่านตรวจ
     */
    public function test_es_modules_are_served_as_javascript(): void
    {
        $config = $this->repoFile('docker/nginx/default.conf');

        $this->assertStringContainsString('\.mjs$', $config, 'ต้องมี location เฉพาะไฟล์ .mjs');
        $this->assertStringContainsString(
            'default_type application/javascript;',
            $config,
            'nginx ต้องประกาศชนิดไฟล์ของ .mjs เป็น application/javascript',
        );

        /*
         * ต้องไม่ประกาศ `types { }` ระดับ server เพราะบล็อกนี้ **แทนที่** ตารางชนิดไฟล์
         * ที่สืบทอดมาทั้งชุด ทำให้ CSS/รูปภาพกลายเป็น octet-stream ตามไปด้วย
         */
        $this->assertStringNotContainsString(
            "\n    types {",
            $config,
            'ห้ามใช้บล็อก types ระดับ server — ใช้ location เฉพาะนามสกุลแทน',
        );
    }
}
