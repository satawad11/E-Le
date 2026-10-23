<?php

namespace App\Http\Middleware;

use App\Authz\Manifest\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * deny by default ระดับ route (R-04 แทน DenyUntilPermissionEngine ของ R-02)
 *
 * route ที่ต้องยืนยันตัวตนใช้ได้เมื่อ:
 *   (ก) ประกาศ `->permission(...)` ด้วยชื่อ permission ที่มีอยู่ใน manifest (ต่อ PermissionEngine แล้ว) หรือ
 *   (ข) อยู่ใน SELF_SERVICE — การเข้า/ออกจากระบบและข้อมูลสิทธิ์/เมนูของตนเอง
 * route อื่นทั้งหมด — โมดูลเดิมที่ยังตรวจสิทธิ์แบบเก่า (`can:`, ชื่อสิทธิ์เดิม, ไม่ตรวจเลย) — ตอบ 403
 * จนกว่าจะย้ายใน R-08 จึงไม่มี endpoint ใดผ่านโดยไม่ถาม engine
 *
 * ทำงานก่อน route model binding (prepend ในกลุ่ม api) — ไม่แตะ controller ของโมดูลเดิมที่อ้างตารางที่ถูกตัด
 */
class EnsureRouteUsesPermissionEngine
{
    /** route (METHOD uri) ของตนเองที่ผู้ใช้ที่ยืนยันตัวตนแล้วทุกคนใช้ได้ */
    public const SELF_SERVICE = [
        'POST api/logout',
        'POST api/refresh-token',
        'GET api/user',
        'GET api/me/permissions',
        'GET api/me/menu',
        'GET api/me/home',
    ];

    public const MESSAGE = 'ฟังก์ชันนี้ยังไม่ได้ย้ายเข้าระบบตรวจสิทธิ์ใหม่ (REBUILD-2569) จะเปิดใช้เมื่อย้ายโมดูลเสร็จ';

    public function __construct(private readonly ModuleRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route instanceof Route || ! self::requiresAuthentication($route->gatherMiddleware())) {
            return $next($request);
        }

        if (! Auth::guard('sanctum')->check()) {
            return $next($request); // ให้ auth:sanctum ตอบ 401
        }

        if ($this->isSelfService($route) || $this->declaresEnginePermission($route)) {
            return $next($request);
        }

        return response()->json(['message' => self::MESSAGE], Response::HTTP_FORBIDDEN);
    }

    public function isSelfService(Route $route): bool
    {
        foreach ($route->methods() as $method) {
            if (in_array($method.' '.$route->uri(), self::SELF_SERVICE, true)) {
                return true;
            }
        }

        return false;
    }

    /** route ประกาศ `permission:` ที่ทุกชื่อมีอยู่ใน manifest */
    public function declaresEnginePermission(Route $route): bool
    {
        $known = $this->registry->permissions();

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $names = array_filter(explode('|', str_replace(',', '|', substr($middleware, strlen('permission:')))));

                return $names !== [] && array_diff($names, array_keys($known)) === [];
            }
        }

        return false;
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
