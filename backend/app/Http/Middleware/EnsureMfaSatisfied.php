<?php

namespace App\Http\Middleware;

use App\Services\Access\AuthenticationService;
use App\Services\Access\MfaPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA บังคับทุก request ไม่ใช่เฉพาะตอนเข้าสู่ระบบ (CORE-03 · R05-2)
 *
 * ผู้ที่ MfaPolicy บอกว่าต้องใช้ MFA ต้องถือ token ที่ออกหลังผ่าน MFA (`session:mfa`)
 * — เช่น คนที่ได้รับแต่งตั้งเป็นหัวหน้างานหรือได้ชุดสิทธิ์งานบุคคลระหว่างที่ยังล็อกอินอยู่
 * จะถูกปฏิเสธ (403 `mfa_required`) จนกว่าจะออกและเข้าสู่ระบบใหม่พร้อม MFA · ออกจากระบบได้เสมอ
 */
class EnsureMfaSatisfied
{
    public const CODE = 'mfa_required';

    public function __construct(private readonly MfaPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! $route instanceof Route || ! EnsureRouteUsesPermissionEngine::requiresAuthentication($route->gatherMiddleware())) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();
        $token = $user?->currentAccessToken();
        // ตรวจเฉพาะ token ที่ออกจริง (มีแถวในฐานข้อมูล) · token ชั่วคราวของ session/เทสต์ไม่มีชื่อให้ตรวจ
        if (! $token instanceof PersonalAccessToken || ! $token->exists || $token->name === AuthenticationService::TOKEN_SESSION_MFA) {
            return $next($request);
        }
        if (in_array('POST', $route->methods(), true) && $route->uri() === 'api/logout') {
            return $next($request);
        }

        if ($this->policy->requires($user)) {
            return response()->json([
                'message' => 'บัญชีนี้ต้องยืนยันตัวตนด้วย MFA กรุณาออกจากระบบแล้วเข้าสู่ระบบใหม่',
                'code' => self::CODE,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
