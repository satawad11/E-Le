<?php

namespace App\Http\Middleware;

use App\Services\Operations\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * โหมดอ่านอย่างเดียว (SoT §27)
 *
 * **ต่างจาก `php artisan down` โดยสิ้นเชิง**
 *
 *   `artisan down`  ปิดทั้งระบบ — ผู้ใช้เปิดอะไรไม่ได้เลย
 *   โหมดนี้         **อ่านได้ เขียนไม่ได้**
 *
 * ใช้ตอนกู้คืนข้อมูล ย้ายฐานข้อมูล หรือระหว่างตรวจสอบเหตุการณ์ผิดปกติ —
 * ช่วงเวลาที่ต้องหยุดการเปลี่ยนแปลง **แต่ยังต้องให้คนค้นหนังสือได้**
 * เพราะงานราชการหยุดรอไม่ได้
 *
 * **ตอบ 503 พร้อม `Retry-After`** ไม่ใช่ 403 — ผู้เรียกต้องแยกออกระหว่าง
 * "คุณไม่มีสิทธิ์" กับ "ตอนนี้ระบบไม่รับ ลองใหม่ทีหลัง" · ระบบภายนอกที่เห็น 403
 * จะเลิกลองใหม่ถาวร ซึ่งผิด
 *
 * **ไม่ยกเว้นผู้ดูแลสูงสุด** — จุดประสงค์คือหยุดการเขียนทุกทาง ถ้าผู้ดูแล
 * ยังเขียนได้ระหว่างกู้คืนข้อมูล ข้อมูลที่เพิ่งกู้มาจะถูกทับทันที
 */
class EnforceReadOnlyMode
{
    /** เส้นทางที่ต้องทำงานได้เสมอ แม้อยู่ในโหมดอ่านอย่างเดียว */
    private const ALWAYS_ALLOWED = [
        // ต้องออกจากระบบได้ ไม่งั้นผู้ใช้ค้างอยู่ในเซสชันที่ทำอะไรไม่ได้
        'api/logout',
        // ต้องเข้าสู่ระบบได้เพื่อมาดูสถานะ
        'api/login',
        // ต้องปิดโหมดนี้ได้จากในระบบ ไม่งั้นต้องแก้ฐานข้อมูลด้วยมือ
        'api/admin/feature-flags/{key}',
    ];

    public function __construct(private readonly FeatureFlags $flags) {}

    public function handle(Request $request, Closure $next): Response
    {
        $writes = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if (! $writes || ! $this->flags->enabled('system.read_only')) {
            return $next($request);
        }

        if (in_array($request->route()?->uri(), self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'ระบบอยู่ในโหมดอ่านอย่างเดียวชั่วคราว — ค้นหาและเปิดดูเอกสารได้ตามปกติ '
                .'แต่ยังบันทึกการเปลี่ยนแปลงไม่ได้ กรุณาลองใหม่อีกครั้งภายหลัง',
            'read_only' => true,
        ], 503)->header('Retry-After', '300');
    }
}
