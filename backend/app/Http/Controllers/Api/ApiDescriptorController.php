<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * คำอธิบายพื้นผิว API รุ่น v1 แบบอ่านด้วยเครื่องได้ (SoT §17)
 *
 * **สร้างจากตาราง route จริง ไม่ใช่เอกสารที่เขียนมือ** — เอกสารที่เขียนมือ
 * จะไม่ตรงกับความจริงภายในไม่กี่เดือน และไม่มีใครรู้ว่าไม่ตรงตรงไหน
 * ตัวนี้ผิดไม่ได้เพราะมันอ่านจากสิ่งเดียวกับที่ระบบใช้จริง
 *
 * ใช้เป็นฐานของ MCP ตาม SoT §17: ผู้เรียกภายนอกรู้ว่ามี endpoint อะไร
 * ต้องมีสิทธิ์อะไร และต้องยืนยันตัวตนซ้ำหรือไม่ — **โดยไม่ต้องแตะฐานข้อมูลตรง**
 *
 * เปิดให้ผู้ที่ยืนยันตัวตนแล้วทุกคนอ่านได้ เพราะเป็นรายชื่อความสามารถ ไม่ใช่ข้อมูล
 * — และเป็นรายการเดียวกับที่ผู้เรียกค้นพบได้อยู่แล้วจากการลองเรียก
 */
class ApiDescriptorController extends Controller
{
    public function __invoke()
    {
        $paths = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = '/'.$route->uri();
            $middleware = $route->gatherMiddleware();

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$path][strtolower($method)] = [
                    'operationId' => $route->getName(),
                    'security' => $this->securityOf($middleware),
                ];
            }
        }

        ksort($paths);

        return response()->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'E-Leave API',
                'version' => 'v1',
                'description' => 'พื้นผิว API รุ่น v1 · สร้างจากตาราง route จริงของระบบ',
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api']],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'headers' => [
                    'Idempotency-Key' => [
                        'description' => 'กุญแจกันทำซ้ำสำหรับคำขอที่เปลี่ยนแปลงข้อมูล · ส่งกุญแจเดิมซ้ำจะได้ผลเดิมโดยไม่ทำงานซ้ำ',
                        'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 190],
                    ],
                    'X-Request-ID' => [
                        'description' => 'รหัสอ้างอิงคำขอ · ระบบคืนกลับมาในทุก response และบันทึกลง audit',
                        'schema' => ['type' => 'string', 'maxLength' => 128],
                    ],
                ],
            ],
            'paths' => $paths,
        ]);
    }

    /**
     * สิทธิ์และเงื่อนไขที่ endpoint นั้นบังคับ อ่านจาก middleware จริงของ route
     *
     * @param  array<int,string>  $middleware
     * @return array<string,mixed>
     */
    private function securityOf(array $middleware): array
    {
        $abilities = [];
        $stepUp = false;

        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'ability:')) {
                $abilities = array_merge($abilities, explode('|', substr($entry, strlen('ability:'))));
            }

            if (str_starts_with($entry, 'can:')) {
                $abilities[] = substr($entry, strlen('can:'));
            }

            if ($entry === 'step_up') {
                $stepUp = true;
            }
        }

        return [
            'bearerAuth' => array_values(array_unique($abilities)),
            'requires_recent_authentication' => $stepUp,
        ];
    }
}
