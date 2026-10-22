<?php

namespace App\Services\Access;

use App\Models\LoginEvent;
use App\Models\MfaCredential;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\User;
use App\Notifications\AccountNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * เข้าสู่ระบบ (CORE-03 · R05-2, R05-3)
 *
 * 1. หาบัญชีจากอีเมล / รหัสบุคลากร / (ถ้าเปิด) เลขประจำตัวประชาชน — ADR-R-013
 * 2. รหัสผ่านผิดครบ `auth.lockout.attempts` ครั้ง → ล็อก `auth.lockout.minutes` นาที (นับรหัส MFA ผิดด้วย)
 * 3. บัญชีต้อง active และไม่ใช่บัญชีระบบ
 * 4. ต้องใช้ MFA (MfaPolicy) หรือเปิด MFA ไว้เอง → ออก challenge อายุ 5 นาที ยังไม่ออก token
 *    ยังไม่เคยตั้ง MFA แต่ถูกบังคับ → ตั้งค่าผ่าน challenge เดียวกันก่อนจึงได้ token
 * 5. ได้ token: ชื่อ `session:mfa` เมื่อผ่าน MFA แล้ว (EnsureMfaSatisfied ตรวจชื่อนี้) · อุปกรณ์ใหม่ → อีเมลแจ้งเตือน
 *    · break-glass → แจ้ง ผอ. และผู้ตรวจสอบทุกครั้ง
 * ทุกผลลัพธ์บันทึก `login_events` (append-only)
 */
class AuthenticationService
{
    public const TOKEN_SESSION = 'session';

    public const TOKEN_SESSION_MFA = 'session:mfa';

    private const GENERIC_FAILURE = 'ข้อมูลการเข้าสู่ระบบไม่ถูกต้อง';

    public function __construct(
        private readonly MfaPolicy $mfa,
        private readonly TotpService $totp,
        private readonly DeviceRegistry $devices,
        private readonly AccessRecipients $recipients,
        private readonly AccountLifecycleService $lifecycle,
    ) {}

    /**
     * @return array<string, mixed> ['type' => 'token', ...token] หรือ ['type' => 'mfa', 'challenge' => ..., 'enrolled' => bool]
     */
    public function attempt(string $identifier, string $password, Request $request): array
    {
        $deviceHash = $this->devices->hash($request->input('device_id'), $request->userAgent());
        $user = $this->findUser($identifier);

        if ($user === null) {
            $this->event(null, $identifier, 'bad_password', $request, $deviceHash);
            throw ValidationException::withMessages(['login' => [self::GENERIC_FAILURE]]);
        }

        $this->releaseExpiredLock($user);
        if ($user->status === User::STATUS_LOCKED) {
            $this->event($user, $identifier, 'locked', $request, $deviceHash);
            $this->throwLocked($user);
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user, $identifier, 'bad_password', $request, $deviceHash);
            throw ValidationException::withMessages(['login' => [self::GENERIC_FAILURE]]);
        }

        if (! $user->isActive() || $user->isService()) {
            $this->event($user, $identifier, 'blocked', $request, $deviceHash);
            throw ValidationException::withMessages(['login' => ['บัญชีผู้ใช้นี้ยังไม่เปิดใช้งานหรือถูกระงับการใช้งาน']]);
        }

        $this->resetFailures($user);

        $credential = $user->confirmedMfa();
        $reasons = $this->mfa->reasons($user);

        if ($credential !== null || $reasons !== []) {
            $challenge = Str::random(64);
            Cache::put($this->challengeKey($challenge), [
                'user_id' => $user->id,
                'identifier' => $identifier,
                'device_hash' => $deviceHash,
                'remember' => $request->boolean('remember_device'),
                'attempts' => 0,
            ], now()->addSeconds((int) config('auth.mfa.challenge_seconds', 300)));

            return [
                'type' => 'mfa',
                'mfa_required' => true,
                'challenge' => $challenge,
                'enrolled' => $credential !== null,
                'reasons' => $reasons,
                'expires_in' => (int) config('auth.mfa.challenge_seconds', 300),
            ];
        }

        return ['type' => 'token'] + $this->issue($user, $identifier, $deviceHash, $request->boolean('remember_device'), false, $request);
    }

    /** ยืนยันด้วยรหัส TOTP หรือรหัสสำรอง */
    public function verifyChallenge(string $challenge, ?string $code, ?string $recoveryCode, Request $request): array
    {
        [$state, $user] = $this->openChallenge($challenge);
        $credential = $user->confirmedMfa();
        if ($credential === null) {
            throw ValidationException::withMessages(['code' => ['บัญชีนี้ยังไม่ได้ตั้งค่า MFA']]);
        }

        $ok = filled($recoveryCode)
            ? $this->totp->useRecoveryCode($credential, (string) $recoveryCode)
            : $this->totp->verify($credential, (string) $code);

        if (! $ok) {
            $this->failChallenge($challenge, $state, $user, $request);
        }

        Cache::forget($this->challengeKey($challenge));
        $this->resetFailures($user);

        return $this->issue($user, $state['identifier'], $state['device_hash'], $state['remember'], true, $request)
            + ['recovery_codes_remaining' => $this->totp->remainingRecoveryCodes($credential->fresh())];
    }

    /** เริ่มตั้งค่า MFA ระหว่างเข้าสู่ระบบ (ถูกบังคับแต่ยังไม่เคยตั้ง) */
    public function beginEnrollment(string $challenge): array
    {
        [, $user] = $this->openChallenge($challenge);

        return $this->startEnrollment($user);
    }

    /** ยืนยันการตั้งค่า MFA ระหว่างเข้าสู่ระบบ → ได้ token และรหัสสำรอง (แสดงครั้งเดียว) */
    public function confirmEnrollment(string $challenge, string $code, Request $request): array
    {
        [$state, $user] = $this->openChallenge($challenge);
        $codes = $this->confirmPendingEnrollment($user, $code);
        if ($codes === null) {
            $this->failChallenge($challenge, $state, $user, $request);
        }

        Cache::forget($this->challengeKey($challenge));
        $this->resetFailures($user);

        return $this->issue($user, $state['identifier'], $state['device_hash'], $state['remember'], true, $request)
            + ['recovery_codes' => $codes];
    }

    // ------------------------------------------------------------------ ตั้งค่า MFA (ใช้ทั้งตอนเข้าสู่ระบบและหน้า /me/security)

    /** @return array{secret: string, otpauth_uri: string, qr_svg: string} */
    public function startEnrollment(User $user): array
    {
        if ($user->confirmedMfa() !== null) {
            throw ValidationException::withMessages(['mfa' => ['บัญชีนี้ตั้งค่า MFA แล้ว']]);
        }

        $secret = $this->totp->generateSecret();
        MfaCredential::updateOrCreate(
            ['user_id' => $user->id, 'type' => 'totp'],
            ['secret' => $secret, 'confirmed_at' => null, 'recovery_codes' => null, 'last_timestep' => null],
        );
        $uri = $this->totp->otpauthUri($user, $secret);

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => $this->totp->qrSvg($uri)];
    }

    /** @return array<int, string>|null รหัสสำรอง หรือ null เมื่อรหัสไม่ถูกต้อง */
    public function confirmPendingEnrollment(User $user, string $code): ?array
    {
        $pending = MfaCredential::where('user_id', $user->id)->where('type', 'totp')->whereNull('confirmed_at')->first();
        if ($pending === null) {
            throw ValidationException::withMessages(['code' => ['ยังไม่ได้เริ่มตั้งค่า MFA หรือหมดเวลา กรุณาเริ่มใหม่']]);
        }
        if (! $this->totp->verify($pending, $code)) {
            return null;
        }

        $pending->forceFill(['confirmed_at' => now()])->save();

        return $this->totp->issueRecoveryCodes($pending);
    }

    // ------------------------------------------------------------------ ภายใน

    /** @return array<string, mixed> */
    private function issue(User $user, string $identifier, string $deviceHash, bool $remember, bool $mfaPassed, Request $request): array
    {
        if (! $this->devices->isKnown($user, $deviceHash)) {
            $this->event($user, $identifier, 'new_device', $request, $deviceHash);
            $user->notify(new AccountNotice(
                AccountNotice::NEW_DEVICE,
                'มีการเข้าสู่ระบบบุคลากรจากอุปกรณ์ใหม่',
                [
                    'บัญชี '.$user->username.' เข้าสู่ระบบจาก '.$this->devices->label($request->userAgent()).' เวลา '.now()->format('d/m/Y H:i'),
                    'ถ้าไม่ใช่คุณ ให้เปลี่ยนรหัสผ่านทันทีและแจ้งงานศูนย์ข้อมูลสารสนเทศ',
                ],
            ));
        }
        $this->devices->remember($user, $deviceHash, $remember, $request->ip(), $request->userAgent());

        $this->event($user, $identifier, 'success', $request, $deviceHash);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        if ($user->is_break_glass) {
            Notification::send($this->recipients->directors()->merge($this->recipients->auditors())->unique('id'), new AccountNotice(
                AccountNotice::BREAK_GLASS_USED,
                'มีการใช้บัญชีฉุกเฉิน (break-glass): '.$user->username,
                ['บัญชีฉุกเฉิน '.$user->username.' เข้าสู่ระบบเวลา '.now()->format('d/m/Y H:i').' จาก IP '.$request->ip(), 'ตรวจสอบเหตุผลการใช้และบันทึกการใช้งานได้ที่หน้าบันทึกการใช้งาน'],
            ));
        }

        $minutes = (int) config('sanctum.expiration', 480);
        $token = $user->createToken($mfaPassed ? self::TOKEN_SESSION_MFA : self::TOKEN_SESSION, ['*'], now()->addMinutes($minutes))->plainTextToken;

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $minutes * 60,
            'mfa_verified' => $mfaPassed,
            'user' => $user,
        ];
    }

    /** @return array{0: array<string, mixed>, 1: User} */
    private function openChallenge(string $challenge): array
    {
        $state = Cache::get($this->challengeKey($challenge));
        $user = is_array($state) ? User::find($state['user_id']) : null;

        if ($user === null) {
            throw ValidationException::withMessages(['challenge' => ['หมดเวลายืนยันตัวตน กรุณาเข้าสู่ระบบใหม่']]);
        }
        $this->releaseExpiredLock($user);
        if ($user->status === User::STATUS_LOCKED) {
            Cache::forget($this->challengeKey($challenge));
            $this->throwLocked($user);
        }
        if (! $user->isActive() || $user->isService()) {
            Cache::forget($this->challengeKey($challenge));
            throw ValidationException::withMessages(['challenge' => ['บัญชีผู้ใช้นี้ยังไม่เปิดใช้งานหรือถูกระงับการใช้งาน']]);
        }

        return [$state, $user];
    }

    /** @param  array<string, mixed>  $state */
    private function failChallenge(string $challenge, array $state, User $user, Request $request): never
    {
        $state['attempts']++;
        if ($state['attempts'] >= (int) config('auth.mfa.challenge_attempts', 5)) {
            Cache::forget($this->challengeKey($challenge));
        } else {
            Cache::put($this->challengeKey($challenge), $state, now()->addSeconds((int) config('auth.mfa.challenge_seconds', 300)));
        }

        $this->registerFailure($user, $state['identifier'], 'mfa_failed', $request, $state['device_hash']);

        throw ValidationException::withMessages(['code' => ['รหัสยืนยันไม่ถูกต้อง']]);
    }

    private function registerFailure(User $user, string $identifier, string $result, Request $request, string $deviceHash): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $user->forceFill(['failed_login_attempts' => $attempts])->saveQuietly();
        $this->event($user, $identifier, $result, $request, $deviceHash);

        if ($attempts >= (int) config('auth.lockout.attempts', 5) && $user->status === User::STATUS_ACTIVE) {
            $user->forceFill(['locked_until' => now()->addMinutes((int) config('auth.lockout.minutes', 30))]);
            $this->lifecycle->setStatus($user, User::STATUS_LOCKED, AccountLifecycleService::REASON_FAILED_LOGINS, null, "รหัสผิด {$attempts} ครั้ง");
            $this->event($user, $identifier, 'locked', $request, $deviceHash);
            $this->throwLocked($user);
        }
    }

    private function resetFailures(User $user): void
    {
        if ($user->failed_login_attempts !== 0) {
            $user->forceFill(['failed_login_attempts' => 0])->saveQuietly();
        }
    }

    private function releaseExpiredLock(User $user): void
    {
        if ($user->status === User::STATUS_LOCKED && $user->locked_until !== null && $user->locked_until->isPast()) {
            $this->lifecycle->setStatus($user, User::STATUS_ACTIVE, null, null, 'ครบเวลาล็อก');
        }
    }

    private function throwLocked(User $user): never
    {
        $until = $user->locked_until;
        $message = 'บัญชีถูกล็อกชั่วคราวเพราะใส่รหัสผิดหลายครั้ง'.($until ? ' ลองใหม่หลังเวลา '.$until->format('H:i') : ' กรุณาติดต่องานศูนย์ข้อมูลสารสนเทศ');

        throw new HttpException(423, $message);
    }

    private function event(?User $user, string $identifier, string $result, Request $request, ?string $deviceHash): void
    {
        LoginEvent::create([
            'user_id' => $user?->id,
            'username_attempted' => $this->maskIdentifier($identifier),
            'result' => $result,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'device_hash' => $deviceHash,
            'created_at' => now(),
        ]);
    }

    /** เลข 13 หลัก (อาจเป็นเลขประจำตัวประชาชน) ไม่เก็บเต็มใน log */
    private function maskIdentifier(string $identifier): string
    {
        $identifier = mb_substr(trim($identifier), 0, 255);

        return preg_match('/^\d{13}$/', $identifier) ? substr($identifier, 0, 1).str_repeat('•', 10).substr($identifier, -2) : $identifier;
    }

    private function challengeKey(string $challenge): string
    {
        return 'auth:mfa-challenge:'.hash('sha256', $challenge);
    }

    /** อีเมล → รหัสบุคลากร → (ถ้าเปิดไว้) เลขประจำตัวประชาชนผ่าน hash — ADR-R-013 */
    public function findUser(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', $identifier)->first();
        }

        $user = User::where('username', $identifier)->whereNull('personnel_id')->first(); // บัญชีระบบ/ฉุกเฉินที่ไม่ผูกบุคลากร
        if ($user !== null) {
            return $user;
        }

        $personnelId = Personnel::where('personnel_code', $identifier)->value('id');
        if ($personnelId === null && config('auth.allow_national_id_login', false) && preg_match('/^\d{13}$/', $identifier)) {
            $personnelId = PersonnelProfile::where('national_id_hash', PersonnelProfile::hashNationalId($identifier))->value('personnel_id');
        }

        return $personnelId === null ? null : User::where('personnel_id', $personnelId)->first();
    }
}
