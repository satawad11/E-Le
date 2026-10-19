<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * R02-11 — deny by default ระหว่าง R-02 ถึง R-04 (PROJECT_RULES ข้อ 4)
 *
 * แบบจำลองสิทธิ์เดิม (spatie, allow-all ก่อน Gate, ตารางสิทธิ์เสริม) ถูกถอดแล้ว แต่ PermissionEngine
 * จะต่อใน R-04 ระหว่างนี้ทุก route ที่ต้องยืนยันตัวตนตอบ 403 ยกเว้นรายการ ALLOWED
 * ซึ่งเป็นการเข้า/ออกจากระบบและข้อมูลบัญชีของตนเองเท่านั้น
 *
 * ทำงานก่อน route model binding (prepend ในกลุ่ม api) จึงไม่แตะ controller หรือ model ของโมดูลเดิม
 * ที่ยังอ้างตารางที่ถูกตัด — คำขอที่ไม่ได้ยืนยันตัวตนปล่อยให้ `auth:sanctum` ตอบ 401 ตามเดิม
 *
 * R-04: ถอด middleware นี้ออกเมื่อทุก route มี `->permission(...)` ที่ต่อ PermissionEngine แล้ว
 */
class DenyUntilPermissionEngine
{
    /** route (METHOD uri) ที่ผู้ใช้ที่ยืนยันตัวตนแล้วใช้ได้ระหว่าง R-02–R-03 */
    public const ALLOWED = [
        'POST api/logout',
        'POST api/refresh-token',
        'GET api/user',
    ];

    public const MESSAGE = 'ฟังก์ชันนี้ปิดชั่วคราวระหว่างปรับระบบสิทธิ์ (REBUILD-2569) จะเปิดใช้เมื่อเชื่อมระบบตรวจสิทธิ์ใหม่';

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null || ! $this->requiresAuthentication($route->gatherMiddleware())) {
            return $next($request);
        }

        if (! Auth::guard('sanctum')->check()) {
            return $next($request);
        }

        foreach ($route->methods() as $method) {
            if (in_array($method.' '.$route->uri(), self::ALLOWED, true)) {
                return $next($request);
            }
        }

        return response()->json(['message' => self::MESSAGE], Response::HTTP_FORBIDDEN);
    }

    /** @param  array<int, mixed>  $middleware */
    public static function requiresAuthentication(array $middleware): bool
    {
        foreach ($middleware as $item) {
            if (is_string($item) && ($item === 'auth' || str_starts_with($item, 'auth:'))) {
                return true;
            }
        }

        return false;
    }
}
