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
 * MFA บังคับทุก request ไม่ใช่เฉพาะตอนเข้าสู่ระบบ (CORE-03 · R05-2) — fail-closed (แก้ไขหลังตรวจ R-05 ข้อ 1)
 *
 * ผู้ที่ MfaPolicy บอกว่าต้องใช้ MFA ผ่านได้เฉพาะเมื่อถือ token `session:mfa` ที่มีแถวจริงในฐานข้อมูล
 * ทางยืนยันตัวตนอื่นทุกทาง (token ที่ไม่ผ่าน MFA, TransientToken จาก session ของ guard web ฯลฯ) ได้ 403 `mfa_required`
 * — เช่น คนที่ได้รับแต่งตั้งเป็นหัวหน้างานหรือได้ชุดสิทธิ์งานบุคคลระหว่างที่ยังล็อกอินอยู่ · ออกจากระบบได้เสมอ
 *
 * ข้อยกเว้นเดียว: ระหว่างรันเทสต์ (`runningUnitTests()`) และเทสต์ตั้ง `auth.mfa.allow_transient_in_tests` เอง
 * ให้ token จำลองที่ไม่มีแถว (Sanctum::actingAs) ผ่านได้ — ค่านี้ไม่อ่านจาก env และไม่มีผลใน environment อื่น
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
        if ($user === null) {
            return $next($request); // ให้ auth:sanctum ตอบ 401
        }
        if (in_array('POST', $route->methods(), true) && $route->uri() === 'api/logout') {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        $persisted = $token instanceof PersonalAccessToken && $token->exists;

        if ($persisted && $token->name === AuthenticationService::TOKEN_SESSION_MFA) {
            return $next($request);
        }
        if (! $persisted && app()->runningUnitTests() && config('auth.mfa.allow_transient_in_tests') === true) {
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
