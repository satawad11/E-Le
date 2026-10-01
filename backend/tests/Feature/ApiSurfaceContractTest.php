<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * CLEANUP-02 — ทุก endpoint ต้องมีคนเรียก และทุกปุ่มต้องมี endpoint รองรับ
 *
 * ตอนสำรวจรอบสองพบ endpoint 11 เส้นทางที่ไม่มีหน้าจอใดเรียกเลย บางตัวคือฟีเจอร์ที่
 * สร้างเสร็จแล้วแต่ไม่มีหน้าจอ (การขอแก้ไขเวลา, การตั้งค่าการแจ้งเตือนรายบุคคล)
 * บางตัวคือของที่ถูกแทนที่ไปแล้วแต่ลืมถอด — ทั้งสองแบบมองไม่เห็นจนกว่าจะไล่มือ
 *
 * เทสต์นี้ผูกสองฝั่งเข้าด้วยกัน: endpoint ที่ไม่มีใครเรียกต้องมีเหตุผลกำกับไว้ในรายการ
 * ด้านล่างเสมอ มิฉะนั้นถือว่าเป็นฟีเจอร์ที่ยังไม่มีทางเข้า หรือของที่ลืมถอด
 */
class ApiSurfaceContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * endpoint ที่หน้าเว็บไม่ได้เรียกโดยตรง พร้อมเหตุผล
     *
     * @var array<string,string>
     */
    private const CALLED_FROM_ELSEWHERE = [
        'api/ping' => 'ใช้ตรวจสุขภาพระบบจากภายนอก',
        'api/health' => 'สัญญาสุขภาพสำหรับเครื่องมือเฝ้าระวัง ตรวจโดย OperationsHealthAndReadOnlyTest',
        'api/v1/openapi.json' => 'คำอธิบาย API สำหรับผู้เชื่อมต่อ ตรวจโดย ApiIdempotencyAndWebhookTest',
        'api/login' => 'เรียกจาก auth store',
        'api/logout' => 'เรียกจาก auth store',
        'api/refresh-token' => 'เรียกจาก auth store',
        'api/user' => 'เรียกจาก auth store',
        'api/leaves/verify/{hash}/qrcode' => 'ฝัง QR ลงในเอกสารจากฝั่ง server',
        'api/files/{path}' => 'เรียกผ่าน directive secure-src',
        'api/v1/reports/vocational/evaluation-summary' => 'รายงานสรุปวันลาและเวลาปฏิบัติราชการรอบเลื่อนขั้นเงินเดือน ก.ค.ศ. (เฟส 2)',
        'api/v1/reports/vocational/teacher-pa-evidence' => 'แฟ้มหลักฐานผลการปฏิบัติงานรายบุคคลสำหรับ ว PA (เฟส 2)',
        'api/v1/attendance/biometric/import-file' => 'ระบบนำเข้าไฟล์จากเครื่องสแกน Biometric Hub (เฟส 2)',
    ];

    public function test_every_api_endpoint_is_reachable_from_the_web_application(): void
    {
        $frontend = $this->frontendCallPatterns();

        if ($frontend === null) {
            $this->markTestSkipped('ไม่พบซอร์สของหน้าเว็บจากบริบทนี้ (CI เป็นตัวบังคับ)');
        }

        $orphans = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'))
            ->map(fn (Route $route) => $route->uri())
            ->unique()
            ->reject(fn (string $uri) => array_key_exists($uri, self::CALLED_FROM_ELSEWHERE))
            ->reject(fn (string $uri) => $this->isCalledBy($uri, $frontend))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $orphans,
            'พบ endpoint ที่ไม่มีหน้าจอใดเรียก — สร้างหน้าจอให้ใช้งานได้ ถอดออก หรือระบุเหตุผลไว้ใน CALLED_FROM_ELSEWHERE: '
                .implode(', ', $orphans)
        );
    }

    /**
     * เปลี่ยนเส้นทางที่หน้าเว็บเรียกให้เป็นรูปแบบเทียบได้
     *
     * `${...}` ของ template literal และค่าที่ต่อสตริงเอง ถูกแทนด้วยตัวคั่นเดียวกับ
     * พารามิเตอร์ของ route เพื่อให้ `/leaves/${id}/pdf` เทียบกับ `api/leaves/{leave}/pdf` ได้
     *
     * @return array<int,string>|null
     */
    private function frontendCallPatterns(): ?array
    {
        $source = base_path('../frontend/src');

        if (! is_dir($source)) {
            return null;
        }

        $patterns = [];

        $finder = Finder::create()->files()->name(['*.vue', '*.js'])->in($source);

        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            $contents = $file->getContents();

            preg_match_all(
                '/(?:api|axios)\s*\.\s*(?:get|post|put|patch|delete)\(\s*([`\'"])(.*?)\1/s',
                $contents,
                $matches
            );

            foreach ($matches[2] as $path) {
                $patterns[] = $this->normalise($path);
            }

            // ประกอบ URL จาก composable จริง: ตรวจทั้งจุดเรียกและ suffix ใน implementation
            preg_match_all('/useAttendanceSummary\([\'"](\/[^\'"]+)[\'"]\)/', $contents, $summaryCalls);
            if ($summaryCalls[1] !== []) {
                $composable = file_get_contents($source.'/composables/useAttendanceSummary.js');
                preg_match_all('/runReport\(`\$\{basePath\}([^`]+)`\)/', $composable, $suffixes);
                foreach ($summaryCalls[1] as $base) {
                    foreach ($suffixes[1] as $suffix) {
                        $patterns[] = $this->normalise($base.$suffix);
                    }
                }
            }

            /*
             * เส้นทางที่ประกอบจากตัวแปร เช่น `runReport('/admin/attendances/summary/preview')`
             * ไม่ได้อยู่ในรูปแบบด้านบน จึงเก็บสตริงที่ขึ้นต้นด้วย `/admin` หรือ `/api` เพิ่ม
             */
            preg_match_all('/([`\'"])(\/(?:admin|api|leaves|attendances|overtime|reporting|reports|notification)[^`\'"\s]*)\1/', $contents, $literals);

            foreach ($literals[2] as $path) {
                $patterns[] = $this->normalise($path);
            }
        }

        return array_values(array_unique($patterns));
    }

    private function normalise(string $path): string
    {
        $path = preg_replace('/\?.*$/', '', $path);          // ตัด query string
        $path = preg_replace('/\$\{[^}]*\}/', '{p}', $path);  // ตัวแปรใน template literal

        /*
         * เส้นทางที่ต่อสตริงเอง เช่น `'/admin/settings/secrets/' + key`
         * สตริงจึงจบด้วย `/` — ถือว่ามีพารามิเตอร์ต่อท้ายหนึ่งตัว
         */
        if (str_ends_with((string) $path, '/')) {
            $path .= '{p}';
        }

        $path = ltrim((string) $path, '/');
        $path = preg_replace('#^api/#', '', $path);

        return rtrim($path, '/');
    }

    /** @param array<int,string> $frontend */
    private function isCalledBy(string $uri, array $frontend): bool
    {
        $route = $this->normalise($uri);
        $routeParts = explode('/', $route);

        foreach ($frontend as $call) {
            $callParts = explode('/', $call);

            if (count($callParts) !== count($routeParts)) {
                continue;
            }

            $matches = true;

            foreach ($routeParts as $index => $segment) {
                $callSegment = $callParts[$index];

                // พารามิเตอร์ของ route เทียบได้กับตัวแปรของหน้าเว็บหรือค่าคงที่ที่หน้าเว็บใส่มา
                if (str_starts_with($segment, '{') || $callSegment === '{p}') {
                    continue;
                }

                if ($segment !== $callSegment) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
