<?php

namespace App\Http\Middleware;

use App\Authz\PermissionEngine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->permission('module.feature.action')` / alias `permission:` (R04-5)
 *
 * ตรวจว่าผู้ใช้มี permission นี้อย่างน้อยหนึ่ง scope จาก PermissionEngine — ไม่มีเลยตอบ 403
 * การตรวจระดับรายการ (resource อยู่ใน scope ไหม / กฎกลาง) ทำใน controller ด้วย `can()` และตอบ 404 เมื่อนอก scope
 * ระบุหลายชื่อคั่นด้วย `|` = มีชื่อใดชื่อหนึ่งก็ผ่าน
 */
class RequirePermission
{
    public function __construct(private readonly PermissionEngine $engine) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $names = collect($permissions)->flatMap(fn (string $p) => explode('|', $p))->filter()->values();

        if ($user === null) {
            return response()->json(['message' => 'กรุณาเข้าสู่ระบบ'], Response::HTTP_UNAUTHORIZED);
        }

        foreach ($names as $name) {
            if ($this->engine->allows($user, $name)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'ไม่มีสิทธิ์ใช้งานฟังก์ชันนี้'], Response::HTTP_FORBIDDEN);
    }
}
