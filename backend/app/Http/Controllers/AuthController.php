<?php

namespace App\Http\Controllers;

use App\Authz\PermissionEngine;
use App\Models\Assignment;
use App\Models\User;
use App\Services\Access\AuthenticationService;
use App\Services\Access\MfaPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * เข้าสู่ระบบ / MFA / ออกจากระบบ (CORE-03 · R-05) — ตรรกะอยู่ที่ AuthenticationService
 *
 * endpoint ก่อนยืนยันตัวตน (`/login`, `/login/mfa*`) ประกาศ ->permission() ไม่ได้ เพราะยังไม่รู้ว่าเป็นใคร
 * จึงจำกัดอัตราด้วย `throttle:login` ต่อ IP และล็อกบัญชีเมื่อผิดครบ 5 ครั้ง
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthenticationService $auth) {}

    /** ตัวเลือกของหน้าเข้าสู่ระบบ (สาธารณะ) — แสดงช่องเลขประจำตัวประชาชนเฉพาะเมื่อเปิด ADR-R-013 */
    public function options(): JsonResponse
    {
        return response()->json([
            'allow_national_id_login' => (bool) config('auth.allow_national_id_login', false),
            'remember_device_days' => (int) config('auth.devices.remember_days', 30),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        /*
         * ADR-R-013 (Q10) — ตัวระบุหลักคืออีเมลหรือรหัสบุคลากร (`personnel_code`)
         * เลขประจำตัวประชาชนใช้ได้เฉพาะเมื่อเปิด `auth.allow_national_id_login` (ค่าเริ่มต้นปิด)
         * รับชื่อช่อง `login` และ `email` (หน้าเข้าสู่ระบบเดิมส่ง `email`)
         */
        $request->validate([
            'login' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:login', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'remember_device' => ['nullable', 'boolean'],
        ], [
            'login.required_without' => 'กรุณากรอกอีเมลหรือรหัสบุคลากร',
            'email.required_without' => 'กรุณากรอกอีเมลหรือรหัสบุคลากร',
        ]);

        $identifier = trim((string) ($request->input('login') ?? $request->input('email')));
        $result = $this->auth->attempt($identifier, (string) $request->input('password'), $request);

        if ($result['type'] === 'mfa') {
            unset($result['type']);

            return response()->json($result);
        }

        return $this->tokenResponse($result);
    }

    /** ยืนยัน MFA ด้วยรหัส 6 หลัก หรือรหัสสำรอง */
    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:10'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:20'],
        ]);

        return $this->tokenResponse($this->auth->verifyChallenge($data['challenge'], $data['code'] ?? null, $data['recovery_code'] ?? null, $request));
    }

    /** ถูกบังคับใช้ MFA แต่ยังไม่เคยตั้ง → ขอ secret และ QR */
    public function startMfaSetup(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'size:64']]);

        return response()->json($this->auth->beginEnrollment($data['challenge']));
    }

    public function confirmMfaSetup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        return $this->tokenResponse($this->auth->confirmEnrollment($data['challenge'], $data['code'], $request));
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()?->currentAccessToken();

        // Session-authenticated requests receive Sanctum's TransientToken,
        // which is not a persisted model and therefore cannot be deleted.
        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'ออกจากระบบเรียบร้อยแล้ว']);
    }

    /** หมุน token — คงสถานะ MFA ของ token เดิม (ชื่อ token) */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessToken = $user->currentAccessToken();
        $name = $accessToken instanceof PersonalAccessToken ? $accessToken->name : AuthenticationService::TOKEN_SESSION;

        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        $expiration = (int) config('sanctum.expiration', 480);
        $token = $user->createToken($name, ['*'], now()->addMinutes($expiration))->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiration * 60,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        // Always return the same response to prevent account enumeration.
        return response()->json(['message' => 'หากพบบัญชี ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ให้ทางอีเมล']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:12|confirmed',
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['ลิงก์ตั้งรหัสผ่านไม่ถูกต้องหรือหมดอายุ']]);
        }

        return response()->json(['message' => 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()));
    }

    /** @param  array<string, mixed>  $result */
    private function tokenResponse(array $result): JsonResponse
    {
        $user = $result['user'];
        unset($result['user'], $result['type']);

        return response()->json($result + ['user' => $this->present($user)]);
    }

    /**
     * ข้อมูลบัญชีของตนเอง — `permission_names` มาจาก PermissionEngine ใช้ซ่อน/แสดงในหน้าเว็บเท่านั้น
     */
    private function present(User $user): array
    {
        $personnel = $user->personnel;
        $engine = app(PermissionEngine::class);

        return [
            'uuid' => $user->uuid,
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->status,
            'account_type' => $user->account_type,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'personnel' => $personnel ? [
                'uuid' => $personnel->uuid,
                'personnel_code' => $personnel->personnel_code,
                'full_name' => $personnel->full_name,
                'first_name' => $personnel->first_name,
                'last_name' => $personnel->last_name,
                // แสดงที่ท้ายแถบเมนู (R-06): ตำแหน่งหลัก · สังกัด
                'primary' => ($primary = Assignment::with(['position', 'orgUnit'])->where('personnel_id', $personnel->id)
                    ->where('kind', Assignment::KIND_PRIMARY)->effectiveOn(today()->toDateString())->first())
                    ? ['position' => $primary->position?->name, 'unit' => $primary->orgUnit?->name] : null,
            ] : null,
            'mfa' => [
                'enabled' => $user->confirmedMfa() !== null,
                'required' => app(MfaPolicy::class)->requires($user),
            ],
            'permission_names' => $engine->effective($user)->pluck('permission')->unique()->sort()->values()->all(),
        ];
    }
}
