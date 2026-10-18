<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * alias `permission:` ของ route — แทน middleware ของ spatie ที่ถอดออกแล้ว (ADR-R-003)
 *
 * ระหว่าง R-02–R-03 ยังไม่มี PermissionEngine จึงปฏิเสธทุกคำขอ (deny by default)
 * R-04 เปลี่ยนให้เรียก `PermissionEngine::can()` และแทนการประกาศแบบเดิมด้วย `->permission('x.y.z')`
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        return response()->json(['message' => DenyUntilPermissionEngine::MESSAGE], Response::HTTP_FORBIDDEN);
    }
}
