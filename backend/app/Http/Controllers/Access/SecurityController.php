<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\KnownDevice;
use App\Models\LoginEvent;
use App\Models\MfaCredential;
use App\Services\Access\AuthenticationService;
use App\Services\Access\MfaPolicy;
use App\Services\Access\TotpService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * ความปลอดภัยบัญชีของตนเอง `/me/security` (PLAN §8.2 เมนูผู้ใช้ · CORE-03) — `access.self.security` (own)
 *
 * เปลี่ยนรหัสผ่าน (ต้องใส่รหัสเดิม · ตัด token ทั้งหมด) · ตั้ง/ปิด MFA · ออกรหัสสำรองใหม่ · ดูและลืมอุปกรณ์ · ประวัติเข้าใช้
 */
class SecurityController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly TotpService $totp,
        private readonly MfaPolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $credential = $user->confirmedMfa();

        return response()->json([
            'mfa' => [
                'enabled' => $credential !== null,
                'required' => $this->policy->requires($user),
                'reasons' => $this->policy->reasons($user),
                'confirmed_at' => $credential?->confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => $credential ? $this->totp->remainingRecoveryCodes($credential) : 0,
            ],
            'devices' => $user->knownDevices()->orderByDesc('last_seen_at')->get()->map(fn (KnownDevice $d) => [
                'uuid' => $d->uuid,
                'label' => $d->label,
                'ip' => $d->ip,
                'first_seen_at' => $d->first_seen_at?->toIso8601String(),
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'trusted_until' => $d->trusted_until?->toIso8601String(),
            ])->values(),
            'recent_logins' => $user->loginEvents()->orderByDesc('id')->limit(10)->get()->map(fn (LoginEvent $e) => [
                'result' => $e->result,
                'ip' => $e->ip,
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordRule::min(12)],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        }

        // UserObserver ตัด token ทั้งหมด (รวม token ปัจจุบัน) เมื่อรหัสผ่านเปลี่ยน — ต้องเข้าสู่ระบบใหม่
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $this->audit->log('account.password_changed', $user, null, null, null, null, $user->id);

        return response()->json(['message' => 'เปลี่ยนรหัสผ่านแล้ว กรุณาเข้าสู่ระบบใหม่ด้วยรหัสผ่านใหม่']);
    }

    public function startMfa(Request $request): JsonResponse
    {
        return response()->json($this->auth->startEnrollment($request->user()));
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $codes = $this->auth->confirmPendingEnrollment($request->user(), $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'รหัสยืนยันไม่ถูกต้อง']);
        }
        $this->audit->log('account.mfa_enabled', $request->user(), null, null, null, null, $request->user()->id);

        return response()->json(['recovery_codes' => $codes]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $credential = $this->requireCode($request, $data['code']);
        $this->audit->log('account.mfa_recovery_regenerated', $request->user(), null, null, null, null, $request->user()->id);

        return response()->json(['recovery_codes' => $this->totp->issueRecoveryCodes($credential)]);
    }

    public function disableMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();
        if ($this->policy->requires($user)) {
            throw ValidationException::withMessages(['mfa' => 'ตำแหน่งหรือชุดสิทธิ์ของคุณต้องใช้ MFA ปิดไม่ได้']);
        }

        $this->requireCode($request, $data['code']);
        $user->mfaCredentials()->delete();
        $this->audit->log('account.mfa_disabled', $user, null, null, null, null, $user->id);

        return response()->json(['message' => 'ปิด MFA แล้ว']);
    }

    public function forgetDevice(Request $request, KnownDevice $device): JsonResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        $device->delete();

        return response()->json(['message' => 'ลบอุปกรณ์แล้ว การเข้าใช้ครั้งถัดไปจากอุปกรณ์นี้จะแจ้งเตือนเป็นอุปกรณ์ใหม่']);
    }

    private function requireCode(Request $request, string $code): MfaCredential
    {
        $credential = $request->user()->confirmedMfa();
        if ($credential === null) {
            throw ValidationException::withMessages(['mfa' => 'ยังไม่ได้ตั้งค่า MFA']);
        }
        if (! $this->totp->verify($credential, $code)) {
            throw ValidationException::withMessages(['code' => 'รหัสยืนยันไม่ถูกต้อง']);
        }

        return $credential;
    }
}
